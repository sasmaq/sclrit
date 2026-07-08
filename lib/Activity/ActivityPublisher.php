<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Activity;

use OCA\FilesSeclore\AppInfo\Application;
use OCP\Activity\IManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Publishes the audit-trail Activity events (SDD §4.6): file_protected,
 * file_unprotected, protect_failed. Success events reach the actor and the
 * file owner; failures only the actor. Publishing is best-effort — a broken
 * activity stream must never fail a protect/unprotect operation.
 */
final class ActivityPublisher {
	public const TYPE = 'files_seclore';

	public const SUBJECT_PROTECTED = 'file_protected';
	public const SUBJECT_UNPROTECTED = 'file_unprotected';
	public const SUBJECT_PROTECT_FAILED = 'protect_failed';

	public function __construct(
		private readonly IManager $activityManager,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
	}

	public function fileProtected(string $actorId, File $file, ?string $policyName): void {
		$this->publish(self::SUBJECT_PROTECTED, $actorId, $this->audience($actorId, $file), [
			'actor' => $actorId,
			'fileId' => $file->getId(),
			'fileName' => $file->getName(),
			'filePath' => $this->relativePath($file),
			'policy' => $policyName ?? '',
		], $file->getId(), $file->getName());
	}

	public function fileUnprotected(string $actorId, File $file, ?string $policyName): void {
		$this->publish(self::SUBJECT_UNPROTECTED, $actorId, $this->audience($actorId, $file), [
			'actor' => $actorId,
			'fileId' => $file->getId(),
			'fileName' => $file->getName(),
			'filePath' => $this->relativePath($file),
			'policy' => $policyName ?? '',
		], $file->getId(), $file->getName());
	}

	public function protectFailed(string $actorId, int $fileId, ?string $fileName, ?string $policyName, string $error): void {
		$this->publish(self::SUBJECT_PROTECT_FAILED, $actorId, [$actorId], [
			'actor' => $actorId,
			'fileId' => $fileId,
			'fileName' => $fileName ?? '',
			'filePath' => '',
			'policy' => $policyName ?? '',
			'error' => $error,
		], $fileId, $fileName ?? '');
	}

	/**
	 * @param string[] $affectedUsers
	 * @param array<string, int|string> $parameters
	 */
	private function publish(string $subject, string $actorId, array $affectedUsers, array $parameters, int $fileId, string $fileName): void {
		try {
			foreach (array_unique($affectedUsers) as $affectedUser) {
				$event = $this->activityManager->generateEvent();
				$event->setApp(Application::APP_ID)
					->setType(self::TYPE)
					->setAuthor($actorId)
					->setAffectedUser($affectedUser)
					->setTimestamp($this->timeFactory->getTime())
					->setObject('files', $fileId, $fileName)
					->setSubject($subject, $parameters);
				$this->activityManager->publish($event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Could not publish Seclore activity event', [
				'subject' => $subject,
				'fileId' => $fileId,
				'exception' => $e,
			]);
		}
	}

	/** @return string[] actor plus the file owner (SDD §8.2). */
	private function audience(string $actorId, File $file): array {
		try {
			$ownerId = $file->getOwner()?->getUID();
		} catch (\Throwable) {
			$ownerId = null;
		}
		return $ownerId !== null && $ownerId !== $actorId ? [$actorId, $ownerId] : [$actorId];
	}

	/** Path relative to the files root, for the rich file object. */
	private function relativePath(File $file): string {
		// Node paths look like /<uid>/files/<relative path>.
		$parts = explode('/', ltrim($file->getPath(), '/'), 3);
		return count($parts) === 3 ? '/' . $parts[2] : $file->getName();
	}
}
