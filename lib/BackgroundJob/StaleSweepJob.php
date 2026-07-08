<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\BackgroundJob;

use OCA\FilesSeclore\Db\SecloreState;
use OCA\FilesSeclore\Db\SecloreStateMapper;
use OCA\FilesSeclore\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Watchdog (SDD §9 E14): in-flight rows older than `stale_after` are swept so
 * a crashed worker cannot wedge a file forever. Rows that carry a Seclore file
 * id belonged to an interrupted *unprotect* — their file content is still the
 * protected binary, so they are restored to `protected` instead of `failed`.
 */
class StaleSweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly SecloreStateMapper $mapper,
		private readonly ConfigService $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
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
}
