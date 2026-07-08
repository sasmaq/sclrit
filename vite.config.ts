import { createAppConfig } from '@nextcloud/vite-config'

// Outputs js/files_seclore-{main,admin}.mjs, loaded via Util::addScript (SDD §3.2).
export default createAppConfig(
	{
		main: 'src/main.ts',
		admin: 'src/admin.ts',
	},
	{
		// Each entry point injects its own CSS: the admin page only loads
		// files_seclore-admin.mjs, so shared styles must not end up in main.
		inlineCSS: { relativeCSSInjection: true },
		minify: true,
	},
)
