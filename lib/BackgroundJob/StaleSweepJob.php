<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\BackgroundJob;

use OCA\Sclrit\AppInfo\Application;
use OCA\Sclrit\Db\SecloreState;
use OCA\Sclrit\Db\SecloreStateMapper;
use OCA\Sclrit\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Files\Config\IUserMountCache;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly state hygiene:
 *
 * - Watchdog (SDD §9 E14): in-flight rows older than `stale_after` are swept
 *   so a crashed worker cannot wedge a file forever. Rows that carry a Seclore
 *   file id belonged to an interrupted *unprotect* — their file content is
 *   still the protected binary, so they are restored to `protected` instead
 *   of `failed`.
 * - Orphan sweep (SDD §6.1): rows whose file no longer exists anywhere in the
 *   file cache are removed. This backstops the NodeDeletedListener for
 *   deletions it cannot attribute (folder deletions, trash-less storages).
 */
class StaleSweepJob extends TimedJob {
	private const ORPHAN_BATCH = 500;
	private const ORPHAN_CURSOR_KEY = 'orphan_sweep_cursor';

	public function __construct(
		ITimeFactory $time,
		private readonly SecloreStateMapper $mapper,
		private readonly ConfigService $config,
		private readonly IUserMountCache $userMountCache,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->sweepStale();
		$this->pruneOrphans();
	}

	private function sweepStale(): void {
		$cutoff = $this->time->getTime() - $this->config->getStaleAfter();
		foreach ($this->mapper->findStale($cutoff) as $state) {
			if ($state->getSecloreFileId() !== null) {
				$state->setStatus(SecloreState::STATUS_PROTECTED);
				$state->setLastError('The unprotect request never completed (stale) — the file is still protected');
			} else {
				$state->setStatus(SecloreState::STATUS_FAILED);
				$state->setLastError('The request never completed (stale)');
			}
			$state->setUpdatedAt($this->time->getTime());
			$this->mapper->update($state);
			$this->logger->warning('Swept stale Seclore state row', [
				'fileId' => $state->getFileId(),
				'newStatus' => $state->getStatus(),
				'requestId' => $state->getRequestId(),
			]);
		}
	}

	/**
	 * Checks a bounded batch per run, resuming from a persistent cursor so
	 * large tables are covered across runs without one heavy pass.
	 */
	private function pruneOrphans(): void {
		$cursor = $this->appConfig->getValueInt(Application::APP_ID, self::ORPHAN_CURSOR_KEY, 0);
		$rows = $this->mapper->findChunk($cursor, self::ORPHAN_BATCH);

		$lastId = 0;
		foreach ($rows as $state) {
			$lastId = $state->getId();
			if ($this->userMountCache->getMountsForFileId($state->getFileId()) === []) {
				$this->mapper->delete($state);
				$this->logger->info('Removed orphaned Seclore state row (file no longer exists)', [
					'fileId' => $state->getFileId(),
					'status' => $state->getStatus(),
				]);
			}
		}

		// Full batch → continue after the last row next run; otherwise wrap.
		$nextCursor = count($rows) === self::ORPHAN_BATCH ? $lastId + 1 : 0;
		$this->appConfig->setValueInt(Application::APP_ID, self::ORPHAN_CURSOR_KEY, $nextCursor);
	}
}
