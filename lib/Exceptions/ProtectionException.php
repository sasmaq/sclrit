<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/**
 * Base class for protect/unprotect request failures that map directly to an
 * OCS error response (SDD Appendix B). Messages are user-safe.
 */
abstract class ProtectionException extends \Exception {
	public function __construct(
		string $message,
		private readonly int $httpStatus,
		private readonly string $errorCode,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
