<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service;

use OCA\FilesSeclore\Activity\ActivityPublisher;
use OCA\FilesSeclore\AppInfo\Application;
use OCA\FilesSeclore\BackgroundJob\ProtectJob;
use OCA\FilesSeclore\BackgroundJob\UnprotectJob;
use OCA\FilesSeclore\Db\SecloreState;
use OCA\FilesSeclore\Db\SecloreStateMapper;
use OCA\FilesSeclore\Exceptions\AlreadyProtectedException;
use OCA\FilesSeclore\Exceptions\ConflictException;
use OCA\FilesSeclore\Exceptions\InProgressException;
use OCA\FilesSeclore\Exceptions\NotAllowedException;
use OCA\FilesSeclore\Exceptions\NotConfiguredException;
use OCA\FilesSeclore\Exceptions\NotProtectedException;
use OCA\FilesSeclore\Exceptions\PolicyNotFoundException;
use OCA\FilesSeclore\Exceptions\ProtectionException;
use OCA\FilesSeclore\Exceptions\SecloreApiException;
use OCA\FilesSeclore\Exceptions\UnsupportedFileException;
use OCA\FilesSeclore\Notification\Notifier;
use OCA\FilesSeclore\Service\Dto\HotFolder;
use OCA\FilesSeclore\Service\Dto\ProtectionState;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DBException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end protect/unprotect orchestration and state machine (SDD §4.1).
 *
 * The oc_seclore_state row is the authoritative state (decision D5); the
 * files-metadata flag written here is only a display projection (§4.5).
 * Concurrent edits are detected with an ETag compare-and-swap around the
 * Seclore round-trip (decision D6): any failure before the write-back leaves
 * the original file byte-identical.
 */
final class ProtectionService {
	/** Files-metadata key projecting "is protected" into WebDAV PROPFIND (SDD §4.5). */
	public const METADATA_KEY = 'files_seclore-protected';

	private const MAX_ATTEMPTS = 3;
	private const RETRY_BACKOFF_S = 300;
	private const ERROR_MAX_LEN = 1024;

	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly SecloreStateMapper $mapper,
		private readonly ISecloreClient $client,
		private readonly PolicyService $policyService,
		private readonly ConfigService $config,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly IJobList $jobList,
		private readonly IFilesMetadataManager $metadataManager,
		private readonly ITimeFactory $timeFactory,
		private readonly ActivityPublisher $activity,
		private readonly INotificationManager $notificationManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Protect a file on demand. Runs synchronously when the file size is at
	 * most sync_max_size, otherwise enqueues a ProtectJob (SDD §4.2).
	 *
	 * @param bool $forceSync run inline regardless of size — for occ batch use
	 *                        (SDD §4.4), where there is no request timeout
	 * @throws NotFoundException|ProtectionException|SecloreApiException
	 */
	public function requestProtect(string $userId, int $fileId, ?string $hotFolderId = null, bool $forceSync = false): ProtectionState {
		$file = $this->resolveFile($userId, $fileId);
		$this->authorizeProtect($userId, $file);
		$policy = $this->resolvePolicy($hotFolderId);

		$state = $this->claimForProtect($userId, $fileId, $policy);

		if ($forceSync || $file->getSize() <= $this->config->getSyncMaxSize()) {
			return $this->executeProtect($state, $userId);
		}

		$this->jobList->add(ProtectJob::class, ['fileId' => $fileId, 'userId' => $userId]);
		$this->logger->info('Seclore protect queued', ['fileId' => $fileId, 'userId' => $userId, 'bytes' => $file->getSize()]);
		return ProtectionState::fromEntity($state);
	}

	/**
	 * Remove protection. Restricted to members of unprotect_groups (SDD §8.2).
	 * Same sync/async split as protect.
	 *
	 * @throws NotFoundException|ProtectionException|SecloreApiException
	 */
	public function requestUnprotect(string $userId, int $fileId): ProtectionState {
		$file = $this->resolveFile($userId, $fileId);
		$this->authorizeUnprotect($userId, $file);

		try {
			$state = $this->mapper->findByFileId($fileId);
		} catch (DoesNotExistException) {
			throw new NotProtectedException();
		}
		if ($state->getStatus() === SecloreState::STATUS_PENDING || $state->getStatus() === SecloreState::STATUS_PROCESSING) {
			throw new InProgressException();
		}
		if ($state->getStatus() !== SecloreState::STATUS_PROTECTED) {
			throw new NotProtectedException();
		}

		if ($file->getSize() <= $this->config->getSyncMaxSize()) {
			return $this->executeUnprotect($state, $userId);
		}

		$state->setStatus(SecloreState::STATUS_PENDING);
		$state->setRequestedBy($userId);
		$state->setLastError(null);
		$this->touch($state);
		$state = $this->mapper->update($state);
		$this->jobList->add(UnprotectJob::class, ['fileId' => $fileId, 'userId' => $userId]);
		$this->logger->info('Seclore unprotect queued', ['fileId' => $fileId, 'userId' => $userId]);
		return ProtectionState::fromEntity($state);
	}

	/**
	 * Re-run a failed protect request with its original policy (SDD §4.3 /retry).
	 *
	 * @throws NotFoundException|ProtectionException|SecloreApiException
	 */
	public function requestRetry(string $userId, int $fileId): ProtectionState {
		try {
			$state = $this->mapper->findByFileId($fileId);
		} catch (DoesNotExistException) {
			throw new NotProtectedException('There is no failed request to retry for this file', 'not_failed');
		}
		if ($state->getStatus() !== SecloreState::STATUS_FAILED) {
			throw new NotProtectedException('There is no failed request to retry for this file', 'not_failed');
		}
		// A failed row always means "file is unprotected" (unprotect failures
		// restore the protected status), so retrying is always a protect.
		return $this->requestProtect($userId, $fileId, $state->getHotFolderId());
	}

	/**
	 * Protection states for a batch of files, limited to files the user can
	 * reach; inaccessible or unknown ids are omitted from the result.
	 *
	 * @param int[] $fileIds
	 * @return array<int, ProtectionState>
	 */
	public function getStates(string $userId, array $fileIds): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);

		$states = [];
		foreach (array_unique($fileIds) as $fileId) {
			if ($userFolder->getFirstNodeById($fileId) !== null) {
				$states[$fileId] = ProtectionState::none($fileId);
			}
		}
		foreach ($this->mapper->findByFileIds(array_keys($states)) as $row) {
			$states[$row->getFileId()] = ProtectionState::fromEntity($row);
		}
		return $states;
	}

	/** Group gate for protecting: empty allowed_groups means everyone (SDD §8.2). */
	public function userCanProtect(string $userId): bool {
		$groups = $this->config->getAllowedGroups();
		return $groups === [] || $this->isInAnyGroup($userId, $groups);
	}

	/** Group gate for unprotecting: empty unprotect_groups means nobody (SDD §8.2). */
	public function userCanUnprotect(string $userId): bool {
		$groups = $this->config->getUnprotectGroups();
		return $groups !== [] && $this->isInAnyGroup($userId, $groups);
	}

	/**
	 * ProtectJob entry point: re-validates every guard from §4.1 (the file may
	 * have changed or been deleted since enqueueing) and applies the bounded
	 * retry policy for transient errors (SDD §4.2). Never throws.
	 */
	public function runQueuedProtect(string $userId, int $fileId): void {
		try {
			$state = $this->mapper->findByFileId($fileId);
		} catch (DoesNotExistException) {
			$this->logger->debug('ProtectJob: state row gone, nothing to do', ['fileId' => $fileId]);
			return;
		}
		if ($state->getStatus() !== SecloreState::STATUS_PENDING) {
			$this->logger->debug('ProtectJob: row no longer pending, nothing to do', ['fileId' => $fileId, 'status' => $state->getStatus()]);
			return;
		}

		try {
			$file = $this->resolveFile($userId, $fileId);
			$this->authorizeProtect($userId, $file);
		} catch (NotFoundException) {
			// E2: the file was deleted while the job was queued.
			$this->mapper->delete($state);
			$this->logger->info('ProtectJob: file deleted while queued, dropping state row', ['fileId' => $fileId]);
			return;
		} catch (\Throwable $e) {
			$this->markFailed($state, $e, $file ?? null);
			$this->pushNotification($userId, Notifier::SUBJECT_PROTECT_FAILED, $state);
			return;
		}

		try {
			$this->executeProtect($state, $userId);
			$this->pushNotification($userId, Notifier::SUBJECT_PROTECT_DONE, $state, $file->getName());
		} catch (SecloreApiException $e) {
			if ($e->isRetryable() && $state->getAttempts() < self::MAX_ATTEMPTS) {
				// executeProtect marked the row failed; flip back to pending and
				// re-schedule with a growing delay (SDD §4.2, §9 E6).
				$state->setStatus(SecloreState::STATUS_PENDING);
				$this->touch($state);
				$this->mapper->update($state);
				$runAfter = $this->timeFactory->getTime() + self::RETRY_BACKOFF_S * $state->getAttempts();
				$this->jobList->scheduleAfter(ProtectJob::class, $runAfter, ['fileId' => $fileId, 'userId' => $userId]);
				$this->logger->info('ProtectJob: transient failure, retry scheduled', [
					'fileId' => $fileId,
					'attempt' => $state->getAttempts(),
					'runAfter' => $runAfter,
				]);
				return;
			}
			$this->pushNotification($userId, Notifier::SUBJECT_PROTECT_FAILED, $state, $file->getName());
		} catch (\Throwable) {
			// Already marked failed and logged by executeProtect.
			$this->pushNotification($userId, Notifier::SUBJECT_PROTECT_FAILED, $state, $file->getName());
		}
	}

	/** UnprotectJob entry point; mirrors runQueuedProtect. Never throws. */
	public function runQueuedUnprotect(string $userId, int $fileId): void {
		try {
			$state = $this->mapper->findByFileId($fileId);
		} catch (DoesNotExistException) {
			return;
		}
		if ($state->getStatus() !== SecloreState::STATUS_PENDING) {
			return;
		}

		try {
			$file = $this->resolveFile($userId, $fileId);
			$this->authorizeUnprotect($userId, $file);
		} catch (NotFoundException) {
			$this->mapper->delete($state);
			$this->logger->info('UnprotectJob: file deleted while queued, dropping state row', ['fileId' => $fileId]);
			return;
		} catch (\Throwable $e) {
			$this->restoreProtected($state, $e);
			$this->pushNotification($userId, Notifier::SUBJECT_UNPROTECT_FAILED, $state);
			return;
		}

		try {
			$this->executeUnprotect($state, $userId);
			$this->pushNotification($userId, Notifier::SUBJECT_UNPROTECT_DONE, $state, $file->getName());
		} catch (SecloreApiException $e) {
			if ($e->isRetryable() && $state->getAttempts() < self::MAX_ATTEMPTS) {
				$state->setStatus(SecloreState::STATUS_PENDING);
				$this->touch($state);
				$this->mapper->update($state);
				$runAfter = $this->timeFactory->getTime() + self::RETRY_BACKOFF_S * $state->getAttempts();
				$this->jobList->scheduleAfter(UnprotectJob::class, $runAfter, ['fileId' => $fileId, 'userId' => $userId]);
				return;
			}
			$this->pushNotification($userId, Notifier::SUBJECT_UNPROTECT_FAILED, $state, $file->getName());
		} catch (\Throwable) {
			// Already restored to protected and logged by executeUnprotect.
			$this->pushNotification($userId, Notifier::SUBJECT_UNPROTECT_FAILED, $state, $file->getName());
		}
	}

	/**
	 * Synchronous protect algorithm, steps 4–9 of SDD §4.1. The row must be
	 * claimed (status pending). On failure the row is marked failed and the
	 * exception is rethrown; the original file content is never touched unless
	 * the ETag compare succeeded immediately before the write-back.
	 *
	 * @throws ProtectionException|SecloreApiException|NotFoundException
	 */
	private function executeProtect(SecloreState $state, string $userId): ProtectionState {
		$fileId = $state->getFileId();
		$started = microtime(true);

		try {
			$file = $this->resolveFile($userId, $fileId);
			$etagBefore = $file->getEtag();

			$state->setStatus(SecloreState::STATUS_PROCESSING);
			$state->setEtagBefore($etagBefore);
			$state->setAttempts($state->getAttempts() + 1);
			$this->touch($state);
			$state = $this->mapper->update($state);

			$in = $file->fopen('rb');
			if (!is_resource($in)) {
				throw new \RuntimeException('Could not open the file for reading');
			}
			try {
				$ownerEmail = $this->userManager->get($userId)?->getEMailAddress();
				$result = $this->client->protect($in, $file->getName(), (string)$state->getHotFolderId(), $ownerEmail ?: null);
			} finally {
				if (is_resource($in)) {
					@fclose($in);
				}
			}
			$state->setRequestId($result->requestId);

			try {
				// ETag compare-and-swap (decision D6): re-resolve so the check
				// reads the current file cache row, not the pre-round-trip copy.
				$fresh = $this->resolveFile($userId, $fileId);
				if ($fresh->getEtag() !== $etagBefore) {
					throw new ConflictException();
				}

				$out = fopen($result->tempPath, 'rb');
				if (!is_resource($out)) {
					throw new \RuntimeException('Could not reopen the protected temporary file');
				}
				try {
					// putContent locks the file, bumps the ETag/version and
					// propagates the change (SDD §4.1 step 7).
					$fresh->putContent($out);
				} finally {
					if (is_resource($out)) {
						@fclose($out);
					}
				}
			} finally {
				@unlink($result->tempPath);
			}

			$state->setStatus(SecloreState::STATUS_PROTECTED);
			$state->setSecloreFileId($result->secloreFileId);
			$state->setLastError(null);
			$this->touch($state);
			$state = $this->mapper->update($state);

			$this->setProtectedFlag($fileId, true);
			$this->purgeVersions($userId, $fresh);
			$this->activity->fileProtected($userId, $fresh, $state->getPolicyName());

			$this->logger->info('Seclore protect succeeded', [
				'fileId' => $fileId,
				'userId' => $userId,
				'policyId' => $state->getHotFolderId(),
				'requestId' => $state->getRequestId(),
				'durationMs' => (int)((microtime(true) - $started) * 1000),
			]);
			return ProtectionState::fromEntity($state);
		} catch (\Throwable $e) {
			$this->markFailed($state, $e, $file ?? null);
			throw $e;
		}
	}

	/**
	 * Mirror of executeProtect for unprotect (SDD §4.1). On success the state
	 * row and metadata flag are cleared; on failure the row is restored to
	 * `protected` because the file content is still the protected binary.
	 *
	 * @throws ProtectionException|SecloreApiException|NotFoundException
	 */
	private function executeUnprotect(SecloreState $state, string $userId): ProtectionState {
		$fileId = $state->getFileId();

		try {
			$file = $this->resolveFile($userId, $fileId);
			$etagBefore = $file->getEtag();

			$state->setStatus(SecloreState::STATUS_PROCESSING);
			$state->setEtagBefore($etagBefore);
			$state->setAttempts($state->getAttempts() + 1);
			$this->touch($state);
			$state = $this->mapper->update($state);

			$in = $file->fopen('rb');
			if (!is_resource($in)) {
				throw new \RuntimeException('Could not open the file for reading');
			}
			try {
				$tempPath = $this->client->unprotect($in, $file->getName());
			} finally {
				if (is_resource($in)) {
					@fclose($in);
				}
			}

			try {
				$fresh = $this->resolveFile($userId, $fileId);
				if ($fresh->getEtag() !== $etagBefore) {
					throw new ConflictException('The file was modified while it was being unprotected — please try again');
				}

				$out = fopen($tempPath, 'rb');
				if (!is_resource($out)) {
					throw new \RuntimeException('Could not reopen the unprotected temporary file');
				}
				try {
					$fresh->putContent($out);
				} finally {
					if (is_resource($out)) {
						@fclose($out);
					}
				}
			} finally {
				@unlink($tempPath);
			}

			$this->mapper->delete($state);
			$this->setProtectedFlag($fileId, false);
			// Unprotect is always audited (SDD §4.1): Activity event + structured log.
			$this->activity->fileUnprotected($userId, $fresh, $state->getPolicyName());
			$this->logger->info('Seclore unprotect succeeded', [
				'fileId' => $fileId,
				'userId' => $userId,
				'previousPolicyId' => $state->getHotFolderId(),
				'previousPolicyName' => $state->getPolicyName(),
			]);
			return ProtectionState::none($fileId);
		} catch (\Throwable $e) {
			$this->restoreProtected($state, $e);
			throw $e;
		}
	}

	/**
	 * Guard + claim the state row for a protect request (SDD §4.1 steps 3–4).
	 * The unique file_id index arbitrates concurrent claims (SDD §9 E13).
	 *
	 * @throws AlreadyProtectedException|InProgressException|DBException
	 */
	private function claimForProtect(string $userId, int $fileId, HotFolder $policy): SecloreState {
		$now = $this->timeFactory->getTime();

		try {
			$state = $this->mapper->findByFileId($fileId);
		} catch (DoesNotExistException) {
			$state = new SecloreState();
			$state->setFileId($fileId);
			$state->setStatus(SecloreState::STATUS_PENDING);
			$state->setHotFolderId($policy->id);
			$state->setPolicyName($policy->name);
			$state->setRequestedBy($userId);
			$state->setAttempts(0);
			$state->setCreatedAt($now);
			$state->setUpdatedAt($now);
			try {
				return $this->mapper->insert($state);
			} catch (DBException $e) {
				if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw new InProgressException();
				}
				throw $e;
			}
		}

		switch ($state->getStatus()) {
			case SecloreState::STATUS_PENDING:
			case SecloreState::STATUS_PROCESSING:
				throw new InProgressException();
			case SecloreState::STATUS_PROTECTED:
				throw new AlreadyProtectedException();
		}

		// failed → pending: user retry (SDD §4.1 state machine).
		$state->setStatus(SecloreState::STATUS_PENDING);
		$state->setHotFolderId($policy->id);
		$state->setPolicyName($policy->name);
		$state->setRequestedBy($userId);
		$state->setAttempts(0);
		$state->setLastError(null);
		$state->setEtagBefore(null);
		$state->setUpdatedAt($now);
		return $this->mapper->update($state);
	}

	/** @throws NotFoundException|UnsupportedFileException */
	private function resolveFile(string $userId, int $fileId): File {
		$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		if ($node === null) {
			throw new NotFoundException('File not found');
		}
		if (!$node instanceof File) {
			throw new UnsupportedFileException('Only files can be protected', 'not_a_file');
		}
		return $node;
	}

	/** SDD §4.1 step 2 / §8.2. @throws NotAllowedException|UnsupportedFileException */
	private function authorizeProtect(string $userId, File $file): void {
		if (!$this->config->isConfigured()) {
			throw new NotConfiguredException();
		}
		if (!$this->userCanProtect($userId)) {
			throw new NotAllowedException('You are not allowed to protect files with Seclore');
		}
		if (!$file->isUpdateable()) {
			throw new NotAllowedException('You need edit permission to protect this file', 'no_permission');
		}
		if ($this->isInEndToEndEncryptedFolder($file)) {
			throw new UnsupportedFileException('Files in end-to-end encrypted folders cannot be protected', 'e2ee_unsupported');
		}
		if ($this->isOnFederatedShare($file)) {
			throw new UnsupportedFileException('Files on federated shares cannot be protected', 'federated_unsupported');
		}
		if ((int)$file->getSize() === 0) {
			throw new UnsupportedFileException('Empty files cannot be protected', 'empty_file');
		}
	}

	/** @throws NotAllowedException|UnsupportedFileException */
	private function authorizeUnprotect(string $userId, File $file): void {
		if (!$this->config->isConfigured()) {
			throw new NotConfiguredException();
		}
		if (!$this->userCanUnprotect($userId)) {
			throw new NotAllowedException('You are not allowed to remove Seclore protection');
		}
		if (!$file->isUpdateable()) {
			throw new NotAllowedException('You need edit permission to unprotect this file', 'no_permission');
		}
		if ($this->isOnFederatedShare($file)) {
			throw new UnsupportedFileException('Files on federated shares are not supported', 'federated_unsupported');
		}
	}

	/** @throws PolicyNotFoundException */
	private function resolvePolicy(?string $hotFolderId): HotFolder {
		$hotFolderId = $hotFolderId !== null && $hotFolderId !== '' ? $hotFolderId : $this->config->getDefaultHotFolder();
		if ($hotFolderId === '') {
			throw new PolicyNotFoundException('No policy given and no default policy is configured');
		}
		// The list is admin-maintained (SDD §15 Q1a); a policy deleted on the
		// Seclore side surfaces later as a 404 from the protect call (E5).
		$policy = $this->policyService->find($hotFolderId);
		if ($policy === null) {
			throw new PolicyNotFoundException('The policy is not in the configured policy list');
		}
		return $policy;
	}

	/**
	 * E2EE detection (SDD §8.5, A7): the E2EE app marks the top folder of an
	 * encrypted tree as encrypted in the file cache. Server-side encryption
	 * never flags directories, so walking the ancestry avoids false positives
	 * on SSE files (which are supported).
	 */
	private function isInEndToEndEncryptedFolder(File $file): bool {
		try {
			$node = $file->getParent();
			while ($node instanceof Folder) {
				if ($node->isEncrypted()) {
					return true;
				}
				$path = $node->getPath();
				// Stop at the user's files root (/<uid>/files).
				if ($path === '/' || substr_count($path, '/') <= 2) {
					break;
				}
				$node = $node->getParent();
			}
		} catch (NotFoundException) {
			// Unresolvable ancestry: treat as not encrypted; protect will fail
			// later with a clearer error if the node is actually unreadable.
		}
		return false;
	}

	/** Federated shares are out of scope in v1 (SDD §1.2, §8.5). */
	private function isOnFederatedShare(File $file): bool {
		try {
			return $file->getStorage()->instanceOfStorage('OCA\\Files_Sharing\\External\\Storage');
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Purge pre-protection file versions after a successful protect (decision
	 * D7): they contain the plaintext the DRM was meant to lock down. Failure
	 * is logged loudly but does not fail the protect — the file itself is
	 * protected; only the version history still leaks.
	 */
	private function purgeVersions(string $userId, File $file): void {
		if (!$this->config->getPurgeVersions()) {
			return;
		}
		// files_versions is an optional app; resolve its manager lazily.
		$managerClass = 'OCA\\Files_Versions\\Versions\\IVersionManager';
		if (!interface_exists($managerClass)) {
			$this->logger->debug('purge_versions is enabled but files_versions is not available');
			return;
		}
		try {
			$versionManager = $this->container->get($managerClass);
			$user = $this->userManager->get($userId);
			if ($user === null) {
				return;
			}
			$purged = 0;
			foreach ($versionManager->getVersionsForFile($user, $file) as $version) {
				$versionManager->deleteVersion($version);
				$purged++;
			}
			if ($purged > 0) {
				$this->logger->info('Purged pre-protection file versions', ['fileId' => $file->getId(), 'count' => $purged]);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Could not purge pre-protection versions — unprotected content may remain in the version history (SDD §8.3)',
				['fileId' => $file->getId(), 'exception' => $e],
			);
		}
	}

	/**
	 * Display projection only (SDD §4.5): best-effort, the DB row stays
	 * authoritative and the frontend falls back to /status.
	 */
	private function setProtectedFlag(int $fileId, bool $protected): void {
		try {
			$metadata = $this->metadataManager->getMetadata($fileId, true);
			$metadata->setBool(self::METADATA_KEY, $protected, true);
			$this->metadataManager->saveMetadata($metadata);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not update the files-metadata protection flag', [
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	private function markFailed(SecloreState $state, \Throwable $e, ?File $file = null): void {
		// Only messages from our own exception hierarchy are user-safe (SDD §6.1 last_error).
		$message = ($e instanceof ProtectionException || $e instanceof SecloreApiException)
			? $e->getMessage()
			: 'Unexpected internal error — see the server log';
		$state->setStatus(SecloreState::STATUS_FAILED);
		$state->setLastError(mb_substr($message, 0, self::ERROR_MAX_LEN));
		$this->touch($state);
		try {
			$this->mapper->update($state);
		} catch (\Throwable $updateError) {
			$this->logger->error('Could not persist failed protection state', ['fileId' => $state->getFileId(), 'exception' => $updateError]);
		}
		$this->activity->protectFailed(
			$state->getRequestedBy(),
			$state->getFileId(),
			$file?->getName(),
			$state->getPolicyName(),
			(string)$state->getLastError(),
		);
		$this->logger->error('Seclore operation failed', [
			'fileId' => $state->getFileId(),
			'requestId' => $state->getRequestId(),
			'errorClass' => $e::class,
			'exception' => $e,
		]);
	}

	/**
	 * A failed unprotect leaves the file content protected, so `protected` (with
	 * last_error set) is the truthful status — `failed` is reserved for protect
	 * failures, where the file is unprotected.
	 */
	private function restoreProtected(SecloreState $state, \Throwable $e): void {
		$message = ($e instanceof ProtectionException || $e instanceof SecloreApiException)
			? $e->getMessage()
			: 'Unexpected internal error — see the server log';
		$state->setStatus(SecloreState::STATUS_PROTECTED);
		$state->setLastError(mb_substr($message, 0, self::ERROR_MAX_LEN));
		$this->touch($state);
		try {
			$this->mapper->update($state);
		} catch (\Throwable $updateError) {
			$this->logger->error('Could not restore protected state after failed unprotect', ['fileId' => $state->getFileId(), 'exception' => $updateError]);
		}
		$this->logger->error('Seclore unprotect failed — the file remains protected', [
			'fileId' => $state->getFileId(),
			'errorClass' => $e::class,
			'exception' => $e,
		]);
	}

	/**
	 * Push a completion/failure notification for a queued operation (SDD §4.6).
	 * Best-effort: a broken notification stack never fails the operation.
	 */
	private function pushNotification(string $userId, string $subject, SecloreState $state, ?string $fileName = null): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->timeFactory->getDateTime())
				->setObject('seclore', (string)$state->getFileId())
				->setSubject($subject, [
					'fileId' => $state->getFileId(),
					'fileName' => $fileName ?? '',
					'policy' => (string)$state->getPolicyName(),
					'error' => (string)$state->getLastError(),
				]);
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not push a Seclore notification', [
				'fileId' => $state->getFileId(),
				'subject' => $subject,
				'exception' => $e,
			]);
		}
	}

	private function touch(SecloreState $state): void {
		$state->setUpdatedAt($this->timeFactory->getTime());
	}

	/** @param string[] $groupIds */
	private function isInAnyGroup(string $userId, array $groupIds): bool {
		foreach ($groupIds as $groupId) {
			if ($this->groupManager->isInGroup($userId, $groupId)) {
				return true;
			}
		}
		return false;
	}
}
