/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Shared test environment: the app code runs inside a Nextcloud page, so the
 * globals it reads through @nextcloud/* packages must exist before import.
 */
import { afterEach, vi } from 'vitest'

// @nextcloud/l10n falls back to these when no translation bundle is loaded.
document.documentElement.lang = 'en'

// @nextcloud/capabilities reads the capabilities from this global.
Object.assign(window, {
	OC: {
		getCurrentUser: () => ({ uid: 'test', displayName: 'Test' }),
	},
	_oc_capabilities: {},
})

afterEach(() => {
	window.localStorage.clear()
	vi.restoreAllMocks()
})
