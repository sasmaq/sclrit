import type { Wrapper } from '@vue/test-utils'
import type Vue from 'vue'
import type { AdminConfig, ConnectionTestResult, Policy } from '../../../src/api'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { mount } from '@vue/test-utils'
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSettings from '../../../src/components/AdminSettings.vue'
import {
	fetchAdminConfig,
	saveAdminConfig,
	searchGroups,
	testConnection,
} from '../../../src/api'

vi.mock('../../../src/api', () => ({
	fetchAdminConfig: vi.fn(),
	saveAdminConfig: vi.fn(),
	searchGroups: vi.fn(),
	testConnection: vi.fn(),
	ocsErrorMessage: (error: unknown) => (error instanceof Error ? error.message : String(error)),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(),
}))

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', async () => ({
	default: (await import('../nc-stubs')).NcButtonStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js', async () => ({
	default: (await import('../nc-stubs')).NcCheckboxRadioSwitchStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcLoadingIcon.js', async () => ({
	default: (await import('../nc-stubs')).NcLoadingIconStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcNoteCard.js', async () => ({
	default: (await import('../nc-stubs')).NcNoteCardStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcPasswordField.js', async () => ({
	default: (await import('../nc-stubs')).NcPasswordFieldStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcSelect.js', async () => ({
	default: (await import('../nc-stubs')).NcSelectStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcSettingsSection.js', async () => ({
	default: (await import('../nc-stubs')).NcSettingsSectionStub,
}))
vi.mock('@nextcloud/vue/dist/Components/NcTextField.js', async () => ({
	default: (await import('../nc-stubs')).NcTextFieldStub,
}))

const MIB = 1048576

function storedConfig(): AdminConfig {
	return {
		baseUrl: 'https://policy.example.com/api',
		appId: 'tenant-1',
		appSecretSet: true,
		defaultHotFolder: 'hf-1',
		policies: [{ id: 'hf-1', name: 'Confidential', description: 'View only' }],
		allowedGroups: ['staff'],
		unprotectGroups: ['compliance'],
		syncMaxSize: 25 * MIB,
		requestTimeoutMax: 600,
		verifyTls: true,
		purgeVersions: true,
		staleAfter: 21600,
	}
}

interface AdminVm {
	loading: boolean
	loadError: string
	appSecret: string
	appSecretSet: boolean
	form: {
		baseUrl: string
		appId: string
		verifyTls: boolean
		defaultHotFolder: string
		policies: Policy[]
		allowedGroups: string[]
		unprotectGroups: string[]
		purgeVersions: boolean
		syncMaxSizeMiB: string
		requestTimeoutMax: string
		staleAfter: string
	}
	testResult: ConnectionTestResult | null
	testResultText: string
	groupOptions: string[]
	defaultPolicyModel: Policy | null
	addPolicy(): void
	removePolicy(index: number): void
	test(): Promise<void>
	save(): Promise<void>
	onGroupSearch(search: string): Promise<void>
}

const flush = () => new Promise((resolve) => setTimeout(resolve))

async function mountSettings(): Promise<{ wrapper: Wrapper<Vue>, vm: AdminVm }> {
	const wrapper = mount(AdminSettings)
	await flush()
	await wrapper.vm.$nextTick()
	return { wrapper, vm: wrapper.vm as unknown as AdminVm }
}

beforeEach(() => {
	vi.mocked(fetchAdminConfig).mockResolvedValue(storedConfig())
	vi.mocked(searchGroups).mockResolvedValue(['admin', 'staff'])
	vi.mocked(confirmPassword).mockResolvedValue()
	vi.mocked(saveAdminConfig).mockImplementation(async () => storedConfig())
})

describe('loading', () => {
	it('fills the form from the stored config, converting units', async () => {
		const { vm } = await mountSettings()

		expect(vm.loading).toBe(false)
		expect(vm.form.baseUrl).toBe('https://policy.example.com/api')
		expect(vm.form.syncMaxSizeMiB).toBe('25')
		expect(vm.form.requestTimeoutMax).toBe('600')
		expect(vm.appSecretSet).toBe(true)
		expect(vm.appSecret).toBe('')
		expect(vm.groupOptions).toEqual(['admin', 'staff'])
	})

	it('copies the policy rows so edits do not alias the fetched config', async () => {
		const config = storedConfig()
		vi.mocked(fetchAdminConfig).mockResolvedValue(config)
		const { vm } = await mountSettings()

		vm.form.policies[0].name = 'Changed'

		expect(config.policies[0].name).toBe('Confidential')
	})

	it('shows the error when the config cannot be loaded', async () => {
		vi.mocked(fetchAdminConfig).mockRejectedValue(new Error('Forbidden'))
		const { wrapper, vm } = await mountSettings()

		expect(vm.loadError).toBe('Forbidden')
		expect(wrapper.find('.nc-note-card').text()).toContain('Forbidden')
	})

	it('tolerates a failing group search', async () => {
		vi.mocked(searchGroups).mockRejectedValue(new Error('nope'))
		const { vm } = await mountSettings()

		expect(vm.loadError).toBe('')
		expect(vm.groupOptions).toEqual([])
	})
})

describe('save', () => {
	it('does nothing when the password confirmation is cancelled', async () => {
		vi.mocked(confirmPassword).mockRejectedValue(new Error('cancelled'))
		const { vm } = await mountSettings()

		await vm.save()

		expect(saveAdminConfig).not.toHaveBeenCalled()
	})

	it('omits the secret when left empty, sends and clears it when entered', async () => {
		const { vm } = await mountSettings()

		await vm.save()
		expect(vi.mocked(saveAdminConfig).mock.calls[0][0]).not.toHaveProperty('appSecret')

		vm.appSecret = 's3cret'
		await vm.save()
		expect(vi.mocked(saveAdminConfig).mock.calls[1][0]).toMatchObject({ appSecret: 's3cret' })
		expect(vm.appSecret).toBe('')
		expect(showSuccess).toHaveBeenCalled()
	})

	it('trims policy rows and drops incomplete ones', async () => {
		const { vm } = await mountSettings()
		vm.form.policies = [
			{ id: ' hf-1 ', name: ' Confidential ', description: ' View only ' },
			{ id: 'hf-2', name: '', description: 'no name' },
			{ id: '', name: 'no id', description: '' },
		]

		await vm.save()

		expect(vi.mocked(saveAdminConfig).mock.calls[0][0].policies).toEqual([
			{ id: 'hf-1', name: 'Confidential', description: 'View only' },
		])
	})

	it('converts MiB to bytes and falls back on unparsable numbers', async () => {
		const { vm } = await mountSettings()
		vm.form.syncMaxSizeMiB = '10'
		vm.form.requestTimeoutMax = 'abc'
		vm.form.staleAfter = ''

		await vm.save()

		expect(vi.mocked(saveAdminConfig).mock.calls[0][0]).toMatchObject({
			syncMaxSize: 10 * MIB,
			requestTimeoutMax: 600,
			staleAfter: 21600,
		})
	})

	it('shows the backend error and keeps the form editable', async () => {
		vi.mocked(saveAdminConfig).mockRejectedValue(new Error('Base URL must use HTTPS'))
		const { vm } = await mountSettings()

		await vm.save()

		expect(showError).toHaveBeenCalledWith('Base URL must use HTTPS')
		expect((vm as unknown as { saving: boolean }).saving).toBe(false)
	})
})

describe('test connection', () => {
	it('exercises the entered values, sending the secret only when set', async () => {
		vi.mocked(testConnection).mockResolvedValue({ ok: true, policyCount: 3, error: null })
		const { vm } = await mountSettings()

		await vm.test()
		expect(vi.mocked(testConnection).mock.calls[0][0]).toEqual({
			baseUrl: 'https://policy.example.com/api',
			appId: 'tenant-1',
			verifyTls: true,
		})
		expect(vm.testResult?.ok).toBe(true)

		vm.appSecret = 's3cret'
		await vm.test()
		expect(vi.mocked(testConnection).mock.calls[1][0]).toMatchObject({ appSecret: 's3cret' })
	})

	it('turns a thrown error into a failed test result', async () => {
		vi.mocked(testConnection).mockRejectedValue(new Error('timeout'))
		const { vm } = await mountSettings()

		await vm.test()

		expect(vm.testResult).toEqual({ ok: false, policyCount: null, error: 'timeout' })
		expect(vm.testResultText).toBe('timeout')
	})
})

describe('default policy model', () => {
	it('resolves the configured policy and falls back to a synthetic entry', async () => {
		const { vm } = await mountSettings()

		expect(vm.defaultPolicyModel).toMatchObject({ id: 'hf-1', name: 'Confidential' })

		vm.form.defaultHotFolder = 'hf-removed'
		expect(vm.defaultPolicyModel).toMatchObject({ id: 'hf-removed', name: 'hf-removed' })

		vm.form.defaultHotFolder = ''
		expect(vm.defaultPolicyModel).toBeNull()
	})

	it('writes the selection back as an id', async () => {
		const { vm } = await mountSettings()

		vm.defaultPolicyModel = { id: 'hf-2', name: 'Internal', description: '' }
		expect(vm.form.defaultHotFolder).toBe('hf-2')

		vm.defaultPolicyModel = null
		expect(vm.form.defaultHotFolder).toBe('')
	})
})

describe('policy rows and group search', () => {
	it('adds and removes policy rows', async () => {
		const { vm } = await mountSettings()

		vm.addPolicy()
		expect(vm.form.policies).toHaveLength(2)
		expect(vm.form.policies[1]).toEqual({ id: '', name: '', description: '' })

		vm.removePolicy(0)
		expect(vm.form.policies).toHaveLength(1)
		expect(vm.form.policies[0].id).toBe('')
	})

	it('keeps the selected groups available while searching', async () => {
		const { vm } = await mountSettings()
		vi.mocked(searchGroups).mockResolvedValue(['accounting', 'staff'])

		await vm.onGroupSearch('acc')

		expect(vm.groupOptions).toEqual(['accounting', 'staff', 'compliance'])
	})

	it('keeps the previous options when the search fails', async () => {
		const { vm } = await mountSettings()
		vi.mocked(searchGroups).mockRejectedValue(new Error('nope'))

		await vm.onGroupSearch('x')

		expect(vm.groupOptions).toEqual(['admin', 'staff'])
	})
})
