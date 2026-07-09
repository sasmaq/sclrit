<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Controller;

use OCA\Sclrit\Exceptions\FileTooLargeException;
use OCA\Sclrit\Exceptions\NotConfiguredException;
use OCA\Sclrit\Exceptions\PolicyNotFoundException;
use OCA\Sclrit\Exceptions\ProtectionException;
use OCA\Sclrit\Exceptions\SecloreApiException;
use OCA\Sclrit\Exceptions\SecloreUnavailableException;
use OCA\Sclrit\Service\ConfigService;
use OCA\Sclrit\Service\Dto\HotFolder;
use OCA\Sclrit\Service\Dto\ProtectionState;
use OCA\Sclrit\Service\PolicyService;
use OCA\Sclrit\Service\ProtectionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * User-facing OCS API (SDD §4.3): protect, unprotect, retry, status, policies.
 * Error responses carry `{code, message}` per SDD Appendix B.
 */
class ApiController extends OCSController {
	private const MAX_STATUS_IDS = 200;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ProtectionService $protectionService,
		private readonly PolicyService $policyService,
		private readonly ConfigService $config,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/protect')]
	public function protect(int $fileId, ?string $hotFolderId = null): DataResponse {
		try {
			$state = $this->protectionService->requestProtect($this->userId(), $fileId, $hotFolderId);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
		return $this->stateResponse($state);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/unprotect')]
	public function unprotect(int $fileId): DataResponse {
		try {
			$state = $this->protectionService->requestUnprotect($this->userId(), $fileId);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
		return $this->stateResponse($state);
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 600)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/retry')]
	public function retry(int $fileId): DataResponse {
		try {
			$state = $this->protectionService->requestRetry($this->userId(), $fileId);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
		return $this->stateResponse($state);
	}

	/**
	 * Batched status lookup for the files list and the sidebar (SDD §4.3).
	 *
	 * @param int[] $fileIds
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/status')]
	public function status(array $fileIds = []): DataResponse {
		$fileIds = array_values(array_filter(array_map(intval(...), $fileIds), static fn (int $id): bool => $id > 0));
		if (count($fileIds) > self::MAX_STATUS_IDS) {
			return $this->error(Http::STATUS_BAD_REQUEST, 'too_many_ids', 'At most ' . self::MAX_STATUS_IDS . ' file ids per request');
		}
		return new DataResponse(['states' => $this->protectionService->getStates($this->userId(), $fileIds)]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/policies')]
	public function policies(): DataResponse {
		// Served from local configuration (SDD §15 Q1a) — no upstream call.
		return new DataResponse([
			'policies' => array_map(
				static fn (HotFolder $f): array => ['id' => $f->id, 'name' => $f->name, 'description' => $f->description],
				$this->policyService->getPolicies(),
			),
			'defaultId' => $this->config->getDefaultHotFolder(),
		]);
	}

	private function userId(): string {
		// NoAdminRequired guarantees an authenticated user on these routes.
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function stateResponse(ProtectionState $state): DataResponse {
		$queued = $state->status === 'pending';
		return new DataResponse(['state' => $state], $queued ? Http::STATUS_ACCEPTED : Http::STATUS_OK);
	}

	/** Exception → OCS error mapping (SDD §7.4, Appendix B). */
	private function errorResponse(\Throwable $e): DataResponse {
		if ($e instanceof NotFoundException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'file_not_found', 'File not found');
		}
		if ($e instanceof ProtectionException) {
			return $this->error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage());
		}
		if ($e instanceof PolicyNotFoundException) {
			return $this->error(Http::STATUS_UNPROCESSABLE_ENTITY, 'policy_unknown', $e->getMessage());
		}
		if ($e instanceof FileTooLargeException) {
			return $this->error(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, 'too_large', $e->getMessage());
		}
		if ($e instanceof NotConfiguredException) {
			return $this->error(Http::STATUS_BAD_GATEWAY, 'not_configured', $e->getMessage());
		}
		if ($e instanceof SecloreUnavailableException) {
			return $this->error(Http::STATUS_BAD_GATEWAY, 'seclore_unavailable', $e->getMessage(), true);
		}
		if ($e instanceof SecloreApiException) {
			return $this->error(Http::STATUS_BAD_GATEWAY, 'seclore_error', $e->getMessage(), $e->isRetryable());
		}
		$this->logger->error('Unexpected error in the Seclore OCS API', ['exception' => $e]);
		return $this->error(Http::STATUS_INTERNAL_SERVER_ERROR, 'internal_error', 'Internal error');
	}

	private function error(int $status, string $code, string $message, ?bool $retryable = null): DataResponse {
		$data = ['code' => $code, 'message' => $message];
		if ($retryable !== null) {
			$data['retryable'] = $retryable;
		}
		return new DataResponse($data, $status);
	}
}
