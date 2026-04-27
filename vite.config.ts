import react from '@vitejs/plugin-react'
import tsconfigPaths from 'vite-tsconfig-paths'
import alias from '@rollup/plugin-alias'
import path from 'path'
import { defineConfig } from 'vite'

// import liveReload from 'vite-plugin-live-reload'

import { v4wp } from '@kucrut/vite-for-wp'

export default defineConfig({
	server: {
		port: 5174,
		cors: {
			origin: '*',
			preflightContinue: true,
		},
		headers: {
			'Access-Control-Allow-Private-Network': 'true',
		},
		fs: {
			allow: ['./'],
		},
	},
	plugins: [
		alias(),
		react(),
		tsconfigPaths(),

		// liveReload(__dirname + '/**/*.php'), // Optional, if you want to reload page on php changed

		v4wp({
			input: 'js/src/main.tsx', // Optional, defaults to 'src/main.js'.
			outDir: 'js/dist', // Optional, defaults to 'dist'.
		}),
	],

	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'js/src'),
			// 把 @wordpress/i18n 的 import 導向本地 shim，讓 __()/sprintf() 直接使用 window.wp.i18n，
			// 與 Bootstrap.php::inject_locale_data_to_handle() 注入的 setLocaleData 共用同一個 store。
			'@wordpress/i18n': path.resolve(__dirname, 'js/src/shims/wordpress-i18n.ts'),
		},
	},
})
