<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Service\Dto;

/** Result of the optional protection probe (SDD §9 E4, §15 Q6). */
final class SecloreFileInfo {
	public function __construct(
		public readonly bool $isProtected,
		public readonly ?string $secloreFileId = null,
		public readonly ?string $hotFolderId = null,
	) {
	}
}
