/**
 * Power Course 自產 Tailwind CSS 的共用設定（前台 / 後台兩份 config 共用）
 *
 * ## 為什麼 Power Course 要自己產 utility CSS（Issue #285）
 *
 * Power Course 的模板與 React 元件原本完全依賴 Powerhouse 的 `front.min.css` /
 * `admin.min.css`。那兩份 CSS 是 Powerhouse 建置時用 `'../power-*' + '/inc/**'`
 * 這種**相對路徑**去掃「旁邊剛好放著的外掛」產生的，有兩個結構性問題：
 *
 *   1. 建置環境旁邊沒有 power-course（worktree / CI）→ 整批被 purge。
 *      Powerhouse 3.6.0 就這樣前台少了 786 條、後台少了 787 條規則：
 *        - `[&_.active]:!tw-block` 不見 →「我的課程」分頁永遠 `display:none`
 *        - `size-3` / `sm:size-4` 不見 → 教室頁 SVG 圖示撐到數百 px
 *        - `pc-drawer` / `pc-modal` / `pc-progress` / `pc-rating` / `pc-countdown` 等 daisyUI 元件不見
 *        - 後台 `grid-cols-12`、`lg:grid-cols-[1fr_32rem]` 等版面 class 不見
 *   2. 版本錯位：Power Course 新版模板用了新 class，要等 Powerhouse 重新建置發版才會有樣式
 *
 * 「用了哪些 class」只有 Power Course 自己知道，所以由 Power Course 自己掃、自己產。
 *
 * ## 與 Powerhouse 的關係
 *
 * 本檔必須與 Powerhouse 的 `tailwind.config.cjs` / `tailwind.config.front.cjs`
 * **產出相同的規則**（同樣的 `important`、斷點、自訂 utility、blocklist、daisyUI prefix）。
 * 這樣 Powerhouse 正常時兩份 CSS 只是重複宣告同樣的值；Powerhouse 漏掉時由我們補上。
 * Powerhouse 那邊改了設定，記得同步這裡。
 *
 * 刻意與 Powerhouse 不同的地方：
 *   - `content` 只掃 Power Course 自己（由各 config 指定）
 *   - daisyUI `themes: []` / `base: false`：主題色變數（`:root` / `[data-theme=xxx]`）與
 *     daisyUI base 一律由 Powerhouse 提供。我們的 CSS 載入在後，若也輸出 `:root{--p:…}`，
 *     會把站長在 Powerhouse 選的主題色蓋回預設值
 *   - 不帶 Powerhouse 的 safelist：那是 Powerhouse 自己動態組 class 用的，我們全靠掃描
 */

/** 與 Powerhouse 相同的 important scope；`scripts/postcss-scope-daisyui.cjs` 也讀這個值 */
const IMPORTANT_SCOPE = '#tw'

/**
 * 產生 Tailwind config
 *
 * @param {string[]} content 要掃描的檔案 glob
 * @return {import('tailwindcss').Config}
 */
function createConfig(content) {
	return {
		important: IMPORTANT_SCOPE,
		corePlugins: {
			preflight: false,
		},
		future: {
			disableColorOpacityUtilitiesByDefault: true,
			respectDefaultRingColorOpacity: true,
		},
		colorSpace: 'srgb',
		content,
		theme: {
			extend: {
				animation: {
					pulse: 'tw-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
				},
				screens: {
					sm: '576px', // iphone SE
					md: '810px', // ipad Portrait
					lg: '1080px', // ipad Landscape
					xl: '1280px', // mac air
					xxl: '1440px',
				},
				keyframes: {
					'tw-pulse': {
						'50%': { opacity: '0.5' },
					},
				},
			},
		},
		plugins: [
			// eslint-disable-next-line no-undef
			require('daisyui'),
			function ({ addUtilities, addComponents }) {
				const newUtilities = {
					'.right-to-left': {
						direction: 'rtl',
					},
					// 與 WordPress 衝突的 class
					'.tw-hidden': {
						display: 'none',
					},
					'.tw-columns-1': {
						columnCount: 1,
					},
					'.tw-columns-2': {
						columnCount: 2,
					},
					'.tw-fixed': {
						position: 'fixed',
					},
					'.tw-block': {
						display: 'block',
					},
					'.tw-inline': {
						display: 'inline',
					},
					'.tw-blur': {
						'--tw-blur': 'blur(8px)',
						filter:
							'var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)',
					},
				}
				addUtilities(newUtilities, ['responsive', 'hover'])

				addComponents({
					'.tw-container': {
						maxWidth: '100%',
						marginLeft: 'auto',
						marginRight: 'auto',
						paddingLeft: '1rem',
						paddingRight: '1rem',
						'@screen sm': {
							maxWidth: '640px',
						},
						'@screen md': {
							maxWidth: '768px',
						},
						'@screen lg': {
							maxWidth: '1024px',
						},
						'@screen xl': {
							maxWidth: '1280px',
						},
					},
				})
			},
		],
		// 與 WordPress / 佈景主題同名的 class，改用上面的 tw-* 版本
		blocklist: [
			'hidden',
			'columns-1',
			'columns-2',
			'fixed',
			'block',
			'inline',
			'blur',
			'size-full',
			'container',
			'rtl',
		],
		daisyui: {
			themes: [],
			base: false,
			logs: false,
			prefix: 'pc-', // 與 Powerhouse 相同，模板用的是 pc-btn / pc-drawer …
		},
	}
}

// eslint-disable-next-line no-undef
module.exports = { createConfig, IMPORTANT_SCOPE }
