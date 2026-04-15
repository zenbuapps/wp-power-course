#!/usr/bin/env node
/**
 * build-zhtw-po.mjs
 *
 * 依據：
 *   - `languages/power-course.pot`（新產生，msgid 全部英文）
 *   - `scripts/i18n-translations/*.json`（Phase 1/2 各 batch 產出的英中對照表）
 *   - 既有 `languages/power-course-zh_TW.po`（保留已有英文 msgid 的翻譯）
 *
 * 產出：
 *   - `languages/power-course-zh_TW.po`（重建）
 *
 * JSON 格式：
 *   [
 *     { "msgid": "Course name", "msgstr_zh_TW": "課程名稱", "context": "inc/templates/..." },
 *     ...
 *   ]
 */

import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

const POT_PATH = join(ROOT, 'languages/power-course.pot');
const OLD_PO_PATH = join(ROOT, 'languages/power-course-zh_TW.po');
const NEW_PO_PATH = join(ROOT, 'languages/power-course-zh_TW.po');
const JSON_DIR = join(ROOT, 'scripts/i18n-translations');

/**
 * 極簡 PO 解析器：支援 msgid / msgstr / msgctxt / msgid_plural / msgstr[n] / #: 註解。
 * 回傳 { header: string, entries: Array<{ comments: string[], msgctxt?: string, msgid: string, msgid_plural?: string, msgstr: string | string[] }> }
 */
function parsePo(content) {
	const lines = content.split(/\r?\n/);
	const entries = [];
	let current = null;
	let lastKey = null;

	const flush = () => {
		if (current && (current.msgid !== undefined)) {
			entries.push(current);
		}
		current = { comments: [] };
		lastKey = null;
	};

	flush();

	for (const line of lines) {
		if (line === '') {
			flush();
			continue;
		}
		if (line.startsWith('#')) {
			current.comments.push(line);
			lastKey = null;
			continue;
		}
		const m = line.match(/^(msgctxt|msgid|msgid_plural|msgstr(?:\[\d+\])?)\s+"(.*)"$/);
		if (m) {
			const key = m[1];
			const val = unescape(m[2]);
			if (key.startsWith('msgstr[')) {
				if (!Array.isArray(current.msgstr)) current.msgstr = [];
				const idx = parseInt(key.match(/\d+/)[0], 10);
				current.msgstr[idx] = val;
				lastKey = key;
			} else {
				current[key] = val;
				lastKey = key;
			}
			continue;
		}
		const cont = line.match(/^"(.*)"$/);
		if (cont && lastKey) {
			const val = unescape(cont[1]);
			if (lastKey.startsWith('msgstr[')) {
				const idx = parseInt(lastKey.match(/\d+/)[0], 10);
				current.msgstr[idx] += val;
			} else {
				current[lastKey] += val;
			}
		}
	}
	flush();

	// 第一個 entry (msgid === '') 是 header
	let header = '';
	const realEntries = [];
	for (const e of entries) {
		if (e.msgid === '' && !e.msgctxt) {
			header = e.msgstr || '';
		} else {
			realEntries.push(e);
		}
	}

	return { header, entries: realEntries };
}

function unescape(s) {
	return s.replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\"/g, '"').replace(/\\\\/g, '\\');
}

function escape(s) {
	return s.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\t/g, '\\t');
}

/**
 * 將一個 msgstr 字串正確序列化（若含 \n 拆多行）
 */
function serializeMsgstr(key, val) {
	if (val === undefined || val === null) val = '';
	if (!val.includes('\n')) {
		return `${key} "${escape(val)}"`;
	}
	const parts = val.split('\n');
	const lines = [`${key} ""`];
	for (let i = 0; i < parts.length; i++) {
		const last = i === parts.length - 1;
		lines.push(`"${escape(parts[i])}${last ? '' : '\\n'}"`);
	}
	return lines.join('\n');
}

function serializeEntry(entry) {
	const out = [];
	for (const c of entry.comments || []) out.push(c);
	if (entry.msgctxt !== undefined) out.push(serializeMsgstr('msgctxt', entry.msgctxt));
	out.push(serializeMsgstr('msgid', entry.msgid));
	if (entry.msgid_plural !== undefined) {
		out.push(serializeMsgstr('msgid_plural', entry.msgid_plural));
		const arr = Array.isArray(entry.msgstr) ? entry.msgstr : ['', ''];
		arr.forEach((v, i) => out.push(serializeMsgstr(`msgstr[${i}]`, v)));
	} else {
		out.push(serializeMsgstr('msgstr', entry.msgstr || ''));
	}
	return out.join('\n');
}

// ============================================================
// 主流程
// ============================================================

console.log('[build-zhtw-po] 開始重建 zh_TW.po');

// 1) 讀 .pot（新產生的，英文 msgid）
if (!existsSync(POT_PATH)) {
	console.error(`✗ .pot 檔不存在：${POT_PATH}\n  請先跑 pnpm run i18n:pot`);
	process.exit(1);
}
const potContent = readFileSync(POT_PATH, 'utf8');
const pot = parsePo(potContent);
console.log(`[build-zhtw-po] .pot 載入 ${pot.entries.length} 筆 entries`);

// 2) 讀既有 zh_TW.po（保留已有英文 msgid 的翻譯）
const existingTranslations = new Map(); // msgid → msgstr
const existingHeader = (() => {
	if (!existsSync(OLD_PO_PATH)) {
		console.log('[build-zhtw-po] 無既有 zh_TW.po，從頭建立');
		return null;
	}
	const oldContent = readFileSync(OLD_PO_PATH, 'utf8');
	const old = parsePo(oldContent);
	let kept = 0;
	let skippedChinese = 0;
	for (const e of old.entries) {
		// 略過中文 msgid（即將被淘汰）
		if (/[\u4e00-\u9fff]/.test(e.msgid)) {
			skippedChinese++;
			continue;
		}
		const key = e.msgctxt ? `${e.msgctxt}\u0004${e.msgid}` : e.msgid;
		existingTranslations.set(key, e);
		kept++;
	}
	console.log(`[build-zhtw-po] 既有 zh_TW.po：保留 ${kept} 筆英文 msgid 翻譯，略過 ${skippedChinese} 筆中文 msgid`);
	return old.header;
})();

// 3) 讀 scripts/i18n-translations/*.json 對照表
const jsonFiles = existsSync(JSON_DIR)
	? readdirSync(JSON_DIR).filter((f) => f.endsWith('.json'))
	: [];
const translationMap = new Map(); // msgid → { msgstr, sources: [] }
const conflicts = [];
let jsonEntryCount = 0;

function extractList(raw, filename) {
	// 支援多種格式：
	// (1) 純 array
	// (2) { translations: [...] } / { entries: [...] } / { items: [...] }
	// (3) { files: { "path.tsx": [ {msgid, zh|msgstr_zh_TW, context}, ... ] } }
	if (Array.isArray(raw)) return raw;
	if (Array.isArray(raw.translations)) return raw.translations;
	if (Array.isArray(raw.entries)) return raw.entries;
	if (Array.isArray(raw.items)) return raw.items;
	if (raw.files && typeof raw.files === 'object') {
		const flat = [];
		for (const [filepath, arr] of Object.entries(raw.files)) {
			if (!Array.isArray(arr)) continue;
			for (const item of arr) {
				flat.push({ ...item, context: `${filepath}${item.context ? ` (${item.context})` : ''}` });
			}
		}
		return flat;
	}
	console.warn(`[build-zhtw-po] ⚠️ ${filename} 不是 array 也沒有 translations/entries/items/files 欄位，略過`);
	return null;
}

for (const f of jsonFiles) {
	const path = join(JSON_DIR, f);
	const raw = JSON.parse(readFileSync(path, 'utf8'));
	const list = extractList(raw, f);
	if (!list) continue;
	for (const item of list) {
		// 支援 msgstr_zh_TW 或 zh 兩種 key
		const msgstr = item.msgstr_zh_TW ?? item.zh ?? item.msgstr;
		if (!item.msgid || !msgstr) continue;
		item.msgstr_zh_TW = msgstr;
		jsonEntryCount++;
		const key = item.msgctxt ? `${item.msgctxt}\u0004${item.msgid}` : item.msgid;
		if (translationMap.has(key)) {
			const prev = translationMap.get(key);
			if (prev.msgstr !== item.msgstr_zh_TW) {
				conflicts.push({
					msgid: item.msgid,
					a: { msgstr: prev.msgstr, source: prev.sources[0] },
					b: { msgstr: item.msgstr_zh_TW, source: `${f}:${item.context || '?'}` },
				});
			}
			prev.sources.push(`${f}:${item.context || '?'}`);
		} else {
			translationMap.set(key, {
				msgstr: item.msgstr_zh_TW,
				sources: [`${f}:${item.context || '?'}`],
			});
		}
	}
}
console.log(`[build-zhtw-po] 對照表載入 ${jsonEntryCount} 筆（${jsonFiles.length} 個 JSON 檔案）`);

if (conflicts.length > 0) {
	console.warn(`\n⚠️  發現 ${conflicts.length} 筆 msgid 翻譯衝突：`);
	for (const c of conflicts) {
		console.warn(`  msgid: "${c.msgid}"`);
		console.warn(`    [A] "${c.a.msgstr}" (${c.a.source})`);
		console.warn(`    [B] "${c.b.msgstr}" (${c.b.source})`);
	}
	console.warn('\n衝突時優先採用 JSON 檔名字典序較前者。如需修正請調整對照表。\n');
}

// 4) 產出新 zh_TW.po
// header：優先用既有的，否則用 pot 的（並改 Language: zh_TW）
let header = existingHeader;
if (!header) {
	header = pot.header.replace(/Language:\s*[^\n]*/, 'Language: zh_TW\nPlural-Forms: nplurals=1; plural=0;');
}

const newEntries = [];
let translatedCount = 0;
let untranslatedCount = 0;
let reusedCount = 0;

for (const potEntry of pot.entries) {
	const key = potEntry.msgctxt ? `${potEntry.msgctxt}\u0004${potEntry.msgid}` : potEntry.msgid;

	// 優先順序：translationMap (JSON 對照表) > existingTranslations (既有英文 msgid)
	let msgstr;
	if (translationMap.has(key)) {
		msgstr = translationMap.get(key).msgstr;
		translatedCount++;
	} else if (existingTranslations.has(key)) {
		const reuse = existingTranslations.get(key);
		if (potEntry.msgid_plural) {
			msgstr = Array.isArray(reuse.msgstr) ? reuse.msgstr : [reuse.msgstr || '', reuse.msgstr || ''];
		} else {
			msgstr = reuse.msgstr || '';
		}
		if (msgstr && (Array.isArray(msgstr) ? msgstr.some(Boolean) : true)) {
			reusedCount++;
		} else {
			untranslatedCount++;
		}
	} else {
		msgstr = potEntry.msgid_plural ? ['', ''] : '';
		untranslatedCount++;
	}

	newEntries.push({
		comments: potEntry.comments,
		msgctxt: potEntry.msgctxt,
		msgid: potEntry.msgid,
		msgid_plural: potEntry.msgid_plural,
		msgstr,
	});
}

console.log(`[build-zhtw-po] 翻譯統計：`);
console.log(`  JSON 對照表命中：${translatedCount}`);
console.log(`  既有 .po 沿用：${reusedCount}`);
console.log(`  未翻譯（空 msgstr）：${untranslatedCount}`);

// 序列化
const headerBlock = [
	'# Copyright (C) 2026 J7',
	'# This file is distributed under the GPL v2 or later.',
	'msgid ""',
	serializeMsgstr('msgstr', header),
].join('\n');

const body = newEntries.map(serializeEntry).join('\n\n');
const output = `${headerBlock}\n\n${body}\n`;

writeFileSync(NEW_PO_PATH, output, 'utf8');
console.log(`\n✓ 已產出 ${NEW_PO_PATH}`);
console.log(`  總 entries: ${newEntries.length}`);

if (untranslatedCount > 0) {
	console.warn(`\n⚠️  有 ${untranslatedCount} 筆 msgid 沒對應翻譯，msgstr 為空。譯者需要手動補齊。`);
}

process.exit(conflicts.length > 0 ? 2 : 0);
