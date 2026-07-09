<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Tests\Unit\Service;

use OCA\FilesSeclore\Activity\ActivityPublisher;
use OCA\FilesSeclore\BackgroundJob\ProtectJob;
use OCA\FilesSeclore\BackgroundJob\UnprotectJob;
use OCA\FilesSeclore\Db\SecloreState;
use OCA\FilesSeclore\Db\SecloreStateMapper;
use OCA\FilesSeclore\Exceptions\AlreadyProtectedException;
use OCA\FilesSeclore\Exceptions\ConflictException;
use OCA\FilesSeclore\Exceptions\InProgressException;
use OCA\FilesSeclore\Exceptions\NotAllowedException;
use OCA\FilesSeclore\Exceptions\NotConfiguredException;
use OCA\FilesSeclore\Exceptions\NotProtectedException;
use OCA\FilesSeclore\Exceptions\PolicyNotFoundException;
use OCA\FilesSeclore\Exceptions\SecloreApiException;
use OCA\FilesSeclore\Exceptions\UnsupportedFileException;
use OCA\FilesSeclore\Service\ConfigService;
use OCA\FilesSeclore\Service\Dto\ProtectResult;
use OCA\FilesSeclore\Service\Dto\ProtectionState;
use OCA\FilesSeclore\Service\ISecloreClient;
use OCA\FilesSeclore\Service\PolicyService;
use OCA\FilesSeclore\Service\ProtectionService;
use OCA\FilesSeclore\Tests\Unit\AppConfigMockTrait;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception as DBException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\FilesMetadata\Model\IFilesMetadata;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class ProtectionServiceTest extends TestCase {
	use AppConfigMockTrait;

	private const NOW = 1720000000;
	private const FILE_ID = 42;
	private const USER = 'alice';
	private const LARGE_SIZE = 100 * 1024 * 1024;

	private IRootFolder&MockObject $rootFolder;
	private Folder&MockObject $userFolder;
	private SecloreStateMapper&MockObject $mapper;
	private ISecloreClient&MockObject $client;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private IJobList&MockObject $jobList;
	private IFilesMetadataManager&MockObject $metadataManager;
	private IFilesMetadata&MockObject $metadata;
	private ITimeFactory&MockObject $timeFactory;
	private INotificationManager&MockObject $notificationManager;
	private ContainerInterface&MockObject $container;

	/** Groups the test user belongs to. */
	private array $userGroups = ['unprotectors'];
	/** @var SecloreState[] every state passed to mapper::update, in order */
	private array $updatedStates = [];
	/** @var string[] */
	private array $tempFiles = [];

	protected function setUp(): void {
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->rootFolder->method('getUserFolder')->with(self::USER)->willReturn($this->userFolder);

		$this->mapper = $this->createMock(SecloreStateMapper::class);
		$this->mapper->method('insert')->willReturnCallback(static fn (SecloreState $s): SecloreState => $s);
		$this->mapper->method('update')->willReturnCallback(function (SecloreState $s): SecloreState {
			$this->updatedStates[] = clone $s;
			return $s;
		});

		$this->client = $this->createMock(ISecloreClient::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $userId, string $groupId): bool => in_array($groupId, $this->userGroups, true),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('alice@example.com');
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->with(self::USER)->willReturn($user);

		$this->jobList = $this->createMock(IJobList::class);

		$this->metadata = $this->createMock(IFilesMetadata::class);
		$this->metadataManager = $this->createMock(IFilesMetadataManager::class);
		$this->metadataManager->method('getMetadata')->willReturn($this->metadata);

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(self::NOW);

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $path) {
			@unlink($path);
		}
	}

	/** @param array<string, mixed> $configOverrides */
	private function newService(array $configOverrides = [], bool $configured = true): ProtectionService {
		$values = $configOverrides;
		if ($configured) {
			$values = array_merge(self::configuredValues(), [
				ConfigService::KEY_DEFAULT_HOT_FOLDER => 'hf1',
				ConfigService::KEY_POLICIES => json_encode([['id' => 'hf1', 'name' => 'Confidential']]),
				ConfigService::KEY_UNPROTECT_GROUPS => '["unprotectors"]',
			], $configOverrides);
		}
		$config = $this->createConfigService($values);

		return new ProtectionService(
			$this->rootFolder,
			$this->mapper,
			$this->client,
			new PolicyService($config),
			$config,
			$this->groupManager,
			$this->userManager,
			$this->jobList,
			$this->metadataManager,
			$this->timeFactory,
			new ActivityPublisher($this->createMock(IActivityManager::class), $this->timeFactory, new NullLogger()),
			$this->notificationManager,
			$this->container,
			new NullLogger(),
		);
	}

	/** @param string[] $etags consecutive getEtag() values; the last one repeats */
	private function mockFile(
		int $size = 10,
		bool $updateable = true,
		array $etags = ['etag-1'],
		bool $encryptedParent = false,
	): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$file->method('getName')->willReturn('doc.txt');
		$file->method('getPath')->willReturn('/alice/files/doc.txt');
		$file->method('getSize')->willReturn($size);
		$file->method('isUpdateable')->willReturn($updateable);
		$file->method('getEtag')->willReturnCallback(static function () use (&$etags): string {
			return count($etags) > 1 ? array_shift($etags) : $etags[0];
		});
		$file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'rb+'));

		$parent = $this->createMock(Folder::class);
		$parent->method('isEncrypted')->willReturn($encryptedParent);
		$parent->method('getPath')->willReturn('/alice/files');
		$file->method('getParent')->willReturn($parent);

		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$file->method('getStorage')->willReturn($storage);

		return $file;
	}

	private function serveFile(?File $file): void {
		$this->userFolder->method('getFirstNodeById')->with(self::FILE_ID)->willReturn($file);
	}

	private function stateRow(string $status, int $attempts = 0): SecloreState {
		$state = new SecloreState();
		$state->setFileId(self::FILE_ID);
		$state->setStatus($status);
		$state->setHotFolderId('hf1');
		$state->setPolicyName('Confidential');
		$state->setRequestedBy(self::USER);
		$state->setAttempts($attempts);
		$state->setCreatedAt(self::NOW - 100);
		$state->setUpdatedAt(self::NOW - 100);
		return $state;
	}

	private function noStateRow(): void {
		$this->mapper->method('findByFileId')->willThrowException(new DoesNotExistException('no row'));
	}

	private function protectedTempFile(string $content): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'seclore-test');
		file_put_contents($path, $content);
		$this->tempFiles[] = $path;
		return $path;
	}

	// ---- protect guards ---------------------------------------------------

	public function testRequestProtectRequiresConfiguration(): void {
		$this->serveFile($this->mockFile());
		$this->expectException(NotConfiguredException::class);
		$this->newService(configured: false)->requestProtect(self::USER, self::FILE_ID);
	}

	public function testRequestProtectRequiresAnExistingFile(): void {
		$this->serveFile(null);
		$this->expectException(NotFoundException::class);
		$this->newService()->requestProtect(self::USER, self::FILE_ID);
	}

	public function testRequestProtectRequiresAllowedGroupMembership(): void {
		$this->serveFile($this->mockFile());
		$this->expectException(NotAllowedException::class);
		$this->newService([ConfigService::KEY_ALLOWED_GROUPS => '["drm-users"]'])
			->requestProtect(self::USER, self::FILE_ID);
	}

	public function testRequestProtectRequiresEditPermission(): void {
		$this->serveFile($this->mockFile(updateable: false));
		try {
			$this->newService()->requestProtect(self::USER, self::FILE_ID);
			$this->fail('Expected a NotAllowedException');
		} catch (NotAllowedException $e) {
			$this->assertSame('no_permission', $e->getErrorCode());
		}
	}

	public function testRequestProtectRejectsFilesInEndToEndEncryptedFolders(): void {
		$this->serveFile($this->mockFile(encryptedParent: true));
		try {
			$this->newService()->requestProtect(self::USER, self::FILE_ID);
			$this->fail('Expected an UnsupportedFileException');
		} catch (UnsupportedFileException $e) {
			$this->assertSame('e2ee_unsupported', $e->getErrorCode());
		}
	}

	public function testRequestProtectRejectsEmptyFiles(): void {
		$this->serveFile($this->mockFile(size: 0));
		try {
			$this->newService()->requestProtect(self::USER, self::FILE_ID);
			$this->fail('Expected an UnsupportedFileException');
		} catch (UnsupportedFileException $e) {
			$this->assertSame('empty_file', $e->getErrorCode());
		}
	}

	public function testRequestProtectWithoutAPolicyOrDefaultFails(): void {
		$this->serveFile($this->mockFile());
		$this->expectException(PolicyNotFoundException::class);
		$this->newService([ConfigService::KEY_DEFAULT_HOT_FOLDER => ''])
			->requestProtect(self::USER, self::FILE_ID);
	}

	public function testRequestProtectRejectsAPolicyOutsideTheConfiguredList(): void {
		$this->serveFile($this->mockFile());
		$this->expectException(PolicyNotFoundException::class);
		$this->newService()->requestProtect(self::USER, self::FILE_ID, 'hf-unknown');
	}

	public function testRequestProtectRejectsAnAlreadyProtectedFile(): void {
		$this->serveFile($this->mockFile());
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PROTECTED));
		$this->expectException(AlreadyProtectedException::class);
		$this->newService()->requestProtect(self::USER, self::FILE_ID);
	}

	public function testRequestProtectRejectsAnInFlightRequest(): void {
		$this->serveFile($this->mockFile());
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PENDING));
		$this->expectException(InProgressException::class);
		$this->newService()->requestProtect(self::USER, self::FILE_ID);
	}

	public function testConcurrentClaimLosesGracefully(): void {
		// Two workers race for the unique file_id index (SDD §9 E13): the loser's
		// insert fails with a unique-constraint violation and maps to "in progress".
		$this->serveFile($this->mockFile());
		$dbException = $this->createMock(DBException::class);
		$dbException->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		// Fresh mapper mock: setUp stubbed insert() with a pass-through already.
		$this->mapper = $this->createMock(SecloreStateMapper::class);
		$this->mapper->method('findByFileId')->willThrowException(new DoesNotExistException('no row'));
		$this->mapper->method('insert')->willThrowException($dbException);

		$this->expectException(InProgressException::class);
		$this->newService()->requestProtect(self::USER, self::FILE_ID);
	}

	// ---- protect execution ------------------------------------------------

	public function testRequestProtectQueuesLargeFiles(): void {
		$this->serveFile($this->mockFile(size: self::LARGE_SIZE));
		$this->noStateRow();
		$this->client->expects($this->never())->method('protect');
		$this->jobList->expects($this->once())
			->method('add')
			->with(ProtectJob::class, ['fileId' => self::FILE_ID, 'userId' => self::USER]);

		$state = $this->newService()->requestProtect(self::USER, self::FILE_ID);

		$this->assertSame(SecloreState::STATUS_PENDING, $state->status);
		$this->assertSame('hf1', $state->hotFolderId);
		$this->assertSame('Confidential', $state->policyName);
	}

	public function testForceSyncBypassesTheSizeThreshold(): void {
		$file = $this->mockFile(size: self::LARGE_SIZE);
		$this->serveFile($file);
		$this->noStateRow();
		$tempPath = $this->protectedTempFile('PROTECTED-BYTES');
		$this->client->method('protect')->willReturn(new ProtectResult('sf-1', $tempPath, 15, 'req-1'));
		$this->jobList->expects($this->never())->method('add');

		$state = $this->newService()->requestProtect(self::USER, self::FILE_ID, forceSync: true);

		$this->assertSame(SecloreState::STATUS_PROTECTED, $state->status);
	}

	public function testSynchronousProtectHappyPath(): void {
		$file = $this->mockFile();
		$this->serveFile($file);
		$this->noStateRow();

		$tempPath = $this->protectedTempFile('PROTECTED-BYTES');
		$this->client->expects($this->once())
			->method('protect')
			->with($this->anything(), 'doc.txt', 'hf1', 'alice@example.com')
			->willReturn(new ProtectResult('sf-1', $tempPath, 15, 'req-1'));

		$file->expects($this->once())->method('putContent');
		$this->metadata->expects($this->once())
			->method('setBool')
			->with(ProtectionService::METADATA_KEY, true, true);
		$this->metadataManager->expects($this->once())->method('saveMetadata');

		$state = $this->newService()->requestProtect(self::USER, self::FILE_ID);

		$this->assertSame(SecloreState::STATUS_PROTECTED, $state->status);
		$this->assertSame('sf-1', $state->secloreFileId);
		$this->assertNull($state->error);
		$this->assertFileDoesNotExist($tempPath, 'The protected temp file must be cleaned up');

		$final = end($this->updatedStates);
		$this->assertSame('req-1', $final->getRequestId());
		$this->assertSame(1, $final->getAttempts());
	}

	public function testProtectAbortsWhenTheFileChangedDuringTheRoundTrip(): void {
		// ETag compare-and-swap (decision D6): the write-back must never clobber
		// an edit that happened while Seclore was processing the old content.
		$file = $this->mockFile(etags: ['etag-before', 'etag-after']);
		$this->serveFile($file);
		$this->noStateRow();
		$tempPath = $this->protectedTempFile('PROTECTED-BYTES');
		$this->client->method('protect')->willReturn(new ProtectResult('sf-1', $tempPath, 15, 'req-1'));

		$file->expects($this->never())->method('putContent');

		try {
			$this->newService()->requestProtect(self::USER, self::FILE_ID);
			$this->fail('Expected a ConflictException');
		} catch (ConflictException) {
		}

		$final = end($this->updatedStates);
		$this->assertSame(SecloreState::STATUS_FAILED, $final->getStatus());
		$this->assertStringContainsString('modified', (string)$final->getLastError());
	}

	// ---- unprotect ----------------------------------------------------------

	public function testRequestUnprotectRequiresUnprotectGroupMembership(): void {
		$this->userGroups = [];
		$this->serveFile($this->mockFile());
		$this->expectException(NotAllowedException::class);
		$this->newService()->requestUnprotect(self::USER, self::FILE_ID);
	}

	public function testRequestUnprotectFailsWithoutAStateRow(): void {
		$this->serveFile($this->mockFile());
		$this->noStateRow();
		$this->expectException(NotProtectedException::class);
		$this->newService()->requestUnprotect(self::USER, self::FILE_ID);
	}

	public function testRequestUnprotectRejectsAnInFlightRequest(): void {
		$this->serveFile($this->mockFile());
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PENDING));
		$this->expectException(InProgressException::class);
		$this->newService()->requestUnprotect(self::USER, self::FILE_ID);
	}

	public function testRequestUnprotectQueuesLargeFiles(): void {
		$this->serveFile($this->mockFile(size: self::LARGE_SIZE));
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PROTECTED));
		$this->jobList->expects($this->once())
			->method('add')
			->with(UnprotectJob::class, ['fileId' => self::FILE_ID, 'userId' => self::USER]);

		$state = $this->newService()->requestUnprotect(self::USER, self::FILE_ID);

		$this->assertSame(SecloreState::STATUS_PENDING, $state->status);
	}

	public function testSynchronousUnprotectHappyPath(): void {
		$file = $this->mockFile();
		$this->serveFile($file);
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PROTECTED));

		$tempPath = $this->protectedTempFile('PLAINTEXT');
		$this->client->expects($this->once())
			->method('unprotect')
			->with($this->anything(), 'doc.txt')
			->willReturn($tempPath);

		$file->expects($this->once())->method('putContent');
		$this->mapper->expects($this->once())->method('delete');
		$this->metadata->expects($this->once())
			->method('setBool')
			->with(ProtectionService::METADATA_KEY, false, true);

		$state = $this->newService()->requestUnprotect(self::USER, self::FILE_ID);

		$this->assertSame(ProtectionState::STATUS_NONE, $state->status);
		$this->assertFileDoesNotExist($tempPath);
	}

	public function testFailedUnprotectRestoresTheProtectedStatus(): void {
		// The file content is still the protected binary, so the row must go
		// back to `protected` — `failed` is reserved for protect failures.
		$file = $this->mockFile();
		$this->serveFile($file);
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PROTECTED));
		$this->client->method('unprotect')->willThrowException(new SecloreApiException('server exploded', true));

		try {
			$this->newService()->requestUnprotect(self::USER, self::FILE_ID);
			$this->fail('Expected a SecloreApiException');
		} catch (SecloreApiException) {
		}

		$final = end($this->updatedStates);
		$this->assertSame(SecloreState::STATUS_PROTECTED, $final->getStatus());
		$this->assertSame('server exploded', $final->getLastError());
	}

	// ---- retry -------------------------------------------------------------

	public function testRequestRetryRequiresAFailedRow(): void {
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PROTECTED));
		$this->expectException(NotProtectedException::class);
		$this->newService()->requestRetry(self::USER, self::FILE_ID);
	}

	public function testRequestRetryReprotectsWithTheOriginalPolicy(): void {
		$this->serveFile($this->mockFile(size: self::LARGE_SIZE));
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_FAILED, attempts: 3));
		$this->jobList->expects($this->once())
			->method('add')
			->with(ProtectJob::class, ['fileId' => self::FILE_ID, 'userId' => self::USER]);

		$state = $this->newService()->requestRetry(self::USER, self::FILE_ID);

		$this->assertSame(SecloreState::STATUS_PENDING, $state->status);
		$this->assertSame('hf1', $state->hotFolderId);

		$final = end($this->updatedStates);
		$this->assertSame(0, $final->getAttempts(), 'A user retry resets the attempt counter');
		$this->assertNull($final->getLastError());
	}

	// ---- getStates / permissions --------------------------------------------

	public function testGetStatesOmitsInaccessibleFilesAndMergesRows(): void {
		$file = $this->mockFile();
		$this->userFolder->method('getFirstNodeById')->willReturnCallback(
			static fn (int $id) => in_array($id, [1, 3], true) ? $file : null,
		);
		$row = $this->stateRow(SecloreState::STATUS_PROTECTED);
		$row->setFileId(3);
		$this->mapper->expects($this->once())
			->method('findByFileIds')
			->with([1, 3])
			->willReturn([$row]);

		$states = $this->newService()->getStates(self::USER, [1, 2, 3, 3]);

		$this->assertSame([1, 3], array_keys($states));
		$this->assertSame(ProtectionState::STATUS_NONE, $states[1]->status);
		$this->assertSame(SecloreState::STATUS_PROTECTED, $states[3]->status);
	}

	public function testUserCanProtectDefaultsToEveryone(): void {
		$this->assertTrue($this->newService()->userCanProtect(self::USER));
	}

	public function testUserCanProtectHonoursAllowedGroups(): void {
		$service = $this->newService([ConfigService::KEY_ALLOWED_GROUPS => '["drm-users"]']);
		$this->assertFalse($service->userCanProtect(self::USER));

		$this->userGroups[] = 'drm-users';
		$this->assertTrue($service->userCanProtect(self::USER));
	}

	public function testUserCanUnprotectDefaultsToNobody(): void {
		$service = $this->newService([ConfigService::KEY_UNPROTECT_GROUPS => '[]']);
		$this->assertFalse($service->userCanUnprotect(self::USER));
	}

	public function testUserCanUnprotectHonoursUnprotectGroups(): void {
		$this->assertTrue($this->newService()->userCanUnprotect(self::USER));

		$this->userGroups = [];
		$this->assertFalse($this->newService()->userCanUnprotect(self::USER));
	}

	// ---- queued jobs ---------------------------------------------------------

	public function testRunQueuedProtectDoesNothingWithoutAStateRow(): void {
		$this->noStateRow();
		$this->mapper->expects($this->never())->method('delete');
		$this->client->expects($this->never())->method('protect');

		$this->newService()->runQueuedProtect(self::USER, self::FILE_ID);
	}

	public function testRunQueuedProtectDropsTheRowWhenTheFileWasDeleted(): void {
		$state = $this->stateRow(SecloreState::STATUS_PENDING);
		$this->mapper->method('findByFileId')->willReturn($state);
		$this->serveFile(null);
		$this->mapper->expects($this->once())->method('delete')->with($state);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->newService()->runQueuedProtect(self::USER, self::FILE_ID);
	}

	public function testRunQueuedProtectReschedulesTransientFailures(): void {
		$this->serveFile($this->mockFile());
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PENDING));
		$this->client->method('protect')->willThrowException(new SecloreApiException('gateway timeout', true));

		$this->jobList->expects($this->once())
			->method('scheduleAfter')
			->with(ProtectJob::class, self::NOW + 300, ['fileId' => self::FILE_ID, 'userId' => self::USER]);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->newService()->runQueuedProtect(self::USER, self::FILE_ID);

		$final = end($this->updatedStates);
		$this->assertSame(SecloreState::STATUS_PENDING, $final->getStatus(), 'The row must be pending again for the retry');
	}

	public function testRunQueuedProtectNotifiesWhenRetriesAreExhausted(): void {
		$this->serveFile($this->mockFile());
		$this->mapper->method('findByFileId')->willReturn($this->stateRow(SecloreState::STATUS_PENDING, attempts: 2));
		$this->client->method('protect')->willThrowException(new SecloreApiException('gateway timeout', true));

		$this->jobList->expects($this->never())->method('scheduleAfter');
		$this->notificationManager->expects($this->once())->method('notify');

		$this->newService()->runQueuedProtect(self::USER, self::FILE_ID);

		$final = end($this->updatedStates);
		$this->assertSame(SecloreState::STATUS_FAILED, $final->getStatus());
		$this->assertSame('gateway timeout', $final->getLastError());
	}
}
