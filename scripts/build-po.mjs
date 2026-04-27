#!/usr/bin/env node
/**
 * build-po.mjs
 *
 * 多語系泛化版本（取代 build-zhtw-po.mjs）。
 *
 * 依據：
 *   - `languages/power-course.pot`（新產生，msgid 全部英文）
 *   - `scripts/i18n-translations/*.json`（多語系對照表，含 msgstr_zh_TW / msgstr_ja 等欄位）
 *   - 既有 `languages/power-course-{locale}.po`（保留已有英文 msgid 的翻譯，避免 LLM 微調被覆寫）
 *
 * 產出：
 *   - `languages/power-course-{locale}.po` ← 對 LOCALES 陣列每個 locale 各產出一份
 *
 * JSON 對照表格式（Single Source of Truth = scripts/i18n-translations/manual.json）：
 *   [
 *     {
 *       "msgid": "Course name",
 *       "msgstr_zh_TW": "課程名稱",
 *       "msgstr_ja":    "コース名",
 *       "context": "inc/templates/..."
 *     }
 *   ]
 *
 * 新增第三語系（如 ko_KR）只需：
 *   1) manual.json 每個 entry 加 `msgstr_ko_KR` 欄位
 *   2) 本檔 LOCALES 陣列加 'ko_KR'
 *   3) 本檔 LOCALE_META 補 plural-forms
 *   不需要新增任何 build 腳本，pnpm run i18n:build 自動處理。
 */

import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');

const POT_PATH = join(ROOT, 'languages/power-course.pot');
const LANG_DIR = join(ROOT, 'languages');
const JSON_DIR = join(ROOT, 'scripts/i18n-translations');

// 支援的 locale 列表 — 新增語系只改這裡 + LOCALE_META + manual.json 欄位
const LOCALES = ['zh_TW', 'ja'];

// 各 locale 的 PO header 設定（語言代碼 + Plural-Forms）
// 中文與日文皆無單複數區分，故 nplurals=1; plural=0;
const LOCALE_META = {
	zh_TW: { language: 'zh_TW', pluralForms: 'nplurals=1; plural=0;' },
	ja:    { language: 'ja',    pluralForms: 'nplurals=1; plural=0;' },
};

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

/**
 * 讀 scripts/i18n-translations/*.json 對照表，並抽出指定 locale 的翻譯。
 * 支援多種 JSON 結構（純 array / { translations / entries / items / files } 包裝）。
 */
function extractList(raw, filename) {
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
	console.warn(`[build-po] ⚠️ ${filename} 不是 array 也沒有 translations/entries/items/files 欄位，略過`);
	return null;
}

/**
 * 載入所有 JSON 對照表並回傳 raw entries（未依 locale 抽欄位）。
 * 同一個 entry 多個 locale 共用，呼叫端自行依 locale 抽欄位。
 */
function loadAllTranslationItems() {
	const jsonFiles = existsSync(JSON_DIR)
		? readdirSync(JSON_DIR).filter((f) => f.endsWith('.json'))
		: [];
	const items = [];
	for (const f of jsonFiles) {
		const path = join(JSON_DIR, f);
		const raw = JSON.parse(readFileSync(path, 'utf8'));
		const list = extractList(raw, f);
		if (!list) continue;
		for (const item of list) {
			items.push({ ...item, _source: f });
		}
	}
	return { items, fileCount: jsonFiles.length };
}

/**
 * 為單一 locale 產出 .po 檔。
 *
 * @param {string} locale - 'zh_TW' / 'ja' / ...
 * @param {object} pot    - 已 parse 的 .pot 物件 { header, entries }
 * @param {Array}  items  - 所有 JSON 對照表 entries（未依 locale 抽欄位）
 * @returns {{ untranslated: number, conflicts: number, total: number }}
 */
function buildLocalePo(locale, pot, items) {
	const meta = LOCALE_META[locale];
	if (!meta) {
		throw new Error(`[build-po] LOCALE_META 缺少 ${locale} 設定`);
	}

	const PO_PATH = join(LANG_DIR, `power-course-${locale}.po`);
	const fieldName = `msgstr_${locale}`;

	// ============== 讀既有 .po（保留 LLM 微調 / 人工 review）==============
	const existingTranslations = new Map();
	let existingHeader = null;
	if (existsSync(PO_PATH)) {
		const oldContent = readFileSync(PO_PATH, 'utf8');
		const old = parsePo(oldContent);
		let kept = 0;
		for (const e of old.entries) {
			const key = e.msgctxt ? `${e.msgctxt}${e.msgid}` : e.msgid;
			existingTranslations.set(key, e);
			kept++;
		}
		existingHeader = old.header;
		console.log(`[build-po:${locale}] 既有 .po：保留 ${kept} 筆 entries（msgid 沿用 / 翻譯 fallback）`);
	} else {
		console.log(`[build-po:${locale}] 無既有 .po，從 .pot 從頭建立`);
	}

	// ============== 處理 JSON 對照表（抽出該 locale 的翻譯）==============
	const translationMap = new Map();
	const conflicts = [];
	let jsonHit = 0;

	for (const item of items) {
		if (!item.msgid) continue;
		const msgstr = item[fieldName];
		if (msgstr === undefined || msgstr === null) continue;
		if (typeof msgstr === 'string' && msgstr === '') continue;
		if (Array.isArray(msgstr) && msgstr.length === 0) continue;
		if (typeof msgstr !== 'string' && !Array.isArray(msgstr)) continue;
		jsonHit++;

		const key = item.msgctxt ? `${item.msgctxt}${item.msgid}` : item.msgid;
		const sourceTag = `${item._source}:${item.context || '?'}`;

		if (translationMap.has(key)) {
			const prev = translationMap.get(key);
			const prevKey = Array.isArray(prev.msgstr) ? prev.msgstr.join('\x01') : prev.msgstr;
			const curKey = Array.isArray(msgstr) ? msgstr.join('\x01') : msgstr;
			if (prevKey !== curKey) {
				conflicts.push({
					msgid: item.msgid,
					a: { msgstr: prev.msgstr, source: prev.sources[0] },
					b: { msgstr, source: sourceTag },
				});
			}
			prev.sources.push(sourceTag);
		} else {
			translationMap.set(key, { msgstr, sources: [sourceTag] });
		}
	}
	console.log(`[build-po:${locale}] 對照表載入 ${jsonHit} 筆 ${fieldName}`);

	if (conflicts.length > 0) {
		console.warn(`\n[build-po:${locale}] ⚠️  發現 ${conflicts.length} 筆 msgid 翻譯衝突：`);
		for (const c of conflicts) {
			console.warn(`  msgid: "${c.msgid}"`);
			console.warn(`    [A] "${c.a.msgstr}" (${c.a.source})`);
			console.warn(`    [B] "${c.b.msgstr}" (${c.b.source})`);
		}
		console.warn(`[build-po:${locale}] 衝突時優先採用 JSON 檔名字典序較前者。\n`);
	}

	// ============== header：優先用既有的，否則用 pot 的（並改 Language: locale + Plural-Forms）==============
	let header = existingHeader;
	if (!header) {
		// 先移除 .pot 預設的 Plural-Forms（英文 nplurals=2），避免與 locale 自己的 plural-forms 重複
		const stripped = pot.header.replace(/Plural-Forms:\s*[^\n]*\n?/g, '');
		header = stripped.replace(
			/Language:\s*[^\n]*/,
			`Language: ${meta.language}\nPlural-Forms: ${meta.pluralForms}`
		);
	}

	// ============== 對齊 .pot 產出新 entries ==============
	const newEntries = [];
	let translatedCount = 0;
	let untranslatedCount = 0;
	let reusedCount = 0;

	for (const potEntry of pot.entries) {
		const key = potEntry.msgctxt ? `${potEntry.msgctxt}${potEntry.msgid}` : potEntry.msgid;

		// 優先順序：translationMap (manual.json) > existingTranslations (既有 .po)
		let msgstr;
		if (translationMap.has(key)) {
			const raw = translationMap.get(key).msgstr;
			if (potEntry.msgid_plural) {
				msgstr = Array.isArray(raw) ? raw : [raw];
			} else if (Array.isArray(raw)) {
				console.warn(`[build-po:${locale}] ⚠️ msgid "${potEntry.msgid}" 非 plural，但對照表給了 array，取 [0]`);
				msgstr = raw[0] || '';
			} else {
				msgstr = raw;
			}
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

	console.log(`[build-po:${locale}] 翻譯統計：`);
	console.log(`  JSON 對照表命中：${translatedCount}`);
	console.log(`  既有 .po 沿用：${reusedCount}`);
	console.log(`  未翻譯（空 msgstr）：${untranslatedCount}`);

	// ============== 序列化 ==============
	const headerBlock = [
		'# Copyright (C) 2026 J7',
		'# This file is distributed under the GPL v2 or later.',
		'msgid ""',
		serializeMsgstr('msgstr', header),
	].join('\n');

	const body = newEntries.map(serializeEntry).join('\n\n');
	const output = `${headerBlock}\n\n${body}\n`;

	writeFileSync(PO_PATH, output, 'utf8');
	console.log(`[build-po:${locale}] ✓ 已產出 ${PO_PATH}（總 ${newEntries.length} entries）\n`);

	return {
		untranslated: untranslatedCount,
		conflicts: conflicts.length,
		total: newEntries.length,
	};
}

// ============================================================
// 主流程
// ============================================================

console.log(`[build-po] 開始多語系迴圈處理：${LOCALES.join(', ')}\n`);

if (!existsSync(POT_PATH)) {
	console.error(`✗ .pot 檔不存在：${POT_PATH}\n  請先跑 pnpm run i18n:pot`);
	process.exit(1);
}
const potContent = readFileSync(POT_PATH, 'utf8');
const pot = parsePo(potContent);
console.log(`[build-po] .pot 載入 ${pot.entries.length} 筆 entries`);

const { items, fileCount } = loadAllTranslationItems();
console.log(`[build-po] 對照表載入 ${items.length} 筆 raw entries（${fileCount} 個 JSON 檔案）\n`);

const results = {};
let totalConflicts = 0;
for (const locale of LOCALES) {
	results[locale] = buildLocalePo(locale, pot, items);
	totalConflicts += results[locale].conflicts;
}

console.log('[build-po] 全部 locale 處理完成：');
for (const locale of LOCALES) {
	const r = results[locale];
	console.log(`  ${locale}: total=${r.total}, untranslated=${r.untranslated}, conflicts=${r.conflicts}`);
}

if (totalConflicts > 0) {
	console.warn(`\n⚠️  共 ${totalConflicts} 筆翻譯衝突，請檢視上方日誌。`);
	process.exit(2);
}

process.exit(0);
