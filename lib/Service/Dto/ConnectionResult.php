<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service\Dto;

/** Result of a connection test (SDD §4.3 admin endpoint, occ files_seclore:test). */
final class ConnectionResult {
	public function __construct(
		public readonly bool $ok,
		public readonly ?int $policyCount = null,
		public readonly ?string $error = null,
	) {
	}
}
