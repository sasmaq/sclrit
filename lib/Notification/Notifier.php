<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Notification;

use OCA\Sclrit\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the notifications pushed on completion/failure of queued protect
 * and unprotect operations (SDD §4.6), deep-linking to the file.
 */
class Notifier implements INotifier {
	public const SUBJECT_PROTECT_DONE = 'protect_done';
	public const SUBJECT_PROTECT_FAILED = 'protect_failed';
	public const SUBJECT_UNPROTECT_DONE = 'unprotect_done';
	public const SUBJECT_UNPROTECT_FAILED = 'unprotect_failed';

	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Seclore File Protection');
	}

	/**
	 * @throws UnknownNotificationException
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();
		$fileId = (int)($parameters['fileId'] ?? $notification->getObjectId());
		$fileName = (string)($parameters['fileName'] ?? '');
		$policyName = (string)($parameters['policy'] ?? '');
		$error = (string)($parameters['error'] ?? '');

		$file = [
			'type' => 'file',
			'id' => (string)$fileId,
			'name' => $fileName !== '' ? $fileName : ('#' . $fileId),
			'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]),
		];
		$rich = ['file' => $file];

		switch ($notification->getSubject()) {
			case self::SUBJECT_PROTECT_DONE:
				if ($policyName !== '') {
					$subject = $l->t('{file} is now protected with the Seclore policy {policy}');
					$rich['policy'] = ['type' => 'highlight', 'id' => $policyName, 'name' => $policyName];
				} else {
					$subject = $l->t('{file} is now protected with Seclore');
				}
				break;
			case self::SUBJECT_PROTECT_FAILED:
				$subject = $l->t('Protecting {file} with Seclore failed');
				break;
			case self::SUBJECT_UNPROTECT_DONE:
				$subject = $l->t('The Seclore protection was removed from {file}');
				break;
			case self::SUBJECT_UNPROTECT_FAILED:
				$subject = $l->t('Removing the Seclore protection from {file} failed');
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setRichSubject($subject, $rich);
		$notification->setParsedSubject($this->plainify($subject, $rich));
		if ($error !== '') {
			$notification->setParsedMessage($error);
		}
		$notification->setLink($file['link']);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('core', 'actions/password.svg'),
		));
		return $notification;
	}

	/** @param array<string, array{name: string}> $rich */
	private function plainify(string $subject, array $rich): string {
		return str_replace(
			array_map(static fn (string $key): string => '{' . $key . '}', array_keys($rich)),
			array_map(static fn (array $param): string => $param['name'], array_values($rich)),
			$subject,
		);
	}
}
