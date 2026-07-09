<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/**
 * Caller fails the group gate (`not_allowed`) or lacks PERMISSION_UPDATE on
 * the node (`no_permission`) — SDD §8.2, Appendix B.
 */
class NotAllowedException extends ProtectionException {
	public function __construct(string $message, string $errorCode = 'not_allowed') {
		parent::__construct($message, 403, $errorCode);
	}
}
