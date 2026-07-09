/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * "Seclore" details-sidebar tab (SDD §5.3), registered through the Files
 * sidebar API (OCA.Files.Sidebar).
 */
import Vue from 'vue'
import { translate as t } from '@nextcloud/l10n'
import type { Node } from '@nextcloud/files'
import SecloreTab from './components/SecloreTab.vue'
import { lockSvg } from './icons'

// Minimal typing for the sidebar API this file consumes.
interface FilesSidebar {
	registerTab(tab: unknown): void
	setActiveTab(id: string): void
	open(path: string): void
	Tab: new (options: object) => unknown
}

const getSidebar = (): FilesSidebar | null =>
	(window as unknown as { OCA?: { Files?: { Sidebar?: FilesSidebar } } }).OCA?.Files?.Sidebar ?? null

export const TAB_ID = 'sclrit'

export function registerSecloreSidebarTab(): void {
	window.addEventListener('DOMContentLoaded', () => {
		const sidebar = getSidebar()
		if (sidebar === null) {
			return
		}

		let tabInstance: (Vue & { setFileInfo(fileInfo: unknown): void }) | null = null
		const TabView = Vue.extend(SecloreTab)

		sidebar.registerTab(new sidebar.Tab({
			id: TAB_ID,
			name: t('sclrit', 'Seclore'),
			iconSvg: lockSvg,
			enabled: (fileInfo: { type?: string } | null) => fileInfo?.type === 'file',

			async mount(el: HTMLElement, fileInfo: unknown) {
				tabInstance?.$destroy()
				tabInstance = new TabView() as Vue & { setFileInfo(fileInfo: unknown): void }
				tabInstance.$mount(el)
				tabInstance.setFileInfo(fileInfo)
			},

			update(fileInfo: unknown) {
				tabInstance?.setFileInfo(fileInfo)
			},

			destroy() {
				tabInstance?.$destroy()
				tabInstance = null
			},
		}))
	})
}

/** Open the sidebar for a node with the Seclore tab active. */
export function openSecloreTab(node: Node): void {
	const sidebar = getSidebar()
	if (sidebar === null) {
		return
	}
	sidebar.setActiveTab(TAB_ID)
	sidebar.open(node.path)
}
