import vue2 from '@vitejs/plugin-vue2'
import { defineConfig } from 'vitest/config'

// Standalone test config: the app's vite.config.ts is a production build
// config (createAppConfig) and must not leak minification/licensing plugins
// into the test pipeline.
export default defineConfig({
	plugins: [vue2()],
	resolve: {
		// Full build: component specs stub @nextcloud/vue components with
		// string templates, which need the runtime template compiler.
		alias: { vue: 'vue/dist/vue.esm.js' },
	},
	test: {
		environment: 'jsdom',
		server: {
			deps: {
				// Transform instead of importing natively: its cancelable-promise
				// dependency is CJS and lacks the named exports node expects.
				inline: ['@nextcloud/files'],
			},
		},
		include: ['tests/js/**/*.spec.ts'],
		setupFiles: ['tests/js/setup.ts'],
		clearMocks: true,
	},
})
