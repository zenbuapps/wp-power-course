# Issue #201 多語系日文翻譯 — 決策紀錄

- **Issue**：[#201 多語系日文翻譯](https://github.com/j7-dev/wp-power-course/issues/201)
- **澄清日期**：2026-04-27
- **澄清模式**：3 輪互動式澄清（PM 模式 + 兩輪工程澄清 + 最終總結）
- **最終裁決原則**：「英文 / 中文 / 日文 多語系邏輯要 **一致**」（用戶於 2026-04-27 11:03 留言）

## 第一輪用戶決策（情境 / 工程基礎題）

| 題號 | 主題 | 用戶選擇 | 含義 |
|------|------|----------|------|
| Q1 | 首次上線翻譯範圍 | **A** | 全量 1,314 條一次到位（不分階段） |
| Q2 | 翻譯來源 | **C** | 純機器翻譯（LLM）先上線，後續迭代修正 |
| Q3 | 核心日文術語表 | **A** | Udemy 風格外來語：コース / チャプター / 受講生 / レッスン |
| Q4 | manual.json 結構 | ~~D~~ | 原選「業界標準格式（Lokalise / Crowdin / 直接維護 .po）」 |
| Q5 | Build pipeline 架構 | **A** | 泛化單一腳本，迴圈處理 `LOCALES = ['zh_TW', 'ja']` |
| Q6 | PR / CI 防退化 | **B** | CI 警告但不阻擋 merge，留下 traceable 留言 |

## 第二輪用戶最終裁決（覆寫 Q4）

> 「這些問題你決定吧。反正你原本已經有做英文 & 中文版本了，我這次要做日文版本的。**英文 中文 日文 語言的多語系邏輯要一致**。」

此原則直接推翻 Q4=D 與其衍生的 Q7~Q10，因為「業界標準格式（直接維護 .po）」會破壞現有 SSOT 機制（`manual.json` → `.po`），與 zh_TW 邏輯不一致。

### 覆寫後的最終決策

| 題號 | 修訂後決策 | 修訂理由 |
|------|-----------|----------|
| **Q4** | **改為 A — 單檔擴欄** | `scripts/i18n-translations/manual.json` 從 `{msgid, msgstr_zh_TW, context}` 升級為 `{msgid, msgstr_zh_TW, msgstr_ja, context}`。所有語系集中在同一個 SSOT，與現有「manual.json 為唯一翻譯真相、禁止手改 .po」邏輯完全一致 |
| Q7 (脫離現有 pipeline 的具體形態) | **N/A** | Q4 改 A 後已不適用 |
| Q8 (LLM 執行時機) | **首版一次性手動執行** | 由實作者本地跑批次 LLM 翻譯，產出 `msgstr_ja` 後 commit 進 `manual.json`；後續新字串以 PR 為單位由開發者觸發 LLM 補翻並一起 commit |
| Q9 (zh_TW 既有翻譯處理) | **既有 153 條完全保留 + LLM 補齊缺漏** | `manual.json` 既有 entries 不動，新增 `msgstr_ja` 欄位；同時用 LLM 補齊 zh_TW 尚未翻譯的 1,161 條，使兩語系覆蓋率對齊 1,314 條 |
| Q10 (CLAUDE.md 規則修訂) | **規則維持不變** | 「禁止手改 .po」原則繼續有效；只新增「多語系翻譯一律寫入 `manual.json` 對應的 `msgstr_{locale}` 欄位」說明 |

## 決策一致性驗證

| 一致性面向 | 現有（zh_TW） | 新增（ja） | 是否一致 |
|-----------|---------------|------------|---------|
| msgid 來源語言 | 英文完整句子 | 英文完整句子 | ✅ |
| 翻譯 SSOT | `scripts/i18n-translations/manual.json` | 同一個 `manual.json`（擴欄） | ✅ |
| 禁止手改 .po | 是 | 是 | ✅ |
| Pipeline 階段 | pot → merge → mo → json | pot → merge → mo → json | ✅ |
| .po / .mo / .json 路徑慣例 | `languages/power-course-zh_TW.{po,mo,json}` | `languages/power-course-ja.{po,mo,json}` | ✅ |
| WP runtime 載入機制 | `load_plugin_textdomain` + `wp_set_script_translations` + `inject_locale_data_to_handle` | 完全相同 | ✅ |
| Fallback 行為 | 缺翻譯 → 顯示英文 msgid | 缺翻譯 → 顯示英文 msgid | ✅ |

## 影響檔案總覽

### 必改

- `scripts/i18n-translations/manual.json` — 擴欄為多語系 + 補齊 1,314 × 2 翻譯
- `scripts/build-zhtw-po.mjs` → 重新命名為 `scripts/build-po.mjs` — 迴圈處理 `LOCALES = ['zh_TW', 'ja']`
- `package.json` — `i18n:merge` 指令指向新腳本
- `.claude/rules/i18n.rule.md` — 補充多語系欄位說明、ja 加入目標語言
- `.claude/CLAUDE.md` — 補充日文支援與術語表連結

### 必新增

- `languages/power-course-ja.po`（pipeline 自動產出）
- `languages/power-course-ja.mo`（pipeline 自動產出）
- `languages/power-course-ja.json`（pipeline 自動產出）
- `.github/workflows/i18n-coverage.yml`（CI 警告 workflow，Q6=B）
- `specs/features/i18n-japanese/i18n-japanese.feature`（本 spec 配套）
- `specs/plan/issue-201-japanese-i18n.md`（本 spec 配套）

### 不改（驗證即可）

- `scripts/i18n-make-pot.mjs` — 已掃 PHP + JS 全範圍，無需改
- `scripts/i18n-make-mo.mjs` — 迴圈讀 `power-course-*.po` 已支援多語系
- `scripts/i18n-make-json.mjs` — 迴圈讀 `power-course-*.po` 已支援多語系
- `inc/classes/Bootstrap.php::enqueue_script` — `inject_locale_data_to_handle` 依 `get_locale()` 自動切換
- `inc/classes/Templates/Ajax.php::wp_enqueue_scripts` — 同上
- `vite.config-for-wp.ts` — `@wordpress/i18n` shim alias 已就位

## 驗收標準對應（PM 文件 → 規格）

| PM 驗收標準 | 規格對應 |
|-------------|---------|
| WP 站台語言設為日文 → 後台顯示日文 | `i18n-japanese.feature::Rule 後台日文介面` |
| WP 站台語言設為日文 → 前台教室日文 | `i18n-japanese.feature::Rule 前台教室日文介面` |
| WP 站台語言設為日文 → 前台銷售頁日文 | `i18n-japanese.feature::Rule 前台銷售頁日文介面` |
| 未翻譯字串 fallback 為英文 | `i18n-japanese.feature::Rule 缺翻譯 fallback` |
| `pnpm run i18n:build` 同時產出兩套翻譯檔 | `issue-201-japanese-i18n.md::Phase 2 Pipeline 泛化` |
| 日文翻譯檔包含在 release zip | `issue-201-japanese-i18n.md::Phase 5 發版驗證` |
| 日文術語表已建立 | `issue-201-japanese-i18n.md::Phase 1 術語表` |
| 前台高優先字串 100% 翻譯 | `i18n-japanese.feature::Rule 翻譯覆蓋率 100%` |
| 切換語言時繁中翻譯不受影響 | `i18n-japanese.feature::Rule 多語系並存無干擾` |
| 同站台依用戶語言偏好分別顯示 | `i18n-japanese.feature::Rule 用戶語言偏好` |
