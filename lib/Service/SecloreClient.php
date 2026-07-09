<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service;

use OCA\Sclrit\Exceptions\FileTooLargeException;
use OCA\Sclrit\Exceptions\NotConfiguredException;
use OCA\Sclrit\Exceptions\PolicyNotFoundException;
use OCA\Sclrit\Exceptions\SecloreApiException;
use OCA\Sclrit\Exceptions\SecloreAuthException;
use OCA\Sclrit\Exceptions\SecloreProtocolException;
use OCA\Sclrit\Exceptions\SecloreUnavailableException;
use OCA\Sclrit\Service\Dto\ConnectionResult;
use OCA\Sclrit\Service\Dto\Credentials;
use OCA\Sclrit\Service\Dto\ProtectResult;
use OCA\Sclrit\Service\Dto\SecloreFileInfo;
use OCA\Sclrit\Service\Dto\Token;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * HTTP implementation of the Seclore adapter against the **verified** Seclore
 * DRM tenant API 1.0 (SDD §7.3.1, confirmed 2026-07-09 from Seclore's public
 * n8n integration node). File operations are multi-step:
 *
 *   protect:   upload → POST protect/hf → download protected → cleanup
 *   unprotect: upload → POST unprotect  → download original  → cleanup
 *
 * Each HTTP step gets its own token refresh-and-replay (SDD §7.2), so a token
 * expiring mid-sequence never replays a side-effecting step. On-premises
 * Policy Server deployments must still be reconciled with this contract
 * (SDD §15 Q1).
 */
final class SecloreClient implements ISecloreClient {
	// Verified endpoints (SDD §7.3.1).
	private const EP_LOGIN = '/seclore/drm/1.0/auth/login';
	private const EP_PROTECT_HF = '/seclore/drm/1.0/protect/hf';
	private const EP_UNPROTECT = '/seclore/drm/1.0/unprotect';
	private const EP_STORAGE_UPLOAD = '/seclore/drm/filestorage/1.0/upload';
	private const EP_STORAGE_DOWNLOAD = '/seclore/drm/filestorage/1.0/download/';
	private const EP_STORAGE_DELETE = '/seclore/drm/filestorage/1.0/';

	private const HEADER_CORRELATION = 'X-SECLORE-CORRELATION-ID';

	/**
	 * The login response carries no expiry (SDD §7.3.1) — assume a short
	 * lifetime; a rejected token is refreshed and replayed once anyway. The
	 * refresh-token endpoint is intentionally unused: re-login is equivalent
	 * and keeps the token cache single-valued.
	 */
	private const ASSUMED_TOKEN_TTL_S = 600;

	private const CONNECT_TIMEOUT_S = 10;
	private const DEFAULT_TIMEOUT_S = 120;
	private const AUTH_TIMEOUT_S = 30;
	private const ERROR_DETAIL_MAX = 2048;

	public function __construct(
		private readonly IClientService $clientService,
		private readonly ConfigService $config,
		private readonly TokenStore $tokenStore,
		private readonly ITempManager $tempManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function testConnection(?Credentials $override = null): ConnectionResult {
		$credentials = $override ?? ($this->config->isConfigured() ? $this->config->getCredentials() : null);
		if ($credentials === null) {
			return new ConnectionResult(false, null, 'Not configured: base URL, tenant ID and secret are required');
		}

		try {
			$this->authenticate($credentials);
			// The API has no policy-listing endpoint (SDD §15 Q1a), so there is
			// no policy count to report.
			return new ConnectionResult(true);
		} catch (SecloreApiException $e) {
			return new ConnectionResult(false, null, $e->getMessage());
		}
	}

	public function protect($in, string $fileName, string $hotFolderId, ?string $ownerEmail = null): ProtectResult {
		// $ownerEmail is accepted for interface stability but unused: the DRM
		// tenant API has no on-behalf-of field — ownership follows the Hot
		// Folder (SDD §15 Q2).
		$credentials = $this->requireConfigured();
		$correlationId = $this->newCorrelationId();
		$sizeBytes = $this->streamSize($in);
		$started = microtime(true);

		$uploadId = $this->uploadStream($credentials, $in, $fileName, $sizeBytes, $correlationId);
		try {
			$data = $this->postJson($credentials, self::EP_PROTECT_HF, [
				'hotfolderId' => $hotFolderId,
				'fileStorageId' => $uploadId,
			], 'protect', $correlationId, $this->scaledTimeout($sizeBytes));
		} finally {
			$this->deleteStored($credentials, $uploadId, $correlationId);
		}

		$protectedStorageId = $data['fileStorageId'] ?? null;
		$secloreFileId = $data['secloreFileId'] ?? null;
		if (!is_string($protectedStorageId) || $protectedStorageId === ''
			|| !is_string($secloreFileId) || $secloreFileId === '') {
			throw new SecloreProtocolException('Unexpected protect response shape — confirm the API contract (SDD §7.3.1)');
		}

		$sink = $this->downloadToTemp($credentials, $protectedStorageId, $sizeBytes, $correlationId, 'protect download');
		$this->deleteStored($credentials, $protectedStorageId, $correlationId);

		clearstatcache(true, $sink);
		$protectedBytes = (int)(filesize($sink) ?: 0);

		$this->logger->info('Seclore protect succeeded', [
			'requestId' => $correlationId,
			'inBytes' => $sizeBytes,
			'outBytes' => $protectedBytes,
			'durationMs' => (int)((microtime(true) - $started) * 1000),
		]);

		return new ProtectResult($secloreFileId, $sink, $protectedBytes, $correlationId);
	}

	public function unprotect($in, string $fileName): string {
		$credentials = $this->requireConfigured();
		$correlationId = $this->newCorrelationId();
		$sizeBytes = $this->streamSize($in);

		$uploadId = $this->uploadStream($credentials, $in, $fileName, $sizeBytes, $correlationId);
		try {
			$data = $this->postJson($credentials, self::EP_UNPROTECT, [
				'fileStorageId' => $uploadId,
			], 'unprotect', $correlationId, $this->scaledTimeout($sizeBytes));
		} finally {
			$this->deleteStored($credentials, $uploadId, $correlationId);
		}

		$unprotectedStorageId = $data['fileStorageId'] ?? null;
		if (!is_string($unprotectedStorageId) || $unprotectedStorageId === '') {
			throw new SecloreProtocolException('Unexpected unprotect response shape — confirm the API contract (SDD §7.3.1)');
		}

		$sink = $this->downloadToTemp($credentials, $unprotectedStorageId, $sizeBytes, $correlationId, 'unprotect download');
		$this->deleteStored($credentials, $unprotectedStorageId, $correlationId);

		$this->logger->info('Seclore unprotect succeeded', [
			'requestId' => $correlationId,
			'inBytes' => $sizeBytes,
		]);

		return $sink;
	}

	public function getFileInfo($in): ?SecloreFileInfo {
		// The DRM tenant API 1.0 has no info/probe endpoint (SDD §15 Q6);
		// the probe is inconclusive by contract.
		$this->requireConfigured();
		return null;
	}

	/**
	 * Upload a stream to the file storage, returning its fileStorageId.
	 *
	 * @param resource $in
	 */
	private function uploadStream(Credentials $credentials, $in, string $fileName, ?int $sizeBytes, string $correlationId): string {
		$response = $this->withToken($credentials, function (string $token) use ($credentials, $in, $fileName, $sizeBytes, $correlationId): IResponse {
			// The stream may already be consumed when this closure is replayed
			// after a token refresh (SDD §7.2).
			$this->rewindIfNeeded($in);
			$response = $this->request($credentials, 'POST', self::EP_STORAGE_UPLOAD, [
				'multipart' => [['name' => 'file', 'contents' => $in, 'filename' => $fileName]],
				'timeout' => $this->scaledTimeout($sizeBytes),
			], $token, $correlationId);
			$this->assertOk($response, 'upload');
			return $response;
		});

		$data = json_decode((string)$response->getBody(), true);
		$fileStorageId = is_array($data) ? ($data['fileStorageId'] ?? null) : null;
		if (!is_string($fileStorageId) || $fileStorageId === '') {
			throw new SecloreProtocolException('Unexpected upload response shape — confirm the API contract (SDD §7.3.1)');
		}
		return $fileStorageId;
	}

	/**
	 * Stream a stored file into a temp file owned by the caller.
	 */
	private function downloadToTemp(Credentials $credentials, string $fileStorageId, ?int $sizeBytes, string $correlationId, string $operation): string {
		$sink = $this->newTempFile();
		try {
			$this->withToken($credentials, function (string $token) use ($credentials, $fileStorageId, $sizeBytes, $correlationId, $sink, $operation): IResponse {
				$response = $this->request($credentials, 'GET', self::EP_STORAGE_DOWNLOAD . rawurlencode($fileStorageId), [
					'sink' => $sink,
					'timeout' => $this->scaledTimeout($sizeBytes),
				], $token, $correlationId);
				$this->assertOk($response, $operation, $sink);
				return $response;
			});

			clearstatcache(true, $sink);
			if ((int)(filesize($sink) ?: 0) === 0) {
				throw new SecloreProtocolException(ucfirst($operation) . ' returned an empty body');
			}
			return $sink;
		} catch (\Throwable $e) {
			@unlink($sink);
			throw $e;
		}
	}

	/**
	 * Best-effort cleanup of a staged file on the server; the server also
	 * auto-expires them, so failures are only logged.
	 */
	private function deleteStored(Credentials $credentials, string $fileStorageId, string $correlationId): void {
		try {
			$this->withToken($credentials, function (string $token) use ($credentials, $fileStorageId, $correlationId): IResponse {
				return $this->request($credentials, 'DELETE', self::EP_STORAGE_DELETE . rawurlencode($fileStorageId), [
					'timeout' => self::AUTH_TIMEOUT_S,
				], $token, $correlationId);
			});
		} catch (\Throwable $e) {
			$this->logger->debug('Seclore file-storage cleanup failed (ignored): ' . $e->getMessage(), [
				'requestId' => $correlationId,
			]);
		}
	}

	/**
	 * POST a JSON body and return the decoded JSON response.
	 *
	 * @param array<string, string> $body
	 * @return array<string, mixed>
	 */
	private function postJson(Credentials $credentials, string $path, array $body, string $operation, string $correlationId, int $timeout): array {
		$response = $this->withToken($credentials, function (string $token) use ($credentials, $path, $body, $operation, $correlationId, $timeout): IResponse {
			$response = $this->request($credentials, 'POST', $path, [
				'json' => $body,
				'timeout' => $timeout,
			], $token, $correlationId);
			$this->assertOk($response, $operation);
			return $response;
		});

		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data)) {
			throw new SecloreProtocolException("Unexpected $operation response shape — confirm the API contract (SDD §7.3.1)");
		}
		return $data;
	}

	/**
	 * Run $operation with a valid token; on an auth rejection the token is
	 * refreshed once and the operation replayed (SDD §7.2). A second rejection
	 * propagates as a configuration error.
	 *
	 * @template T
	 * @param \Closure(string): T $operation
	 * @return T
	 */
	private function withToken(Credentials $credentials, \Closure $operation) {
		$token = $this->tokenStore->getToken($credentials, fn (): Token => $this->authenticate($credentials));
		try {
			return $operation($token);
		} catch (SecloreAuthException) {
			$this->logger->debug('Seclore token rejected — refreshing once and replaying');
			$this->tokenStore->invalidate($credentials);
			$token = $this->tokenStore->getToken($credentials, fn (): Token => $this->authenticate($credentials));
			return $operation($token);
		}
	}

	private function authenticate(Credentials $credentials): Token {
		$response = $this->request($credentials, 'POST', self::EP_LOGIN, [
			'json' => ['tenantId' => $credentials->appId, 'tenantSecret' => $credentials->appSecret],
			'timeout' => self::AUTH_TIMEOUT_S,
		], null, $this->newCorrelationId());

		$status = $response->getStatusCode();
		if ($status === 401 || $status === 403) {
			throw new SecloreAuthException();
		}
		if ($status !== 200) {
			throw $this->mapError($status, 'authenticate', $this->errorDetail($response, null));
		}

		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data) || !is_string($data['accessToken'] ?? null) || $data['accessToken'] === '') {
			throw new SecloreProtocolException('Unexpected login response shape — confirm the API contract (SDD §7.3.1)');
		}

		return new Token($data['accessToken'], self::ASSUMED_TOKEN_TTL_S);
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function request(Credentials $credentials, string $method, string $path, array $options, ?string $token, string $correlationId): IResponse {
		if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
			throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method);
		}

		$url = $credentials->baseUrl . $path;

		$headers = $options['headers'] ?? [];
		$headers[self::HEADER_CORRELATION] = $correlationId;
		$headers['Accept'] = 'application/json, application/octet-stream';
		if ($token !== null) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$options['headers'] = $headers;

		$options['verify'] = $credentials->verifyTls;
		$options['connect_timeout'] = self::CONNECT_TIMEOUT_S;
		$options['timeout'] = $options['timeout'] ?? self::DEFAULT_TIMEOUT_S;
		// Surface HTTP errors as status codes (mapped in mapError) and never
		// follow redirects away from the configured host (SDD §8.1).
		$options['http_errors'] = false;
		$options['allow_redirects'] = false;

		try {
			$client = $this->clientService->newClient();
			return match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'DELETE' => $client->delete($url, $options),
			};
		} catch (\Exception $e) {
			throw new SecloreUnavailableException('Seclore server unreachable: ' . $e->getMessage(), $e);
		}
	}

	/**
	 * @throws SecloreApiException when the response is not HTTP 200
	 */
	private function assertOk(IResponse $response, string $operation, ?string $sinkPath = null): void {
		$status = $response->getStatusCode();
		if ($status === 200) {
			return;
		}
		throw $this->mapError($status, $operation, $this->errorDetail($response, $sinkPath));
	}

	/** Error mapping per SDD §7.4. */
	private function mapError(int $status, string $operation, string $detail): SecloreApiException {
		$suffix = $detail !== '' ? (': ' . $detail) : '';
		return match (true) {
			$status === 401, $status === 403 => new SecloreAuthException("Seclore rejected the $operation request (HTTP $status)$suffix"),
			$status === 404 && $operation === 'protect' => new PolicyNotFoundException("Seclore does not know the requested Hot Folder (HTTP 404)$suffix"),
			$status === 404 => new SecloreProtocolException("Seclore endpoint or resource not found (HTTP 404, $operation)$suffix"),
			$status === 413 => new FileTooLargeException("The Seclore server rejected the file as too large (HTTP 413)$suffix"),
			$status >= 500 => new SecloreApiException("Seclore server error (HTTP $status, $operation)$suffix", true),
			default => new SecloreProtocolException("Unexpected Seclore response (HTTP $status, $operation)$suffix"),
		};
	}

	/**
	 * When a sink was used, the error body was streamed into the sink file;
	 * read the detail from there instead of the (already drained) response.
	 */
	private function errorDetail(IResponse $response, ?string $sinkPath): string {
		if ($sinkPath !== null) {
			$body = @file_get_contents($sinkPath, false, null, 0, self::ERROR_DETAIL_MAX);
			return is_string($body) ? trim($body) : '';
		}
		$body = $response->getBody();
		return is_string($body) ? trim(substr($body, 0, self::ERROR_DETAIL_MAX)) : '';
	}

	private function requireConfigured(): Credentials {
		if (!$this->config->isConfigured()) {
			throw new NotConfiguredException();
		}
		return $this->config->getCredentials();
	}

	/** @param resource $in */
	private function streamSize($in): ?int {
		$stat = @fstat($in);
		$size = is_array($stat) ? ($stat['size'] ?? null) : null;
		return (is_int($size) && $size >= 0) ? $size : null;
	}

	/** @param resource $in */
	private function rewindIfNeeded($in): void {
		$meta = stream_get_meta_data($in);
		if (($meta['seekable'] ?? false) && ftell($in) !== 0) {
			rewind($in);
		}
	}

	/** ~2 s per MiB with a 120 s floor, capped by the admin setting (SDD §7.4). */
	private function scaledTimeout(?int $sizeBytes): int {
		$max = $this->config->getRequestTimeoutMax();
		if ($sizeBytes === null) {
			return $max;
		}
		return min($max, max(self::DEFAULT_TIMEOUT_S, (int)ceil($sizeBytes / 1048576) * 2));
	}

	private function newTempFile(): string {
		$path = $this->tempManager->getTemporaryFile('seclore');
		if (!is_string($path) || $path === '') {
			throw new SecloreApiException('Could not create a temporary file for the Seclore transfer');
		}
		return $path;
	}

	private function newCorrelationId(): string {
		return bin2hex(random_bytes(16));
	}
}
