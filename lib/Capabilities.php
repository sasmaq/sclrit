<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore;

use OCA\FilesSeclore\Service\ConfigService;
use OCA\FilesSeclore\Service\ProtectionService;
use OCP\Capabilities\ICapability;
use OCP\IUserSession;

/**
 * Advertises per-user availability to clients and scripts (SDD §4.3):
 * `files_seclore: {enabled, canProtect, canUnprotect, defaultPolicy}`.
 */
class Capabilities implements ICapability {
	public function __construct(
		private readonly ConfigService $config,
		private readonly ProtectionService $protectionService,
		private readonly IUserSession $userSession,
	) {
	}

	/**
	 * @return array{files_seclore: array{enabled: bool, canProtect: bool, canUnprotect: bool, defaultPolicy: string}}
	 */
	public function getCapabilities(): array {
		$enabled = $this->config->isConfigured();
		$userId = $this->userSession->getUser()?->getUID();
		return [
			'files_seclore' => [
				'enabled' => $enabled,
				'canProtect' => $enabled && $userId !== null && $this->protectionService->userCanProtect($userId),
				'canUnprotect' => $enabled && $userId !== null && $this->protectionService->userCanUnprotect($userId),
				'defaultPolicy' => $this->config->getDefaultHotFolder(),
			],
		];
	}
}
