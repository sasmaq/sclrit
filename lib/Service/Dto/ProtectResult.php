<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Service\Dto;

/**
 * Outcome of a successful protect call. The caller owns $tempPath and must
 * delete it after the write-back (SDD §4.1 step 9).
 */
final class ProtectResult {
	public function __construct(
		public readonly string $secloreFileId,
		public readonly string $tempPath,
		public readonly int $sizeBytes,
		public readonly string $requestId,
	) {
	}
}
