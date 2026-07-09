<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit\Service;

use OCA\Sclrit\AppInfo\Application;
use OCA\Sclrit\Service\ConfigService;
use OCA\Sclrit\Tests\Unit\AppConfigMockTrait;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase {
	use AppConfigMockTrait;

	public function testGetBaseUrlStripsTrailingSlash(): void {
		$config = $this->createConfigService([ConfigService::KEY_BASE_URL => 'https://drm.example.com/']);
		$this->assertSame('https://drm.example.com', $config->getBaseUrl());
	}

	public function testSetBaseUrlTrimsWhitespaceAndTrailingSlash(): void {
		$config = $this->createConfigService();
		$config->setBaseUrl('  https://drm.example.com/  ');
		$this->assertSame('https://drm.example.com', $this->appConfigValues[ConfigService::KEY_BASE_URL]);
	}

	public function testSetAppSecretIsStoredWithTheSensitiveFlag(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with(Application::APP_ID, ConfigService::KEY_APP_SECRET, 's3cret', false, true)
			->willReturn(true);
		(new ConfigService($appConfig))->setAppSecret('s3cret');
	}

	public function testGetPoliciesParsesValidEntriesAndSkipsInvalidOnes(): void {
		$config = $this->createConfigService([
			ConfigService::KEY_POLICIES => json_encode([
				['id' => 'hf1', 'name' => 'Confidential', 'description' => 'Internal only'],
				['id' => 'hf2', 'name' => 'Restricted'],
				['id' => '', 'name' => 'No id'],
				['id' => 'hf3'],
				'not-an-array',
				['id' => 'hf4', 'name' => 'Bad description', 'description' => 42],
			]),
		]);

		$policies = $config->getPolicies();

		$this->assertCount(3, $policies);
		$this->assertSame('hf1', $policies[0]->id);
		$this->assertSame('Confidential', $policies[0]->name);
		$this->assertSame('Internal only', $policies[0]->description);
		$this->assertSame('hf2', $policies[1]->id);
		$this->assertSame('', $policies[1]->description);
		$this->assertSame('', $policies[2]->description);
	}

	public function testGetPoliciesReturnsEmptyListForMalformedJson(): void {
		$config = $this->createConfigService([ConfigService::KEY_POLICIES => '{not json']);
		$this->assertSame([], $config->getPolicies());
	}

	public function testGetPoliciesDefaultsToEmptyList(): void {
		$this->assertSame([], $this->createConfigService()->getPolicies());
	}

	public function testSetPoliciesNormalizesAndSkipsIncompleteEntries(): void {
		$config = $this->createConfigService();
		$config->setPolicies([
			['id' => ' hf1 ', 'name' => ' Confidential ', 'description' => ' desc '],
			['id' => '', 'name' => 'Skipped'],
			['id' => 'hf2', 'name' => ''],
			['id' => 'hf3', 'name' => 'No description'],
		]);

		$stored = json_decode((string)$this->appConfigValues[ConfigService::KEY_POLICIES], true);
		$this->assertSame([
			['id' => 'hf1', 'name' => 'Confidential', 'description' => 'desc'],
			['id' => 'hf3', 'name' => 'No description', 'description' => ''],
		], $stored);
	}

	public function testGroupListsFilterNonStringEntries(): void {
		$config = $this->createConfigService([
			ConfigService::KEY_ALLOWED_GROUPS => '["drm-users", 7, null, "admins"]',
			ConfigService::KEY_UNPROTECT_GROUPS => 'not json',
		]);

		$this->assertSame(['drm-users', 'admins'], $config->getAllowedGroups());
		$this->assertSame([], $config->getUnprotectGroups());
	}

	public function testSetGroupListStoresOnlyStrings(): void {
		$config = $this->createConfigService();
		$config->setAllowedGroups(['drm-users', 7, 'admins']);
		$this->assertSame('["drm-users","admins"]', $this->appConfigValues[ConfigService::KEY_ALLOWED_GROUPS]);
	}

	public function testDefaults(): void {
		$config = $this->createConfigService();

		$this->assertSame(ConfigService::DEFAULT_SYNC_MAX_SIZE, $config->getSyncMaxSize());
		$this->assertSame(ConfigService::DEFAULT_REQUEST_TIMEOUT_MAX, $config->getRequestTimeoutMax());
		$this->assertSame(ConfigService::DEFAULT_STALE_AFTER, $config->getStaleAfter());
		$this->assertTrue($config->getVerifyTls());
		$this->assertTrue($config->getPurgeVersions());
	}

	public function testIsConfiguredRequiresBaseUrlAppIdAndSecret(): void {
		$this->assertTrue($this->createConfigService(self::configuredValues())->isConfigured());

		$incomplete = self::configuredValues();
		unset($incomplete[ConfigService::KEY_APP_SECRET]);
		$this->assertFalse($this->createConfigService($incomplete)->isConfigured());

		$this->assertFalse($this->createConfigService()->isConfigured());
	}

	public function testGetCredentialsCarriesTheConnectionSettings(): void {
		$config = $this->createConfigService(self::configuredValues() + [
			ConfigService::KEY_VERIFY_TLS => false,
		]);

		$credentials = $config->getCredentials();

		$this->assertSame('https://drm.example.com', $credentials->baseUrl);
		$this->assertSame('tenant-1', $credentials->appId);
		$this->assertSame('s3cret', $credentials->appSecret);
		$this->assertFalse($credentials->verifyTls);
	}
}
