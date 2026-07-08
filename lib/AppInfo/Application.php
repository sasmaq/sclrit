<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\AppInfo;

use OCA\FilesSeclore\Service\ISecloreClient;
use OCA\FilesSeclore\Service\SecloreClient;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_seclore';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// The Seclore REST contract lives behind this interface (SDD §7, decision D3).
		$context->registerServiceAlias(ISecloreClient::class, SecloreClient::class);

		// Upcoming registrations (SDD §4): capability, event listeners,
		// notification notifier, files-metadata provider.
	}

	public function boot(IBootContext $context): void {
	}
}
