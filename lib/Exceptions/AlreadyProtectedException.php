<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/** The file is already protected (SDD §9 E3). Re-protection is a v2 feature. */
class AlreadyProtectedException extends ProtectionException {
	public function __construct(string $message = 'This file is already protected with Seclore') {
		parent::__construct($message, 409, 'already_protected');
	}
}
