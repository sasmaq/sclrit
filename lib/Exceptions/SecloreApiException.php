<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Exceptions;

/**
 * Base class for Seclore integration failures. The error-mapping table lives
 * in SDD §7.4; $retryable drives the background-job retry policy (SDD §4.2).
 */
class SecloreApiException extends \Exception {
	public function __construct(
		string $message,
		private readonly bool $retryable = false,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function isRetryable(): bool {
		return $this->retryable;
	}
}
