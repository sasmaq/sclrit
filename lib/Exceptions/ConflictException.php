<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/**
 * The file changed between the pre-protect ETag snapshot and the write-back
 * (SDD decision D6, §9 E1). The original file is untouched.
 */
class ConflictException extends ProtectionException {
	public function __construct(string $message = 'The file was modified while it was being protected — please try again') {
		parent::__construct($message, 409, 'conflict');
	}
}
