<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit\Service\Dto;

use OCA\Sclrit\Db\SecloreState;
use OCA\Sclrit\Service\Dto\ProtectionState;
use PHPUnit\Framework\TestCase;

class ProtectionStateTest extends TestCase {
	public function testNoneReportsStatusNone(): void {
		$state = ProtectionState::none(42);
		$this->assertSame(42, $state->fileId);
		$this->assertSame(ProtectionState::STATUS_NONE, $state->status);
		$this->assertNull($state->hotFolderId);
		$this->assertNull($state->error);
	}

	public function testFromEntityProjectsAllFields(): void {
		$entity = new SecloreState();
		$entity->setFileId(42);
		$entity->setStatus(SecloreState::STATUS_FAILED);
		$entity->setHotFolderId('hf1');
		$entity->setPolicyName('Confidential');
		$entity->setSecloreFileId('sf-9');
		$entity->setRequestedBy('alice');
		$entity->setUpdatedAt(1720000000);
		$entity->setLastError('boom');

		$state = ProtectionState::fromEntity($entity);

		$this->assertSame(42, $state->fileId);
		$this->assertSame(SecloreState::STATUS_FAILED, $state->status);
		$this->assertSame('hf1', $state->hotFolderId);
		$this->assertSame('Confidential', $state->policyName);
		$this->assertSame('sf-9', $state->secloreFileId);
		$this->assertSame('alice', $state->requestedBy);
		$this->assertSame(1720000000, $state->updatedAt);
		$this->assertSame('boom', $state->error);
	}

	public function testJsonSerializeExposesTheApiShape(): void {
		$json = ProtectionState::none(7)->jsonSerialize();
		$this->assertSame(
			['fileId', 'status', 'hotFolderId', 'policyName', 'secloreFileId', 'requestedBy', 'updatedAt', 'error'],
			array_keys($json),
		);
		$this->assertSame(7, $json['fileId']);
		$this->assertSame('none', $json['status']);
	}
}
