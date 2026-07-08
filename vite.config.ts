import { createAppConfig } from '@nextcloud/vite-config'

// Outputs js/files_seclore-main.mjs, loaded via Util::addScript (SDD §3.2).
export default createAppConfig(
	{
		main: 'src/main.ts',
	},
	{
		inlineCSS: true,
		minify: true,
	},
)
