#!/usr/bin/env node
/**
 * llm-translate.mjs
 *
 * 用 Anthropic Claude API 把 manual.json 中 msgstr_{locale} 為空的 entry 批次翻譯。
 *
 * 用法：
 *   node scripts/llm-translate.mjs --locale=ja                            # 翻 ja 全部空 entry
 *   node scripts/llm-translate.mjs --locale=zh_TW --only-empty            # 只翻空 entry，保護人工翻譯
 *   node scripts/llm-translate.mjs --locale=ja --limit=50 --dry-run       # 印前 50 條翻譯草稿不寫檔
 *
 * 環境變數：
 *   ANTHROPIC_API_KEY   必填（CI 預設不設定，本腳本只在本地執行）
 *   LLM_MODEL           可選，預設 claude-sonnet-4-6
 *   LLM_BATCH_SIZE      可選，預設 30 條 / 批次
 *
 * 設計原則：
 *   - 預設 only-empty 模式：絕不覆寫已有 msgstr，保護人工審校過的譯文
 *   - 術語表硬編碼於 prompt（與 .claude/rules/i18n.rule.md 對齊）
 *   - 保留 placeholder（%s, %1$d, %d, %s%s）與 HTML tag（<a>, <strong>）
 *   - 失敗 entry 留空 + log warning，下次再跑 --only-empty 補
 *   - 每批寫回一次 manual.json，意外中斷可續跑
 */

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const MANUAL_PATH = join(ROOT, 'scripts/i18n-translations/manual.json');

// ============== CLI 參數 ==============
const args = Object.fromEntries(
	process.argv.slice(2).flatMap((a) => {
		const m = a.match(/^--([^=]+)(?:=(.*))?$/);
		return m ? [[m[1], m[2] ?? true]] : [];
	})
);
const LOCALE = args.locale;
const DRY_RUN = !!args['dry-run'];
const ONLY_EMPTY = !!args['only-empty'] || true; // 預設強制
const LIMIT = args.limit ? parseInt(args.limit, 10) : Infinity;
const MODEL = process.env.LLM_MODEL || 'claude-sonnet-4-6';
const BATCH_SIZE = parseInt(process.env.LLM_BATCH_SIZE || '30', 10);

if (!LOCALE) {
	console.error('用法：node scripts/llm-translate.mjs --locale=<ja|zh_TW> [--limit=N] [--dry-run]');
	process.exit(1);
}

const FIELD = `msgstr_${LOCALE}`;

// ============== 術語表（與 .claude/rules/i18n.rule.md 對齊）==============
const GLOSSARY = {
	zh_TW: {
		Course: '課程',
		Chapter: '章節',
		Lesson: '小節',
		Student: '學員',
		Instructor: '講師',
		Bundle: '銷售方案',
		Cart: '購物車',
		Order: '訂單',
		Classroom: '教室',
		'Add to cart': '加入購物車',
		'Enroll now': '立即報名',
		'Buy now': '立即購買',
		Save: '儲存',
		Delete: '刪除',
		Cancel: '取消',
		Confirm: '確認',
		Close: '關閉',
		Loading: '載入中',
	},
	ja: {
		Course: 'コース',
		Chapter: 'チャプター',
		Lesson: 'レッスン',
		Student: '受講生',
		Instructor: '講師',
		Bundle: 'バンドル',
		Cart: 'カート',
		Order: '注文',
		Classroom: '教室',
		'Add to cart': 'カートに追加',
		'Enroll now': '今すぐ申し込む',
		'Buy now': '今すぐ購入',
		Save: '保存',
		Delete: '削除',
		Cancel: 'キャンセル',
		Confirm: '確認',
		Close: '閉じる',
		Loading: '読み込み中',
		Featured: 'おすすめ',
		Popular: '人気',
		Free: '無料',
		'Loading video...': '動画を読み込んでいます...',
		'Replay chapter': 'このチャプターをもう一度見る',
		'Back to My Courses': 'マイコースに戻る',
	},
};

const LOCALE_NAME = { zh_TW: '繁體中文', ja: '日本語' };

function buildSystemPrompt(locale) {
	const glossaryLines = Object.entries(GLOSSARY[locale])
		.map(([en, tr]) => `  ${en} → ${tr}`)
		.join('\n');
	return `你是一位精通 WordPress 線上課程平台術語的翻譯員。請把英文 msgid 翻譯成${LOCALE_NAME[locale]}。

【術語表（必須遵守）】
${glossaryLines}

【翻譯規則】
1. 保留所有 placeholder：%s, %d, %1$s, %2$d 等格式化符號原封不動
2. 保留所有 HTML tag：<a>, <strong>, <br/>, <span> 等
3. ${locale === 'ja' ? '採用 Udemy 風格的外來語譯法（コース／チャプター／レッスン），避免過度漢字化；敬語使用一般敬體（です／ます調）；禁止使用尊敬語或謙讓語；' : '採用台灣繁體中文用語（不是中國大陸用語）；'}
4. 沒有複數區分時 singular/plural 翻譯填相同內容
5. 完整句子首字大小寫沿用 msgid 風格
6. 錯誤訊息直接翻譯成「⋯⋯失敗 / ⋯⋯しました」

【輸出格式】
回傳純 JSON array，無任何 markdown 程式碼框、無前後文字解釋。每個元素：
{"msgid": "原英文", "msgstr": "翻譯結果"}

若 msgid 是 plural（會給 msgid_plural），msgstr 改為 array：
{"msgid": "原英文 singular", "msgid_plural": "原英文 plural", "msgstr": ["${locale === 'ja' || locale === 'zh_TW' ? '單一翻譯' : '單數翻譯'}", "${locale === 'ja' || locale === 'zh_TW' ? '單一翻譯' : '複數翻譯'}"]}
（${locale === 'ja' || locale === 'zh_TW' ? `${locale} 的 nplurals=1，msgstr array 長度 1 即可` : '依 nplurals 提供對應數量'}）

若 msgid 是 URL、人名（如 "J7"）、版本號等不該翻譯的內容，msgstr 直接複製 msgid。`;
}

// ============== 載入 manual.json ==============
const manual = JSON.parse(readFileSync(MANUAL_PATH, 'utf8'));
console.log(`[llm-translate:${LOCALE}] manual.json 載入 ${manual.length} 筆 entries`);

const isEmpty = (v) => {
	if (v === undefined || v === null) return true;
	if (typeof v === 'string') return v === '';
	if (Array.isArray(v)) return v.every((s) => !s);
	return false;
};

const targets = manual
	.map((entry, idx) => ({ entry, idx }))
	.filter(({ entry }) => entry.msgid !== undefined && (ONLY_EMPTY ? isEmpty(entry[FIELD]) : true))
	.slice(0, LIMIT);

console.log(`[llm-translate:${LOCALE}] 待翻譯 ${targets.length} 筆${LIMIT < Infinity ? ` (--limit=${LIMIT})` : ''}`);

if (targets.length === 0) {
	console.log(`[llm-translate:${LOCALE}] 沒有待翻譯 entry，結束`);
	process.exit(0);
}

if (DRY_RUN) {
	console.log('\n--- DRY RUN（前 10 筆）---');
	for (const { entry } of targets.slice(0, 10)) {
		console.log(`  msgid: "${entry.msgid}"${entry.msgid_plural ? ` | plural: "${entry.msgid_plural}"` : ''}`);
	}
	process.exit(0);
}

// ============== 呼叫 Anthropic API ==============
const apiKey = process.env.ANTHROPIC_API_KEY;
if (!apiKey) {
	console.error(`✗ 缺 ANTHROPIC_API_KEY 環境變數。
  本腳本不在 CI 環境執行；本地請在 .env 或 shell 設定後重跑。
  範例：export ANTHROPIC_API_KEY=sk-ant-... && node scripts/llm-translate.mjs --locale=${LOCALE}`);
	process.exit(1);
}

let Anthropic;
try {
	Anthropic = (await import('@anthropic-ai/sdk')).default;
} catch (e) {
	console.error(`✗ 缺 @anthropic-ai/sdk 套件。請跑：pnpm add -D @anthropic-ai/sdk`);
	process.exit(1);
}

const client = new Anthropic({ apiKey });
const systemPrompt = buildSystemPrompt(LOCALE);

async function translateBatch(batch, batchIdx, totalBatches) {
	const userPayload = batch.map(({ entry }) => ({
		msgid: entry.msgid,
		...(entry.msgid_plural ? { msgid_plural: entry.msgid_plural } : {}),
	}));

	const userMsg = `請翻譯以下 ${batch.length} 筆 msgid（JSON array 形式）：

${JSON.stringify(userPayload, null, 2)}

只回傳一個 JSON array，不要任何文字說明或 markdown。`;

	const resp = await client.messages.create({
		model: MODEL,
		max_tokens: 8192,
		system: systemPrompt,
		messages: [{ role: 'user', content: userMsg }],
	});

	const text = resp.content.map((c) => (c.type === 'text' ? c.text : '')).join('');
	let parsed;
	try {
		// 容忍 LLM 偶爾包 ```json 程式碼框
		const cleaned = text.replace(/^```json\s*/m, '').replace(/^```\s*/m, '').replace(/```\s*$/m, '');
		parsed = JSON.parse(cleaned);
	} catch (e) {
		console.warn(`  ⚠️ batch ${batchIdx + 1}/${totalBatches} 解析失敗：${e.message}`);
		console.warn(`  原始回應前 200 字：${text.slice(0, 200)}`);
		return [];
	}
	if (!Array.isArray(parsed)) {
		console.warn(`  ⚠️ batch ${batchIdx + 1}/${totalBatches} 不是 array`);
		return [];
	}
	return parsed;
}

function chunk(arr, size) {
	const out = [];
	for (let i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
	return out;
}

const batches = chunk(targets, BATCH_SIZE);
console.log(`[llm-translate:${LOCALE}] 拆分為 ${batches.length} 批次（每批 ${BATCH_SIZE} 筆），模型：${MODEL}\n`);

let totalSuccess = 0;
let totalFail = 0;

for (let i = 0; i < batches.length; i++) {
	const batch = batches[i];
	process.stdout.write(`[batch ${i + 1}/${batches.length}] ${batch.length} 條... `);
	const result = await translateBatch(batch, i, batches.length);

	// 對齊 msgid 寫回 manual.json
	const resultMap = new Map(result.map((r) => [r.msgid, r]));
	for (const { entry, idx } of batch) {
		const r = resultMap.get(entry.msgid);
		if (!r || !r.msgstr) {
			totalFail++;
			continue;
		}
		if (entry.msgid_plural) {
			manual[idx][FIELD] = Array.isArray(r.msgstr) ? r.msgstr : [r.msgstr];
		} else {
			manual[idx][FIELD] = typeof r.msgstr === 'string' ? r.msgstr : '';
		}
		totalSuccess++;
	}

	// 每批寫回，中斷可續跑
	writeFileSync(MANUAL_PATH, JSON.stringify(manual, null, '\t') + '\n', 'utf8');
	console.log(`✓ 寫回 ${resultMap.size} 筆`);
}

console.log(`\n[llm-translate:${LOCALE}] 完成：成功 ${totalSuccess} / 失敗 ${totalFail} / 總計 ${targets.length}`);
if (totalFail > 0) {
	console.log(`  → 失敗的 entry 留空，下次跑 --only-empty 可續補`);
}
