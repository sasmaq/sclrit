<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Sclrit\Capabilities;
use OCA\Sclrit\Listener\LoadAdditionalScriptsListener;
use OCA\Sclrit\Listener\NodeDeletedListener;
use OCA\Sclrit\Notification\Notifier;
use OCA\Sclrit\Service\ISecloreClient;
use OCA\Sclrit\Service\SecloreClient;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sclrit';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// The Seclore REST contract lives behind this interface (SDD §7, decision D3).
		$context->registerServiceAlias(ISecloreClient::class, SecloreClient::class);

		// sclrit: {enabled, canProtect, canUnprotect, defaultPolicy} (SDD §4.3).
		$context->registerCapability(Capabilities::class);

		// Files web UI integration bundle (SDD §5.1).
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadAdditionalScriptsListener::class);

		// Async completion/failure notifications (SDD §4.6); the activity
		// provider and setting are registered via info.xml.
		$context->registerNotifierService(Notifier::class);

		// State-row lifecycle on file deletion (SDD §6.1).
		$context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
