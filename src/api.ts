/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin client for the OCS API (SDD §4.3).
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export type ProtectionStatus = 'none' | 'pending' | 'processing' | 'protected' | 'failed'

export interface ProtectionState {
	fileId: number
	status: ProtectionStatus
	hotFolderId: string | null
	policyName: string | null
	secloreFileId: string | null
	requestedBy: string | null
	updatedAt: number | null
	error: string | null
}

export interface Policy {
	id: string
	name: string
	description: string
}

export interface PolicyList {
	policies: Policy[]
	defaultId: string
}

const apiUrl = (path: string): string => generateOcsUrl('apps/files_seclore/api/v1{path}', { path })

export async function protectFile(fileId: number, hotFolderId?: string | null): Promise<ProtectionState> {
	const { data } = await axios.post(apiUrl('/protect'), {
		fileId,
		...(hotFolderId ? { hotFolderId } : {}),
	})
	return data.ocs.data.state
}

export async function unprotectFile(fileId: number): Promise<ProtectionState> {
	const { data } = await axios.post(apiUrl('/unprotect'), { fileId })
	return data.ocs.data.state
}

export async function retryFile(fileId: number): Promise<ProtectionState> {
	const { data } = await axios.post(apiUrl('/retry'), { fileId })
	return data.ocs.data.state
}

/** Batched status lookup; inaccessible ids are omitted from the result. */
export async function fetchStates(fileIds: number[]): Promise<Record<number, ProtectionState>> {
	const { data } = await axios.get(apiUrl('/status'), { params: { fileIds } })
	return data.ocs.data.states ?? {}
}

let policyCache: PolicyList | null = null

/** Policy list, cached for the session (SDD §5.2). */
export async function fetchPolicies(bypassCache = false): Promise<PolicyList> {
	if (policyCache !== null && !bypassCache) {
		return policyCache
	}
	const { data } = await axios.get(apiUrl('/policies'))
	policyCache = data.ocs.data as PolicyList
	return policyCache
}

/** User-safe message from an OCS error response (SDD Appendix B). */
export function ocsErrorMessage(error: unknown): string {
	const data = (error as { response?: { data?: { ocs?: { data?: { message?: string } } } } })?.response?.data?.ocs?.data
	if (data?.message) {
		return data.message
	}
	return error instanceof Error ? error.message : String(error)
}
