#!/usr/bin/env node
/**
 * sync-manual-from-pot.mjs
 *
 * 從 `languages/power-course.pot` 取出所有 msgid，
 * 將 `scripts/i18n-translations/manual.json` 缺漏的 entry append 進去（msgstr 暫空）。
 *
 * 既有 entry 完全不動：
 * - msgid 已存在則略過
 * - 既有 msgstr_zh_TW / msgstr_ja 內容保留
 *
 * 用法：
 *   node scripts/sync-manual-from-pot.mjs            # 同步並寫回 manual.json
 *   node scripts/sync-manual-from-pot.mjs --dry-run  # 印出將被新增的 msgid 但不寫檔
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const POT_PATH = join(ROOT, 'languages/power-course.pot');
const MANUAL_PATH = join(ROOT, 'scripts/i18n-translations/manual.json');

const LOCALES = ['zh_TW', 'ja'];
const dryRun = process.argv.includes('--dry-run');

if (!existsSync(POT_PATH)) {
	console.error(`✗ .pot 檔不存在：${POT_PATH}\n  請先跑 pnpm run i18n:pot`);
	process.exit(1);
}

function unescape(s) {
	return s.replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
}

function parsePot(content) {
	const lines = content.split(/\r?\n/);
	const entries = [];
	let cur = null;
	let lastKey = null;
	const flush = () => {
		if (cur && cur.msgid !== undefined) entries.push(cur);
		cur = { comments: [] };
		lastKey = null;
	};
	flush();
	for (const line of lines) {
		if (line === '') {
			flush();
			continue;
		}
		if (line.startsWith('#')) {
			cur.comments.push(line);
			lastKey = null;
			continue;
		}
		const m = line.match(/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/);
		if (m) {
			const key = m[1];
			const val = unescape(m[2]);
			if (!key.startsWith('msgstr')) {
				cur[key] = val;
				lastKey = key;
			} else {
				lastKey = null; // .pot 沒有實際 msgstr 內容
			}
			continue;
		}
		const cont = line.match(/^"(.*)"$/);
		if (cont && lastKey) {
			cur[lastKey] += unescape(cont[1]);
		}
	}
	flush();
	return entries.filter((e) => e.msgid !== '');
}

const potRaw = readFileSync(POT_PATH, 'utf8');
const potEntries = parsePot(potRaw);
console.log(`[sync] .pot 載入 ${potEntries.length} 筆 entries`);

const manualRaw = readFileSync(MANUAL_PATH, 'utf8');
const manual = JSON.parse(manualRaw);
console.log(`[sync] manual.json 既有 ${manual.length} 筆 entries`);

const existingKeys = new Set(
	manual
		.filter((e) => e.msgid !== undefined)
		.map((e) => (e.msgctxt ? `${e.msgctxt}${e.msgid}` : e.msgid))
);

const appended = [];
for (const pe of potEntries) {
	const key = pe.msgctxt ? `${pe.msgctxt}${pe.msgid}` : pe.msgid;
	if (existingKeys.has(key)) continue;
	const entry = {};
	if (pe.msgctxt) entry.msgctxt = pe.msgctxt;
	entry.msgid = pe.msgid;
	if (pe.msgid_plural) entry.msgid_plural = pe.msgid_plural;
	for (const loc of LOCALES) {
		if (pe.msgid_plural) {
			entry[`msgstr_${loc}`] = [''];
		} else {
			entry[`msgstr_${loc}`] = '';
		}
	}
	const cmt = (pe.comments || []).find((c) => c.startsWith('#:'));
	if (cmt) entry.context = cmt.replace(/^#:\s*/, '').trim();
	appended.push(entry);
}

console.log(`[sync] 將新增 ${appended.length} 筆 entries（既有 ${manual.length} 筆完全保留）`);

if (dryRun) {
	console.log('\n--- DRY RUN，僅列出前 20 筆 ---');
	for (const e of appended.slice(0, 20)) {
		console.log(`  + msgid: "${e.msgid}"${e.msgctxt ? ` (msgctxt: ${e.msgctxt})` : ''}`);
	}
	if (appended.length > 20) console.log(`  ...還有 ${appended.length - 20} 筆`);
	process.exit(0);
}

if (appended.length === 0) {
	console.log('[sync] manual.json 已涵蓋所有 .pot msgid，無需更新');
	process.exit(0);
}

const merged = [...manual, ...appended];
writeFileSync(MANUAL_PATH, JSON.stringify(merged, null, '\t') + '\n', 'utf8');
console.log(`[sync] ✓ manual.json 已更新：${manual.length} → ${merged.length} 筆 entries`);
