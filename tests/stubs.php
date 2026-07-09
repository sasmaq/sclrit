<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal stubs for private OC\* symbols referenced by OCP interfaces. The
 * nextcloud/ocp package ships only the public API; at runtime these are
 * provided by the Nextcloud server.
 */

namespace OC\Hooks {
	if (!interface_exists(Emitter::class)) {
		interface Emitter {
			public function listen($scope, $method, callable $callback);

			public function removeListener($scope = null, $method = null, ?callable $callback = null);
		}
	}
}

namespace OC\User {
	if (!class_exists(NoUserException::class)) {
		class NoUserException extends \Exception {
		}
	}
}
