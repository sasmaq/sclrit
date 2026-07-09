/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, expect, it, vi } from 'vitest'
import { mount, type Wrapper } from '@vue/test-utils'
import type Vue from 'vue'
import ConfirmDialog from '../../../src/components/ConfirmDialog.vue'

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', async () => ({
	default: (await import('../nc-stubs')).NcButtonStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcDialog.js', async () => ({
	default: (await import('../nc-stubs')).NcDialogStub,
}))

const mountDialog = (props: Record<string, unknown> = {}): Wrapper<Vue> =>
	mount(ConfirmDialog, {
		propsData: {
			name: 'Remove protection',
			message: 'Remove Seclore protection from "report.pdf"?',
			confirmLabel: 'Remove protection',
			...props,
		},
	})

const buttons = (wrapper: Wrapper<Vue>) => {
	const all = wrapper.findAll('button.nc-button')
	return { cancel: all.at(0), confirm: all.at(1) }
}

describe('ConfirmDialog', () => {
	it('renders the message and the labels', () => {
		const wrapper = mountDialog()

		expect(wrapper.text()).toContain('Remove Seclore protection from "report.pdf"?')
		expect(buttons(wrapper).cancel.text()).toBe('Cancel')
		expect(buttons(wrapper).confirm.text()).toBe('Remove protection')
	})

	it('emits close(true) on confirm', async () => {
		const wrapper = mountDialog()

		await buttons(wrapper).confirm.trigger('click')

		expect(wrapper.emitted('close')).toEqual([[true]])
	})

	it('emits close(false) on cancel', async () => {
		const wrapper = mountDialog()

		await buttons(wrapper).cancel.trigger('click')

		expect(wrapper.emitted('close')).toEqual([[false]])
	})

	it('emits close only once', async () => {
		const wrapper = mountDialog()

		await buttons(wrapper).confirm.trigger('click')
		await buttons(wrapper).confirm.trigger('click')
		await buttons(wrapper).cancel.trigger('click')

		expect(wrapper.emitted('close')).toEqual([[true]])
	})

	it('treats closing the dialog as cancel', () => {
		const wrapper = mountDialog()

		;(wrapper.vm as unknown as { onOpenChanged(open: boolean): void }).onOpenChanged(false)

		expect(wrapper.emitted('close')).toEqual([[false]])
	})

	it('styles the confirm button as destructive when asked to', () => {
		expect(buttons(mountDialog({ destructive: true })).confirm.attributes('data-type')).toBe('error')
		expect(buttons(mountDialog()).confirm.attributes('data-type')).toBe('primary')
	})
})
