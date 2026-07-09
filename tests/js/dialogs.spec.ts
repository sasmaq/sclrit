import { showError, spawnDialog } from '@nextcloud/dialogs'
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ConfirmDialog from '../../src/components/ConfirmDialog.vue'
import PolicyPicker from '../../src/components/PolicyPicker.vue'
import { fetchPolicies } from '../../src/api'
import { confirmDialog, pickPolicy } from '../../src/dialogs'

vi.mock('../../src/api', () => ({
	fetchPolicies: vi.fn(),
	ocsErrorMessage: (error: unknown) => (error instanceof Error ? error.message : String(error)),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	spawnDialog: vi.fn(),
}))

// The dialog components themselves are covered by their own specs.
vi.mock('../../src/components/PolicyPicker.vue', () => ({ default: { name: 'PolicyPicker' } }))
vi.mock('../../src/components/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog' } }))

const LAST_POLICY_KEY = 'sclrit:last-policy'

const policyList = {
	policies: [
		{ id: 'hf-1', name: 'Confidential', description: '' },
		{ id: 'hf-2', name: 'Internal', description: '' },
	],
	defaultId: 'hf-2',
}

/** The (props, callback) the picker was spawned with. */
function spawnedPicker() {
	const call = vi.mocked(spawnDialog).mock.calls.at(-1)!
	return { props: call[1] as Record<string, unknown>, callback: call[2] as (result?: unknown) => void }
}

beforeEach(() => {
	vi.mocked(fetchPolicies).mockResolvedValue(policyList)
})

describe('pickPolicy', () => {
	it('spawns the picker with the default policy preselected', async () => {
		const promise = pickPolicy(2)
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())

		const { props, callback } = spawnedPicker()
		expect(vi.mocked(spawnDialog).mock.calls[0][0]).toBe(PolicyPicker)
		expect(props).toMatchObject({
			policies: policyList.policies,
			preselectedId: 'hf-2',
			fileCount: 2,
		})

		callback('hf-1')
		expect(await promise).toBe('hf-1')
	})

	it('remembers the confirmed choice for the next picker', async () => {
		const promise = pickPolicy(1)
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())

		spawnedPicker().callback('hf-1')
		await promise

		expect(window.localStorage.getItem(LAST_POLICY_KEY)).toBe('hf-1')

		const second = pickPolicy(1)
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalledTimes(2))
		expect(spawnedPicker().props.preselectedId).toBe('hf-1')
		spawnedPicker().callback()
		await second
	})

	it('ignores a remembered policy that no longer exists', async () => {
		window.localStorage.setItem(LAST_POLICY_KEY, 'hf-gone')

		const promise = pickPolicy(1)
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())

		expect(spawnedPicker().props.preselectedId).toBe('hf-2')
		spawnedPicker().callback()
		await promise
	})

	it('resolves null on cancel without storing anything', async () => {
		const promise = pickPolicy(1)
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())

		spawnedPicker().callback(undefined)

		expect(await promise).toBeNull()
		expect(window.localStorage.getItem(LAST_POLICY_KEY)).toBeNull()
	})

	it('shows an error and resolves null when the policies cannot be loaded', async () => {
		vi.mocked(fetchPolicies).mockRejectedValue(new Error('Policy Server unreachable'))

		expect(await pickPolicy(1)).toBeNull()
		expect(showError).toHaveBeenCalledWith(expect.stringContaining('Policy Server unreachable'))
		expect(spawnDialog).not.toHaveBeenCalled()
	})

	it('shows an error and resolves null when no policies exist', async () => {
		vi.mocked(fetchPolicies).mockResolvedValue({ policies: [], defaultId: '' })

		expect(await pickPolicy(1)).toBeNull()
		expect(showError).toHaveBeenCalled()
		expect(spawnDialog).not.toHaveBeenCalled()
	})
})

describe('confirmDialog', () => {
	it('spawns the confirm dialog and resolves with the decision', async () => {
		const promise = confirmDialog('Remove protection', 'Really?', 'Remove protection')
		await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())

		const [component, props, callback] = vi.mocked(spawnDialog).mock.calls[0]
		expect(component).toBe(ConfirmDialog)
		expect(props).toMatchObject({
			name: 'Remove protection',
			message: 'Really?',
			confirmLabel: 'Remove protection',
			destructive: true,
		})

		;(callback as (result?: unknown) => void)(true)
		expect(await promise).toBe(true)
	})

	it('resolves false for anything but an explicit true', async () => {
		for (const result of [false, undefined, 'yes']) {
			vi.mocked(spawnDialog).mockClear()
			const promise = confirmDialog('a', 'b', 'c')
			await vi.waitFor(() => expect(spawnDialog).toHaveBeenCalled())
			;(vi.mocked(spawnDialog).mock.calls[0][2] as (result?: unknown) => void)(result)
			expect(await promise).toBe(false)
		}
	})
})
