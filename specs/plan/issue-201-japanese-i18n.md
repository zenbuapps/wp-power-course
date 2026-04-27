# Issue #201 多語系日文翻譯 — 工程計畫

- **Issue**：[#201 多語系日文翻譯](https://github.com/j7-dev/wp-power-course/issues/201)
- **澄清紀錄**：[`specs/clarify/2026-04-27-issue201-japanese-i18n.md`](../clarify/2026-04-27-issue201-japanese-i18n.md)
- **行為規格**：[`specs/features/i18n-japanese/i18n-japanese.feature`](../features/i18n-japanese/i18n-japanese.feature)
- **核心原則**：英文 / 中文 / 日文多語系邏輯保持一致；最小改動現有 SSOT 機制

## 一張圖看完成果

```
原始碼                    [pnpm run i18n:build 串全套，多語系迴圈處理]
──────                    ─────────────────────────────────────────
PHP (inc/)                       │
JS/TSX (js/src/)                 │  i18n:pot   ──→ languages/power-course.pot
JS (inc/assets/src/)             │
                                 │
scripts/i18n-translations/       │                   ┌─→ power-course-zh_TW.po
  └── manual.json                │  i18n:merge   ───┤
       (msgstr_zh_TW + msgstr_ja)│  build-po.mjs    └─→ power-course-ja.po
                                 │
                                 │  i18n:mo     ──→ power-course-zh_TW.mo
                                 │                  power-course-ja.mo
                                 │
                                 └  i18n:json   ──→ power-course-zh_TW.json
                                                    power-course-ja.json
```

## 階段拆解（建議實作順序）

### Phase 1 — 術語表與資料結構升級（後端，無破壞性）

**目標**：為日文翻譯建立 1) 術語表 2) 資料結構基礎，不影響現有繁中流程。

**任務**：
1. **建立日文術語表**：在 `.claude/rules/i18n.rule.md` 新增「日文術語表（Glossary - ja）」段落，採 Udemy 風格外來語
   - Course → コース、Chapter → チャプター、Student → 受講生、Lesson → レッスン
   - Instructor → 講師、Bundle → バンドル、Cart → カート、Order → 注文
   - Classroom → 教室、Watch progress → 視聴進捗、Add to cart → カートに追加
   - Featured course → おすすめコース、Popular course → 人気コース、Free course → 無料コース
   - Save → 保存、Delete → 削除、Cancel → キャンセル、Confirm → 確認
   - 完整 100+ 詞需在實作時逐條對照繁中術語表補上
2. **升級 `scripts/i18n-translations/manual.json`**：每筆 entry 新增 `msgstr_ja` 欄位（先空字串，下一階段 LLM 補上）
   - 注意：不可破壞既有 `msgstr_zh_TW` 欄位
   - 範例 entry：
     ```json
     {
       "msgid": "Featured course",
       "msgstr_zh_TW": "精選課程",
       "msgstr_ja": "おすすめコース",
       "context": "inc/templates/components/badge/feature.php"
     }
     ```
3. **更新 i18n.rule.md 的「核心決策」表格**：目標語言區塊改為 `zh_TW + ja + en_US fallback`，移除「ja 為次階段」標註

**驗收**：
- `manual.json` 既有 153 條 `msgstr_zh_TW` 內容完全不變
- 新增 `msgstr_ja` 欄位 schema 通過 JSON validate
- 術語表新增段落有 100+ 詞涵蓋所有高頻 UI 字串

### Phase 2 — Pipeline 泛化（最小破壞性重構）

**目標**：把 `build-zhtw-po.mjs` 升級為支援多語系迴圈的 `build-po.mjs`，邏輯複用、不影響 zh_TW 行為。

**任務**：
1. **重新命名 + 泛化 build 腳本**：
   - `scripts/build-zhtw-po.mjs` → `scripts/build-po.mjs`
   - 在腳本頂部宣告 `const LOCALES = ['zh_TW', 'ja'];`
   - 主流程改為 `for (const locale of LOCALES) { ... }` 迴圈
   - 將 `msgstr_zh_TW`、`zh` 等 hardcode 鍵改為 `msgstr_${locale}`、locale-aware 的 fallback chain
   - PO header 的 `Language: zh_TW` / `Plural-Forms` 依 locale 動態產出（ja 採 `nplurals=1; plural=0;`）
   - 既有 `OLD_PO_PATH` / `NEW_PO_PATH` 改為依 locale 計算路徑
2. **更新 `package.json`**：
   - `"i18n:merge": "node scripts/build-zhtw-po.mjs"` → `"i18n:merge": "node scripts/build-po.mjs"`
3. **驗證 `i18n-make-mo.mjs` / `i18n-make-json.mjs` 已是多語系 ready**：兩支腳本目前已迴圈處理 `power-course-*.po`，無需改

**驗收**：
- 跑 `pnpm run i18n:build` 不報錯
- `languages/power-course-zh_TW.po` 內容與重構前 byte-for-byte 一致（除非有真實字串新增）
- `languages/power-course-ja.po` 被產出，header `Language: ja`、`Plural-Forms: nplurals=1;`
- 翻譯衝突偵測仍正常運作

**保險**：在重構前先 commit 一份既有 `languages/power-course-zh_TW.po` 作 baseline，重構後 `git diff` 應該僅有合理的格式差異或 0 差異。

### Phase 3 — LLM 批次翻譯產出（一次性執行）

**目標**：用 LLM 把 1,314 條（× 2 語系，含 zh_TW 缺漏補齊）翻譯完成。

**任務**：
1. **產出最新 `.pot`**：先跑 `pnpm run i18n:pot`，確保 manual.json 有完整 1,314 個 msgid 對應
2. **同步 manual.json 條目**：將 `.pot` 內所有 msgid 補上 manual.json 的 entry skeleton（`msgstr_zh_TW` / `msgstr_ja` 皆預設為空字串）
3. **撰寫一次性翻譯腳本** `scripts/llm-translate.mjs`（不納入 i18n:build pipeline）：
   - 讀 manual.json，找出 `msgstr_ja === ""` 或 `msgstr_zh_TW === ""` 的 entries
   - 批次呼叫 LLM API（建議 Claude Sonnet / Opus，prompt 帶上術語表）
   - 將回傳寫回 manual.json
   - 必須保留既有 153 條人工 zh_TW 翻譯（不覆寫）
4. **執行翻譯 + 跑 pipeline**：
   - `node scripts/llm-translate.mjs --locale=ja`（產 1,314 條 msgstr_ja）
   - `node scripts/llm-translate.mjs --locale=zh_TW --only-empty`（補 1,161 條缺漏 msgstr_zh_TW）
   - `pnpm run i18n:build`
5. **人工檢視高曝光字串**：手動 review manual.json 中的高曝光按鈕、錯誤訊息、表單 label（PR 中以 commit 分批進行人工微調）

**驗收**：
- manual.json 中所有 entries 的 `msgstr_ja` 與 `msgstr_zh_TW` 皆非空字串
- `pnpm run i18n:build` console 輸出兩語系皆 `未翻譯（空 msgstr）: 0`
- 既有 153 條 zh_TW 人工翻譯內容保持完全相同（git diff 無變動）

### Phase 4 — Runtime 載入驗證

**目標**：確認 WordPress 實際 render 出來的介面真的是日文，避免「pipeline 跑完但 runtime 沒讀到翻譯」的歷史坑（見 i18n.rule.md 「前台 bundle 漏掛 @wordpress/i18n shim」）。

**任務**：
1. **本地驗證 — 後台 SPA**：
   - 將本地 WP 站台語言改為「日本語」
   - 登入 `/wp-admin`，逐頁瀏覽 Power Course 主要管理頁面
   - 截圖前 5 個高頻頁面（課程列表 / 學員列表 / 章節編輯 / 銷售方案 / 設定）回報
2. **本地驗證 — 前台教室**：
   - 站台語言保持「日本語」
   - 用測試帳號購買並進入教室頁
   - 確認 navigation、播放器 overlay、章節提示、購物車流程全日文
3. **本地驗證 — 前台銷售頁**：
   - 訪問課程銷售頁、加入購物車、結帳流程
4. **檢查 Vite bundle**：確認 `vite.config.ts` 與 `vite.config-for-wp.ts` 兩支 config 的 `resolve.alias` 都已有 `@wordpress/i18n` shim（避免 i18n.rule.md 提到的歷史問題重演）
5. **fallback 行為驗證**：手動清掉某個 entry 的 `msgstr_ja`，重跑 pipeline，確認該字串顯示英文 msgid 而非繁中

**驗收**：
- 三大區塊（後台 / 前台教室 / 前台銷售頁）均無漏翻譯
- 缺翻譯 entry 正確 fallback 為英文 msgid
- 兩個 Vite config 都有 shim alias

### Phase 5 — CI 警告 workflow + 發版驗證

**目標**：建立長期維護機制，避免日文覆蓋率退化（Q6=B）。

**任務**：
1. **新增 GitHub Actions workflow** `.github/workflows/i18n-coverage.yml`：
   - 觸發：`pull_request` 對 `master` / 觸發於修改 `.po` / `.pot` / `manual.json` / PHP / JSX
   - 步驟：
     - `pnpm install`
     - `pnpm run i18n:build`
     - 解析 build 輸出，計算 zh_TW 與 ja 各自「未翻譯（空 msgstr）」數量
     - 若任一 locale 未翻譯數 > 0，於 PR 留 comment 警告（不阻擋 merge）
     - 若 `git diff languages/` 顯示差異未 commit，亦留 comment 提醒「請跑 i18n:build 並 commit 翻譯檔」
2. **發版整合驗證**：
   - 執行 `pnpm run zip`
   - 解壓 zip 確認 `languages/power-course-ja.{po,mo,json}` 三檔皆存在
   - 確認 `release/.release-it.cjs` / `release/zip.cjs` 不需要修改（既有 glob 應已涵蓋 `languages/*`）

**驗收**：
- CI workflow 在測試 PR 上正確留 comment（warning，不阻擋）
- release zip 內含日文翻譯檔
- workflow 不誤殺正常 PR（未動 i18n 的 PR 應 silent pass）

### Phase 6 — 文件同步收尾

**目標**：把架構決策落入長期文件，未來新增第三、第四語系時開發者能照著做。

**任務**：
1. **更新 `.claude/CLAUDE.md`**：
   - 「核心架構決策」段落補：「i18n 支援多語系 SSOT — `manual.json` 內以 `msgstr_{locale}` 欄位維護所有語言」
   - 「i18n 資源」表格補上術語表（zh_TW + ja）參考連結
2. **更新 `.claude/rules/i18n.rule.md`**：
   - 「目標語言」段落更新：`zh_TW + ja + en_US fallback`
   - 「Pipeline 全景」圖更新成多語系版本
   - 新增「新增第三語系的步驟」段落（教學用，至少 5 步）
   - 補日文術語表完整版（從 Phase 1 的草稿擴充至 100+ 詞）
3. **PR 模板補一行**（可選）：「☐ 若新增 user-facing 字串，已補 `msgstr_zh_TW` 與 `msgstr_ja`」

**驗收**：
- CLAUDE.md / i18n.rule.md / PR template 三處同步更新
- 新進開發者照著 rule 做能完整新增第三語系（憑文件即可）

## 風險與緩解

| 風險 | 緩解 |
|------|------|
| LLM 翻譯品質不一致（敬語層級、專業術語） | Phase 3 限定使用術語表 prompt + 高曝光字串人工 review；CLAUDE.md 留下「持續迭代翻譯品質」說明 |
| Pipeline 重構意外破壞 zh_TW | Phase 2 用「重構前 byte-for-byte 一致」當驗收門檻；保留 git revert path |
| Bundle 漏掛 shim 導致看似 build 完整但 runtime 不讀（歷史坑） | Phase 4 強制人工瀏覽器驗證；i18n.rule.md 已明文要求 shim alias |
| manual.json 變大導致 PR diff 難審 | 後續 commit 拆分 — Phase 1（schema）、Phase 3（資料）獨立 commit；diff 工具加 word-diff 模式 |
| 既有人工繁中翻譯被 LLM 誤覆寫 | Phase 3 LLM 腳本必須 `--only-empty` 模式，並在 PR description 列出 zh_TW 受影響 entry 數（預期 1,161 而非 1,314） |
| CI workflow 過度敏感誤報 | Q6=B 採警告不阻擋設計；workflow 邏輯只檢測「未翻譯數量 > 0」非「未翻譯數量增加」 |

## 不在範圍

- 韓文 / 英文（en_US 顯示）等其他語系新增 — 僅打底資料結構支援，不交付實際翻譯
- WordPress.org plugin directory 多語系上架元資料 — 留給後續 release issue
- WP_LANG_DIR / GlotPress 整合 — 仍維持 plugin 內 `languages/` 自帶翻譯
- 翻譯品質的人工全量 review — 接受首版純機器翻譯，後續以 issue 迭代修正
- E2E 測試新增 ja locale 案例 — 暫時以 Phase 4 人工瀏覽器驗證為主，未來可在 Playwright config 加 locale matrix

## 預期交付清單

```
新增/修改檔案：
├── scripts/
│   ├── build-po.mjs                                  (新增，取代 build-zhtw-po.mjs)
│   ├── llm-translate.mjs                             (新增，一次性使用)
│   └── i18n-translations/
│       └── manual.json                               (擴欄 + 補滿翻譯)
├── languages/
│   ├── power-course.pot                              (重產，可能新增字串)
│   ├── power-course-zh_TW.{po,mo,json}              (重產，補滿缺漏翻譯)
│   └── power-course-ja.{po,mo,json}                 (新增)
├── package.json                                      (修 i18n:merge 指向)
├── .github/workflows/
│   └── i18n-coverage.yml                             (新增)
├── .claude/
│   ├── CLAUDE.md                                     (補多語系說明)
│   └── rules/
│       └── i18n.rule.md                              (擴日文術語表 + 多語系教學)
└── specs/                                            (本 PR 已附)
    ├── clarify/2026-04-27-issue201-japanese-i18n.md
    ├── features/i18n-japanese/i18n-japanese.feature
    └── plan/issue-201-japanese-i18n.md
```

## Dependency Graph（簡）

```
Phase 1 (術語表 + manual.json schema)
  └→ Phase 2 (build-po.mjs 泛化)
       └→ Phase 3 (LLM 批次翻譯)
            └→ Phase 4 (Runtime 驗證)
                 └→ Phase 5 (CI workflow + 發版)
                      └→ Phase 6 (文件同步)
```

各 Phase 嚴格序列；Phase 1/2/6 可由純工程處理，Phase 3/4 需要可跑 LLM API + 本地 WP 站。
