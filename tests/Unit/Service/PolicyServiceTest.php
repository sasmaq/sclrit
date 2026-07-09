<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Tests\Unit\Service;

use OCA\Sclrit\Service\ConfigService;
use OCA\Sclrit\Service\PolicyService;
use OCA\Sclrit\Tests\Unit\AppConfigMockTrait;
use PHPUnit\Framework\TestCase;

class PolicyServiceTest extends TestCase {
	use AppConfigMockTrait;

	private function newService(): PolicyService {
		return new PolicyService($this->createConfigService([
			ConfigService::KEY_POLICIES => json_encode([
				['id' => 'hf1', 'name' => 'Confidential'],
				['id' => 'hf2', 'name' => 'Restricted', 'description' => 'Board only'],
			]),
		]));
	}

	public function testGetPoliciesReturnsTheConfiguredList(): void {
		$policies = $this->newService()->getPolicies();
		$this->assertCount(2, $policies);
		$this->assertSame(['hf1', 'hf2'], array_map(static fn ($p) => $p->id, $policies));
	}

	public function testFindReturnsTheMatchingPolicy(): void {
		$policy = $this->newService()->find('hf2');
		$this->assertNotNull($policy);
		$this->assertSame('Restricted', $policy->name);
		$this->assertSame('Board only', $policy->description);
	}

	public function testFindReturnsNullForAnUnknownId(): void {
		$this->assertNull($this->newService()->find('nope'));
	}

	public function testExists(): void {
		$service = $this->newService();
		$this->assertTrue($service->exists('hf1'));
		$this->assertFalse($service->exists('nope'));
	}
}
