<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service;

use OCA\Sclrit\Service\Dto\HotFolder;

/**
 * The protection policies (Hot Folders) offered to users.
 *
 * The verified Seclore DRM tenant API has no Hot Folder listing endpoint
 * (SDD §7.3.1, §15 Q1a), so the list is maintained by the administrator in
 * the app settings and read from local configuration — no caching needed.
 * Whether an id is actually valid is ultimately decided by the Policy Server
 * at protect time (a 404 there maps to PolicyNotFoundException, SDD §9 E5).
 */
final class PolicyService {
	public function __construct(
		private readonly ConfigService $config,
	) {
	}

	/** @return HotFolder[] */
	public function getPolicies(): array {
		return $this->config->getPolicies();
	}

	public function find(string $hotFolderId): ?HotFolder {
		foreach ($this->getPolicies() as $policy) {
			if ($policy->id === $hotFolderId) {
				return $policy;
			}
		}
		return null;
	}

	public function exists(string $hotFolderId): bool {
		return $this->find($hotFolderId) !== null;
	}
}
