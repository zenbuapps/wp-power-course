#!/usr/bin/env node
/**
 * 將 languages/power-course-{locale}.po 編譯為 languages/power-course-{locale}.mo。
 *
 * 設計動機：
 * - 原本使用 `wp i18n make-mo` 需要 WP-CLI，GitHub Actions runner 沒裝會壞。
 * - 改用純 node + gettext-parser（已是 devDependency），與 i18n:pot / i18n:json 工具鏈一致。
 *
 * 用法：
 *   node scripts/i18n-make-mo.mjs           # 編譯 languages/ 下所有 power-course-*.po
 *   node scripts/i18n-make-mo.mjs <dir>     # 指定輸出目錄（預設 languages）
 */
import { readFileSync, writeFileSync, readdirSync, existsSync, mkdirSync } from 'node:fs'
import { resolve, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import gettextParser from 'gettext-parser'

const __dirname = fileURLToPath(new URL('.', import.meta.url))
const projectRoot = resolve(__dirname, '..')
const TEXT_DOMAIN = 'power-course'

// 允許用 CLI 參數覆寫目錄，預設為 languages/
const targetDir = process.argv[2]
	? resolve(process.cwd(), process.argv[2])
	: join(projectRoot, 'languages')

if (!existsSync(targetDir)) {
	mkdirSync(targetDir, { recursive: true })
}

const poFiles = readdirSync(targetDir).filter((f) => {
	return f.startsWith(`${TEXT_DOMAIN}-`) && f.endsWith('.po')
})

if (poFiles.length === 0) {
	console.warn(`[i18n-make-mo] No ${TEXT_DOMAIN}-*.po files found in ${targetDir}`)
	process.exit(0)
}

let totalGenerated = 0

for (const poFile of poFiles) {
	const poPath = join(targetDir, poFile)
	const moPath = poPath.replace(/\.po$/, '.mo')

	const raw = readFileSync(poPath)
	const parsed = gettextParser.po.parse(raw)

	// 編譯為 .mo（binary）
	const mo = gettextParser.mo.compile(parsed)
	writeFileSync(moPath, mo)

	console.log(`[i18n-make-mo] ${poFile} → ${moPath}`)
	totalGenerated++
}

console.log(`[i18n-make-mo] Done. ${totalGenerated} .mo file(s) generated.`)
