/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type * as ApiModule from '../../src/api'

const axios = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	put: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url: string, params?: Record<string, unknown>) => {
		let result = url
		for (const [key, value] of Object.entries(params ?? {})) {
			result = result.replace(`{${key}}`, String(value))
		}
		return '/ocs/v2.php/' + result
	},
}))

const ocsResponse = (data: object) => ({ data: { ocs: { data } } })

const state = (fileId: number, status = 'protected'): object => ({
	fileId,
	status,
	hotFolderId: 'hf-1',
	policyName: 'Confidential',
	secloreFileId: 'sf-1',
	requestedBy: 'alice',
	updatedAt: 1700000000,
	error: null,
})

// api.ts keeps a module-level policy cache, so each test gets a fresh copy.
let api: typeof ApiModule

beforeEach(async () => {
	vi.resetModules()
	api = await import('../../src/api')
})

describe('protectFile', () => {
	it('posts the file id and hot folder and unwraps the state', async () => {
		axios.post.mockResolvedValue(ocsResponse({ state: state(42) }))

		const result = await api.protectFile(42, 'hf-1')

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/protect',
			{ fileId: 42, hotFolderId: 'hf-1' },
		)
		expect(result).toMatchObject({ fileId: 42, status: 'protected' })
	})

	it('omits hotFolderId when none is given', async () => {
		axios.post.mockResolvedValue(ocsResponse({ state: state(42) }))

		await api.protectFile(42)
		await api.protectFile(42, null)

		expect(axios.post).toHaveBeenNthCalledWith(1, expect.any(String), { fileId: 42 })
		expect(axios.post).toHaveBeenNthCalledWith(2, expect.any(String), { fileId: 42 })
	})
})

describe('unprotectFile / retryFile', () => {
	it('post to their endpoints and unwrap the state', async () => {
		axios.post.mockResolvedValue(ocsResponse({ state: state(7, 'none') }))

		expect(await api.unprotectFile(7)).toMatchObject({ fileId: 7, status: 'none' })
		expect(axios.post).toHaveBeenLastCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/unprotect',
			{ fileId: 7 },
		)

		axios.post.mockResolvedValue(ocsResponse({ state: state(7, 'pending') }))
		expect(await api.retryFile(7)).toMatchObject({ status: 'pending' })
		expect(axios.post).toHaveBeenLastCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/retry',
			{ fileId: 7 },
		)
	})
})

describe('fetchStates', () => {
	it('requests the ids as params and returns the states map', async () => {
		axios.get.mockResolvedValue(ocsResponse({ states: { 1: state(1) } }))

		const states = await api.fetchStates([1, 2])

		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/status',
			{ params: { fileIds: [1, 2] } },
		)
		expect(states).toHaveProperty('1')
		expect(states).not.toHaveProperty('2')
	})

	it('returns an empty map when the response has no states', async () => {
		axios.get.mockResolvedValue(ocsResponse({}))

		expect(await api.fetchStates([1])).toEqual({})
	})
})

describe('fetchPolicies', () => {
	const list = { policies: [{ id: 'hf-1', name: 'Confidential', description: '' }], defaultId: 'hf-1' }

	it('caches the list for the session', async () => {
		axios.get.mockResolvedValue(ocsResponse(list))

		const first = await api.fetchPolicies()
		const second = await api.fetchPolicies()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(second).toBe(first)
	})

	it('refetches when the cache is bypassed', async () => {
		axios.get.mockResolvedValue(ocsResponse(list))

		await api.fetchPolicies()
		await api.fetchPolicies(true)

		expect(axios.get).toHaveBeenCalledTimes(2)
	})
})

describe('admin endpoints', () => {
	it('saveAdminConfig puts the partial config and returns the stored one', async () => {
		axios.put.mockResolvedValue(ocsResponse({ baseUrl: 'https://drm.example.com' }))

		const result = await api.saveAdminConfig({ baseUrl: 'https://drm.example.com' })

		expect(axios.put).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/admin/config',
			{ baseUrl: 'https://drm.example.com' },
		)
		expect(result).toMatchObject({ baseUrl: 'https://drm.example.com' })
	})

	it('testConnection posts the unsaved values', async () => {
		axios.post.mockResolvedValue(ocsResponse({ ok: true, policyCount: 3, error: null }))

		const result = await api.testConnection({ baseUrl: 'https://x', appId: 'a', appSecret: 's', verifyTls: false })

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sclrit/api/v1/admin/test-connection',
			{ baseUrl: 'https://x', appId: 'a', appSecret: 's', verifyTls: false },
		)
		expect(result.ok).toBe(true)
	})

	it('searchGroups queries the provisioning API and tolerates a missing groups key', async () => {
		axios.get.mockResolvedValue(ocsResponse({ groups: ['admin', 'staff'] }))
		expect(await api.searchGroups('a')).toEqual(['admin', 'staff'])
		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.php/cloud/groups',
			{ params: { search: 'a', limit: 30 } },
		)

		axios.get.mockResolvedValue(ocsResponse({}))
		expect(await api.searchGroups('a')).toEqual([])
	})
})

describe('ocsErrorMessage', () => {
	it('prefers the message from the OCS error body', () => {
		const error = Object.assign(new Error('Request failed with status code 403'), {
			response: { data: { ocs: { data: { message: 'You are not allowed to protect this file' } } } },
		})

		expect(api.ocsErrorMessage(error)).toBe('You are not allowed to protect this file')
	})

	it('falls back to the Error message', () => {
		expect(api.ocsErrorMessage(new Error('Network Error'))).toBe('Network Error')
	})

	it('stringifies non-Error values', () => {
		expect(api.ocsErrorMessage('boom')).toBe('boom')
	})
})
