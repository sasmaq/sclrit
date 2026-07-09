<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/** The integration has not been configured yet (SDD Appendix A). */
class NotConfiguredException extends SecloreApiException {
	public function __construct(
		string $message = 'The Seclore integration is not configured yet — base URL, app ID and secret are required',
		?\Throwable $previous = null,
	) {
		parent::__construct($message, false, $previous);
	}
}
