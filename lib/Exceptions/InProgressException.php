<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/** A protect/unprotect request for this file is already in flight (SDD §9 E13). */
class InProgressException extends ProtectionException {
	public function __construct(string $message = 'A Seclore operation is already in progress for this file') {
		parent::__construct($message, 409, 'in_progress');
	}
}
