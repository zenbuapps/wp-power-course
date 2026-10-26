/**
 * 後台 Tailwind 設定 → `js/dist/css/admin-utilities.min.css`
 *
 * 由 `Bootstrap::enqueue_admin_utility_style()` 在課程後台 SPA 頁載入，
 * 印在 Powerhouse admin-layout `<head>` 內的 `admin.min.css` 之後（元件包在 cascade layer 裡讓 Powerhouse 優先，
 * 見 `js/src/styles/tailwind-admin.css`）。
 * 為什麼要自己產、與 Powerhouse 的關係，見 `tailwind.config.base.cjs`。
 */

// eslint-disable-next-line no-undef
const { createConfig } = require('./tailwind.config.base.cjs')

// eslint-disable-next-line no-undef
module.exports = createConfig([
	// 後台 React SPA
	'./js/src/**/*.{js,ts,jsx,tsx}',
])
