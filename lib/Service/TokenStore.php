<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service;

use OCA\Sclrit\AppInfo\Application;
use OCA\Sclrit\Service\Dto\Credentials;
use OCA\Sclrit\Service\Dto\Token;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Session-token cache shared across web and cron workers (SDD §7.2).
 *
 * Tokens are cached with TTL = expiresIn − 60 s. Refresh is serialised with a
 * best-effort cache lock so concurrent workers don't stampede the Policy
 * Server with re-authentication requests.
 */
final class TokenStore {
	private const TTL_SAFETY_MARGIN_S = 60;
	private const LOCK_TTL_S = 10;
	private const LOCK_POLL_MS = 200;
	private const LOCK_MAX_WAIT_MS = 5000;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * @param \Closure(): Token $authenticate performs the actual authentication round-trip
	 */
	public function getToken(Credentials $credentials, \Closure $authenticate): string {
		$key = $this->key($credentials);

		$cached = $this->cache->get($key);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$lockKey = $key . '/lock';
		$locked = $this->tryLock($lockKey);
		if (!$locked) {
			// Another worker is refreshing: poll briefly for its result.
			$waited = 0;
			while ($waited < self::LOCK_MAX_WAIT_MS) {
				usleep(self::LOCK_POLL_MS * 1000);
				$waited += self::LOCK_POLL_MS;
				$cached = $this->cache->get($key);
				if (is_string($cached) && $cached !== '') {
					return $cached;
				}
			}
			// The other worker did not deliver in time; authenticate ourselves.
		}

		try {
			$this->logger->debug('Requesting a new Seclore session token');
			$token = $authenticate();
			$ttl = max(self::TTL_SAFETY_MARGIN_S, $token->expiresIn - self::TTL_SAFETY_MARGIN_S);
			$this->cache->set($key, $token->value, $ttl);
			return $token->value;
		} finally {
			if ($locked) {
				$this->cache->remove($lockKey);
			}
		}
	}

	public function invalidate(Credentials $credentials): void {
		$this->cache->remove($this->key($credentials));
	}

	private function key(Credentials $credentials): string {
		return 'token/' . $credentials->cacheKey();
	}

	private function tryLock(string $lockKey): bool {
		if ($this->cache instanceof IMemcache) {
			return $this->cache->add($lockKey, 1, self::LOCK_TTL_S);
		}
		// No atomic add available (no distributed memcache configured): skip
		// locking — the worst case is a redundant authentication round-trip.
		return true;
	}
}
