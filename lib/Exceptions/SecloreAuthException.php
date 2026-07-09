<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/** Authentication rejected after one token refresh (SDD §7.2) — admin-facing. */
class SecloreAuthException extends SecloreApiException {
	public function __construct(
		string $message = 'Seclore authentication failed — check the app ID and secret',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, false, $previous);
	}
}
