#!/usr/bin/env node
/**
 * 從 PHP（inc/） 與 JS/TSX（js/src/） 原始碼掃出可翻譯字串，合併產出
 * languages/power-course.pot。
 *
 * 設計動機：
 * - 原本 `pnpm run i18n:pot` 直接呼叫 `@wp-blocks/make-pot` 掃整個專案，
 *   但該工具對 PHP 檔案的掃描覆蓋不全（Issue #208：漏掃 24 個 PHP 檔 / 71 條字串，
 *   整個 inc/templates/components/ 目錄全軍覆沒）。
 * - 新 pipeline 採混合官方工具：
 *   - PHP 端由 WP-CLI `wp i18n make-pot --skip-js` 掃描（WordPress 官方，覆蓋完整）
 *   - JS/TSX 端由 `gettext-extractor`（原生支援 TypeScript/TSX）掃描，
 *     並強制 domain 字面量必須為 'power-course'（嚴格過濾）
 *   - 以 `gettext-parser` 合併兩份中間 .pot，覆寫 header（含 UTF-8、X-Domain）
 *
 * 用法：
 *   node scripts/i18n-make-pot.mjs           # 產 languages/power-course.pot
 */
import {
	readFileSync,
	writeFileSync,
	mkdtempSync,
	rmSync,
	existsSync,
	mkdirSync,
} from 'node:fs'
import { resolve, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { tmpdir } from 'node:os'
import { spawnSync } from 'node:child_process'
import gettextParser from 'gettext-parser'
import { GettextExtractor, JsExtractors } from 'gettext-extractor'
import ts from 'typescript'

const __dirname = fileURLToPath(new URL('.', import.meta.url))
const projectRoot = resolve(__dirname, '..')
const TEXT_DOMAIN = 'power-course'
const PLUGIN_SLUG = 'power-course'
const OUTPUT_POT = join(projectRoot, 'languages', 'power-course.pot')
// 掃描目錄：
// - js/src/**：Admin SPA（React + TSX）
// - inc/assets/src/**：前台 vanilla TS（jQuery-based，走 @wordpress/i18n）
const JS_GLOBS = [
	'js/src/**/*.{ts,tsx,js,jsx}',
	'inc/assets/src/**/*.{ts,tsx,js,jsx}',
]
const EXCLUDES = [
	'node_modules',
	'vendor',
	'tests',
	'release',
	'js/dist',
	'languages',
	// shim 是 @wordpress/i18n 的 wrapper（透過 vite alias 注入），其 __()/_n()/_x()
	// 的 domain 必為變數（runtime 透傳），不是可翻譯字串來源，掃了只會誤報。
	'js/src/shims',
]

/** -------------------------------------------------------------------------
 * 1. 偵測 WP-CLI 是否可用，否則列印安裝指引並退出
 * -----------------------------------------------------------------------*/
function ensureWpCli() {
	const r = spawnSync('wp', ['--info'], {
		shell: true,
		encoding: 'utf8',
	})
	if (r.status !== 0) {
		console.error('')
		console.error('[i18n-make-pot] ❌ 找不到 WP-CLI（wp 指令）。')
		console.error('  i18n-make-pot 需要 WordPress CLI 來掃描 PHP 檔。')
		console.error('')
		console.error('  本地安裝：')
		console.error('    composer global require wp-cli/wp-cli-bundle')
		console.error('  （記得把 ~/.composer/vendor/bin 或 %APPDATA%\\Composer\\vendor\\bin 加入 PATH）')
		console.error('')
		console.error('  CI 環境：確認 .github/workflows/*.yml 有以下 step：')
		console.error('    - uses: shivammathur/setup-php@v2')
		console.error('      with:')
		console.error('        php-version: \'8.1\'')
		console.error('        tools: wp-cli, composer')
		console.error('')
		process.exit(1)
	}
	const version = (r.stdout || '').match(/WP-CLI version:\s*([\d.]+)/)?.[1] ?? '?'
	console.log(`[i18n-make-pot] WP-CLI detected: ${version}`)
}

/** -------------------------------------------------------------------------
 * 2. 呼叫 WP-CLI 產 PHP-only .pot
 * -----------------------------------------------------------------------*/
function runWpCliMakePot(phpPotPath) {
	console.log('[i18n-make-pot] Running wp i18n make-pot (PHP only)...')
	const args = [
		'i18n',
		'make-pot',
		'.',
		phpPotPath,
		`--slug=${PLUGIN_SLUG}`,
		`--domain=${TEXT_DOMAIN}`,
		'--skip-js',
		'--skip-audit',
		'--skip-block-json',
		'--skip-theme-json',
		`--exclude=${EXCLUDES.join(',')}`,
	]
	const r = spawnSync('wp', args, {
		cwd: projectRoot,
		shell: true,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	})
	if (r.stdout) process.stdout.write(r.stdout)
	if (r.status !== 0) {
		console.error(r.stderr || '[i18n-make-pot] WP-CLI make-pot 失敗')
		process.exit(2)
	}
	const parsed = gettextParser.po.parse(readFileSync(phpPotPath))
	const count = countMsgids(parsed)
	console.log(`[i18n-make-pot] PHP strings: ${count}`)
	return parsed
}

/** -------------------------------------------------------------------------
 * 3. 用 gettext-extractor 掃 JS/TSX
 *
 *    gettext-extractor 內建 callExpression extractor 不支援 domain 過濾，
 *    故包一層自訂 extractor：先檢 domain 參數為字面量且等於 'power-course'
 *    才呼叫內建 extractor 把字串加進 catalog。
 *
 *    涵蓋函式（對齊 @wordpress/i18n）：
 *      __(text, domain)                            domainArgIdx = 1
 *      _e(text, domain)                            domainArgIdx = 1
 *      _x(text, context, domain)                   domainArgIdx = 2
 *      _ex(text, context, domain)                  domainArgIdx = 2
 *      _n(single, plural, number, domain)          domainArgIdx = 3
 *      _nx(single, plural, number, context, domain) domainArgIdx = 4
 * -----------------------------------------------------------------------*/
const JS_FUNCTIONS = [
	{ names: ['__'], args: { text: 0 }, domainArgIdx: 1 },
	{ names: ['_e'], args: { text: 0 }, domainArgIdx: 1 },
	{ names: ['_x'], args: { text: 0, context: 1 }, domainArgIdx: 2 },
	{ names: ['_ex'], args: { text: 0, context: 1 }, domainArgIdx: 2 },
	{
		names: ['_n'],
		args: { text: 0, textPlural: 1 },
		domainArgIdx: 3,
	},
	{
		names: ['_nx'],
		args: { text: 0, textPlural: 1, context: 3 },
		domainArgIdx: 4,
	},
]

/** 自訂 extractor：包裝內建 callExpression extractor，先過濾 domain */
function makeDomainFilteredExtractor(spec, stats) {
	const builtin = JsExtractors.callExpression(spec.names, {
		arguments: spec.args,
		comments: {
			regex: /translators:\s*(.*)/i,
			otherLineLeading: true,
			sameLineLeading: true,
		},
	})

	return (node, sourceFile, addMessage, lineNumberStart) => {
		if (!ts.isCallExpression(node)) return

		// 取 callee 名稱（支援 __(...) 與 wp.i18n.__(...) 兩種形式）
		const expr = node.expression
		let name = null
		if (ts.isIdentifier(expr)) name = expr.text
		else if (ts.isPropertyAccessExpression(expr)) name = expr.name.text
		if (!name || !spec.names.includes(name)) return

		// 檢 domain 參數
		const arg = node.arguments[spec.domainArgIdx]
		const { line } = sourceFile.getLineAndCharacterOfPosition(node.getStart())
		const loc = `${sourceFile.fileName}:${line + 1}`

		if (!arg) {
			console.warn(`[i18n-make-pot] ⚠️  ${name}() 缺 domain 參數 @ ${loc}`)
			stats.skipNoDomain++
			return
		}
		if (!ts.isStringLiteral(arg) && !ts.isNoSubstitutionTemplateLiteral(arg)) {
			console.warn(`[i18n-make-pot] ⚠️  ${name}() domain 非字面量 @ ${loc}`)
			stats.skipNonLiteral++
			return
		}
		if (arg.text !== TEXT_DOMAIN) {
			console.warn(
				`[i18n-make-pot] ⚠️  ${name}() domain="${arg.text}" ≠ '${TEXT_DOMAIN}' @ ${loc}`
			)
			stats.skipOtherDomain++
			return
		}

		// 通過：委託內建 extractor 把字串丟進 catalog
		builtin(node, sourceFile, addMessage, lineNumberStart)
		stats.accepted++
	}
}

function extractJsTsx() {
	console.log('[i18n-make-pot] Scanning JS/TSX via gettext-extractor...')
	const extractor = new GettextExtractor()
	const stats = {
		accepted: 0,
		skipNoDomain: 0,
		skipNonLiteral: 0,
		skipOtherDomain: 0,
	}
	const jsParser = extractor.createJsParser(
		JS_FUNCTIONS.map((spec) => makeDomainFilteredExtractor(spec, stats))
	)
	for (const glob of JS_GLOBS) {
		jsParser.parseFilesGlob(glob, {
			cwd: projectRoot,
			ignore: EXCLUDES.map((e) => `${e}/**`),
		})
	}
	const potString = extractor.getPotString()
	const parsed = gettextParser.po.parse(potString)
	console.log(
		`[i18n-make-pot] JS/TSX strings: ${stats.accepted} ` +
			`(skipped: ${stats.skipNoDomain} no-domain, ${stats.skipNonLiteral} non-literal, ` +
			`${stats.skipOtherDomain} other-domain)`
	)
	return parsed
}

/** -------------------------------------------------------------------------
 * 4. 合併兩份 .pot
 *
 *    同 (msgctxt, msgid) 合併 references 與 extracted comments；
 *    plural 資訊優先保留已有的，缺則用對方的。
 * -----------------------------------------------------------------------*/
function mergePots(php, js) {
	for (const [ctx, msgs] of Object.entries(js.translations)) {
		php.translations[ctx] ??= {}
		for (const [msgid, entry] of Object.entries(msgs)) {
			if (msgid === '') continue
			const existing = php.translations[ctx][msgid]
			if (!existing) {
				php.translations[ctx][msgid] = entry
				continue
			}
			existing.comments ??= {}
			existing.comments.reference = unionLines(
				existing.comments?.reference,
				entry.comments?.reference
			)
			existing.comments.extracted = unionLines(
				existing.comments?.extracted,
				entry.comments?.extracted
			)
			if (!existing.msgid_plural && entry.msgid_plural) {
				existing.msgid_plural = entry.msgid_plural
				existing.msgstr = entry.msgstr
			}
		}
	}
	return php
}

function unionLines(a, b) {
	const set = new Set()
	for (const s of (a || '').split('\n')) if (s) set.add(s)
	for (const s of (b || '').split('\n')) if (s) set.add(s)
	return Array.from(set).join('\n')
}

/** -------------------------------------------------------------------------
 * 5. 覆寫 header
 *
 *    注意：gettext-parser 的 compile 行為：
 *    - 對「已知 header」（Content-Type、Project-Id-Version 等 10 個 gnu gettext
 *      規範的 key）會自動把 lowercase key 映射成 TitleCase 輸出；
 *    - 對自訂 header（MIME-Version、X-Generator、X-Domain 等）會保留原 key，
 *      因此要在 key 就寫 TitleCase。
 *    - charset 透過 `po.charset` 設，但 pocompiler 會呼叫 formatCharset 強制
 *      轉小寫（utf-8），此為 gettext-parser 行為，無影響實質編碼。
 *
 *    讀取 header 時 i18n-make-json 等下游 script 使用 lowercase key，
 *    詳見 commit 14a72e6e 的前例修復。
 * -----------------------------------------------------------------------*/
function applyHeaders(po) {
	const version = readPluginVersion()
	po.charset = 'utf-8'
	po.headers = {
		'Project-Id-Version': `Power Course ${version}`,
		'Report-Msgid-Bugs-To': 'https://wordpress.org/support/plugins/power-course',
		'MIME-Version': '1.0',
		'Content-Type': 'text/plain; charset=UTF-8',
		'Content-Transfer-Encoding': '8bit',
		'Plural-Forms': 'nplurals=2; plural=(n != 1);',
		'POT-Creation-Date': new Date().toISOString(),
		'PO-Revision-Date': 'YEAR-MO-DA HO:MI+ZONE',
		'Last-Translator': 'J7 <j7.dev.gg@gmail.com>',
		'Language-Team': 'J7 <j7.dev.gg@gmail.com>',
		'Language': 'en',
		'X-Generator': 'power-course/scripts/i18n-make-pot.mjs',
		'X-Domain': TEXT_DOMAIN,
	}
	return po
}

function readPluginVersion() {
	try {
		const php = readFileSync(join(projectRoot, 'plugin.php'), 'utf8')
		return php.match(/^\s*\*\s*Version:\s*([^\s]+)/m)?.[1] ?? '0.0.0-dev'
	} catch {
		return '0.0.0-dev'
	}
}

function countMsgids(po) {
	let n = 0
	for (const msgs of Object.values(po.translations)) {
		for (const msgid of Object.keys(msgs)) {
			if (msgid !== '') n++
		}
	}
	return n
}

/** -------------------------------------------------------------------------
 * 6. Main
 * -----------------------------------------------------------------------*/
async function main() {
	const tmpDir = mkdtempSync(join(tmpdir(), 'pc-i18n-'))
	let exitCode = 0
	try {
		ensureWpCli()

		const phpPotPath = join(tmpDir, 'php.pot')
		const phpPo = runWpCliMakePot(phpPotPath)

		const jsPo = extractJsTsx()

		const merged = mergePots(phpPo, jsPo)
		const withHeaders = applyHeaders(merged)

		const outDir = join(projectRoot, 'languages')
		if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true })

		writeFileSync(OUTPUT_POT, gettextParser.po.compile(withHeaders))

		const total = countMsgids(withHeaders)
		console.log(
			`[i18n-make-pot] Merged: ${total} unique msgids → languages/power-course.pot`
		)
		console.log('[i18n-make-pot] Done.')
	} catch (err) {
		console.error('[i18n-make-pot] ❌ Unexpected error:', err)
		exitCode = 4
	} finally {
		try {
			rmSync(tmpDir, { recursive: true, force: true })
		} catch {
			/* idempotent cleanup */
		}
	}
	process.exit(exitCode)
}

// 使用者 SIGINT 時也要能清理 tmp dir — 用 process-level handler
process.on('SIGINT', () => process.exit(130))
process.on('SIGTERM', () => process.exit(143))

main()
