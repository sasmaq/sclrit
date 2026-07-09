/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { Node } from '@nextcloud/files'
import { TAB_ID, openSecloreTab, registerSecloreSidebarTab } from '../../src/sidebar'

const setFileInfo = vi.hoisted(() => vi.fn())

// The tab content itself is covered by SecloreTab.spec.ts.
vi.mock('../../src/components/SecloreTab.vue', () => ({
	default: {
		name: 'SecloreTabStub',
		render: (h: (tag: string) => unknown) => h('div'),
		methods: { setFileInfo },
	},
}))

interface TabOptions {
	id: string
	name: string
	enabled(fileInfo: { type?: string } | null): boolean
	mount(el: HTMLElement, fileInfo: unknown): Promise<void>
	update(fileInfo: unknown): void
	destroy(): void
}

function installSidebar() {
	const sidebar = {
		registerTab: vi.fn(),
		setActiveTab: vi.fn(),
		open: vi.fn(),
		Tab: class {
			constructor(public options: TabOptions) {}
		},
	}
	;(window as unknown as { OCA?: object }).OCA = { Files: { Sidebar: sidebar } }
	return sidebar
}

afterEach(() => {
	delete (window as unknown as { OCA?: object }).OCA
})

describe('registerSecloreSidebarTab', () => {
	// DOMContentLoaded listeners from earlier tests stay attached to the
	// shared window, so always read the latest registration.
	const registerTab = (): TabOptions => {
		const sidebar = installSidebar()
		registerSecloreSidebarTab()
		window.dispatchEvent(new Event('DOMContentLoaded'))
		expect(sidebar.registerTab).toHaveBeenCalled()
		return (sidebar.registerTab.mock.calls.at(-1)![0] as { options: TabOptions }).options
	}

	it('registers a tab that is only enabled for files', () => {
		const tab = registerTab()

		expect(tab.id).toBe(TAB_ID)
		expect(tab.enabled({ type: 'file' })).toBe(true)
		expect(tab.enabled({ type: 'dir' })).toBe(false)
		expect(tab.enabled(null)).toBe(false)
	})

	it('mounts the tab component and forwards the file info', async () => {
		const tab = registerTab()
		const fileInfo = { id: 42, name: 'document.odt' }

		const el = document.createElement('div')
		document.body.appendChild(el)
		await tab.mount(el, fileInfo)
		expect(setFileInfo).toHaveBeenCalledWith(fileInfo)

		const updated = { id: 43, name: 'other.odt' }
		tab.update(updated)
		expect(setFileInfo).toHaveBeenLastCalledWith(updated)

		// Destroy then update again: the stale instance must not receive it.
		tab.destroy()
		tab.update(fileInfo)
		expect(setFileInfo).toHaveBeenCalledTimes(2)
	})

	it('does nothing when the sidebar API is unavailable', () => {
		registerSecloreSidebarTab()
		expect(() => window.dispatchEvent(new Event('DOMContentLoaded'))).not.toThrow()
	})
})

describe('openSecloreTab', () => {
	it('activates the Seclore tab and opens the sidebar at the node path', () => {
		const sidebar = installSidebar()

		openSecloreTab({ path: '/folder/document.odt' } as Node)

		expect(sidebar.setActiveTab).toHaveBeenCalledWith(TAB_ID)
		expect(sidebar.open).toHaveBeenCalledWith('/folder/document.odt')
	})

	it('is a no-op without the sidebar API', () => {
		expect(() => openSecloreTab({ path: '/x' } as Node)).not.toThrow()
	})
})
