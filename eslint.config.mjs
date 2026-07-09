// SPDX-License-Identifier: AGPL-3.0-or-later
import { recommendedVue2 } from '@nextcloud/eslint-config'

export default [
	{
		// Build output, dependencies and PHP vendor tree are never linted.
		ignores: ['js/', 'node_modules/', 'vendor/'],
	},
	...recommendedVue2,
	{
		rules: {
			// This app imports without file extensions (e.g. `./api`), which is
			// what Vite and vue-tsc (moduleResolution: Bundler) resolve natively;
			// requiring `.ts` would also need tsconfig's allowImportingTsExtensions.
			'import-extensions/extensions': 'off',
			// The codebase documents functions with short single-line summaries by
			// design, rather than a full @param block per argument.
			'jsdoc/require-param': 'off',
			'jsdoc/require-param-description': 'off',
			// This config's @nextcloud/vue prop deprecations target the v9 (Vue 3)
			// API, but the app pins @nextcloud/vue 8 (Vue 2): its autofixes rewrite
			// props that don't exist in v8 (type→variant, checked→model-value,
			// close-on-select→keep-open) and break the components at runtime.
			'@nextcloud/no-deprecated-library-props': 'off',
		},
	},
]
