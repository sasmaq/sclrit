<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Generic confirmation dialog; emits `close` with true (confirmed) or false.
  Used for the explicit unprotect confirmation (SDD §5.1).
-->
<template>
	<NcDialog :name="name" size="small" @update:open="onOpenChanged">
		<p class="confirm-dialog__message">{{ message }}</p>
		<template #actions>
			<NcButton @click="close(false)">
				{{ t('sclrit', 'Cancel') }}
			</NcButton>
			<NcButton :type="destructive ? 'error' : 'primary'" @click="close(true)">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import { defineComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'

export default defineComponent({
	name: 'ConfirmDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		name: {
			type: String,
			required: true,
		},
		message: {
			type: String,
			required: true,
		},
		confirmLabel: {
			type: String,
			required: true,
		},
		destructive: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			closed: false,
		}
	},

	methods: {
		t,

		close(confirmed: boolean) {
			if (!this.closed) {
				this.closed = true
				this.$emit('close', confirmed)
			}
		},

		onOpenChanged(open: boolean) {
			if (!open) {
				this.close(false)
			}
		},
	},
})
</script>

<style scoped>
.confirm-dialog__message {
	padding-block-end: 8px;
}
</style>
