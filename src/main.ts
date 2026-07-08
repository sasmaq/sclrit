/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Files app integration (SDD §5.1): protect/unprotect actions (single and
 * batch), an inline "protected" badge, and the details sidebar tab.
 *
 * Per-node protection knowledge comes from the files-metadata WebDAV property
 * (SDD §4.5). When the metadata subsystem is unavailable the badge and the
 * already-protected checks degrade gracefully; the authoritative state is
 * always re-checked server-side, and the sidebar tab reads /status directly.
 */
import { getCapabilities } from '@nextcloud/capabilities'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import {
	FileAction,
	FileType,
	Permission,
	registerDavProperty,
	registerFileAction,
	type Node,
	type View,
} from '@nextcloud/files'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { ocsErrorMessage, protectFile, unprotectFile } from './api'
import { confirmDialog, pickPolicy } from './dialogs'
import { lockOpenSvg, lockPlusSvg, lockSvg } from './icons'
import { openSecloreTab, registerSecloreSidebarTab } from './sidebar'
import '@nextcloud/dialogs/style.css'

interface SecloreCapabilities {
	enabled?: boolean
	canProtect?: boolean
	canUnprotect?: boolean
	defaultPolicy?: string
}

const capabilities: SecloreCapabilities =
	((getCapabilities() as Record<string, unknown>).files_seclore ?? {}) as SecloreCapabilities

// Expose the protection flag on PROPFIND results (SDD §4.5).
const PROTECTED_ATTRIBUTE = 'metadata-files_seclore-protected'
registerDavProperty('nc:' + PROTECTED_ATTRIBUTE, { nc: 'http://nextcloud.org/ns' })

const HIDDEN_VIEWS = ['trashbin', 'versions']

function isMarkedProtected(node: Node): boolean {
	const value = node.attributes?.[PROTECTED_ATTRIBUTE]
	return value === true || value === 1 || value === '1' || value === 'true'
}

/** Update the node's local flag so lists refresh without a new PROPFIND. */
function markNode(node: Node, isProtected: boolean): void {
	try {
		node.attributes[PROTECTED_ATTRIBUTE] = isProtected ? '1' : '0'
		emit('files:node:updated', node)
	} catch {
		// Display-only convenience; the next PROPFIND corrects it anyway.
	}
}

/** Map over nodes with bounded concurrency (SDD §5.1: 3 parallel requests). */
async function mapPool<T, R>(items: T[], size: number, fn: (item: T) => Promise<R>): Promise<R[]> {
	const results: R[] = new Array(items.length)
	let next = 0
	const workers = Array.from({ length: Math.min(size, items.length) }, async () => {
		while (next < items.length) {
			const index = next++
			results[index] = await fn(items[index])
		}
	})
	await Promise.all(workers)
	return results
}

/** Shared protect flow for exec and execBatch: one picker, N requests. */
async function protectNodes(nodes: Node[]): Promise<(boolean | null)[]> {
	const policyId = await pickPolicy(nodes.length)
	if (policyId === null) {
		return nodes.map(() => null)
	}

	let protectedCount = 0
	let queuedCount = 0
	const results = await mapPool(nodes, 3, async (node) => {
		try {
			const state = await protectFile(node.fileid!, policyId)
			if (state.status === 'pending') {
				queuedCount++
			} else {
				protectedCount++
				markNode(node, true)
			}
			return true
		} catch (error) {
			showError(t('files_seclore', 'Could not protect "{file}": {message}', {
				file: node.basename,
				message: ocsErrorMessage(error),
			}))
			return false
		}
	})

	if (protectedCount > 0) {
		showSuccess(n('files_seclore', '%n file protected with Seclore', '%n files protected with Seclore', protectedCount))
	}
	if (queuedCount > 0) {
		showInfo(n(
			'files_seclore',
			'%n file queued for protection — you will be notified',
			'%n files queued for protection — you will be notified',
			queuedCount,
		))
	}
	return results
}

async function unprotectNodes(nodes: Node[]): Promise<(boolean | null)[]> {
	const confirmed = await confirmDialog(
		t('files_seclore', 'Remove protection'),
		n(
			'files_seclore',
			'Remove Seclore protection from %n file? Its content will no longer be rights-protected. This action is audited.',
			'Remove Seclore protection from %n files? Their content will no longer be rights-protected. This action is audited.',
			nodes.length,
		),
		t('files_seclore', 'Remove protection'),
	)
	if (!confirmed) {
		return nodes.map(() => null)
	}

	let removedCount = 0
	let queuedCount = 0
	const results = await mapPool(nodes, 3, async (node) => {
		try {
			const state = await unprotectFile(node.fileid!)
			if (state.status === 'pending') {
				queuedCount++
			} else {
				removedCount++
				markNode(node, false)
			}
			return true
		} catch (error) {
			showError(t('files_seclore', 'Could not unprotect "{file}": {message}', {
				file: node.basename,
				message: ocsErrorMessage(error),
			}))
			return false
		}
	})

	if (removedCount > 0) {
		showSuccess(n('files_seclore', 'Protection removed from %n file', 'Protection removed from %n files', removedCount))
	}
	if (queuedCount > 0) {
		showInfo(n(
			'files_seclore',
			'%n file queued for unprotection — you will be notified',
			'%n files queued for unprotection — you will be notified',
			queuedCount,
		))
	}
	return results
}

function actionableFiles(nodes: Node[], view: View): boolean {
	return !HIDDEN_VIEWS.includes(view.id)
		&& nodes.length > 0
		&& nodes.every((node) =>
			node.type === FileType.File
			&& (node.permissions & Permission.UPDATE) !== 0)
}

// "Protect with Seclore" — single file and multi-select (SDD §5.1).
registerFileAction(new FileAction({
	id: 'files_seclore-protect',
	displayName: () => t('files_seclore', 'Protect with Seclore'),
	iconSvgInline: () => lockPlusSvg,
	order: 25,

	enabled(nodes: Node[], view: View) {
		return capabilities.canProtect === true
			&& actionableFiles(nodes, view)
			&& nodes.every((node) => !isMarkedProtected(node))
	},

	async exec(node: Node) {
		return (await protectNodes([node]))[0]
	},

	async execBatch(nodes: Node[]) {
		return await protectNodes(nodes)
	},
}))

// "Remove protection" — only for users in unprotect_groups (SDD §5.1).
registerFileAction(new FileAction({
	id: 'files_seclore-unprotect',
	displayName: () => t('files_seclore', 'Remove Seclore protection'),
	iconSvgInline: () => lockOpenSvg,
	order: 26,

	enabled(nodes: Node[], view: View) {
		return capabilities.canUnprotect === true
			&& actionableFiles(nodes, view)
			&& nodes.every(isMarkedProtected)
	},

	async exec(node: Node) {
		return (await unprotectNodes([node]))[0]
	},

	async execBatch(nodes: Node[]) {
		return await unprotectNodes(nodes)
	},
}))

// Inline lock badge on protected files (SDD §5.3); opens the details tab.
registerFileAction(new FileAction({
	id: 'files_seclore-status',
	displayName: () => t('files_seclore', 'Protected with Seclore'),
	title: () => t('files_seclore', 'Protected with Seclore — open details'),
	iconSvgInline: () => lockSvg,
	inline: () => true,
	order: -10,

	enabled(nodes: Node[], view: View) {
		return capabilities.enabled === true
			&& !HIDDEN_VIEWS.includes(view.id)
			&& nodes.length === 1
			&& isMarkedProtected(nodes[0])
	},

	async exec(node: Node) {
		openSecloreTab(node)
		return null
	},
}))

registerSecloreSidebarTab()
