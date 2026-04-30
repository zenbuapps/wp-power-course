import { defineConfig } from 'vite'
import tsconfigPaths from 'vite-tsconfig-paths'
import alias from '@rollup/plugin-alias'
import path from 'path'
// import liveReload from 'vite-plugin-live-reload'
import optimizer from 'vite-plugin-optimizer'
import { terser } from 'rollup-plugin-terser'

export default defineConfig({
	base: '/wp-content/plugins/power-course/inc/assets/dist/', // ★ 設定正確的公開路徑
	server: {
		port: 6174,
		cors: {
			origin: '*',
		},
		fs: {
			allow: ['./'],
		},
	},
	build: {
		emptyOutDir: true,
		minify: true,
		outDir: path.resolve(__dirname, 'inc/assets/dist'),
		// watch: {
		//   include: 'inc/**',
		//   exclude:
		//     'js/**, modules/**, node_modules/**, release/**, vendor/**, .git/**, .vscode/**',
		// },
		rollupOptions: {
			input: {
				index: 'inc/assets/src/main.ts',
				// Issue #10: 多影片試看的 Swiper bundle，僅在課程銷售頁有 2~6 部試看影片時 enqueue
				'trial-videos-swiper': 'inc/assets/src/trial-videos-swiper.ts',
			},
			output: {
				assetFileNames: '[ext]/[name].[ext]',
				entryFileNames: '[name].js',
			},
		},
	},
	plugins: [
		alias(),
		tsconfigPaths(),
		// liveReload([
		// 	__dirname + '/**/*.php',
		// ]),
		optimizer({
			jquery: 'const $ = window.jQuery; export { $ as default }',
		}),
		terser({
			mangle: {
				reserved: ['$'], // 指定 $ 不被改變
			},
		}),
	],
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'inc/assets/src'),
			// 把 @wordpress/i18n 的 import 導向本地 shim，讓前台 bundle 的 __()/sprintf() 直接使用
			// window.wp.i18n（與 inject_locale_data_to_handle() 注入的 setLocaleData 共用同一 store）。
			// 不走 shim 的話 Vite 會打包獨立的 @wordpress/i18n，它會維護自家 i18n store 永遠讀不到翻譯。
			// 與 vite.config.ts 共用同一份 shim，避免重複。
			'@wordpress/i18n': path.resolve(__dirname, 'js/src/shims/wordpress-i18n.ts'),
		},
	},
})
