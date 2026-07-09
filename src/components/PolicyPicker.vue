<!--
  SPDX-License-Identifier: AGPL-3.0-or-later

  Policy picker dialog (SDD §5.2): NcSelect over the Hot Folder list, default
  pre-selected, description shown so users understand the rights they apply.
  Emits `close` with the chosen policy id, or without payload on cancel.
-->
<template>
	<NcDialog
		:name="t('sclrit', 'Protect with Seclore')"
		size="small"
		@update:open="onOpenChanged">
		<div class="policy-picker">
			<p class="policy-picker__hint">
				{{ t('sclrit', 'The chosen policy travels with the file and is enforced by Seclore wherever it goes.') }}
			</p>
			<NcSelect
				v-model="selected"
				class="policy-picker__select"
				:options="policies"
				label="name"
				:clearable="false"
				:aria-label-combobox="t('sclrit', 'Protection policy')" />
			<p v-if="selected && selected.description" class="policy-picker__description">
				{{ selected.description }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="cancel">
				{{ t('sclrit', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!selected" @click="confirm">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script lang="ts">
import type { PropType } from 'vue'
import type { Policy } from '../api'

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { defineComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default defineComponent({
	name: 'PolicyPicker',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},

	props: {
		policies: {
			type: Array as PropType<Policy[]>,
			required: true,
		},

		preselectedId: {
			type: String,
			default: '',
		},

		fileCount: {
			type: Number,
			default: 1,
		},
	},

	data() {
		return {
			selected: (this.policies.find((p) => p.id === this.preselectedId) ?? this.policies[0] ?? null) as Policy | null,
			closed: false,
		}
	},

	computed: {
		confirmLabel(): string {
			return n('sclrit', 'Protect %n file', 'Protect %n files', this.fileCount)
		},
	},

	methods: {
		t,

		confirm() {
			if (this.selected && !this.closed) {
				this.closed = true
				this.$emit('close', this.selected.id)
			}
		},

		cancel() {
			if (!this.closed) {
				this.closed = true
				this.$emit('close')
			}
		},

		onOpenChanged(open: boolean) {
			if (!open) {
				this.cancel()
			}
		},
	},
})
</script>

<style scoped lang="scss">
.policy-picker {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-block-end: 8px;

	&__select {
		width: 100%;
	}

	&__description {
		color: var(--color-text-maxcontrast);
	}
}
</style>
