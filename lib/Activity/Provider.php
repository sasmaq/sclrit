<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Activity;

use OCA\FilesSeclore\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Renders the Seclore activity events (SDD §4.6) with rich file/user objects.
 */
class Provider implements IProvider {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @throws UnknownActivityException
	 */
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID || $event->getType() !== ActivityPublisher::TYPE) {
			throw new UnknownActivityException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$parameters = $event->getSubjectParameters();

		$fileId = (int)($parameters['fileId'] ?? $event->getObjectId());
		$fileName = (string)($parameters['fileName'] ?? '');
		$actorId = (string)($parameters['actor'] ?? '');
		$policyName = (string)($parameters['policy'] ?? '');

		$rich = [
			'file' => [
				'type' => 'file',
				'id' => (string)$fileId,
				'name' => $fileName !== '' ? $fileName : ('#' . $fileId),
				'path' => (string)($parameters['filePath'] ?? ''),
				'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]),
			],
			'actor' => [
				'type' => 'user',
				'id' => $actorId,
				'name' => $this->userManager->getDisplayName($actorId) ?? $actorId,
			],
		];
		if ($policyName !== '') {
			$rich['policy'] = [
				'type' => 'highlight',
				'id' => $policyName,
				'name' => $policyName,
			];
		}

		switch ($event->getSubject()) {
			case ActivityPublisher::SUBJECT_PROTECTED:
				$subject = $policyName !== ''
					? $l->t('{actor} protected {file} with the Seclore policy {policy}')
					: $l->t('{actor} protected {file} with Seclore');
				break;
			case ActivityPublisher::SUBJECT_UNPROTECTED:
				$subject = $l->t('{actor} removed the Seclore protection from {file}');
				break;
			case ActivityPublisher::SUBJECT_PROTECT_FAILED:
				$subject = $l->t('Protecting {file} with Seclore, requested by {actor}, failed');
				$error = (string)($parameters['error'] ?? '');
				if ($error !== '') {
					$event->setParsedMessage($error);
				}
				break;
			default:
				throw new UnknownActivityException();
		}

		$event->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('core', 'actions/password.svg'),
		));
		$event->setRichSubject($subject, $rich);
		$event->setParsedSubject($this->plainify($subject, $rich));
		return $event;
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
