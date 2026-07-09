<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/**
 * The Policy Server answered with an unexpected shape — likely an API version
 * mismatch. Points back at the contract confirmation task (SDD §7.3, §15 Q1).
 */
class SecloreProtocolException extends SecloreApiException {
	public function __construct(string $message, ?\Throwable $previous = null) {
		parent::__construct($message, false, $previous);
	}
}
