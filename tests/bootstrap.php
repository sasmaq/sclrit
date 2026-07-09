<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/stubs.php';

// nextcloud/ocp declares no composer autoload section (it targets static
// analysis); at runtime the classes are provided by the Nextcloud server.
// Map the OCP/NCU namespaces onto the package so unit tests can mock them.
spl_autoload_register(static function (string $class): void {
	if (!str_starts_with($class, 'OCP\\') && !str_starts_with($class, 'NCU\\')) {
		return;
	}
	$path = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});
