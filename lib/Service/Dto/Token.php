<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service\Dto;

/** Short-lived session token issued by the Policy Server (SDD §7.2). */
final class Token {
	public function __construct(
		public readonly string $value,
		public readonly int $expiresIn,
	) {
	}
}
