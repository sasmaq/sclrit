<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Tests\Unit\Service;

use OCA\FilesSeclore\AppInfo\Application;
use OCA\FilesSeclore\Exceptions\SecloreUnavailableException;
use OCA\FilesSeclore\Service\Dto\Credentials;
use OCA\FilesSeclore\Service\Dto\Token;
use OCA\FilesSeclore\Service\TokenStore;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TokenStoreTest extends TestCase {
	private Credentials $credentials;

	protected function setUp(): void {
		$this->credentials = new Credentials('https://drm.example.com', 'tenant-1', 's3cret');
	}

	private function tokenKey(): string {
		return 'token/' . $this->credentials->cacheKey();
	}

	/** @param ICache&MockObject $cache */
	private function newStore(ICache $cache): TokenStore {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->expects($this->once())
			->method('createDistributed')
			->with(Application::APP_ID)
			->willReturn($cache);
		return new TokenStore($cacheFactory, new NullLogger());
	}

	public function testReturnsCachedTokenWithoutAuthenticating(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->with($this->tokenKey())->willReturn('cached-token');

		$token = $this->newStore($cache)->getToken($this->credentials, function (): Token {
			$this->fail('authenticate must not be called when the token is cached');
		});

		$this->assertSame('cached-token', $token);
	}

	public function testAuthenticatesOnCacheMissAndCachesWithSafetyMargin(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willReturn(null);
		$cache->method('add')->with($this->tokenKey() . '/lock', 1, 10)->willReturn(true);
		$cache->expects($this->once())
			->method('set')
			->with($this->tokenKey(), 'fresh-token', 600 - 60)
			->willReturn(true);
		$cache->expects($this->once())
			->method('remove')
			->with($this->tokenKey() . '/lock')
			->willReturn(true);

		$token = $this->newStore($cache)->getToken(
			$this->credentials,
			static fn (): Token => new Token('fresh-token', 600),
		);

		$this->assertSame('fresh-token', $token);
	}

	public function testTtlNeverDropsBelowTheSafetyMargin(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willReturn(null);
		$cache->method('add')->willReturn(true);
		$cache->expects($this->once())
			->method('set')
			->with($this->tokenKey(), 'short-lived', 60)
			->willReturn(true);

		$this->newStore($cache)->getToken(
			$this->credentials,
			static fn (): Token => new Token('short-lived', 30),
		);
	}

	public function testLockIsReleasedWhenAuthenticationFails(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->method('get')->willReturn(null);
		$cache->method('add')->willReturn(true);
		$cache->expects($this->never())->method('set');
		$cache->expects($this->once())
			->method('remove')
			->with($this->tokenKey() . '/lock')
			->willReturn(true);

		$this->expectException(SecloreUnavailableException::class);
		$this->newStore($cache)->getToken($this->credentials, static function (): Token {
			throw new SecloreUnavailableException('down');
		});
	}

	public function testWorksWithoutAnAtomicCache(): void {
		// A plain ICache has no add(): locking is skipped, authentication still happens.
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())
			->method('set')
			->with($this->tokenKey(), 'fresh-token', 540)
			->willReturn(true);

		$token = $this->newStore($cache)->getToken(
			$this->credentials,
			static fn (): Token => new Token('fresh-token', 600),
		);

		$this->assertSame('fresh-token', $token);
	}

	public function testInvalidateRemovesTheCachedToken(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->once())
			->method('remove')
			->with($this->tokenKey())
			->willReturn(true);

		$this->newStore($cache)->invalidate($this->credentials);
	}
}
