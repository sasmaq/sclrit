<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service;

use OCA\FilesSeclore\AppInfo\Application;
use OCA\FilesSeclore\Service\Dto\Credentials;
use OCP\IAppConfig;

/**
 * Typed access to the app configuration (SDD Appendix A).
 *
 * Until the admin settings UI lands, values are set via:
 *   occ config:app:set files_seclore <key> --value=<value> [--sensitive]
 */
final class ConfigService {
	public const KEY_BASE_URL = 'base_url';
	public const KEY_APP_ID = 'app_id';
	public const KEY_APP_SECRET = 'app_secret';
	public const KEY_DEFAULT_HOT_FOLDER = 'default_hot_folder';
	public const KEY_ALLOWED_GROUPS = 'allowed_groups';
	public const KEY_UNPROTECT_GROUPS = 'unprotect_groups';
	public const KEY_SYNC_MAX_SIZE = 'sync_max_size';
	public const KEY_REQUEST_TIMEOUT_MAX = 'request_timeout_max';
	public const KEY_VERIFY_TLS = 'verify_tls';
	public const KEY_PURGE_VERSIONS = 'purge_versions';
	public const KEY_POLICY_CACHE_TTL = 'policy_cache_ttl';
	public const KEY_STALE_AFTER = 'stale_after';

	public const DEFAULT_SYNC_MAX_SIZE = 26214400; // 25 MiB
	public const DEFAULT_REQUEST_TIMEOUT_MAX = 600;
	public const DEFAULT_POLICY_CACHE_TTL = 300;
	public const DEFAULT_STALE_AFTER = 21600;

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getBaseUrl(): string {
		return rtrim($this->appConfig->getValueString(Application::APP_ID, self::KEY_BASE_URL), '/');
	}

	public function getAppId(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_APP_ID);
	}

	public function getAppSecret(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_APP_SECRET);
	}

	/** Stored with the sensitive flag: masked in occ output, encrypted at rest (SDD §8.1). */
	public function setAppSecret(string $secret): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_APP_SECRET, $secret, false, true);
	}

	public function getDefaultHotFolder(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_DEFAULT_HOT_FOLDER);
	}

	/** @return string[] group ids allowed to protect; empty means everyone (SDD §8.2) */
	public function getAllowedGroups(): array {
		return $this->getGroupList(self::KEY_ALLOWED_GROUPS);
	}

	/** @return string[] group ids allowed to unprotect; empty means nobody (SDD §8.2) */
	public function getUnprotectGroups(): array {
		return $this->getGroupList(self::KEY_UNPROTECT_GROUPS);
	}

	/** Sync/async threshold in bytes (SDD §4.2, decision D4). */
	public function getSyncMaxSize(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_SYNC_MAX_SIZE, self::DEFAULT_SYNC_MAX_SIZE);
	}

	/** Upper cap in seconds for size-scaled request timeouts (SDD §7.4). */
	public function getRequestTimeoutMax(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_REQUEST_TIMEOUT_MAX, self::DEFAULT_REQUEST_TIMEOUT_MAX);
	}

	public function getVerifyTls(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_VERIFY_TLS, true);
	}

	/** Delete pre-protection file versions after success (SDD decision D7). */
	public function getPurgeVersions(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_PURGE_VERSIONS, true);
	}

	public function getPolicyCacheTtl(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_POLICY_CACHE_TTL, self::DEFAULT_POLICY_CACHE_TTL);
	}

	/** Watchdog window in seconds for stuck in-flight states (SDD §9 E14). */
	public function getStaleAfter(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, self::KEY_STALE_AFTER, self::DEFAULT_STALE_AFTER);
	}

	public function isConfigured(): bool {
		return $this->getBaseUrl() !== '' && $this->getAppId() !== '' && $this->getAppSecret() !== '';
	}

	public function getCredentials(): Credentials {
		return new Credentials(
			$this->getBaseUrl(),
			$this->getAppId(),
			$this->getAppSecret(),
			$this->getVerifyTls(),
		);
	}

	/** @return string[] */
	private function getGroupList(string $key): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $key, '[]');
		$list = json_decode($raw, true);
		if (!is_array($list)) {
			return [];
		}
		return array_values(array_filter($list, is_string(...)));
	}
}
