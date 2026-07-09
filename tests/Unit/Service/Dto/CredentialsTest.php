<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Tests\Unit\Service\Dto;

use OCA\FilesSeclore\Service\Dto\Credentials;
use PHPUnit\Framework\TestCase;

class CredentialsTest extends TestCase {
	public function testCacheKeyIsStableAndIgnoresTheSecret(): void {
		$a = new Credentials('https://drm.example.com', 'tenant-1', 'secret-a');
		$b = new Credentials('https://drm.example.com', 'tenant-1', 'secret-b', false);
		$this->assertSame($a->cacheKey(), $b->cacheKey());
	}

	public function testCacheKeyNeverContainsTheSecret(): void {
		$credentials = new Credentials('https://drm.example.com', 'tenant-1', 'super-secret');
		$this->assertStringNotContainsString('super-secret', $credentials->cacheKey());
	}

	public function testCacheKeyDiffersByBaseUrlAndAppId(): void {
		$base = new Credentials('https://drm.example.com', 'tenant-1', 's');
		$otherUrl = new Credentials('https://other.example.com', 'tenant-1', 's');
		$otherTenant = new Credentials('https://drm.example.com', 'tenant-2', 's');
		$this->assertNotSame($base->cacheKey(), $otherUrl->cacheKey());
		$this->assertNotSame($base->cacheKey(), $otherTenant->cacheKey());
	}
}
