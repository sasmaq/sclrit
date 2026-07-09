<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/** Unprotect/retry requested for a file that is not in the required state. */
class NotProtectedException extends ProtectionException {
	public function __construct(
		string $message = 'This file is not protected with Seclore',
		string $errorCode = 'not_protected',
	) {
		parent::__construct($message, 409, $errorCode);
	}
}
