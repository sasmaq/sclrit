/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Covers the Files-app actions registered by main.ts: enablement rules,
 * the protect/unprotect flows and the inline status badge.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FileAction, FileType, Permission, type Node, type View } from '@nextcloud/files'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { protectFile, unprotectFile } from '../../src/api'
import { confirmDialog, pickPolicy } from '../../src/dialogs'
import { openSecloreTab } from '../../src/sidebar'

const capabilities = vi.hoisted(() => ({ value: {} as Record<string, unknown> }))

vi.mock('@nextcloud/capabilities', () => ({
	getCapabilities: () => ({ files_seclore: capabilities.value }),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

vi.mock('@nextcloud/files', async (importOriginal) => ({
	...await importOriginal<object>(),
	registerFileAction: vi.fn(),
	registerDavProperty: vi.fn(),
}))

vi.mock('../../src/api', () => ({
	protectFile: vi.fn(),
	unprotectFile: vi.fn(),
	ocsErrorMessage: (error: unknown) => (error instanceof Error ? error.message : String(error)),
}))

vi.mock('../../src/dialogs', () => ({
	pickPolicy: vi.fn(),
	confirmDialog: vi.fn(),
}))

vi.mock('../../src/sidebar', () => ({
	openSecloreTab: vi.fn(),
	registerSecloreSidebarTab: vi.fn(),
}))

const PROTECTED_ATTRIBUTE = 'metadata-files_seclore-protected'

const filesView = { id: 'files' } as View
const trashbinView = { id: 'trashbin' } as View

const fileState = (fileId: number, status: string) => ({
	fileId,
	status,
	hotFolderId: null,
	policyName: null,
	secloreFileId: null,
	requestedBy: null,
	updatedAt: null,
	error: null,
})

let nextFileid = 1

const fakeNode = (overrides: Record<string, unknown> = {}): Node => ({
	fileid: nextFileid++,
	basename: 'document.odt',
	path: '/document.odt',
	type: FileType.File,
	permissions: Permission.READ | Permission.UPDATE,
	attributes: {} as Record<string, unknown>,
	...overrides,
}) as unknown as Node

const protectedNode = (overrides: Record<string, unknown> = {}): Node =>
	fakeNode({ attributes: { [PROTECTED_ATTRIBUTE]: '1' }, ...overrides })

/** Import main.ts fresh with the given capabilities and collect its actions. */
async function loadActions(caps: Record<string, unknown>): Promise<Record<string, FileAction>> {
	capabilities.value = caps
	vi.resetModules()
	await import('../../src/main')

	const { registerFileAction } = vi.mocked(await import('@nextcloud/files'))
	const actions: Record<string, FileAction> = {}
	for (const [action] of registerFileAction.mock.calls) {
		actions[action.id] = action
	}
	return actions
}

const allCaps = { enabled: true, canProtect: true, canUnprotect: true }

beforeEach(() => {
	nextFileid = 1
})

describe('registration', () => {
	it('registers the three actions and the protected DAV property', async () => {
		const actions = await loadActions(allCaps)

		expect(Object.keys(actions).sort()).toEqual([
			'files_seclore-protect',
			'files_seclore-status',
			'files_seclore-unprotect',
		])

		const { registerDavProperty } = vi.mocked(await import('@nextcloud/files'))
		expect(registerDavProperty).toHaveBeenCalledWith(
			'nc:' + PROTECTED_ATTRIBUTE,
			{ nc: 'http://nextcloud.org/ns' },
		)

		const { registerSecloreSidebarTab } = vi.mocked(await import('../../src/sidebar'))
		expect(registerSecloreSidebarTab).toHaveBeenCalled()
	})
})

describe('protect action enablement', () => {
	it('is enabled for unprotected, updatable files in a regular view', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)

		expect(protect.enabled!([fakeNode(), fakeNode()], filesView)).toBe(true)
	})

	it('is disabled without the canProtect capability', async () => {
		const { 'files_seclore-protect': protect } = await loadActions({ enabled: true, canProtect: false })

		expect(protect.enabled!([fakeNode()], filesView)).toBe(false)
	})

	it('is disabled in the trashbin, for folders, and without update permission', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)

		expect(protect.enabled!([fakeNode()], trashbinView)).toBe(false)
		expect(protect.enabled!([fakeNode({ type: FileType.Folder })], filesView)).toBe(false)
		expect(protect.enabled!([fakeNode({ permissions: Permission.READ })], filesView)).toBe(false)
		expect(protect.enabled!([], filesView)).toBe(false)
	})

	it('is disabled when any selected node is already protected', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)

		for (const marker of [true, 1, '1', 'true']) {
			const marked = fakeNode({ attributes: { [PROTECTED_ATTRIBUTE]: marker } })
			expect(protect.enabled!([fakeNode(), marked], filesView)).toBe(false)
		}
	})
})

describe('unprotect action enablement', () => {
	it('requires the capability and every node to be protected', async () => {
		const { 'files_seclore-unprotect': unprotect } = await loadActions(allCaps)

		expect(unprotect.enabled!([protectedNode()], filesView)).toBe(true)
		expect(unprotect.enabled!([protectedNode(), fakeNode()], filesView)).toBe(false)

		const { 'files_seclore-unprotect': withoutCap } = await loadActions({ enabled: true, canUnprotect: false })
		expect(withoutCap.enabled!([protectedNode()], filesView)).toBe(false)
	})
})

describe('protect flow', () => {
	it('protects the file with the picked policy and marks the node', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)
		vi.mocked(pickPolicy).mockResolvedValue('hf-1')
		vi.mocked(protectFile).mockImplementation(async (fileId) => fileState(fileId, 'protected'))

		const node = fakeNode()
		const result = await protect.exec(node, filesView, '/')

		expect(result).toBe(true)
		expect(pickPolicy).toHaveBeenCalledWith(1)
		expect(protectFile).toHaveBeenCalledWith(node.fileid, 'hf-1')
		expect(node.attributes[PROTECTED_ATTRIBUTE]).toBe('1')
		expect(emit).toHaveBeenCalledWith('files:node:updated', node)
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('shows a single picker for a batch and reports queued files separately', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)
		vi.mocked(pickPolicy).mockResolvedValue('hf-1')
		vi.mocked(protectFile).mockImplementation(async (fileId) =>
			fileState(fileId, fileId === 1 ? 'protected' : 'pending'))

		const nodes = [fakeNode(), fakeNode()]
		const results = await protect.execBatch!(nodes, filesView, '/')

		expect(results).toEqual([true, true])
		expect(pickPolicy).toHaveBeenCalledTimes(1)
		expect(pickPolicy).toHaveBeenCalledWith(2)
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showInfo).toHaveBeenCalledTimes(1)
		// The queued file must not be marked protected yet.
		expect(nodes[0].attributes[PROTECTED_ATTRIBUTE]).toBe('1')
		expect(nodes[1].attributes[PROTECTED_ATTRIBUTE]).toBeUndefined()
	})

	it('does nothing when the picker is cancelled', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)
		vi.mocked(pickPolicy).mockResolvedValue(null)

		const results = await protect.execBatch!([fakeNode(), fakeNode()], filesView, '/')

		expect(results).toEqual([null, null])
		expect(protectFile).not.toHaveBeenCalled()
	})

	it('reports per-file errors and keeps the node unmarked', async () => {
		const { 'files_seclore-protect': protect } = await loadActions(allCaps)
		vi.mocked(pickPolicy).mockResolvedValue('hf-1')
		vi.mocked(protectFile).mockRejectedValue(new Error('File is too large'))

		const node = fakeNode()
		const result = await protect.exec(node, filesView, '/')

		expect(result).toBe(false)
		expect(showError).toHaveBeenCalledWith(expect.stringContaining('File is too large'))
		expect(node.attributes[PROTECTED_ATTRIBUTE]).toBeUndefined()
		expect(showSuccess).not.toHaveBeenCalled()
	})
})

describe('unprotect flow', () => {
	it('asks for confirmation and unmarks the node on success', async () => {
		const { 'files_seclore-unprotect': unprotect } = await loadActions(allCaps)
		vi.mocked(confirmDialog).mockResolvedValue(true)
		vi.mocked(unprotectFile).mockImplementation(async (fileId) => fileState(fileId, 'none'))

		const node = protectedNode()
		const result = await unprotect.exec(node, filesView, '/')

		expect(result).toBe(true)
		expect(confirmDialog).toHaveBeenCalled()
		expect(unprotectFile).toHaveBeenCalledWith(node.fileid)
		expect(node.attributes[PROTECTED_ATTRIBUTE]).toBe('0')
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('does nothing when the confirmation is declined', async () => {
		const { 'files_seclore-unprotect': unprotect } = await loadActions(allCaps)
		vi.mocked(confirmDialog).mockResolvedValue(false)

		const result = await unprotect.exec(protectedNode(), filesView, '/')

		expect(result).toBeNull()
		expect(unprotectFile).not.toHaveBeenCalled()
	})
})

describe('status badge', () => {
	it('is inline and enabled only for a single protected node', async () => {
		const { 'files_seclore-status': status } = await loadActions(allCaps)

		expect(status.inline!(protectedNode(), filesView)).toBe(true)
		expect(status.enabled!([protectedNode()], filesView)).toBe(true)
		expect(status.enabled!([fakeNode()], filesView)).toBe(false)
		expect(status.enabled!([protectedNode(), protectedNode()], filesView)).toBe(false)
		expect(status.enabled!([protectedNode()], trashbinView)).toBe(false)
	})

	it('is hidden entirely when the app is disabled', async () => {
		const { 'files_seclore-status': status } = await loadActions({ enabled: false })

		expect(status.enabled!([protectedNode()], filesView)).toBe(false)
	})

	it('opens the Seclore sidebar tab on click', async () => {
		const { 'files_seclore-status': status } = await loadActions(allCaps)

		const node = protectedNode()
		expect(await status.exec(node, filesView, '/')).toBeNull()
		expect(openSecloreTab).toHaveBeenCalledWith(node)
	})
})
