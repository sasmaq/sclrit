/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Promise wrappers around spawned dialogs (SDD §5.2).
 */
import { showError, spawnDialog } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { fetchPolicies, ocsErrorMessage } from './api'
import ConfirmDialog from './components/ConfirmDialog.vue'
import PolicyPicker from './components/PolicyPicker.vue'

const LAST_POLICY_KEY = 'files_seclore:last-policy'

/**
 * Open the policy picker and resolve with the chosen Hot Folder id, or null
 * when the user cancelled (or no policies could be loaded). The last choice is
 * remembered as a convenience, never as authorization (SDD §5.2).
 */
export async function pickPolicy(fileCount: number): Promise<string | null> {
	let policyList
	try {
		policyList = await fetchPolicies()
	} catch (error) {
		showError(t('files_seclore', 'Could not load the Seclore policies: {message}', { message: ocsErrorMessage(error) }))
		return null
	}
	if (policyList.policies.length === 0) {
		showError(t('files_seclore', 'No Seclore policies are available.'))
		return null
	}

	const lastChoice = window.localStorage.getItem(LAST_POLICY_KEY)
	const preselectedId = policyList.policies.some((p) => p.id === lastChoice)
		? lastChoice!
		: policyList.defaultId

	return await new Promise((resolve) => {
		spawnDialog(PolicyPicker, {
			policies: policyList.policies,
			preselectedId,
			fileCount,
		}, (result?: unknown) => {
			if (typeof result === 'string' && result !== '') {
				window.localStorage.setItem(LAST_POLICY_KEY, result)
				resolve(result)
			} else {
				resolve(null)
			}
		})
	})
}

/** Explicit confirmation dialog; resolves with the user's decision. */
export async function confirmDialog(name: string, message: string, confirmLabel: string): Promise<boolean> {
	return await new Promise((resolve) => {
		spawnDialog(ConfirmDialog, {
			name,
			message,
			confirmLabel,
			destructive: true,
		}, (result?: unknown) => {
			resolve(result === true)
		})
	})
}
