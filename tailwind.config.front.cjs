/**
 * 前台 Tailwind 設定 → `inc/assets/dist/css/front-components.min.css` + `front-utilities.min.css`
 *
 * 由 `Templates\Ajax::enqueue_utility_style()` 載入：元件排在 Powerhouse 的 `front.min.css` 之前、
 * utility 排在之後（理由見 `inc/assets/src/styles/tailwind-front-components.css`）。
 * 為什麼要自己產、與 Powerhouse 的關係，見 `tailwind.config.base.cjs`。
 */

// eslint-disable-next-line no-undef
const { createConfig } = require('./tailwind.config.base.cjs')

// eslint-disable-next-line no-undef
module.exports = createConfig([
	// PHP 模板、PHP 輸出的 HTML、前台 vanilla TS（inc/assets/src）動態加的 class
	'./inc/**/*.{php,js,ts,jsx,tsx}',
	// 前台 vidstack 播放器（inc/templates/components/video/vidstack → main.tsx 的 App2）
	// 是 React 元件，class 寫在 js/src 裡；後台 SPA 其餘檔案不在前台渲染，不掃
	'./js/src/App2/**/*.{ts,tsx}',
	'./js/src/components/general/WaterMark/**/*.{ts,tsx}',
	// 建置產物本身不能當掃描來源，否則上一次 build 的 class 會一直殘留
	'!./inc/assets/dist/**',
])
