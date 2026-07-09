<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Sclrit\Exceptions;

/**
 * The node cannot be protected: not a file, empty, in an E2EE folder, or on a
 * federated share (SDD §9 E8, §8.5, Appendix B — 422 validation failures).
 */
class UnsupportedFileException extends ProtectionException {
	public function __construct(string $message, string $errorCode) {
		parent::__construct($message, 422, $errorCode);
	}
}
