<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit;

use OCA\Sclrit\Service\ConfigService;
use OCP\IAppConfig;

/**
 * Builds a ConfigService backed by an in-memory IAppConfig double, so services
 * depending on the (final) ConfigService can be tested with real config logic.
 */
trait AppConfigMockTrait {
	/** @var \ArrayObject<string, mixed> */
	private \ArrayObject $appConfigValues;

	/** @param array<string, mixed> $initial values keyed by app-config key */
	private function mockAppConfig(array $initial = []): IAppConfig {
		$this->appConfigValues = new \ArrayObject($initial);
		$values = $this->appConfigValues;

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($values): string {
				$value = $values[$key] ?? null;
				return is_string($value) ? $value : $default;
			},
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($values): bool {
				$values[$key] = $value;
				return true;
			},
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) use ($values): int {
				$value = $values[$key] ?? null;
				return is_int($value) ? $value : $default;
			},
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			static function (string $app, string $key, int $value) use ($values): bool {
				$values[$key] = $value;
				return true;
			},
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $default = false) use ($values): bool {
				$value = $values[$key] ?? null;
				return is_bool($value) ? $value : $default;
			},
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			static function (string $app, string $key, bool $value) use ($values): bool {
				$values[$key] = $value;
				return true;
			},
		);
		return $appConfig;
	}

	/** @param array<string, mixed> $initial values keyed by app-config key */
	private function createConfigService(array $initial = []): ConfigService {
		return new ConfigService($this->mockAppConfig($initial));
	}

	/** @return array<string, mixed> a configured connection as initial values */
	private static function configuredValues(): array {
		return [
			ConfigService::KEY_BASE_URL => 'https://drm.example.com',
			ConfigService::KEY_APP_ID => 'tenant-1',
			ConfigService::KEY_APP_SECRET => 's3cret',
		];
	}
}
