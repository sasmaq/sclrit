<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Details sidebar tab (SDD §5.3): protection status, policy, actor, timestamps
  and contextual actions (protect / retry / unprotect).
-->
<template>
	<div class="seclore-tab">
		<NcLoadingIcon v-if="loading" class="seclore-tab__loading" :size="32" />

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else-if="state">
			<!-- none -->
			<NcEmptyContent v-if="state.status === 'none'"
				:name="t('sclrit', 'Not protected')"
				:description="t('sclrit', 'This file has no Seclore protection.')">
				<template #icon>
					<span class="seclore-tab__icon" v-html="lockOpenSvg" /><!-- eslint-disable-line vue/no-v-html -->
				</template>
				<template #action>
					<NcButton v-if="canProtect" type="primary" :disabled="busy" @click="protect">
						{{ t('sclrit', 'Protect with Seclore') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- pending / processing -->
			<NcNoteCard v-else-if="state.status === 'pending' || state.status === 'processing'" type="info">
				{{ inFlightText }}
			</NcNoteCard>

			<!-- protected / failed -->
			<template v-else>
				<NcNoteCard v-if="state.status === 'failed'" type="error">
					{{ state.error || t('sclrit', 'The last request failed.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="state.error" type="warning">
					{{ state.error }}
				</NcNoteCard>
				<NcNoteCard v-else type="success">
					{{ t('sclrit', 'This file is protected with Seclore.') }}
				</NcNoteCard>

				<dl class="seclore-tab__details">
					<template v-if="state.policyName || state.hotFolderId">
						<dt>{{ t('sclrit', 'Policy') }}</dt>
						<dd>{{ state.policyName || state.hotFolderId }}</dd>
					</template>
					<template v-if="state.requestedBy">
						<dt>{{ t('sclrit', 'Requested by') }}</dt>
						<dd>{{ state.requestedBy }}</dd>
					</template>
					<template v-if="state.updatedAt">
						<dt>{{ t('sclrit', 'Last change') }}</dt>
						<dd>{{ formatTime(state.updatedAt) }}</dd>
					</template>
					<template v-if="state.secloreFileId">
						<dt>{{ t('sclrit', 'Seclore file ID') }}</dt>
						<dd>{{ state.secloreFileId }}</dd>
					</template>
				</dl>

				<div class="seclore-tab__actions">
					<NcButton v-if="state.status === 'failed'" type="primary" :disabled="busy" @click="retry">
						{{ t('sclrit', 'Retry') }}
					</NcButton>
					<NcButton v-if="state.status === 'protected' && canUnprotect"
						type="error"
						:disabled="busy"
						@click="unprotect">
						{{ t('sclrit', 'Remove protection') }}
					</NcButton>
				</div>
			</template>
		</template>
	</div>
</template>

<script lang="ts">
import Vue from 'vue'
import { getCapabilities } from '@nextcloud/capabilities'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import { fetchStates, ocsErrorMessage, protectFile, retryFile, unprotectFile, type ProtectionState } from '../api'
import { confirmDialog, pickPolicy } from '../dialogs'
import { lockOpenSvg } from '../icons'

interface SecloreCapabilities {
	canProtect?: boolean
	canUnprotect?: boolean
}

// Legacy sidebar FileInfo model (OCA.Files.Sidebar).
interface FileInfo {
	id: number
	name: string
	permissions?: number
}

const PERMISSION_UPDATE = 2

// Vue.extend instead of defineComponent: the 2.7 defineComponent typings fail
// to infer the methods of this component (async methods + no props).
export default Vue.extend({
	name: 'SecloreTab',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			fileInfo: null as FileInfo | null,
			state: null as ProtectionState | null,
			loading: false,
			loadError: '',
			busy: false,
			lockOpenSvg,
		}
	},

	computed: {
		capabilities(): SecloreCapabilities {
			return ((getCapabilities() as Record<string, unknown>).sclrit ?? {}) as SecloreCapabilities
		},

		updatable(): boolean {
			return ((this.fileInfo?.permissions ?? 0) & PERMISSION_UPDATE) !== 0
		},

		canProtect(): boolean {
			return this.capabilities.canProtect === true && this.updatable
		},

		canUnprotect(): boolean {
			return this.capabilities.canUnprotect === true && this.updatable
		},

		inFlightText(): string {
			return this.state?.status === 'pending'
				? t('sclrit', 'Queued — you will be notified when the operation completes.')
				: t('sclrit', 'Being processed by the Seclore Policy Server…')
		},
	},

	methods: {
		t,

		/** Entry point used by the sidebar tab wrapper (mount and update). */
		setFileInfo(fileInfo: FileInfo): void {
			this.fileInfo = fileInfo
			this.state = null
			this.load()
		},

		async load(): Promise<void> {
			if (!this.fileInfo) {
				return
			}
			const fileId = this.fileInfo.id
			this.loading = true
			this.loadError = ''
			try {
				const states = await fetchStates([fileId])
				// Ignore stale responses after the user switched files.
				if (this.fileInfo?.id === fileId) {
					this.state = states[fileId]
						?? { fileId, status: 'none', hotFolderId: null, policyName: null, secloreFileId: null, requestedBy: null, updatedAt: null, error: null }
				}
			} catch (error) {
				this.loadError = ocsErrorMessage(error)
			} finally {
				this.loading = false
			}
		},

		async protect(): Promise<void> {
			if (!this.fileInfo) {
				return
			}
			const fileId = this.fileInfo.id
			const policyId = await pickPolicy(1)
			if (policyId === null) {
				return
			}
			this.busy = true
			try {
				const state = await protectFile(fileId, policyId)
				if (state.status === 'pending') {
					showInfo(t('sclrit', 'Protection queued — you will be notified.'))
				} else {
					showSuccess(t('sclrit', 'File protected with Seclore.'))
				}
			} catch (error) {
				showError(ocsErrorMessage(error))
			} finally {
				this.busy = false
				await this.load()
			}
		},

		async retry(): Promise<void> {
			if (!this.fileInfo) {
				return
			}
			this.busy = true
			try {
				await retryFile(this.fileInfo.id)
				showInfo(t('sclrit', 'Protection restarted.'))
			} catch (error) {
				showError(ocsErrorMessage(error))
			} finally {
				this.busy = false
				await this.load()
			}
		},

		async unprotect(): Promise<void> {
			if (!this.fileInfo) {
				return
			}
			const fileId = this.fileInfo.id
			const confirmed = await confirmDialog(
				t('sclrit', 'Remove protection'),
				t('sclrit', 'Remove Seclore protection from "{file}"? The file content will no longer be rights-protected. This action is audited.', { file: this.fileInfo.name }),
				t('sclrit', 'Remove protection'),
			)
			if (!confirmed) {
				return
			}
			this.busy = true
			try {
				const state = await unprotectFile(fileId)
				if (state.status === 'pending') {
					showInfo(t('sclrit', 'Unprotection queued — you will be notified.'))
				} else {
					showSuccess(t('sclrit', 'Seclore protection removed.'))
				}
			} catch (error) {
				showError(ocsErrorMessage(error))
			} finally {
				this.busy = false
				await this.load()
			}
		},

		formatTime(seconds: number): string {
			return new Date(seconds * 1000).toLocaleString()
		},
	},
})
</script>

<style scoped lang="scss">
.seclore-tab {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px;

	&__loading {
		margin-top: 24px;
	}

	&__icon :deep(svg) {
		width: 48px;
		height: 48px;
	}

	&__details {
		display: grid;
		grid-template-columns: auto 1fr;
		gap: 4px 16px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			overflow-wrap: anywhere;
		}
	}

	&__actions {
		display: flex;
		gap: 8px;
	}
}
</style>
