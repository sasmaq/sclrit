<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesSeclore\Capabilities;
use OCA\FilesSeclore\Listener\LoadAdditionalScriptsListener;
use OCA\FilesSeclore\Notification\Notifier;
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

		// files_seclore: {enabled, canProtect, canUnprotect, defaultPolicy} (SDD §4.3).
		$context->registerCapability(Capabilities::class);

		// Files web UI integration bundle (SDD §5.1).
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);

		// Async completion/failure notifications (SDD §4.6); the activity
		// provider and setting are registered via info.xml.
		$context->registerNotifierService(Notifier::class);

		// Upcoming registrations (SDD §6.1): NodeDeletedEvent listener for
		// state-row lifecycle.
	}

	public function boot(IBootContext $context): void {
	}
}
