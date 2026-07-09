/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type Wrapper } from '@vue/test-utils'
import type Vue from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import SecloreTab from '../../../src/components/SecloreTab.vue'
import { fetchStates, protectFile, retryFile, unprotectFile, type ProtectionState, type ProtectionStatus } from '../../../src/api'
import { confirmDialog, pickPolicy } from '../../../src/dialogs'

const capabilities = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))

vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: () => ({ sclrit: capabilities.value }),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('../../../src/api', () => ({
	fetchStates: vi.fn(),
	protectFile: vi.fn(),
	retryFile: vi.fn(),
	unprotectFile: vi.fn(),
	ocsErrorMessage: (error: unknown) => (error instanceof Error ? error.message : String(error)),
}))

vi.mock('../../../src/dialogs', () => ({
	pickPolicy: vi.fn(),
	confirmDialog: vi.fn(),
}))

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', async () => ({
	default: (await import('../nc-stubs')).NcButtonStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcEmptyContent.js', async () => ({
	default: (await import('../nc-stubs')).NcEmptyContentStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', async () => ({
	default: (await import('../nc-stubs')).NcLoadingIconStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', async () => ({
	default: (await import('../nc-stubs')).NcNoteCardStub,
}))

const PERMISSION_READ = 1
const PERMISSION_UPDATE = 2

const state = (overrides: Partial<ProtectionState> = {}): ProtectionState => ({
	fileId: 42,
	status: 'protected' as ProtectionStatus,
	hotFolderId: 'hf-1',
	policyName: 'Confidential',
	secloreFileId: 'sf-9',
	requestedBy: 'alice',
	updatedAt: 1700000000,
	error: null,
	...overrides,
})

interface TabVm {
	setFileInfo(fileInfo: { id: number, name: string, permissions?: number }): void
	state: ProtectionState | null
}

const flush = () => new Promise((resolve) => setTimeout(resolve))

/** Mount the tab and show the given file, waiting for the initial load. */
async function openTab(
	wrapper: Wrapper<Vue>,
	fileInfo: { id: number, name: string, permissions?: number } = { id: 42, name: 'report.pdf', permissions: PERMISSION_READ | PERMISSION_UPDATE },
): Promise<void> {
	(wrapper.vm as unknown as TabVm).setFileInfo(fileInfo)
	await flush()
	await wrapper.vm.$nextTick()
}

const buttonByText = (wrapper: Wrapper<Vue>, text: string) =>
	wrapper.findAll('button.nc-button').wrappers.find((button) => button.text() === text)

beforeEach(() => {
	capabilities.value = { canProtect: true, canUnprotect: true }
	vi.mocked(fetchStates).mockResolvedValue({})
})

describe('SecloreTab', () => {
	it('shows "not protected" with a protect action when no state row exists', async () => {
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(fetchStates).toHaveBeenCalledWith([42])
		expect(wrapper.find('.nc-empty-content').attributes('data-name')).toBe('Not protected')
		expect(buttonByText(wrapper, 'Protect with Seclore')).toBeTruthy()
	})

	it('hides the protect action without update permission or capability', async () => {
		const wrapper = mount(SecloreTab)
		await openTab(wrapper, { id: 42, name: 'report.pdf', permissions: PERMISSION_READ })
		expect(buttonByText(wrapper, 'Protect with Seclore')).toBeUndefined()

		capabilities.value = { canProtect: false, canUnprotect: true }
		const restricted = mount(SecloreTab)
		await openTab(restricted)
		expect(buttonByText(restricted, 'Protect with Seclore')).toBeUndefined()
	})

	it('shows details and the remove action for a protected file', async () => {
		vi.mocked(fetchStates).mockResolvedValue({ 42: state() })
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(wrapper.find('.nc-note-card').attributes('data-type')).toBe('success')
		expect(wrapper.text()).toContain('Confidential')
		expect(wrapper.text()).toContain('alice')
		expect(wrapper.text()).toContain('sf-9')
		expect(buttonByText(wrapper, 'Remove protection')).toBeTruthy()
	})

	it('hides the remove action without the canUnprotect capability', async () => {
		capabilities.value = { canProtect: true, canUnprotect: false }
		vi.mocked(fetchStates).mockResolvedValue({ 42: state() })
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(buttonByText(wrapper, 'Remove protection')).toBeUndefined()
	})

	it('shows the error and a retry action for a failed request', async () => {
		vi.mocked(fetchStates).mockResolvedValue({
			42: state({ status: 'failed', error: 'Hot Folder not found' }),
		})
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(wrapper.find('.nc-note-card').attributes('data-type')).toBe('error')
		expect(wrapper.text()).toContain('Hot Folder not found')
		expect(buttonByText(wrapper, 'Retry')).toBeTruthy()
	})

	it('shows a queued notice for pending work without any actions', async () => {
		vi.mocked(fetchStates).mockResolvedValue({ 42: state({ status: 'pending' }) })
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(wrapper.find('.nc-note-card').attributes('data-type')).toBe('info')
		expect(wrapper.findAll('button.nc-button')).toHaveLength(0)
	})

	it('shows the load error when the status lookup fails', async () => {
		vi.mocked(fetchStates).mockRejectedValue(new Error('Backend unavailable'))
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		expect(wrapper.find('.nc-note-card').attributes('data-type')).toBe('error')
		expect(wrapper.text()).toContain('Backend unavailable')
	})

	it('ignores a stale response after switching to another file', async () => {
		let resolveFirst!: (states: Record<number, ProtectionState>) => void
		vi.mocked(fetchStates)
			.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve }))
			.mockResolvedValueOnce({ 43: state({ fileId: 43, status: 'none' }) })

		const wrapper = mount(SecloreTab)
		const vm = wrapper.vm as unknown as TabVm
		vm.setFileInfo({ id: 42, name: 'a.pdf', permissions: 3 })
		vm.setFileInfo({ id: 43, name: 'b.pdf', permissions: 3 })
		await flush()

		resolveFirst({ 42: state() })
		await flush()

		expect(vm.state?.fileId).toBe(43)
	})

	it('protects the file with the picked policy and reloads', async () => {
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		vi.mocked(pickPolicy).mockResolvedValue('hf-1')
		vi.mocked(protectFile).mockResolvedValue(state())
		vi.mocked(fetchStates).mockResolvedValue({ 42: state() })

		await buttonByText(wrapper, 'Protect with Seclore')!.trigger('click')
		await flush()

		expect(protectFile).toHaveBeenCalledWith(42, 'hf-1')
		expect(showSuccess).toHaveBeenCalled()
		expect(fetchStates).toHaveBeenCalledTimes(2)
	})

	it('does not protect when the policy picker is cancelled', async () => {
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		vi.mocked(pickPolicy).mockResolvedValue(null)
		await buttonByText(wrapper, 'Protect with Seclore')!.trigger('click')
		await flush()

		expect(protectFile).not.toHaveBeenCalled()
	})

	it('shows the backend error when protecting fails', async () => {
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		vi.mocked(pickPolicy).mockResolvedValue('hf-1')
		vi.mocked(protectFile).mockRejectedValue(new Error('File is too large'))

		await buttonByText(wrapper, 'Protect with Seclore')!.trigger('click')
		await flush()

		expect(showError).toHaveBeenCalledWith('File is too large')
	})

	it('unprotects only after confirmation', async () => {
		vi.mocked(fetchStates).mockResolvedValue({ 42: state() })
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		vi.mocked(confirmDialog).mockResolvedValue(false)
		await buttonByText(wrapper, 'Remove protection')!.trigger('click')
		await flush()
		expect(unprotectFile).not.toHaveBeenCalled()

		vi.mocked(confirmDialog).mockResolvedValue(true)
		vi.mocked(unprotectFile).mockResolvedValue(state({ status: 'none' }))
		await buttonByText(wrapper, 'Remove protection')!.trigger('click')
		await flush()

		expect(unprotectFile).toHaveBeenCalledWith(42)
		expect(showSuccess).toHaveBeenCalled()
	})

	it('retries a failed request and reloads the state', async () => {
		vi.mocked(fetchStates).mockResolvedValue({ 42: state({ status: 'failed', error: 'boom' }) })
		const wrapper = mount(SecloreTab)
		await openTab(wrapper)

		vi.mocked(retryFile).mockResolvedValue(state({ status: 'pending' }))
		vi.mocked(fetchStates).mockClear()
		vi.mocked(fetchStates).mockResolvedValue({ 42: state({ status: 'pending' }) })

		await buttonByText(wrapper, 'Retry')!.trigger('click')
		await flush()

		expect(retryFile).toHaveBeenCalledWith(42)
		expect(fetchStates).toHaveBeenCalledWith([42])
	})
})
