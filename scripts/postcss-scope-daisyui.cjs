/**
 * PostCSS plugin：把 daisyUI 產出的選擇器收進 Tailwind 的 important scope（`#tw`）
 *
 * 移植自 Powerhouse 的 `scripts/postcss-scope-daisyui.cjs`（Issue #285）。
 * Power Course 自產的 front / admin utility CSS 必須與 Powerhouse 輸出**相同的選擇器**，
 * 否則同一個元件在兩份 CSS 裡 specificity 不同，誰贏就看載入順序以外的東西了。
 * 下文提到的 `tailwind.config.cjs`、`_daisyui.scss` 指的是 Powerhouse 那邊的檔案；
 * 本專案的 scope 讀自 `tailwind.config.base.cjs`。
 *
 * ## 為什麼需要這一步
 *
 * daisyUI v4 **沒有** `important` 之類的設定（config 只有 styled / themes / base /
 * utils / logs / darkTheme / prefix / themeRoot），它的元件 class 一律以
 * specificity **(0,1,0)** 輸出，例如 `.pc-btn{...}`。
 *
 * 而 Tailwind 的 `important: '#tw'` **只作用在 utilities**，不會套到外掛用
 * `addComponents` 加進來的規則——實測 front.min.css 內有 210 條未加 scope 的
 * `.pc-*` 規則，只有 23 條帶 `#tw`（那 23 條是 `_daisyui.scss` 手寫補的）。
 *
 * 於是在 WordPress 前台會發生這件事：
 *
 *   佈景主題（實測 Blocksy）有一條 `.button, .ct-button, [type="submit"], …`
 *   的規則，specificity 同樣是 (0,1,0)。**同分時由載入順序決勝**，而主題 CSS
 *   排在外掛 CSS 之後 → **主題永遠贏**，daisyUI 的 `pc-btn-sm` / `pc-btn-ghost`
 *   / `pc-btn-circle` 全部被蓋掉，按鈕變成主題的尺寸與配色。
 *
 * Tailwind utility 之所以免疫，正是因為 `#tw .h-8` 是 (1,1,0)，嚴格大於主題那條，
 * 與載入順序無關。這個 plugin 讓 daisyUI 得到同樣的保護。
 *
 * ## 為什麼可以安全地機械化處理
 *
 * `tailwind.config.cjs` 設了 `daisyui.prefix: 'pc-'`，所以 daisyUI 的**每一個**
 * 元件 / modifier / 響應式 class 都帶 `pc-` 前綴——那個前綴本來只是用來避免命名
 * 衝突，這裡剛好變成一個**可靠且完整的識別標記**，讓轉換不會漏也不會誤傷。
 *
 * 刻意**不動**的東西：
 *   - `:root` / `[data-theme=xxx]` 的 theme 變數宣告（必須維持全域，否則
 *     `#tw` 之外的元素拿不到 `--p` / `--b1`，色彩系統整個失效）
 *   - `@keyframes` 內的 `from` / `to` / `0%` 等 step
 *   - 未加前綴的色彩 / 圓角 utility（`bg-primary` / `rounded-box`）——它們是
 *     Tailwind utility，本來就已經被 `important` 收進 scope
 *   - 已經帶 `#tw` 的規則（`_daisyui.scss` 手寫那 23 條）
 *
 * ## 為什麼不會反過來蓋掉 Tailwind utility
 *
 * 轉換後 daisyUI 元件是 `#tw .pc-btn` (1,1,0)，Tailwind utility 是
 * `#tw .h-8` (1,1,0)——**同分**。但 Tailwind 的輸出順序是 base → components →
 * utilities，utility 排在後面 → utility 仍然勝出。也就是「utility 覆寫元件樣式」
 * 這個 Tailwind 的基本心智模型完全不變。
 *
 * ## 前提
 *
 * 轉換後 daisyUI 元件只在 `#tw` 的**後代**生效。目前保證：
 *   - 前台：`Theme\Core\FrontEnd::add_html_attr()` 在 `<html>` 加 `id="tw"`
 *   - 後台：`inc/templates/pages/admin-layout/index.php` 的 `<body id="tw">`
 * 任何自己印 HTML 骨架的地方（例如 power-shop 的 partner portal）都必須自帶
 * `id="tw"`，否則 daisyUI 樣式會**靜默全失效**。
 *
 * ## 已知涵蓋不到的情況
 *
 * 本轉換只能讓「daisyUI 有規則、但輸給主題」的情況翻盤。若 daisyUI **根本沒有**
 * 對某個元素出規則（例如 `.pc-modal-backdrop` 內那顆 `<button>`，daisyUI 只給
 * form 本身出規則、沒管 button），主題的樣式沒有對手，仍然要由使用端自己加
 * Tailwind utility（`bg-transparent` 之類）壓過去。
 */

const path = require('path')

/** 從 tailwind.config.base.cjs 讀 important scope，避免與 Tailwind 設定漂移 */
function resolveScope() {
	// eslint-disable-next-line global-require
	const important = require(path.join(__dirname, '..', 'tailwind.config.base.cjs')).IMPORTANT_SCOPE

	if (typeof important !== 'string' || important.trim() === '') {
		throw new Error(
			'[scope-daisyui] tailwind.config.base.cjs 的 IMPORTANT_SCOPE 必須是 selector 字串（例如 "#tw"）；' +
				`目前為 ${JSON.stringify(important)}，無法決定 daisyUI 的 scope。`
		)
	}

	return important.trim()
}

/**
 * 選擇器是否引用了 daisyUI 的 class
 *
 * 比對 `.pc-` 與 `\:pc-` 兩種形式：後者是 Tailwind 對響應式變體的轉義寫法
 * （`sm:pc-btn-sm` → `.sm\:pc-btn-sm`）。刻意要求 `pc-` 緊接在 `.` 或 `\:` 之後，
 * 才不會誤傷 `.upc-code` 這種剛好含有 `pc-` 的第三方 class。
 */
const DAISY_CLASS = /(?:\.|\\:)pc-/

/**
 * 選擇器的第一個 compound 是否已經錨定在文件根部
 *
 * ⚠ 這類選擇器**絕對不能**加 scope。`#tw` 本身就掛在 `<html>`（前台，由
 * `FrontEnd::add_html_attr()` 加）或 `<body>`（後台 admin-layout），
 * 把 `:root .pc-countdown` 變成 `#tw :root .pc-countdown` 之後，會變成
 * 「找 `#tw` 底下的 `:root` 後代」——`:root` 永遠是 `<html>`、不可能是自己的後代，
 * **這條規則從此永遠匹配不到**，而且不會有任何錯誤訊息。
 *
 * 實際踩到的兩個案例（daisyUI v4.12）：
 *   - `:root .pc-countdown`：倒數計時元件的字型設定整個失效
 *   - `:root:has(:is(.pc-modal-open, .pc-modal[open], …))`：Modal 開啟時鎖住
 *     背景捲動的規則失效 → Modal 開著還能捲動背景
 *
 * 這類規則本來就已經是 (0,2,0) 以上、或根本沒有主題會來競爭，不加 scope 也安全。
 */
const ROOT_ANCHORED = /^\s*(?::root\b|html\b|body\b|\*|\[data-theme)/

/**
 * @param {{ scope?: string }} [options] scope 選擇器；未指定時從 tailwind.config.cjs 讀
 */
const plugin = (options = {}) => {
	const scope = options.scope || resolveScope()
	let scopedCount = 0
	let skippedRootAnchored = 0

	return {
		postcssPlugin: 'powerhouse-scope-daisyui',

		Once() {
			scopedCount = 0
			skippedRootAnchored = 0
		},

		Rule(rule) {
			// @keyframes 內的 step（from / to / 50%）不是選擇器，跳過
			if (
				rule.parent &&
				rule.parent.type === 'atrule' &&
				/keyframes$/i.test(rule.parent.name)
			) {
				return
			}

			if (!DAISY_CLASS.test(rule.selector)) {
				return
			}

			rule.selectors = rule.selectors.map((selector) => {
				// 該逗號分段本身沒有 daisyUI class（例如 `:root` 與 `.pc-x` 併在同一組）→ 不動
				if (!DAISY_CLASS.test(selector)) {
					return selector
				}
				// 已經在 scope 內（_daisyui.scss 手寫的那批）→ 不重複加
				if (selector.includes(scope)) {
					return selector
				}
				// 已錨定在文件根部 → 加了 scope 會永遠匹配不到（見 ROOT_ANCHORED 說明）
				if (ROOT_ANCHORED.test(selector)) {
					skippedRootAnchored += 1
					return selector
				}
				scopedCount += 1
				return `${scope} ${selector}`
			})
		},

		OnceExit() {
			// 印出處理量，讓 build log 看得出這一步真的有跑。
			// 數字掉到 0 通常代表 daisyUI 換了 prefix 或選擇器格式，樣式會靜默失去保護。
			// eslint-disable-next-line no-console
			console.log(
				`✓ scope-daisyui: 已把 ${scopedCount} 條 daisyUI 選擇器收進 "${scope}"` +
					`（另有 ${skippedRootAnchored} 條已錨定在根部，刻意不加）`
			)
		},
	}
}

plugin.postcss = true

module.exports = plugin
