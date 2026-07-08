<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Exceptions;

/** The Policy Server rejected the file for exceeding its size limit (SDD §15 Q4). */
class FileTooLargeException extends SecloreApiException {
	public function __construct(
		string $message = 'The Seclore Policy Server rejected the file as too large',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, false, $previous);
	}
}
