<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Listener;

use OCA\FilesSeclore\Db\SecloreStateMapper;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * State-row lifecycle on file deletion (SDD §6.1): the row is removed only
 * when the deletion is final. A deletion into the trashbin keeps the row —
 * the file id survives trash and restore, so restore-from-trash retains the
 * protection status (SDD §9 E11); the final purge fires a second
 * NodeDeletedEvent from within the trashbin mount, which is when we delete.
 *
 * Deletions this listener cannot attribute (folders fire a single event
 * without their children; storages that bypass the trashbin) are covered by
 * the orphan sweep in StaleSweepJob.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener {
	public function __construct(
		private readonly SecloreStateMapper $mapper,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof NodeDeletedEvent) {
			return;
		}
		$node = $event->getNode();
		if (!$node instanceof File) {
			return;
		}

		try {
			$fileId = $node->getId();
			// Node paths look like /<uid>/<section>/<relative path>.
			$section = explode('/', ltrim($node->getPath(), '/'), 3)[1] ?? '';
		} catch (\Throwable) {
			return;
		}

		if ($section === 'files_trashbin') {
			// Final purge from the trashbin.
			$this->deleteRow($fileId, 'trashbin purge');
			return;
		}
		if ($section !== 'files') {
			return;
		}
		if (!$this->appManager->isEnabledForUser('files_trashbin')) {
			// No trashbin: the deletion is immediately final.
			$this->deleteRow($fileId, 'permanent deletion');
		}
		// Otherwise the file just moved to the trashbin: keep the row so a
		// restore keeps its protection status.
	}

	private function deleteRow(int $fileId, string $reason): void {
		try {
			$this->mapper->deleteByFileId($fileId);
			$this->logger->debug('Removed Seclore state row after ' . $reason, ['fileId' => $fileId]);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not remove Seclore state row after ' . $reason, [
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}
}
