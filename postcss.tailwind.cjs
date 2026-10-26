/**
 * Tailwind CLI 專用的 PostCSS 設定（`build:css:*` 用 `--postcss ./postcss.tailwind.cjs` 指定）
 *
 * 刻意不取名 `postcss.config.cjs`：Vite 會自動載入那個檔名，前台 / 後台的 Vite build
 * 就會多跑這兩個 plugin。Tailwind CLI 則**不會**自動讀任何 PostCSS 設定，必須用旗標指定。
 *
 * 這裡只放「Tailwind 產出之後」要跑的處理（Tailwind 本身由 CLI 編譯，不要在這裡再 require）：
 *   - `postcss-scope-daisyui`：把 daisyUI 選擇器收進 `#tw`，與 Powerhouse 的輸出一致
 *   - `autoprefixer`：瀏覽器前綴
 */

// eslint-disable-next-line no-undef
module.exports = {
	plugins: [
		// eslint-disable-next-line no-undef
		require('./scripts/postcss-scope-daisyui.cjs')(),
		// eslint-disable-next-line no-undef
		require('autoprefixer'),
	],
}
