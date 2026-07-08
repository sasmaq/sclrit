<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Admin settings form (SDD §4.6, §5.4). All reads/writes go through the admin
  OCS endpoints; the app secret is write-only and never echoed back. The
  "Test connection" button exercises the currently entered (unsaved) values.
-->
<template>
	<div class="seclore-admin">
		<NcSettingsSection :name="t('files_seclore', 'Seclore File Protection')"
			:description="t('files_seclore', 'Protect files on demand with Seclore Enterprise DRM. Protecting a file transmits its content to the Policy Server configured below.')"
			doc-url="https://github.com/nextcloud/files_seclore">
			<NcLoadingIcon v-if="loading" :size="32" />
			<NcNoteCard v-else-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>
		</NcSettingsSection>

		<template v-if="!loading && !loadError">
			<!-- Connection (SDD §8.1) -->
			<NcSettingsSection :name="t('files_seclore', 'Policy Server connection')"
				:description="t('files_seclore', 'Register this app as an enterprise application in the Seclore console and enter its credentials here. The base URL must use HTTPS.')">
				<div class="seclore-admin__form">
					<NcTextField v-model="form.baseUrl"
						:label="t('files_seclore', 'Policy Server base URL')"
						placeholder="https://policy.example.com/api"
						type="url" />
					<NcTextField v-model="form.appId"
						:label="t('files_seclore', 'Tenant ID')" />
					<NcPasswordField v-model="appSecret"
						:label="t('files_seclore', 'Tenant secret')"
						:placeholder="appSecretSet ? t('files_seclore', '•••• (a secret is saved — leave empty to keep it)') : ''"
						autocomplete="new-password" />
					<NcCheckboxRadioSwitch :checked="form.verifyTls"
						type="switch"
						@update:checked="form.verifyTls = $event">
						{{ t('files_seclore', 'Verify the TLS certificate') }}
					</NcCheckboxRadioSwitch>
					<NcNoteCard v-if="!form.verifyTls" type="warning">
						{{ t('files_seclore', 'TLS verification is disabled. The connection to the Policy Server is not protected against interception — enable it outside of testing.') }}
					</NcNoteCard>

					<div class="seclore-admin__actions">
						<NcButton :disabled="testing || !form.baseUrl || !form.appId" @click="test">
							{{ testing ? t('files_seclore', 'Testing…') : t('files_seclore', 'Test connection') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="testResult" :type="testResult.ok ? 'success' : 'error'">
						{{ testResultText }}
					</NcNoteCard>
				</div>
			</NcSettingsSection>

			<!-- Policies (SDD §15 Q1a: no listing API — admin-maintained) -->
			<NcSettingsSection :name="t('files_seclore', 'Protection policies')"
				:description="t('files_seclore', 'The Seclore API does not expose the Hot Folder list, so the policies offered to users are maintained here. Find the Hot Folder IDs in the Seclore admin console.')">
				<div class="seclore-admin__form">
					<div v-for="(policy, index) in form.policies" :key="index" class="seclore-admin__policy">
						<NcTextField v-model="policy.id"
							:label="t('files_seclore', 'Hot Folder ID')" />
						<NcTextField v-model="policy.name"
							:label="t('files_seclore', 'Display name')" />
						<NcTextField v-model="policy.description"
							:label="t('files_seclore', 'Description (optional, shown in the picker)')" />
						<div>
							<NcButton type="tertiary" @click="removePolicy(index)">
								{{ t('files_seclore', 'Remove') }}
							</NcButton>
						</div>
					</div>
					<div class="seclore-admin__actions">
						<NcButton @click="addPolicy">
							{{ t('files_seclore', 'Add policy') }}
						</NcButton>
					</div>
				</div>
			</NcSettingsSection>

			<!-- Defaults (SDD Appendix A, decision D7) -->
			<NcSettingsSection :name="t('files_seclore', 'Protection defaults')"
				:description="t('files_seclore', 'The default policy is pre-selected in the picker and used by API calls that do not name one.')">
				<div class="seclore-admin__form">
					<div class="seclore-admin__field">
						<label for="seclore-default-policy">{{ t('files_seclore', 'Default policy') }}</label>
						<NcSelect v-model="defaultPolicyModel"
							input-id="seclore-default-policy"
							:options="form.policies"
							label="name"
							:placeholder="form.policies.length === 0 ? t('files_seclore', 'Add a protection policy above first') : t('files_seclore', 'No default')"
							:clearable="true" />
					</div>
					<NcTextField v-model="form.syncMaxSizeMiB"
						:label="t('files_seclore', 'Synchronous protection up to (MiB)')"
						type="number"
						:helper-text="t('files_seclore', 'Larger files are protected by a background job and the user is notified on completion.')" />
					<NcCheckboxRadioSwitch :checked="form.purgeVersions"
						type="switch"
						@update:checked="form.purgeVersions = $event">
						{{ t('files_seclore', 'Delete previous file versions after successful protection') }}
					</NcCheckboxRadioSwitch>
					<NcNoteCard :type="form.purgeVersions ? 'info' : 'warning'">
						{{ form.purgeVersions
							? t('files_seclore', 'Pre-protection versions contain the unprotected content; deleting them closes that leak but makes the protection irreversible from Nextcloud alone.')
							: t('files_seclore', 'Previous versions keep the unprotected content and stay restorable by anyone with access — this defeats the protection for existing files.') }}
					</NcNoteCard>
				</div>
			</NcSettingsSection>

			<!-- Access control (SDD §8.2) -->
			<NcSettingsSection :name="t('files_seclore', 'Access control')"
				:description="t('files_seclore', 'Who may protect and unprotect files. Unprotection is always audited.')">
				<div class="seclore-admin__form">
					<div class="seclore-admin__field">
						<label for="seclore-allowed-groups">{{ t('files_seclore', 'Groups allowed to protect (empty: everyone)') }}</label>
						<NcSelect v-model="form.allowedGroups"
							input-id="seclore-allowed-groups"
							:options="groupOptions"
							:multiple="true"
							:close-on-select="false"
							@search="onGroupSearch" />
					</div>
					<div class="seclore-admin__field">
						<label for="seclore-unprotect-groups">{{ t('files_seclore', 'Groups allowed to unprotect (empty: nobody)') }}</label>
						<NcSelect v-model="form.unprotectGroups"
							input-id="seclore-unprotect-groups"
							:options="groupOptions"
							:multiple="true"
							:close-on-select="false"
							@search="onGroupSearch" />
					</div>
				</div>
			</NcSettingsSection>

			<!-- Advanced (SDD Appendix A) -->
			<NcSettingsSection :name="t('files_seclore', 'Advanced')">
				<div class="seclore-admin__form">
					<NcTextField v-model="form.requestTimeoutMax"
						:label="t('files_seclore', 'Maximum request timeout (seconds)')"
						type="number" />
					<NcTextField v-model="form.staleAfter"
						:label="t('files_seclore', 'Mark stuck operations as failed after (seconds)')"
						type="number" />
				</div>
			</NcSettingsSection>

			<NcSettingsSection :name="''">
				<div class="seclore-admin__actions">
					<NcButton type="primary" :disabled="saving" @click="save">
						{{ saving ? t('files_seclore', 'Saving…') : t('files_seclore', 'Save settings') }}
					</NcButton>
				</div>
			</NcSettingsSection>
		</template>
	</div>
</template>

<script lang="ts">
import Vue from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcPasswordField from '@nextcloud/vue/dist/Components/NcPasswordField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import '@nextcloud/password-confirmation/style.css'
import {
	fetchAdminConfig,
	ocsErrorMessage,
	saveAdminConfig,
	searchGroups,
	testConnection,
	type AdminConfig,
	type ConnectionTestResult,
	type Policy,
} from '../api'

const MIB = 1048576

// Vue.extend instead of defineComponent — see SecloreTab.vue.
export default Vue.extend({
	name: 'AdminSettings',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			loadError: '',
			saving: false,
			testing: false,
			appSecret: '',
			appSecretSet: false,
			form: {
				baseUrl: '',
				appId: '',
				verifyTls: true,
				defaultHotFolder: '',
				policies: [] as Policy[],
				allowedGroups: [] as string[],
				unprotectGroups: [] as string[],
				purgeVersions: true,
				syncMaxSizeMiB: '25',
				requestTimeoutMax: '600',
				staleAfter: '21600',
			},
			testResult: null as ConnectionTestResult | null,
			groupOptions: [] as string[],
		}
	},

	computed: {
		defaultPolicyModel: {
			get(): Policy | null {
				if (this.form.defaultHotFolder === '') {
					return null
				}
				// Fall back to a synthetic entry so a saved id still shows when
				// it is missing from the configured list.
				return this.form.policies.find((p) => p.id === this.form.defaultHotFolder)
					?? { id: this.form.defaultHotFolder, name: this.form.defaultHotFolder, description: '' }
			},
			set(policy: Policy | null) {
				this.form.defaultHotFolder = policy?.id ?? ''
			},
		},

		testResultText(): string {
			if (!this.testResult) {
				return ''
			}
			if (this.testResult.ok) {
				return t('files_seclore', 'Connection and authentication OK.')
			}
			return this.testResult.error || t('files_seclore', 'Connection failed.')
		},
	},

	async mounted(): Promise<void> {
		try {
			this.applyConfig(await fetchAdminConfig())
			this.loading = false
		} catch (error) {
			this.loadError = ocsErrorMessage(error)
			this.loading = false
			return
		}
		// Best-effort prefill; fails quietly when not applicable.
		try {
			this.groupOptions = await searchGroups('')
		} catch {
			this.groupOptions = []
		}
	},

	methods: {
		t,

		applyConfig(config: AdminConfig): void {
			this.appSecretSet = config.appSecretSet
			this.form.baseUrl = config.baseUrl
			this.form.appId = config.appId
			this.form.verifyTls = config.verifyTls
			this.form.defaultHotFolder = config.defaultHotFolder
			// Copy the rows: they are edited in place by the policy editor.
			this.form.policies = config.policies.map((p) => ({ ...p }))
			this.form.allowedGroups = config.allowedGroups
			this.form.unprotectGroups = config.unprotectGroups
			this.form.purgeVersions = config.purgeVersions
			this.form.syncMaxSizeMiB = String(Math.round(config.syncMaxSize / MIB))
			this.form.requestTimeoutMax = String(config.requestTimeoutMax)
			this.form.staleAfter = String(config.staleAfter)
		},

		addPolicy(): void {
			this.form.policies.push({ id: '', name: '', description: '' })
		},

		removePolicy(index: number): void {
			this.form.policies.splice(index, 1)
		},

		async test(): Promise<void> {
			this.testing = true
			this.testResult = null
			try {
				this.testResult = await testConnection({
					baseUrl: this.form.baseUrl,
					appId: this.form.appId,
					...(this.appSecret !== '' ? { appSecret: this.appSecret } : {}),
					verifyTls: this.form.verifyTls,
				})
			} catch (error) {
				this.testResult = { ok: false, policyCount: null, error: ocsErrorMessage(error) }
			} finally {
				this.testing = false
			}
		},

		async save(): Promise<void> {
			try {
				await confirmPassword()
			} catch {
				return // cancelled
			}
			this.saving = true
			try {
				const config = await saveAdminConfig({
					baseUrl: this.form.baseUrl,
					appId: this.form.appId,
					...(this.appSecret !== '' ? { appSecret: this.appSecret } : {}),
					verifyTls: this.form.verifyTls,
					defaultHotFolder: this.form.defaultHotFolder,
					policies: this.form.policies
						.map((p) => ({ id: p.id.trim(), name: p.name.trim(), description: (p.description ?? '').trim() }))
						.filter((p) => p.id !== '' && p.name !== ''),
					allowedGroups: this.form.allowedGroups,
					unprotectGroups: this.form.unprotectGroups,
					purgeVersions: this.form.purgeVersions,
					syncMaxSize: Math.max(0, Math.round(parseFloat(this.form.syncMaxSizeMiB) || 0) * MIB),
					requestTimeoutMax: parseInt(this.form.requestTimeoutMax, 10) || 600,
					staleAfter: parseInt(this.form.staleAfter, 10) || 21600,
				})
				this.applyConfig(config)
				this.appSecret = ''
				showSuccess(t('files_seclore', 'Seclore settings saved.'))
			} catch (error) {
				showError(ocsErrorMessage(error))
			} finally {
				this.saving = false
			}
		},

		async onGroupSearch(search: string): Promise<void> {
			try {
				const found = await searchGroups(search)
				// Keep current selections available as options while searching.
				const selected = [...this.form.allowedGroups, ...this.form.unprotectGroups]
				this.groupOptions = [...new Set([...found, ...selected])]
			} catch {
				// Leave the previous options in place.
			}
		},
	},
})
</script>

<style scoped lang="scss">
.seclore-admin {
	&__form {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-width: 500px;
	}

	&__field label {
		display: block;
		margin-block-end: 4px;
		color: var(--color-text-maxcontrast);
	}

	&__policy {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 8px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__actions {
		display: flex;
		gap: 8px;
	}
}
</style>
