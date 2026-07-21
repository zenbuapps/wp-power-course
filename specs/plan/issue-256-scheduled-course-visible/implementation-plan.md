# 實作計劃：Issue #256 — 排程中的課程保留在後台課程列表

> 模式：**HOLD SCOPE**（bug fix / 行為修正，影響 6 檔）。
> 澄清決策：`A A A A B A`（Q5=B → 後台一併補 `private`）。
> 交棒對象：`@zenbu-powers:tdd-coordinator`。

## 概述

後台課程列表查詢 `status` 硬編碼 `['publish', 'draft']`，導致「排程發佈（`post_status = future`）」與「私密（`private`）」課程從後台管理列表消失。本計劃將後台查詢 status 集合擴充為 `['publish', 'draft', 'future', 'private']`（REST 與 MCP 兩處一致），並在前端以藍色「排程中」Tag + 預計上架時間呈現、於篩選器新增「排程中」選項。前台學員端走獨立查詢路徑，天然隔離、不受影響。

## 需求重述

- 站長把課程發佈時間設為未來後儲存，該課程**仍出現在後台列表**（不消失）。
- 後台列表以藍色「排程中」Tag 標示，並顯示**預計上架時間**（沿用課程公告 `StatusTag.tsx` 的 `scheduled` 藍色樣式與 `_x('Scheduled', ...)` 用語）。
- 站長可直接點進排程課程編輯 / 改排程 / 取消排程（既有 edit_url 已支援，無需額外開發）。
- 後台篩選器新增「排程中(future)」選項，可單獨盤點即將上架課程。
- **紅線**：前台學員課程列表**絕不**出現 future/private/draft 課程（維持現行正確行為）。

## 已知風險（來自研究）

- **風險 R1（高）：`POST_STATUS` 為外部套件常數**
  `useColumns.tsx:72` 以 `antd-toolkit/wp` 匯出的 `POST_STATUS` 陣列 `.find` 對應 status label/color。`node_modules` 於 CI 未安裝、無法確認其是否含 `future` 項。若不含，`future` 課程狀態欄會渲染空 Tag。
  — 緩解：**不依賴** `POST_STATUS` 是否含 future。在 render 內對 `record.status === 'future'` **明確 special-case**，直接輸出藍色「排程中」Tag + `date_publish` 時間文字（同時滿足 Q3 顯示上架時間、Q4 沿用公告樣式）。其餘狀態維持 `POST_STATUS` 查表。

- **風險 R2（中）：`statusOptions` 為跨表共用常數**
  `js/src/utils/constants.ts` 的 `statusOptions` 同時被 Courses 與其他 ProductTable（`components/product/ProductTable/Filter/FullFilter`）共用。新增 `future` 會讓所有 product 篩選器都出現「排程中」。
  — 評估：符合「管理視角一致」精神，可接受；且商品本就可排程。標記於報告讓使用者知情。

- **風險 R3（中）：`future` 的 `date_publish` 來源語意**
  WordPress 排程貼文的 `post_date` 即預計上架時間；WC `get_date_created()` 回傳 `post_date`。故 future 課程的 `date_created` 已等於上架時間，但語意混淆。
  — 緩解：新增獨立 `date_publish` 欄位，**僅 `future` 狀態有值**（其餘 null），語意清楚，對齊 api.yml 規格。

- **風險 R4（低）：MCP 課程工具行為變動**
  `Service/Query::list` 亦被 `power-course-mcp` 課程列表工具呼叫（Q6=A 要求一致）。改 default status 後 AI Agent 也會看到 future/private。
  — 評估：這正是 Q6=A 的預期（人工後台與 AI 視角統一），非缺陷。

- 未發現額外已知風險。

## 架構變更

| # | 檔案 | 變更摘要 |
|---|------|----------|
| C1 | `inc/classes/Api/Course.php` (~L144) | `$default_args['status']` `['publish','draft']` → `['publish','draft','future','private']` |
| C2 | `inc/classes/Api/Course.php` (`format_course_base_records`, ~L431 附近) | 新增 `'date_publish'` 欄位：status=future 時回 `post_date`（`Y-m-d H:i:s`），否則 `null` |
| C3 | `inc/classes/Resources/Course/Service/Query.php` (L37) | `$default_args['status']` 同 C1（MCP 一致） |
| C4 | `js/src/utils/constants.ts` (L17 `statusOptions`) | 新增 `{ label: _x('Scheduled','post status','power-course'), value: 'future' }` |
| C5 | `js/src/pages/admin/Courses/List/hooks/useColumns.tsx` (status 欄 render, L70-73) | special-case `future`：藍色「排程中」Tag + `date_publish` 時間文字 |
| C6 | `js/src/pages/admin/Courses/List/types/index.ts` | `TCourseRecord` / base 型別新增 `date_publish?: string \| null` |

> **無** DB schema 變更（課程狀態存於 WP 核心 `wp_posts.post_status`）。`erm.dbml` 的 enum 註解已由規格 commit 更新，程式無 migration。

## 資料流分析

### 後台課程列表查詢（REST `GET /courses` / MCP 課程工具）

```
REQUEST ──▶ parse_args(status) ──▶ wc_get_products ──▶ format_course_base_records ──▶ RESPONSE
  │              │                      │                      │                          │
  ▼              ▼                      ▼                      ▼                          ▼
[無 status?]  [status 非法?]        [0 筆?]              [非 WC_Product?]           [future 無 date?]
 用預設4態     WP 忽略未知值          回空陣列              return []（已處理）         date_publish=null
```

- **nil/empty path**：無 `status` → 套用預設 `['publish','draft','future','private']`；查無課程 → `wc_get_products` 回空、分頁 header 為 0（既有行為，已處理）。
- **error path**：`format_course_base_records` 已對非 `WC_Product` 回 `[]`（L349-351）。
- **future 特例**：`date_publish` 僅 future 有值，前端渲染前先判空。

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `get_courses_callback` status 參數 | 傳入未知 status 字串 | 無效輸入 | `wc_get_products` 靜默忽略未知值，回符合的子集 | 否（無害） |
| `format_course_base_records` | product 非 WC_Product | 型別錯誤 | 既有 `return []`（L349） | 否 |
| `format_course_base_records` future 分支 | `get_date_created()` 回 null | 空值 | `?->date()` 安全鏈，`date_publish` = null | 否 |
| `useColumns` future render | `date_publish` 為 null/undefined | 空值 | 判空 → 僅顯示 Tag 不顯示時間（或 `-`） | 是（優雅降級） |

> 無「處理方式=無 且 靜默」項 → 無 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| 後台 status 預設 | future/private 仍消失（改漏一處） | 待實作 | ✅ 新 IT | 是 | 補齊兩處 default |
| 前台紅線 | future 課程外洩到 `[pc_courses]` | 天然隔離 | ✅ 新 regression IT | 是（嚴重） | 前台獨立查詢不動 |
| date_publish | future 課程列表不顯示上架時間 | 待實作 | ✅ 新 IT 斷言 | 是 | C2 欄位 |
| 狀態 Tag | future 顯示空 Tag（R1） | special-case | ✅ E2E/元件 | 是 | C5 明確分支 |

## 實作步驟

### 第一階段：後端查詢層（核心根因）

1. **擴充 REST 後台預設 status**（`inc/classes/Api/Course.php` ~L144）
   - 行動：`'status' => [ 'publish', 'draft' ]` → `[ 'publish', 'draft', 'future', 'private' ]`
   - 原因：直接消除課程消失的根因
   - 依賴：無 ｜ 風險：低

2. **擴充 MCP 共用查詢層預設 status**（`inc/classes/Resources/Course/Service/Query.php` L37）
   - 行動：同步驟 1
   - 原因：Q6=A 人工後台與 AI 視角一致
   - 依賴：無（與步驟 1 可並行）｜ 風險：低

3. **新增 `date_publish` 欄位**（`Api/Course.php` `format_course_base_records`，`base_array` 內 `date_created` 附近）
   - 行動：`'date_publish' => ( 'future' === $product->get_status() ) ? $date_created?->date('Y-m-d H:i:s') : null,`
     （future 貼文 `post_date` 即預計上架時間，`get_date_created()` 已回傳之）
   - 原因：對齊 api.yml，語意清楚供前端顯示上架時間
   - 依賴：無 ｜ 風險：低

### 第二階段：前端呈現

4. **`statusOptions` 新增排程中選項**（`js/src/utils/constants.ts` L17）
   - 行動：陣列加入 `{ label: _x('Scheduled', 'post status', 'power-course'), value: 'future' }`（import 補 `_x`）
   - 原因：Q2=A 篩選器可選「排程中」；FullFilter 自動吃此陣列
   - 依賴：無 ｜ 風險：中（R2 跨表共用，已知情）

5. **型別補 `date_publish`**（`js/src/pages/admin/Courses/List/types/index.ts`）
   - 行動：base record 型別新增 `date_publish?: string | null`
   - 依賴：無 ｜ 風險：低

6. **狀態欄 special-case future**（`js/src/pages/admin/Courses/List/hooks/useColumns.tsx` L70-73）
   - 行動：render 內先判 `record.status === 'future'`：
     ```tsx
     if (record?.status === 'future') {
       return (
         <Tag color="blue">
           {_x('Scheduled', 'post status', 'power-course')}
           {record?.date_publish ? ` · ${record.date_publish}` : ''}
         </Tag>
       )
     }
     // 其餘維持 POST_STATUS 查表
     ```
     （用語與顏色沿用公告 `CourseAnnouncement/StatusTag.tsx` 的 blue/scheduled；建議 import `_x`）
   - 原因：Q3+Q4 藍色排程中 Tag + 上架時間；不依賴外部 `POST_STATUS` 是否含 future（R1 緩解）
   - 依賴：步驟 5（型別）｜ 風險：中（R1）

### 第三階段：i18n 同步

7. **補繁中翻譯並跑 pipeline**
   - 行動：`scripts/i18n-translations/manual.json` 加入 `Scheduled`（context `post status`）→「排程中」；跑 `pnpm run i18n:build`；commit `.pot`/`.po`/`.mo`/`.json`
   - 原因：遵守 `.claude/rules/i18n.rule.md`（禁手改 .po；msgid 英文）
   - 注意：公告已有 `_x('Scheduled','announcement status',...)`；本處 context 為 `post status`，屬不同 context 條目，需獨立翻譯
   - 依賴：步驟 4、6 ｜ 風險：低

## 測試策略

### PHP Integration Test（主力，紅燈先行）

新增 `tests/Integration/Course/CourseListScheduledStatusTest.php`（`@group course`），對應 `specs/features/course/查詢課程列表.feature`：

- **T1** 預設查詢回傳 future 課程（排程課程不消失）— 建 publish×2 + future×1，`Query::list([])` items 含 future 課程
- **T2** 預設查詢回傳 private 課程 — 同上含 private
- **T3** 預設查詢總數 = 5（對齊 feature Background：publish×2/draft/future/private）
- **T4** `status=future` 篩選只回 1 筆 future 課程
- **T5** `status=private` 篩選只回 1 筆 private 課程
- **T6** future 課程回應 `status='future'` 且 `date_publish` = 排程 post_date（feature：`2025-08-01 09:00:00`）
- **T7** 非 future 課程 `date_publish` 為 null
- 建立排程課程：`create_course(['post_status'=>'future','post_date'=>'2025-08-01 09:00:00', ...])`（helper 已支援 `post_status`/透傳 `post_date`）

### PHP Integration Test（前台紅線 regression guard）

擴充 `tests/Integration/Shortcode/CoursesPaginationTest.php`（或新增 `FrontendScheduledExclusionTest.php`），對應 `specs/features/shortcode/課程列表分頁.feature` 新增紅線 Rule：

- **T8** 前台 `[pc_courses]` 第 1 頁：publish×3 + future×1 → 只渲染 3 筆，不含排程課程
- **T9** 前台排除 private/draft 課程
- 驗證前台獨立查詢路徑不因後台改動而外洩

### E2E（Playwright，admin，冒煙驗證）

擴充 `tests/e2e/01-admin/course-list.spec.ts`：

- 排程課程出現在後台列表且狀態欄顯示藍色「排程中」Tag（`.ant-tag` 含排程中文字）
- （若成本允許）篩選器 status 下拉含「排程中」選項

### 測試執行指令

```bash
composer run test                              # 全部 PHPUnit
# 或針對性：
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course \
  -- vendor/bin/phpunit --group course
pnpm run test:e2e:admin                        # admin E2E
pnpm run lint:php                              # phpcbf + phpcs + phpstan(level 9)
pnpm run lint:ts                               # ESLint
```

### 關鍵邊界情況

- future 課程 `post_date` 恰等於當下（邊界）— 由 WP 狀態機決定，本計劃不干預轉態
- 同時傳 `status=future` 且分頁 — 分頁 header 正確反映 future 子集數量
- `date_publish` null（非 future）前端不顯示時間 — 優雅降級

## 依賴項目

- 外部：`antd-toolkit`（`POST_STATUS` / `DateTime`；本計劃刻意不擴充其 `POST_STATUS`，改 special-case）
- 內部：`CourseAnnouncement/StatusTag.tsx`（樣式/用語參照對象，不修改）
- WP 核心 `post_status` 狀態機（future 轉 publish 由 wp-cron 既有機制處理，不在範圍）

## 風險與緩解措施

- **高**：R1 `POST_STATUS` 未必含 future → useColumns special-case future，不依賴外部常數
- **中**：R2 `statusOptions` 跨表共用 → 接受並在交付報告標記知情
- **中**：R3 `date_publish` 語意 → 新增獨立欄位僅 future 有值
- **低**：R4 MCP 行為變動 → 為 Q6=A 預期結果

## 錯誤處理策略

沿用既有防禦：`format_course_base_records` 型別守衛（`return []`）、`?->` 空值安全鏈。前端對 `date_publish` 判空優雅降級。無新增使用者可見錯誤路徑。

## 限制條件（本計劃不做）

- 不改前台學員端課程列表查詢路徑（紅線：維持只顯示 publish + visible）
- 不納入 `course_schedule`（開課時間）/ 銷售方案自動上下線等其他排程概念（Q1=A 僅 WP 原生排程發佈）
- 不新增 DB 表 / 不做 schema migration
- 不擴充 `antd-toolkit` 的 `POST_STATUS` 常數（外部套件，改用本地 special-case）
- 不處理 future→publish 的自動轉態機制（WP 核心 wp-cron 既有）
- 不改課程編輯頁排程設定 UI（既有 edit_url 已足以改 / 取消排程）

## 成功標準

- [ ] 課程設 future 發佈時間並儲存後，仍出現在後台列表（T1、T3）
- [ ] private 課程出現在後台列表（T2）
- [ ] 後台列表 future 課程顯示藍色「排程中」Tag + 預計上架時間（T6、E2E）
- [ ] 後台篩選器可單選「排程中」盤點（T4、E2E）
- [ ] 前台 `[pc_courses]` 不出現 future/private/draft 課程（T8、T9）
- [ ] 排程中呈現與課程公告一致（藍色 scheduled）
- [ ] `pnpm run lint:php` / `lint:ts` / `composer run test` 全綠
- [ ] i18n pipeline 跑過，四檔一起 commit

## 預估複雜度：低

後端 3 行核心改動 + 1 欄位；前端 1 選項 + 1 render 分支 + 型別；測試為主要工作量。單一 PR 可獨立交付。
