<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesSeclore\Settings;

use OCA\FilesSeclore\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings panel in the Security section (SDD §4.6). The template is an
 * empty mount point: all data flows through the admin OCS endpoints, so
 * nothing sensitive is embedded in the page at render time (SDD §5.4).
 */
class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 55;
	}
}
