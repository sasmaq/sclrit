<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service\Dto;

/** A Seclore protection policy container (SDD §1.3). */
final class HotFolder {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description = '',
	) {
	}
}
