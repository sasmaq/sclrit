<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service;

use OCA\FilesSeclore\AppInfo\Application;
use OCA\FilesSeclore\Service\Dto\HotFolder;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Cached view of the Hot Folder (policy) list (SDD §4, §10).
 * TTL from ConfigService; explicitly invalidated when the admin saves or
 * tests the connection settings.
 */
final class PolicyService {
	private ICache $cache;

	public function __construct(
		private readonly ISecloreClient $client,
		private readonly ConfigService $config,
		ICacheFactory $cacheFactory,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/** @return HotFolder[] */
	public function getPolicies(bool $bypassCache = false): array {
		$key = $this->cacheKey();

		if (!$bypassCache) {
			$cached = $this->cache->get($key);
			if (is_array($cached)) {
				return $this->fromCache($cached);
			}
		}

		$folders = $this->client->listHotFolders();
		$this->cache->set($key, $this->toCache($folders), $this->config->getPolicyCacheTtl());
		return $folders;
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

	public function invalidate(): void {
		$this->cache->remove($this->cacheKey());
	}

	private function cacheKey(): string {
		return 'policies/' . sha1($this->config->getBaseUrl() . '|' . $this->config->getAppId());
	}

	/** @param HotFolder[] $folders @return array<int, array<string, string>> */
	private function toCache(array $folders): array {
		return array_map(
			static fn (HotFolder $f): array => ['id' => $f->id, 'name' => $f->name, 'description' => $f->description],
			$folders,
		);
	}

	/** @return HotFolder[] */
	private function fromCache(array $cached): array {
		$folders = [];
		foreach ($cached as $item) {
			if (is_array($item) && isset($item['id'], $item['name'])) {
				$folders[] = new HotFolder(
					(string)$item['id'],
					(string)$item['name'],
					(string)($item['description'] ?? ''),
				);
			}
		}
		return $folders;
	}
}
