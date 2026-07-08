<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Exceptions;

/** The requested Hot Folder no longer exists on the Policy Server (SDD §9 E5). */
class PolicyNotFoundException extends SecloreApiException {
	public function __construct(
		string $message = 'The selected Seclore policy no longer exists',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, false, $previous);
	}
}
