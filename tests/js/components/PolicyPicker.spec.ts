import type { Wrapper } from '@vue/test-utils'
import type Vue from 'vue'
import type { Policy } from '../../../src/api'

import { mount } from '@vue/test-utils'
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import PolicyPicker from '../../../src/components/PolicyPicker.vue'

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', async () => ({
	default: (await import('../nc-stubs')).NcButtonStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcDialog.js', async () => ({
	default: (await import('../nc-stubs')).NcDialogStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcSelect.js', async () => ({
	default: (await import('../nc-stubs')).NcSelectStub,
}))

const policies: Policy[] = [
	{ id: 'hf-1', name: 'Confidential', description: 'View only, no printing' },
	{ id: 'hf-2', name: 'Internal', description: '' },
]

interface PickerVm {
	selected: Policy | null
	confirm(): void
	cancel(): void
	onOpenChanged(open: boolean): void
}

function mountPicker(props: Record<string, unknown> = {}): Wrapper<Vue> {
	return mount(PolicyPicker, {
		propsData: { policies, ...props },
	})
}

const vm = (wrapper: Wrapper<Vue>): PickerVm => wrapper.vm as unknown as PickerVm

const confirmButton = (wrapper: Wrapper<Vue>) => wrapper.findAll('button.nc-button').at(1)

describe('PolicyPicker', () => {
	describe('preselection', () => {
		it('preselects the policy matching preselectedId', () => {
			const wrapper = mountPicker({ preselectedId: 'hf-2' })

			expect(vm(wrapper).selected?.id).toBe('hf-2')
		})

		it('falls back to the first policy for an unknown id', () => {
			expect(vm(mountPicker({ preselectedId: 'hf-gone' })).selected?.id).toBe('hf-1')
			expect(vm(mountPicker()).selected?.id).toBe('hf-1')
		})

		it('selects nothing when the list is empty and disables confirm', () => {
			const wrapper = mountPicker({ policies: [] })

			expect(vm(wrapper).selected).toBeNull()
			expect(confirmButton(wrapper).attributes('disabled')).toBeTruthy()
		})
	})

	it('shows the description of the selected policy', async () => {
		const wrapper = mountPicker({ preselectedId: 'hf-1' })
		expect(wrapper.text()).toContain('View only, no printing')

		await wrapper.setData({ selected: policies[1] })
		expect(wrapper.text()).not.toContain('View only, no printing')
	})

	it('pluralizes the confirm label with the file count', () => {
		expect(confirmButton(mountPicker({ fileCount: 1 })).text()).toBe('Protect 1 file')
		expect(confirmButton(mountPicker({ fileCount: 3 })).text()).toBe('Protect 3 files')
	})

	it('emits close with the selected policy id on confirm', async () => {
		const wrapper = mountPicker({ preselectedId: 'hf-2' })

		await confirmButton(wrapper).trigger('click')

		expect(wrapper.emitted('close')).toEqual([['hf-2']])
	})

	it('emits close without payload on cancel', async () => {
		const wrapper = mountPicker()

		await wrapper.findAll('button.nc-button').at(0).trigger('click')

		expect(wrapper.emitted('close')).toEqual([[]])
	})

	it('treats closing the dialog as cancel, and closes only once', async () => {
		const wrapper = mountPicker()

		vm(wrapper).onOpenChanged(false)
		await confirmButton(wrapper).trigger('click')

		expect(wrapper.emitted('close')).toEqual([[]])
	})
})
