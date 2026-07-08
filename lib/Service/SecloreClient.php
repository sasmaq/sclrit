<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service;

use OCA\FilesSeclore\Exceptions\FileTooLargeException;
use OCA\FilesSeclore\Exceptions\NotConfiguredException;
use OCA\FilesSeclore\Exceptions\PolicyNotFoundException;
use OCA\FilesSeclore\Exceptions\SecloreApiException;
use OCA\FilesSeclore\Exceptions\SecloreAuthException;
use OCA\FilesSeclore\Exceptions\SecloreProtocolException;
use OCA\FilesSeclore\Exceptions\SecloreUnavailableException;
use OCA\FilesSeclore\Service\Dto\ConnectionResult;
use OCA\FilesSeclore\Service\Dto\Credentials;
use OCA\FilesSeclore\Service\Dto\HotFolder;
use OCA\FilesSeclore\Service\Dto\ProtectResult;
use OCA\FilesSeclore\Service\Dto\SecloreFileInfo;
use OCA\FilesSeclore\Service\Dto\Token;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * HTTP implementation of the Seclore Policy Server adapter (SDD §7).
 *
 * ⚠ The endpoint paths and payload shapes below follow the *indicative*
 * contract from SDD §7.3. Before production use they must be reconciled with
 * the API guide of the deployed Policy Server version (SDD §15, Q1). Every
 * such spot is marked with `TODO(Q1)` / `TODO(Q2)` / `TODO(Q6)`.
 */
final class SecloreClient implements ISecloreClient {
	// TODO(Q1): confirm endpoint paths against the deployed Policy Server API guide.
	private const EP_TOKEN = '/auth/token';
	private const EP_HOT_FOLDERS = '/hotfolders';
	private const EP_PROTECT = '/files/protect';
	private const EP_UNPROTECT = '/files/unprotect';
	private const EP_FILE_INFO = '/files/info';

	private const CONNECT_TIMEOUT_S = 10;
	private const DEFAULT_TIMEOUT_S = 120;
	private const AUTH_TIMEOUT_S = 30;
	private const INFO_PROBE_BYTES = 65536;
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
			return new ConnectionResult(false, null, 'Not configured: base URL, app ID and secret are required');
		}

		try {
			$token = $this->authenticate($credentials);
			$folders = $this->fetchHotFolders($credentials, $token->value);
			return new ConnectionResult(true, count($folders));
		} catch (SecloreApiException $e) {
			return new ConnectionResult(false, null, $e->getMessage());
		}
	}

	public function listHotFolders(): array {
		$credentials = $this->requireConfigured();
		return $this->withToken(
			$credentials,
			fn (string $token): array => $this->fetchHotFolders($credentials, $token),
		);
	}

	public function protect($in, string $fileName, string $hotFolderId, ?string $ownerEmail = null): ProtectResult {
		$credentials = $this->requireConfigured();
		$requestId = $this->newRequestId();
		$sizeBytes = $this->streamSize($in);
		$sink = $this->newTempFile();

		$operation = function (string $token) use ($credentials, $in, $fileName, $hotFolderId, $ownerEmail, $sink, $sizeBytes, $requestId): IResponse {
			// The stream may already be consumed when this closure is replayed
			// after a token refresh (SDD §7.2).
			$this->rewindIfNeeded($in);

			// TODO(Q1): confirm part names and whether the policy is passed as a
			// form field, query parameter or JSON side-channel.
			$multipart = [
				['name' => 'file', 'contents' => $in, 'filename' => $fileName],
				['name' => 'hotFolderId', 'contents' => $hotFolderId],
			];
			if ($ownerEmail !== null) {
				// TODO(Q2): on-behalf-of ownership attribution support.
				$multipart[] = ['name' => 'ownerEmail', 'contents' => $ownerEmail];
			}

			$response = $this->request($credentials, 'POST', self::EP_PROTECT, [
				'multipart' => $multipart,
				'sink' => $sink,
				'timeout' => $this->scaledTimeout($sizeBytes),
			], $token, $requestId);
			$this->assertOk($response, 'protect', $sink);
			return $response;
		};

		$started = microtime(true);
		try {
			$response = $this->withToken($credentials, $operation);

			// TODO(Q1): confirm how the Seclore file id is returned (response
			// header assumed; may be a JSON envelope instead).
			$secloreFileId = trim($response->getHeader('X-Seclore-File-Id'));
			if ($secloreFileId === '') {
				throw new SecloreProtocolException('Protect succeeded but no Seclore file id was returned — confirm the API contract (SDD §15, Q1)');
			}

			clearstatcache(true, $sink);
			$protectedBytes = (int)(filesize($sink) ?: 0);
			if ($protectedBytes === 0) {
				throw new SecloreProtocolException('Protect returned an empty body');
			}

			$this->logger->info('Seclore protect succeeded', [
				'requestId' => $requestId,
				'inBytes' => $sizeBytes,
				'outBytes' => $protectedBytes,
				'durationMs' => (int)((microtime(true) - $started) * 1000),
			]);

			return new ProtectResult($secloreFileId, $sink, $protectedBytes, $requestId);
		} catch (\Throwable $e) {
			@unlink($sink);
			throw $e;
		}
	}

	public function unprotect($in, string $fileName): string {
		$credentials = $this->requireConfigured();
		$requestId = $this->newRequestId();
		$sizeBytes = $this->streamSize($in);
		$sink = $this->newTempFile();

		$operation = function (string $token) use ($credentials, $in, $fileName, $sink, $sizeBytes, $requestId): IResponse {
			$this->rewindIfNeeded($in);
			$response = $this->request($credentials, 'POST', self::EP_UNPROTECT, [
				'multipart' => [['name' => 'file', 'contents' => $in, 'filename' => $fileName]],
				'sink' => $sink,
				'timeout' => $this->scaledTimeout($sizeBytes),
			], $token, $requestId);
			$this->assertOk($response, 'unprotect', $sink);
			return $response;
		};

		try {
			$this->withToken($credentials, $operation);

			clearstatcache(true, $sink);
			if ((int)(filesize($sink) ?: 0) === 0) {
				throw new SecloreProtocolException('Unprotect returned an empty body');
			}

			$this->logger->info('Seclore unprotect succeeded', [
				'requestId' => $requestId,
				'inBytes' => $sizeBytes,
			]);

			return $sink;
		} catch (\Throwable $e) {
			@unlink($sink);
			throw $e;
		}
	}

	public function getFileInfo($in): ?SecloreFileInfo {
		$credentials = $this->requireConfigured();

		// TODO(Q6): confirm whether an info/probe endpoint exists and how much
		// of the file it needs. A leading chunk is assumed sufficient here.
		$head = fread($in, self::INFO_PROBE_BYTES);
		if ($head === false || $head === '') {
			return null;
		}

		try {
			$response = $this->withToken($credentials, function (string $token) use ($credentials, $head): IResponse {
				return $this->request($credentials, 'POST', self::EP_FILE_INFO, [
					'multipart' => [['name' => 'file', 'contents' => $head, 'filename' => 'probe.bin']],
					'timeout' => self::AUTH_TIMEOUT_S,
				], $token, $this->newRequestId());
			});
		} catch (SecloreApiException $e) {
			// The probe is best-effort by contract: inconclusive, never fatal.
			$this->logger->debug('Seclore file-info probe failed: ' . $e->getMessage());
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data) || !array_key_exists('protected', $data)) {
			return null;
		}

		return new SecloreFileInfo(
			(bool)$data['protected'],
			isset($data['fileId']) ? (string)$data['fileId'] : null,
			isset($data['hotFolderId']) ? (string)$data['hotFolderId'] : null,
		);
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
		// TODO(Q1): confirm the authentication scheme. A bearer session token is
		// assumed; some Policy Server versions use HMAC request signing instead
		// (SDD §7.2) — that variant would also be absorbed inside this class.
		$response = $this->request($credentials, 'POST', self::EP_TOKEN, [
			'json' => ['appId' => $credentials->appId, 'secret' => $credentials->appSecret],
			'timeout' => self::AUTH_TIMEOUT_S,
		], null, $this->newRequestId());

		$status = $response->getStatusCode();
		if ($status === 401 || $status === 403) {
			throw new SecloreAuthException();
		}
		if ($status !== 200) {
			throw $this->mapError($status, 'authenticate', $this->errorDetail($response, null));
		}

		$data = json_decode((string)$response->getBody(), true);
		if (!is_array($data) || !is_string($data['token'] ?? null) || $data['token'] === '') {
			throw new SecloreProtocolException('Unexpected token response shape — confirm the API contract (SDD §15, Q1)');
		}

		return new Token($data['token'], max(60, (int)($data['expiresIn'] ?? 300)));
	}

	/** @return HotFolder[] */
	private function fetchHotFolders(Credentials $credentials, string $token): array {
		$response = $this->request($credentials, 'GET', self::EP_HOT_FOLDERS, [], $token, $this->newRequestId());
		$this->assertOk($response, 'list policies');

		$data = json_decode((string)$response->getBody(), true);
		// TODO(Q1): confirm the collection shape (bare array vs {hotFolders: [...]}).
		$items = is_array($data) ? ($data['hotFolders'] ?? $data) : null;
		if (!is_array($items)) {
			throw new SecloreProtocolException('Unexpected policy list response shape — confirm the API contract (SDD §15, Q1)');
		}

		$folders = [];
		foreach ($items as $item) {
			if (is_array($item) && isset($item['id'], $item['name'])) {
				$folders[] = new HotFolder(
					(string)$item['id'],
					(string)$item['name'],
					(string)($item['description'] ?? ''),
				);
			}
		}
		return $folders;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	private function request(Credentials $credentials, string $method, string $path, array $options, ?string $token, string $requestId): IResponse {
		if ($method !== 'GET' && $method !== 'POST') {
			throw new \InvalidArgumentException('Unsupported HTTP method: ' . $method);
		}

		$url = $credentials->baseUrl . $path;

		$headers = $options['headers'] ?? [];
		$headers['X-Request-Id'] = $requestId;
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
			return $method === 'GET'
				? $client->get($url, $options)
				: $client->post($url, $options);
		} catch (\Exception $e) {
			throw new SecloreUnavailableException('Seclore Policy Server unreachable: ' . $e->getMessage(), $e);
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
			$status === 404 => new PolicyNotFoundException("Seclore endpoint or policy not found (HTTP 404, $operation)$suffix"),
			$status === 413 => new FileTooLargeException("The Policy Server rejected the file as too large (HTTP 413)$suffix"),
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

	private function newRequestId(): string {
		return bin2hex(random_bytes(16));
	}
}
