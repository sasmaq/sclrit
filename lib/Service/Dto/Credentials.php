<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service\Dto;

/** Connection settings for the Seclore Policy Server (SDD Appendix A). */
final class Credentials {
	public function __construct(
		public readonly string $baseUrl,
		public readonly string $appId,
		public readonly string $appSecret,
		public readonly bool $verifyTls = true,
	) {
	}

	/** Stable cache-key component that never exposes the secret. */
	public function cacheKey(): string {
		return sha1($this->baseUrl . '|' . $this->appId);
	}
}
