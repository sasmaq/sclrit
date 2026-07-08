<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Exceptions;

/** Network error or timeout reaching the Policy Server — always retryable. */
class SecloreUnavailableException extends SecloreApiException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, true, $previous);
	}
}
