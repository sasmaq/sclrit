/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Lightweight stand-ins for the @nextcloud/vue components: the specs assert
 * this app's behaviour, not the library's rendering. Use them from a spec via
 * vi.mock('@nextcloud/vue/dist/Components/<Name>.js', ...).
 */

export const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button class="nc-button" :data-type="type" :disabled="disabled" v-on="$listeners"><slot /></button>',
}

export const NcDialogStub = {
	name: 'NcDialog',
	props: ['name'],
	template: `
		<div class="nc-dialog" :data-name="name">
			<slot />
			<div class="nc-dialog__actions"><slot name="actions" /></div>
		</div>`,
}

export const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'label'],
	template: '<div class="nc-select" />',
}

export const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div class="nc-note-card" :data-type="type"><slot /></div>',
}

export const NcEmptyContentStub = {
	name: 'NcEmptyContent',
	props: ['name', 'description'],
	template: `
		<div class="nc-empty-content" :data-name="name">
			<slot name="icon" />
			<slot name="action" />
		</div>`,
}

export const NcLoadingIconStub = {
	name: 'NcLoadingIcon',
	props: ['size'],
	template: '<div class="nc-loading-icon" />',
}

export const NcSettingsSectionStub = {
	name: 'NcSettingsSection',
	props: ['name', 'description'],
	template: '<section class="nc-settings-section" :data-name="name"><slot /></section>',
}

// The real fields use v-model with a custom model option (value/update:value).
const fieldStub = (name: string, className: string) => ({
	name,
	model: { prop: 'value', event: 'update:value' },
	props: ['value', 'label', 'type', 'placeholder'],
	template: `<input class="${className}" :data-label="label" :value="value"
		@input="$emit('update:value', $event.target.value)" />`,
})

export const NcTextFieldStub = fieldStub('NcTextField', 'nc-text-field')

export const NcPasswordFieldStub = fieldStub('NcPasswordField', 'nc-password-field')

export const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['checked', 'type'],
	template: `<label class="nc-checkbox">
		<input type="checkbox" :checked="checked" @change="$emit('update:checked', $event.target.checked)" />
		<slot />
	</label>`,
}
