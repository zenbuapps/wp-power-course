#!/usr/bin/env node
/**
 * 從 languages/power-course-{locale}.po 產出 JED 格式 JSON。
 * 檔名固定為 power-course-{locale}.json（不含 md5 後綴），
 * 由 Bootstrap.php 讀取後透過 wp_add_inline_script 注入到 React bundle 之前。
 *
 * 用法：node scripts/i18n-make-json.mjs
 */
import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync } from 'node:fs'
import { resolve, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import gettextParser from 'gettext-parser'

const __dirname = fileURLToPath(new URL('.', import.meta.url))
const projectRoot = resolve(__dirname, '..')
const LANGUAGES_DIR = join(projectRoot, 'languages')
const TEXT_DOMAIN = 'power-course'
const JED_DOMAIN = 'messages'

if (!existsSync(LANGUAGES_DIR)) {
	mkdirSync(LANGUAGES_DIR, { recursive: true })
}

const poFiles = readdirSync(LANGUAGES_DIR).filter((f) => {
	return f.startsWith(`${TEXT_DOMAIN}-`) && f.endsWith('.po')
})

if (poFiles.length === 0) {
	console.warn(`[i18n-make-json] No ${TEXT_DOMAIN}-*.po files found in ${LANGUAGES_DIR}`)
	process.exit(0)
}

let totalGenerated = 0

for (const poFile of poFiles) {
	const match = poFile.match(new RegExp(`^${TEXT_DOMAIN}-(.+)\\.po$`))
	if (!match) continue
	const locale = match[1]

	const poPath = join(LANGUAGES_DIR, poFile)
	const raw = readFileSync(poPath)
	const parsed = gettextParser.po.parse(raw)

	// gettext-parser 回傳的 header key 保留 .po 原始大小寫（實測為 'Plural-Forms'），
	// 並非舊註解講的「統一轉小寫」。優先讀大寫、再小寫 fallback 防套件未來改行為，
	// 兩者皆缺才退英文複數規則。
	// 若 Plural-Forms 讀錯，zh_TW 等 nplurals=1 語系會套到英文 rule `n != 1`，
	// _n(n>=2) 時算出 index 1，但 JSON 只有 index 0 → 取不到翻譯直接退回英文 msgid。
	const pluralForms =
		parsed.headers['Plural-Forms'] ||
		parsed.headers['plural-forms'] ||
		'nplurals=2; plural=n != 1;'
	const localeData = {
		'': {
			domain: JED_DOMAIN,
			lang: locale,
			'plural-forms': pluralForms,
		},
	}

	let count = 0
	for (const [msgctxt, msgs] of Object.entries(parsed.translations)) {
		for (const [msgid, entry] of Object.entries(msgs)) {
			if (msgid === '') continue
			if (!entry.msgstr || entry.msgstr.every((s) => !s)) continue
			const key = msgctxt ? `${msgctxt}\u0004${msgid}` : msgid
			localeData[key] = entry.msgstr
			count++
		}
	}

	const jed = {
		'translation-revision-date':
			parsed.headers['po-revision-date'] || new Date().toISOString(),
		generator: 'power-course/i18n-make-json',
		domain: JED_DOMAIN,
		locale_data: {
			[JED_DOMAIN]: localeData,
		},
	}

	const outFile = join(LANGUAGES_DIR, `${TEXT_DOMAIN}-${locale}.json`)
	writeFileSync(outFile, JSON.stringify(jed, null, '\t'), 'utf8')
	console.log(`[i18n-make-json] ${locale}: ${count} translations → ${outFile}`)
	totalGenerated++
}

console.log(`[i18n-make-json] Done. ${totalGenerated} JSON file(s) generated.`)
