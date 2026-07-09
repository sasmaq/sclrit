<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit;

use OCA\Sclrit\Activity\ActivityPublisher;
use OCA\Sclrit\Capabilities;
use OCA\Sclrit\Db\SecloreStateMapper;
use OCA\Sclrit\Service\ConfigService;
use OCA\Sclrit\Service\ISecloreClient;
use OCA\Sclrit\Service\PolicyService;
use OCA\Sclrit\Service\ProtectionService;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class CapabilitiesTest extends TestCase {
	use AppConfigMockTrait;

	/** Groups the test user belongs to. */
	private array $userGroups = [];

	/** @param array<string, mixed> $configValues */
	private function newCapabilities(array $configValues, ?string $userId = 'alice'): Capabilities {
		$config = $this->createConfigService($configValues);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $groupId): bool => in_array($groupId, $this->userGroups, true),
		);

		$protectionService = new ProtectionService(
			$this->createMock(IRootFolder::class),
			$this->createMock(SecloreStateMapper::class),
			$this->createMock(ISecloreClient::class),
			new PolicyService($config),
			$config,
			$groupManager,
			$this->createMock(IUserManager::class),
			$this->createMock(IJobList::class),
			$this->createMock(IFilesMetadataManager::class),
			$this->createMock(ITimeFactory::class),
			new ActivityPublisher(
				$this->createMock(IActivityManager::class),
				$this->createMock(ITimeFactory::class),
				new NullLogger(),
			),
			$this->createMock(INotificationManager::class),
			$this->createMock(ContainerInterface::class),
			new NullLogger(),
		);

		$userSession = $this->createMock(IUserSession::class);
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}

		return new Capabilities($config, $protectionService, $userSession);
	}

	public function testDisabledWhenNotConfigured(): void {
		$capabilities = $this->newCapabilities([])->getCapabilities();

		$this->assertSame([
			'sclrit' => [
				'enabled' => false,
				'canProtect' => false,
				'canUnprotect' => false,
				'defaultPolicy' => '',
			],
		], $capabilities);
	}

	public function testConfiguredInstanceAdvertisesPerUserPermissions(): void {
		$this->userGroups = ['unprotectors'];
		$capabilities = $this->newCapabilities(self::configuredValues() + [
			ConfigService::KEY_DEFAULT_HOT_FOLDER => 'hf1',
			ConfigService::KEY_UNPROTECT_GROUPS => '["unprotectors"]',
		])->getCapabilities();

		$this->assertSame([
			'sclrit' => [
				'enabled' => true,
				'canProtect' => true,
				'canUnprotect' => true,
				'defaultPolicy' => 'hf1',
			],
		], $capabilities);
	}

	public function testNoPermissionsWithoutASessionUser(): void {
		$capabilities = $this->newCapabilities(self::configuredValues(), userId: null)->getCapabilities();

		$this->assertTrue($capabilities['sclrit']['enabled']);
		$this->assertFalse($capabilities['sclrit']['canProtect']);
		$this->assertFalse($capabilities['sclrit']['canUnprotect']);
	}

	public function testCanProtectHonoursAllowedGroups(): void {
		$capabilities = $this->newCapabilities(self::configuredValues() + [
			ConfigService::KEY_ALLOWED_GROUPS => '["drm-users"]',
		])->getCapabilities();

		$this->assertFalse($capabilities['sclrit']['canProtect']);
	}
}
