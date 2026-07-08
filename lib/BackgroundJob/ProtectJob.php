<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\BackgroundJob;

use OCA\FilesSeclore\Service\ProtectionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued protection of a large file (SDD §4.2). Arguments carry only ids —
 * never file content or secrets. All guard re-validation, retry accounting
 * and state transitions live in ProtectionService::runQueuedProtect().
 */
class ProtectJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly ProtectionService $protectionService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$fileId = (int)($argument['fileId'] ?? 0);
		$userId = (string)($argument['userId'] ?? '');
		if ($fileId <= 0 || $userId === '') {
			$this->logger->warning('ProtectJob started with invalid arguments', ['argument' => $argument]);
			return;
		}
		$this->protectionService->runQueuedProtect($userId, $fileId);
	}
}
