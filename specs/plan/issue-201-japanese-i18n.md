# Issue #201 多語系日文翻譯 — 工程計畫（v2）

- **Issue**：[#201 多語系日文翻譯](https://github.com/j7-dev/wp-power-course/issues/201)
- **澄清紀錄**：[`specs/clarify/2026-04-27-issue201-japanese-i18n.md`](../clarify/2026-04-27-issue201-japanese-i18n.md)
- **行為規格**：[`specs/features/i18n-japanese/i18n-japanese.feature`](../features/i18n-japanese/i18n-japanese.feature)
- **核心原則**（用戶 2026-04-27 11:03 裁決）：英文 / 中文 / 日文 多語系邏輯保持 **一致**；最小改動現有 SSOT 機制（`manual.json` → `.po`）

## 0. 一張圖看完成果

```
原始碼                    [pnpm run i18n:build 多語系迴圈處理]
──────                    ─────────────────────────────────────────
PHP (inc/, plugin.php)           │
JS/TSX (js/src/)                 │  i18n:pot   ──→ languages/power-course.pot
JS (inc/assets/src/)             │             （單一英文 msgid 總表）
                                 │
scripts/i18n-translations/       │                   ┌─→ power-course-zh_TW.po
  └── manual.json                │  i18n:merge   ───┤   (Plural-Forms: nplurals=1; plural=0;)
       (msgstr_zh_TW + msgstr_ja │  build-po.mjs    └─→ power-course-ja.po
        + context)               │                       (Plural-Forms: nplurals=1; plural=0;)
                                 │
                                 │  i18n:mo     ──→ power-course-{zh_TW,ja}.mo
                                 │
                                 └  i18n:json   ──→ power-course-{zh_TW,ja}.json
```

**一致性錨點**（與既有 zh_TW 完全對齊）：
- msgid 來源語言：英文完整句子
- 翻譯 SSOT：同一個 `manual.json`（擴欄 `msgstr_ja`）
- 禁止手改 .po：保持
- Pipeline 階段：pot → merge → mo → json
- WP runtime 載入：`load_plugin_textdomain` + `wp_set_script_translations` + `inject_locale_data_to_handle`（不改）
- Fallback：缺翻譯 → 顯示英文 msgid（不改）

## 1. 既有程式碼盤點（Verified）

掃過後確認以下檔案**已是多語系 ready**，無需改動：

| 檔案 | 已驗證行為 |
|------|-----------|
| `scripts/i18n-make-pot.mjs` | 掃 PHP（`inc/`, `plugin.php`）+ JS（`js/src/**`, `inc/assets/src/**`），不分 locale，產出單一 .pot |
| `scripts/i18n-make-mo.mjs` (lines 31–55) | 已用 `readdirSync` 迴圈處理 `power-course-*.po`，新增 `power-course-ja.po` 自動被吃進 |
| `scripts/i18n-make-json.mjs` (lines 24–86) | 同上迴圈，並從 `Plural-Forms` header 抽 nplurals 寫入 JED JSON |
| `inc/classes/Bootstrap.php::inject_locale_data_to_handle` (line 327) | 用 `determine_locale()` 抓 user/site locale → 路徑由 `resolve_locale_json_path()` 動態解析 |
| `inc/classes/Templates/Ajax.php` (lines 64–69) | 同樣呼叫 `inject_locale_data_to_handle`，前台 vanilla TS 自動 ready |
| `vite.config-for-wp.ts` / `vite.config.ts` | 已有 `@wordpress/i18n` shim alias（i18n.rule.md 歷史問題已修） |
| `release/.release-it.cjs` (allowedItems) | `'languages'` 整目錄打包，新增 `*-ja.{po,mo,json}` 自動進 zip |

**唯一需要重構的腳本**：`scripts/build-zhtw-po.mjs`（hardcode `zh_TW`、`msgstr_zh_TW`、`Language: zh_TW` header）。

## 2. 階段拆解（建議實作順序）

### Phase 0 — Baseline 凍結（防破壞性重構）

**目的**：在 Phase 2 重構 build 腳本前，鎖定一份 baseline 用於 byte-for-byte diff 驗證。

**任務**：
1. 在分支首個 commit 前確認 `git status` 乾淨
2. 留存 `languages/power-course-zh_TW.po` 的 SHA-256 作 baseline（commit message 註記）
3. 不 commit 任何二進位 baseline；僅靠 git 原本的 history 即可比對

**驗收**：Phase 2 完成後，若 manual.json 沒有真實內容變動，跑 `pnpm run i18n:merge` 產出的 `power-course-zh_TW.po` 與 baseline diff 應為 0（或僅有合理的 header 時間戳差異）。

---

### Phase 1 — 術語表與資料結構升級（無破壞性）

**目標**：為日文翻譯建立 1) 術語表 2) `manual.json` 多語系欄位，不影響現有繁中流程。

**任務**：

1. **`.claude/rules/i18n.rule.md` 修訂**：
   - frontmatter `paths` 段落：把 `scripts/build-zhtw-po.mjs` 改成 `scripts/build-po.mjs`
   - 「核心決策」表格的「目標語言」改為 `zh_TW + ja + en_US fallback`，移除「次階段」
   - 「術語表」段落新增「日文術語表（Glossary - ja）」子節，採 Udemy 風格外來語：

   | 英文 msgid | 繁體中文 | 日文 |
   |-----------|---------|------|
   | Course | 課程 | コース |
   | Chapter | 章節 | チャプター |
   | Lesson | 小節 | レッスン |
   | Student | 學員 | 受講生 |
   | Instructor | 講師 | 講師 |
   | Bundle | 銷售方案 | バンドル |
   | Cart | 購物車 | カート |
   | Order | 訂單 | 注文 |
   | Classroom | 教室 | 教室 |
   | Watch progress | 觀看進度 | 視聴進捗 |
   | Watch time | 觀看時間 | 視聴時間 |
   | Add to cart | 加入購物車 | カートに追加 |
   | Enroll now | 立即報名 | 今すぐ申し込む |
   | Buy now | 立即購買 | 今すぐ購入 |
   | Go to classroom | 前往教室 | 教室へ移動 |
   | Visit course | 前往課程 | コースを見る |
   | Save | 儲存 | 保存 |
   | Delete | 刪除 | 削除 |
   | Create | 建立 | 作成 |
   | Add | 新增 | 追加 |
   | Edit | 編輯 | 編集 |
   | Cancel | 取消 | キャンセル |
   | Confirm | 確認 | 確認 |
   | Confirm delete | 確定刪除 | 削除を確認 |
   | Close | 關閉 | 閉じる |
   | Loading | 載入中 | 読み込み中 |
   | Loading video... | 影片載入中... | 動画を読み込んでいます… |
   | Featured course | 精選課程 | おすすめコース |
   | Popular course | 熱門課程 | 人気コース |
   | Free course | 免費課程 | 無料コース |
   | Replay chapter | 重看本章 | このチャプターをもう一度見る |
   | Back to My Courses | 回《我的課程》 | マイコースに戻る |
   | Failed to delete course data | 刪除課程資料失敗 | コースデータの削除に失敗しました |

   （以上 ~30 條為核心錨點；Phase 3 的 LLM 批次將以此 prompt 為術語約束，剩餘 ~1,280 條由 LLM 在術語表約束下產出）

2. **升級 `scripts/i18n-translations/manual.json`**：
   - 為現有 153 個 entry 新增 `msgstr_ja` 欄位（先空字串 `""`）
   - **絕對不可** 變動既有 `msgstr_zh_TW` 內容（人工審校過）
   - 新格式範例：
     ```json
     {
       "msgid": "Featured course",
       "msgstr_zh_TW": "精選課程",
       "msgstr_ja": "おすすめコース",
       "context": "inc/templates/components/badge/feature.php"
     }
     ```
   - 此階段 `msgstr_ja` 全空，由 Phase 3 的 LLM 腳本批次補上

3. **`.claude/CLAUDE.md` 「核心架構決策」段落補一行**：
   > **i18n 多語系 SSOT**：所有語言的翻譯都集中在 `scripts/i18n-translations/manual.json`，以 `msgstr_{locale}` 欄位區分（zh_TW、ja，未來可加 ko_KR、en_US 等）；`build-po.mjs` 迴圈讀同一個對照表為各 locale 產出 .po。

**驗收**：
- `manual.json` 的 153 條 `msgstr_zh_TW` 內容 SHA-256 與重構前一致
- 新增 `msgstr_ja` 欄位 schema 通過 `JSON.parse`
- 跑 `pnpm run i18n:merge`（仍是舊腳本）不報錯（zh_TW 行為不變）
- 術語表 30+ 詞涵蓋驗收標準提到的所有 specs/features 字串

---

### Phase 2 — Pipeline 泛化（最小破壞性重構）

**目標**：把 `build-zhtw-po.mjs` 升級為支援多語系迴圈的 `build-po.mjs`，邏輯複用、zh_TW 行為不變。

**任務**：

1. **重新命名 + 泛化 build 腳本**：
   - `git mv scripts/build-zhtw-po.mjs scripts/build-po.mjs`
   - 在腳本頂部新增：
     ```js
     const LOCALES = ['zh_TW', 'ja'];
     // 每個 locale 的 plural-forms 設定（日文與繁中都是 nplurals=1）
     const LOCALE_META = {
       zh_TW: { language: 'zh_TW', pluralForms: 'nplurals=1; plural=0;' },
       ja:    { language: 'ja',    pluralForms: 'nplurals=1; plural=0;' },
     };
     ```
   - 主流程改為 `for (const locale of LOCALES) { ... }` 迴圈，把目前 hardcode 的：
     - `OLD_PO_PATH` / `NEW_PO_PATH` → `path.join(ROOT, 'languages', \`power-course-${locale}.po\`)`
     - `item.msgstr_zh_TW ?? item.zh ?? item.msgstr` → `item[\`msgstr_${locale}\`] ?? item.msgstr`（拿掉短欄位 `zh` 的 fallback，讓 schema 收斂）
     - `header.replace(/Language:\s*[^\n]*/, 'Language: zh_TW\nPlural-Forms: nplurals=1; plural=0;')` → 用 `LOCALE_META[locale]` 動態產出
   - 既有 console.log 從 `[build-zhtw-po]` 改為 `[build-po:${locale}]`，方便看每個 locale 的翻譯統計
   - 既有「翻譯衝突偵測」邏輯保留，但分 locale 各自統計

2. **`package.json` 修指令**：
   - `"i18n:merge": "node scripts/build-zhtw-po.mjs"` → `"i18n:merge": "node scripts/build-po.mjs"`

3. **驗證 mo / json 已是多語系 ready**：
   - `pnpm run i18n:mo` 應自動處理 `power-course-ja.po` → `power-course-ja.mo`（讀過原始碼確認 line 31 `readdirSync` 迴圈）
   - `pnpm run i18n:json` 同樣已迴圈（line 24）→ 產出 `power-course-ja.json`，並自動讀 `Plural-Forms` header（line 49）
   - 不需修改這兩支腳本

4. **i18n.rule.md「Pipeline 全景」圖更新**：
   - 把單一輸出改為兩條輸出線
   - 中間階段名稱維持不變，僅補上 locale 維度

**驗收**：
- `pnpm run i18n:build` 不報錯
- `git diff languages/power-course-zh_TW.po` 在 manual.json 內容沒變的前提下應為 0（或僅 header 時間戳差異）
- `languages/power-course-ja.po` 被產出，header 含 `Language: ja\nPlural-Forms: nplurals=1; plural=0;`，所有 entries 的 `msgstr ""`（因 Phase 1 還沒填日文）
- `languages/power-course-ja.mo` 與 `languages/power-course-ja.json` 自動產出（內容空但檔案存在）
- 翻譯衝突偵測仍可正常觸發（人工製造一筆衝突測試 console 輸出）

**保險措施**：Phase 2 commit 時 `manual.json` 的 `msgstr_ja` 全空 → 重構不耦合資料變動，diff 純粹是程式碼改動，便於 review。

---

### Phase 3 — LLM 批次翻譯產出（一次性執行）

**目標**：用 LLM 把 1,314 條 × 2 語系（含 zh_TW 缺漏 1,161 條）翻譯完成。

**任務**：

1. **產出最新 `.pot`** + **同步 manual.json 條目**：
   - `pnpm run i18n:pot` 確認所有 1,314 個 msgid 已抽取
   - 撰寫一次性同步腳本 `scripts/sync-manual-from-pot.mjs`（可獨立或併進 `llm-translate.mjs`）：
     - 讀 `power-course.pot`，找出 manual.json 缺漏的 msgid
     - Append 為 entry skeleton：`{msgid, msgstr_zh_TW: "", msgstr_ja: "", context: "(from .pot)"}`
     - 既有 entry 不動

2. **撰寫 LLM 翻譯腳本** `scripts/llm-translate.mjs`（**不納入** `i18n:build` pipeline）：
   - CLI：`node scripts/llm-translate.mjs --locale=ja [--only-empty] [--limit=N] [--dry-run]`
   - 實作要點：
     - 讀 `manual.json`，找出 `msgstr_${locale} === ""` 的 entries
     - `--only-empty` 強制只處理空欄位（保護人工翻譯不被覆寫）
     - 批次（建議每批 20–50 條）呼叫 LLM API（建議 Claude Sonnet/Opus，prompt 帶上 i18n.rule.md 的術語表）
     - prompt 範例（system message）：
       ```
       你是一位精通 WordPress 線上課程平台術語的翻譯員。請把英文 msgid 翻譯成 {locale}。
       術語表（必須遵守）：
       Course → コース, Chapter → チャプター, Student → 受講生, ...
       規則：
       - 保留所有 placeholder（%s, %1$d, %2$s, %d 等）
       - 保留所有 HTML tag（<a>, <strong>, <br/>）
       - 採用 Udemy 風格外來語，禁止過度漢字化
       - 沒有複數時 singular/plural 翻譯相同
       回傳 JSON 格式：[{"msgid":"...","msgstr":"..."}]
       ```
     - 寫回 `manual.json` 時保持原 entry 順序、其他欄位（含 context、`_comment`）不動
     - `--dry-run` 印出預計翻譯的條目但不寫檔
   - **API key 管理**：透過環境變數（`ANTHROPIC_API_KEY` 或專案 `.env`），CI 不執行此腳本
   - **錯誤處理**：LLM 回傳格式錯誤的 entry 留空 + log warning，下次再跑 `--only-empty` 補

3. **執行翻譯 + 跑 pipeline**：
   ```bash
   node scripts/llm-translate.mjs --locale=ja                           # 1,314 條
   node scripts/llm-translate.mjs --locale=zh_TW --only-empty           # 補約 1,161 條缺漏
   pnpm run i18n:build
   ```

4. **人工微調高曝光字串**（多 commits 拆分）：
   - 在 `manual.json` 中手動 review 以下類型字串（按優先序）：
     1. 高頻按鈕：Save / Delete / Add / Cancel / Confirm
     2. 主要 CTA：Add to cart / Enroll now / Buy now
     3. 教室導航：Back to My Courses / Replay chapter
     4. 錯誤訊息：Failed to ... / Please ...
     5. 標籤徽章：Featured / Popular / Free
   - 每類別獨立 commit，方便日後 git blame 追翻譯演進

**驗收**：
- `manual.json` 中所有 entries 的 `msgstr_ja` 與 `msgstr_zh_TW` 皆非空字串
- `pnpm run i18n:build` console 兩語系皆 `未翻譯（空 msgstr）: 0`
- 既有 153 條 `msgstr_zh_TW` 人工翻譯內容 SHA-256 與本 issue 開工前完全相同
- `git log --follow scripts/i18n-translations/manual.json` 可清楚分辨「LLM 大批量補翻」與「人工微調」commits

---

### Phase 4 — Runtime 載入驗證（瀏覽器人工煙霧測試）

**目標**：確認 WordPress 實際 render 出來的介面真的是日文，避免「pipeline 跑完但 runtime 沒讀到翻譯」的歷史坑（i18n.rule.md「前台 bundle 漏掛 @wordpress/i18n shim」）。

本專案有常駐本地站 `https://local-turbo.powerhouse.tw`，憑證在 `.env`。

**任務**：

1. **本地站台改 ja**：
   - 後台 → Settings → General → Site Language → 「日本語」→ Save
   - 用 WP-CLI（如可用）：`wp option update WPLANG ja`

2. **後台 SPA 走查**（Admin React SPA，handle = `power-course`）：
   - 課程管理列表（`/wp-admin/admin.php?page=power-course#/courses`）
   - 章節編輯（任一課程的「章節」分頁）
   - 學員管理（`/wp-admin/admin.php?page=power-course#/students`）
   - 銷售方案（`/wp-admin/admin.php?page=power-course#/bundles`）
   - 設定頁（`/wp-admin/admin.php?page=power-course#/settings`）
   - **截圖回報**前 5 個高頻頁面

3. **前台教室走查**（vanilla TS，handle = `power-course-template`）：
   - 用測試帳號購買並登入課程
   - 進入教室頁、播放章節、確認章節列表 / 播放器 overlay / 倒數提示全日文
   - 確認「カートに追加」「マイコースに戻る」等驗收標準提及的字串

4. **前台銷售頁走查**：
   - 訪問課程銷售頁（含 Featured/Popular/Free badge 的）
   - 加入購物車 → 結帳流程

5. **fallback 行為驗證**：
   - 在 `manual.json` 暫時清掉某個低頻 entry 的 `msgstr_ja`（commit 前還原）
   - 重跑 `i18n:build`，確認該字串顯示英文 msgid（非繁中、非空白、非 `[missing]`）
   - 與 i18n-japanese.feature `Rule: 缺翻譯 fallback` 對齊

6. **多語系並存驗證**：
   - 站台預設語言 zh_TW
   - 切某個用戶 profile 為 ja
   - 該用戶看到日文，其他用戶看到繁中（驗證 i18n-japanese.feature `Rule: 多語系並存無干擾`）

**驗收**：
- 三大區塊（後台 / 前台教室 / 前台銷售頁）均無漏翻譯
- 缺翻譯 entry 正確 fallback 為英文 msgid
- 兩個 Vite config（`vite.config.ts`、`vite.config-for-wp.ts`）都有 `@wordpress/i18n` shim alias

---

### Phase 5 — CI 警告 workflow + 發版驗證

**目標**：建立長期維護機制（Q6=B 警告不阻擋），確認發版 zip 含日文檔。

**任務**：

1. **新增 GitHub Actions workflow** `.github/workflows/i18n-coverage.yml`：
   - 觸發：`pull_request` 對 `master`（path filter 包含 `inc/**`、`js/**`、`plugin.php`、`scripts/i18n-translations/**`、`scripts/i18n-make-*.mjs`、`scripts/build-po.mjs`）
   - 步驟：
     - `actions/checkout`
     - `pnpm/action-setup`（不傳 `version`，依 `packageManager` 抓，避免歷史 CI 衝突）
     - `actions/setup-node`（with cache: pnpm）
     - `shivammathur/setup-php` + `tools: wp-cli`
     - `pnpm install --frozen-lockfile`
     - `pnpm run i18n:build`
     - 解析 stdout 各 locale 的 `未翻譯（空 msgstr）: N`，計算 zh_TW 與 ja 各自缺翻譯數
     - 若 `git diff --exit-code languages/` 非 0：留 PR comment「請跑 `pnpm run i18n:build` 並 commit 翻譯檔」
     - 若任一 locale 缺翻譯數 > 0：留 comment 列出未翻譯 msgid（最多 20 條 + 「...還有 N 條」）
     - 任何情況皆不 fail（exit 0，僅 comment）
   - 用 `actions/github-script` 或 `peter-evans/create-or-update-comment` 留 comment

2. **發版整合驗證**：
   - 本地執行 `pnpm run zip`
   - 解壓 `release/power-course.zip` 確認：
     - `languages/power-course.pot` 存在
     - `languages/power-course-zh_TW.{po,mo,json}` 三檔皆存在
     - `languages/power-course-ja.{po,mo,json}` 三檔皆存在
   - 確認 `release/.release-it.cjs` 的 `allowedItems` 已含 `'languages'`（line 75，**不需改**）
   - **注意**：`before:init` hook 是 `pnpm i18n:pot && pnpm i18n:json` 而非 `i18n:build`，這代表發版時只重產 pot/json、不重跑 merge/mo。本 issue **不修此 hook**（屬既有行為），但需在 PR description 提醒「發版前需先跑完整 `i18n:build` 並 commit 所有翻譯檔」

**驗收**：
- 在新建測試 PR 模擬「故意留一條未翻譯」場景，CI 留 warning comment
- 在乾淨 PR（無 i18n 變動）上 CI silent pass，不誤殺
- 解壓 zip 看到 6 個翻譯檔（zh_TW × 3 + ja × 3）+ 1 個 pot

---

### Phase 6 — 文件同步收尾

**目標**：把架構決策落入長期文件，讓未來新增第三、第四語系時可照做。

**任務**：

1. **更新 `.claude/CLAUDE.md`**：
   - 「技術棧總覽」i18n 列：把 `build-zhtw-po.mjs` 改為 `build-po.mjs`（多語系迴圈）；把「禁止手改 .po」維持
   - 「核心架構決策」段已在 Phase 1 補一行 multi-locale SSOT，這裡再 review
   - 「全域建置指令」i18n 段落不需動（指令名稱沒變）
   - 「i18n 資源」表格：補日文術語表入口

2. **更新 `.claude/rules/i18n.rule.md`**：
   - 「核心決策」表格的「目標語言」（已在 Phase 1 改）
   - 「Pipeline 全景」圖（已在 Phase 2 改）
   - 「術語表」段落 — 擴充至完整版（從 Phase 1 的 ~30 條核心擴到 100+ 條，可從 manual.json 高頻 msgid 摘錄）
   - 新增「新增第三語系（如 ko_KR）的步驟」段落，至少 5 步：
     1. 在 `manual.json` 每筆 entry 新增 `msgstr_ko_KR` 欄位
     2. 在 `scripts/build-po.mjs` 的 `LOCALES` 加入 `'ko_KR'`，`LOCALE_META` 補 `pluralForms`
     3. 在 `i18n.rule.md` 術語表補韓文欄
     4. 跑 `node scripts/llm-translate.mjs --locale=ko_KR`
     5. 跑 `pnpm run i18n:build` 並 commit 所有 `power-course-ko_KR.{po,mo,json}` + manual.json
   - 「PR 審查驗收標準」第 12 條補：「新字串需同時補 `msgstr_zh_TW` 與 `msgstr_ja`，缺 ja 時 CI 會 warning（i18n-coverage.yml）」

3. **PR template 補一行**（可選，需用戶決定）：
   ```markdown
   ## 國際化檢查
   - [ ] 若新增 user-facing 字串，已補 `msgstr_zh_TW` 與 `msgstr_ja`
   - [ ] 已跑 `pnpm run i18n:build` 並 commit 翻譯檔
   ```

**驗收**：
- CLAUDE.md / i18n.rule.md / （optional）PR template 三處同步更新
- 文件 lint：所有寫到 `build-zhtw-po.mjs` 的地方都已改為 `build-po.mjs`（用 `grep -r build-zhtw-po .claude/` 確認 0 筆）
- 新進開發者照著 rule 做能完整新增第三語系（憑文件即可）

## 3. Dependency Graph

```
Phase 0 (Baseline 凍結)
  └→ Phase 1 (術語表 + manual.json schema 擴欄)
       └→ Phase 2 (build-po.mjs 泛化)
            └→ Phase 3 (LLM 批次翻譯)
                 └→ Phase 4 (Runtime 瀏覽器驗證)
                      └→ Phase 5 (CI workflow + 發版)
                           └→ Phase 6 (文件同步)
```

各 Phase 嚴格序列；Phase 1/2/6 純工程處理；Phase 3 需 LLM API key + node 執行；Phase 4 需本地 WP 站。

## 4. 測試策略

本 issue 不屬於典型「商業邏輯 bug 修復」或「新增 CRUD 功能」，因此**不適合純 PHP Integration Test 或 Playwright E2E** 作為主要驗證手段。改採三層驗證：

| 層級 | 驗證對象 | 工具 | 自動化程度 |
|------|---------|------|-----------|
| 1. Pipeline 輸出 | `i18n:build` 是否產出 6 個翻譯檔 + 兩語系覆蓋率 100% | `pnpm run i18n:build` console 解析 + `ls languages/` | 全自動（CI 跑） |
| 2. 程式碼正確性 | `build-po.mjs` 多語系迴圈邏輯 | （可選）為 build-po.mjs 加一支簡單 node 單元測 — 餵假 manual.json + .pot 驗證輸出 | 半自動 |
| 3. Runtime 真實渲染 | WP 後台/前台實際顯示日文 | Phase 4 人工瀏覽器走查 + 截圖 | 人工 |

**建議的最小自動化測試**（Phase 2 同步加，可選）：
- `tests/scripts/build-po.spec.mjs` 用 vitest 或 node:test：
  - 餵假 .pot（含 plural entry）+ 假 manual.json（多語系欄位）
  - 斷言產出 .po header `Language` / `Plural-Forms` 對齊各 locale
  - 斷言 `msgstr` 對應正確的 `msgstr_${locale}` 欄位
  - 斷言衝突偵測仍生效

**E2E 不在本 issue 範圍**：
- Playwright config 加 ja locale matrix 是大工程，且本 issue 主要驗證在「字串對應 + WP runtime」層級，與 E2E 的「商業流程」目標不同
- 若未來想加，建議單獨開 issue（在 `tests/e2e/admin/i18n.spec.ts` 加一個 `test('renders Japanese UI when locale=ja')`）

## 5. 風險評估與緩解

| # | 風險 | 影響 | 緩解 |
|---|------|------|------|
| R1 | LLM 翻譯品質不一致（敬語層級、專業術語） | 介面不自然 | Phase 3 限定使用術語表 prompt + 高曝光字串人工 review；CLAUDE.md 留下「持續迭代翻譯品質」說明；Phase 5 CI warning 機制可作後續長期校正入口 |
| R2 | Pipeline 重構意外破壞 zh_TW | 既有用戶介面退化 | Phase 0 baseline 凍結 + Phase 2 「重構前 byte-for-byte 一致」當驗收門檻；Phase 1/2 commit 切分（schema vs 重構），方便 git revert |
| R3 | Bundle 漏掛 shim 導致 build 完整但 runtime 不讀（歷史坑） | 看似翻譯了但畫面是英文 | Phase 4 強制人工瀏覽器走查；i18n.rule.md 已明文要求所有 Vite config 加 shim alias |
| R4 | manual.json 變大（153 → 1,314）導致 PR diff 難審 | Code review 痛苦 | Phase 1（schema）、Phase 3（資料）獨立 commit；Phase 3 內部再分批（高曝光人工 vs LLM 批量）；diff 工具用 word-diff 模式 |
| R5 | 既有人工繁中翻譯被 LLM 誤覆寫 | 已驗證的譯文退化 | Phase 3 LLM 腳本必須 `--only-empty` 模式；PR description 明確列「zh_TW 受影響 entry 數預期 1,161 而非 1,314」 |
| R6 | CI workflow 過度敏感誤報 | 開發體驗下降 | Q6=B 採警告不阻擋設計；workflow 邏輯只檢測「未翻譯數量 > 0」非「未翻譯數量增加」；用 path filter 收斂觸發範圍 |
| R7 | Plural-Forms 配置錯誤導致 `_n()` 顯示英文 fallback | `_n()` 函式在 ja 時退回英文 | i18n-make-json.mjs line 49 已正確抽 header 的 Plural-Forms；新腳本 `build-po.mjs` 必須輸出 `nplurals=1; plural=0;`（已在計畫 LOCALE_META 寫死） |
| R8 | 發版 hook `before:init` 只跑 `i18n:pot && i18n:json` 不跑 merge/mo | 發版前忘了 commit .po 會導致 zip 缺漏 | 本 issue **不修此 hook**（外部範圍）；改在 PR description / Release SOP 註記「release 前必須 `pnpm run i18n:build` 並 commit `languages/`」 |
| R9 | LLM API key 洩漏 / 額度超支 | 安全與成本問題 | `llm-translate.mjs` 從 `process.env.ANTHROPIC_API_KEY` 讀；`.env` 已在 `.gitignore`；CI 不跑此腳本；分批呼叫並支援 `--limit=N` 做成本上限 |

## 6. 預期交付清單

```
新增/修改檔案：
├── scripts/
│   ├── build-po.mjs                                  (新增；取代 build-zhtw-po.mjs)
│   ├── build-zhtw-po.mjs                             (刪除；git mv 後消失)
│   ├── llm-translate.mjs                             (新增；一次性使用)
│   ├── sync-manual-from-pot.mjs                      (可選，新增；或併入 llm-translate)
│   └── i18n-translations/
│       └── manual.json                               (擴欄 msgstr_ja + LLM 補滿翻譯)
├── languages/
│   ├── power-course.pot                              (重產，可能新增字串)
│   ├── power-course-zh_TW.{po,mo,json}               (重產，補滿缺漏翻譯)
│   └── power-course-ja.{po,mo,json}                  (新增)
├── package.json                                      (修 i18n:merge 指向 build-po.mjs)
├── .github/workflows/
│   └── i18n-coverage.yml                             (新增 CI warning workflow)
├── .claude/
│   ├── CLAUDE.md                                     (修 i18n 列描述 + 補多語系 SSOT)
│   └── rules/
│       └── i18n.rule.md                              (frontmatter / 目標語言 / 術語表 / Pipeline 圖 / 第三語系教學)
├── tests/scripts/                                    (可選)
│   └── build-po.spec.mjs                             (build-po 單元測試)
└── specs/                                            (本 PR 已附)
    ├── clarify/2026-04-27-issue201-japanese-i18n.md
    ├── features/i18n-japanese/i18n-japanese.feature
    └── plan/issue-201-japanese-i18n.md
```

## 7. 不在範圍

- 韓文 / 英文（en_US 顯示）等其他語系新增 — 僅打底資料結構支援，不交付實際翻譯
- WordPress.org plugin directory 多語系上架元資料 — 留給後續 release issue
- WP_LANG_DIR / GlotPress 整合 — 仍維持 plugin 內 `languages/` 自帶翻譯
- 翻譯品質的人工全量 review — 接受首版純機器翻譯，後續以 issue 迭代修正
- E2E 測試新增 ja locale 案例 — 暫時以 Phase 4 人工瀏覽器驗證為主，未來可在 Playwright config 加 locale matrix
- 修改 `release/.release-it.cjs` 的 `before:init` hook — 屬既有行為，不在本 issue 處理

## 8. tdd-coordinator 任務移交摘要

本計畫**不適合純 TDD（紅綠燈）流程**，因為：
- 主要產物是「翻譯資料」與「pipeline 腳本」，缺乏明確的「先寫測試後寫實作」對應
- 唯一可 TDD 的是 `build-po.mjs` 邏輯（建議寫單元測試），但屬可選

**建議移交模式**：採「階段性 review + 驗收 checklist」而非 TDD：

| Phase | 移交對象 | 驗收方式 |
|-------|---------|---------|
| 1 | `wordpress-master`（manual.json 是 WordPress i18n 數據） | JSON schema lint + zh_TW SHA-256 不變 |
| 2 | `nodejs-master`（build script 重構） | 單元測試 + 跑 `i18n:build` byte-for-byte 比對 zh_TW.po |
| 3 | `wordpress-master`（翻譯內容） | console 兩語系 `未翻譯: 0` |
| 4 | （人工） | 瀏覽器截圖 + checklist |
| 5 | `workflow-master`（GitHub Actions） | act 本地驗證 + 真實 PR 測試 |
| 6 | `doc-updater` | grep 確認所有舊路徑已更新 |

每個 Phase 完成後 commit + push，PR 內以 commit 切分階段，方便分段 review。

---

**最後核對**：本計畫具體到可直接交給 tdd-coordinator 或 wordpress-master / nodejs-master / workflow-master 分階段執行；所有檔案路徑、行號、指令名稱皆已對應實際程式碼驗證。
