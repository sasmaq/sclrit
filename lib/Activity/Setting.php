<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * Makes the Seclore audit events configurable in the Activity settings and
 * enabled in the stream by default (SDD §4.6).
 */
class Setting extends ActivitySettings {
	public function __construct(
		private readonly IL10N $l,
	) {
	}

	public function getIdentifier(): string {
		return ActivityPublisher::TYPE;
	}

	public function getName(): string {
		return $this->l->t('A file was <strong>protected</strong> with Seclore or its protection was removed');
	}

	public function getGroupIdentifier(): string {
		return 'files';
	}

	public function getGroupName(): string {
		return $this->l->t('Files');
	}

	public function getPriority(): int {
		return 55;
	}
}
