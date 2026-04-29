# 實作計劃：課程公告 (Issue #6)

## 概述

為 Power Course 新增「課程公告」功能。管理員可在課程編輯頁建立、更新、刪除（軟刪除/還原）公告，支援立即發佈與預約發佈、結束時間、可見性（公開 / 僅學員）。前台銷售頁在「價格區下方、Tab 上方」嵌入公告區塊（**非 Tab**），多則公告以手風琴排版（最新展開、其餘折疊），依 `post_date` 由新到舊排序。資料採用 WordPress Custom Post Type `pc_announcement`，重用 Power Editor 整合與 `wp_cron` 內建排程。

## 範圍模式：HOLD SCOPE

**預估影響**：18 個檔案（後端 PHP 9 + 前端 TSX 5 + 前台 PHP 模板 3 + 整合測試 1 起跳）。所有 11 個澄清決策已確認（Q1+Q2 不要 Tab、Q3 個別可見性、Q4 不做通知、Q5 CPT、Q6 軟刪除可還原、Q7 第一版不做 MCP；第二輪 C D A A）。

## 需求重述

### 後台管理（CRUD）
1. 管理員可在課程編輯頁的「課程公告」分頁建立、編輯、刪除公告
2. 公告內容以 Power Editor 編輯（與章節同一套）
3. 標題必填、`parent_course_id` 必填且需指向 `_is_course=yes` 的商品（含外部課程）
4. 可選結束時間 `end_at`（10 位 Unix timestamp，須晚於 `post_date`）
5. 可選可見性 `visibility`：`public` / `enrolled`，預設 `public`
6. 立即發佈（`post_status=publish`）或預約發佈（`post_status=future`，由 `wp_cron` 自動轉 `publish`）
7. 列表顯示狀態標籤：`active` / `scheduled` / `expired`，依 `post_date` DESC 排序
8. 刪除為軟刪除（`wp_trash_post`），可透過 restore 端點還原；`force=true` 可永久刪除
9. 已 trash 的公告再次刪除視為冪等成功（避免 `wp_trash_post` 對 trash post 回 false）

### 前台銷售頁顯示
10. 公告區塊嵌在「價格區下方、Tab 上方」（DOM 順序：`#course-pricing` 之後、`#courses-product__tabs-nav` 之前）
11. 多則生效公告以手風琴排版：最新一則預設展開，其餘折疊
12. 點擊折疊項可展開（多項可同時展開），點同一項可再次收合
13. 沒有任何生效公告 → 區塊整體不渲染（不出現 `#pc-announcement-section` 元素）
14. 對未登入訪客／未購學員：只顯示 `visibility=public` 且 `post_status=publish` 且未過 `end_at` 的公告
15. 對已購學員：可額外看到 `visibility=enrolled` 的公告
16. 外部課程（External Course）也支援公告區塊
17. 響應式佈局與既有共用，手機端（375px）正常垂直堆疊

## 已確認的設計決策

### 第 1 輪
| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1+Q2 | Tab 位置與隱藏開關 | **不需要 Tab**，公告直接顯示在銷售頁上 |
| Q3 | 公告可見性 | **C — 每則公告個別設定**（`public` / `enrolled`） |
| Q4 | 通知機制 | **A — 第一版不做**，獨立 Issue 處理 |
| Q5 | 資料儲存 | **A — CPT (`pc_announcement`)**，重用 Power Editor + `wp_cron` |
| Q6 | 刪除行為 | **B — 軟刪除可還原**（`wp_trash_post`） |
| Q7 | MCP 整合 | **B — 第一版不做**，留待下一個 Issue |

### 第 2 輪
| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1 | 公告區塊位置 | **C — 價格區下方、Tab 上方** |
| Q2 | 多則公告排版 | **D — 最新展開、其餘折疊**（手風琴） |
| Q3 | 未購者看到 enrolled 公告 | **A — 完全隱藏** |
| Q4 | 學習頁是否同顯 | **A — 只放銷售頁**（學習頁獨立 Issue） |

## 已知風險（來自程式碼研究）

| 風險 | 嚴重度 | 緩解措施 |
| --- | --- | --- |
| `wp_trash_post()` 對已是 trash 的 post 回傳 false，誤判為失敗 | 中 | Service::delete 先檢查 `get_post_status() === 'trash'`，是則回 true（沿用 `Chapter\Service\Crud::delete` 模式） |
| 預約發佈使用 `post_status=future` + `wp_cron`，遇到 wp_cron 卡住時公告不會自動轉 publish | 中 | 文件記錄並依賴 WordPress 既有機制；前台公開列表查詢一律用 `post_status=publish + post_date <= now`，如此即使 wp_cron 延遲，公告 `post_date` 一旦到期 `WP_Query` 直接會把 future 文章視為非 publish 不顯示，符合語意 |
| 已過期公告（`end_at < now`）若未過濾會洩漏到前台 | 高 | 前台公開列表透過 `meta_query` 加上 `(end_at IS NULL OR end_at = '' OR end_at > UNIX_TIMESTAMP())` 過濾 |
| `visibility=enrolled` 公告被未購者看到 | 高 | `Service\Query::list_public()` 內以 `CourseUtils::is_avl()` 判斷使用者，未通過則只回 `visibility=public` |
| 銷售頁渲染時發 REST 請求會拖慢 TTFB | 中 | 公告區塊由 PHP 直接 server-side 渲染（與 chapters / qa 同模式），不走 REST；管理 SPA 才走 REST |
| 課程刪除（trash/delete）後遺留孤兒公告 | 中 | `Core\LifeCycle` 監聽 `before_delete_post` 與 `wp_trash_post`，課程被刪除時連帶 trash 該課程的公告 |
| `post_date='future' status=publish` 不一致風險 | 中 | `Service\Crud::create/update` 內統一規則：傳入 `post_date` 為未來時間且 `post_status=publish`，自動修正為 `post_status=future`；反之 `post_status=future` 但 `post_date` 為過去時間，視為立即發佈 |
| 時區問題：`post_date` vs `post_date_gmt` 與 `end_at` Unix timestamp | 中 | 一律以 `wp_date()` / `current_time('timestamp')` 處理，並在 Utils 提供 `is_active(WP_Post): bool` 統一判斷 |
| Power Editor 富文本 XSS | 高 | 儲存時走 WP 標準 `wp_insert_post` 流程（內部會 `wp_kses_post`），輸出時用 `wpautop()` + 信任 KSES 後內容（與章節 / 商品描述同模式） |
| 站長累積的公告數量大 → 銷售頁查詢慢 | 低 | 加入 `idx_announcements_course_status`、`idx_announcements_course_date` 索引（透過 `register_post_type` + meta key index 由 WP 內建處理）；公告查詢以 `posts_per_page=20` 為上限 |

## 架構變更

### 後端（PHP）— 9 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 1 | `inc/classes/Resources/Announcement/Core/Loader.php` | **新增** | 模組初始化 `Loader`：載入 `CPT::instance()`, `Api::instance()`, `LifeCycle::instance()` |
| 2 | `inc/classes/Resources/Announcement/Core/CPT.php` | **新增** | 註冊 `pc_announcement` CPT。`hierarchical=false`、`public=false`、`show_ui=Plugin::$is_local`、`supports=[title, editor, custom-fields, author]`（**不含** `page-attributes`），`show_in_rest=true` 但走 React SPA 不依賴 WP 原生 |
| 3 | `inc/classes/Resources/Announcement/Core/Api.php` | **新增** | 繼承 `ApiBase`，namespace `power-course`。註冊 6 個端點（見「REST API 端點」一節）。所有 callback 委派 `Service\Query` 與 `Service\Crud` |
| 4 | `inc/classes/Resources/Announcement/Core/LifeCycle.php` | **新增** | 監聽 `save_post_pc_announcement`：`delete_transient` 清銷售頁渲染快取；監聽 `wp_trash_post` / `before_delete_post` of `product`：連帶 trash 該課程的公告 |
| 5 | `inc/classes/Resources/Announcement/Service/Crud.php` | **新增** | `create / update / delete / delete_many / restore` 業務邏輯。內含參數驗證（visibility / end_at 格式、end_at > post_date、parent_course_id 存在且 `_is_course=yes`）與 post_status / post_date 一致性修正 |
| 6 | `inc/classes/Resources/Announcement/Service/Query.php` | **新增** | `list / list_public / get` 查詢邏輯。`list_public` 內含可見性過濾（依 `is_avl` 判斷未購/已購）與 `end_at` 生效判斷 |
| 7 | `inc/classes/Resources/Announcement/Utils/Utils.php` | **新增** | 靜態工具：`format_announcement_details(WP_Post): array`（含 `status_label` 計算）、`is_active(WP_Post): bool`、`get_cache_key(int $course_id): string`、`validate_visibility / validate_end_at` |
| 8 | `inc/classes/Resources/Loader.php` | 修改 | 1 行加上 `Announcement\Core\Loader::instance();` |
| 9 | `inc/classes/Resources/Course/LifeCycle.php` | 修改 | （若課程有獨立 LifeCycle 處理 trash/delete）增 hook 連動 trash 課程下的公告。若無，則於 `Announcement\Core\LifeCycle` 自行處理 |

### 前台模板（PHP）— 3 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 10 | `inc/templates/pages/course-product/announcement.php` | **新增** | 公告區塊整體模板。Server-side 渲染，呼叫 `Service\Query::list_public( $course_id )` 取得有效公告，逐筆輸出 `pc-collapse` 結構（複用 `pc-collapse pc-collapse-arrow` 既有 CSS）。沒有公告時直接 `return;` 不輸出任何 HTML |
| 11 | `inc/templates/pages/course-product/body.php` | 修改 | 在 line 152（`echo '</div>';` 之後、`Plugin::load_template( 'course-product/tabs', ... )` 之前）插入 `Plugin::load_template( 'course-product/announcement', null, true, true );` |
| 12 | `inc/templates/components/collapse/announcement.php` | **新增**（可選，看是否需獨立元件） | 單筆公告卡片渲染。標題作為 collapse-title，post_date 顯示格式化日期，內容用 `wpautop( $post->post_content )` |

### 前端 React SPA（TypeScript/TSX）— 5 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 13 | `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/index.tsx` | **改寫** | 主元件：`<List>` + `<Button>` 新增 + `<ProTable>` 顯示公告列表。每列含狀態標籤、標題、發佈期間、可見性、操作（編輯/刪除/還原）。透過 `useList({ resource: 'announcements', dataProviderName: 'power-course', filters: [{ field: 'parent_course_id', value: record.id }] })` 取得列表 |
| 14 | `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/AnnouncementForm.tsx` | **新增** | 公告編輯表單（Drawer 或 Modal）。欄位：title、Power Editor (post_content)、`post_status` (publish/future radio)、`post_date` (DatePicker)、`end_at` (DatePicker，可空)、`visibility` (Radio：public/enrolled)。透過 `useCreate` / `useUpdate` 提交 |
| 15 | `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/types.ts` | **新增** | `TAnnouncement` 型別定義，對齊 `AnnouncementBase` schema |
| 16 | `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/StatusTag.tsx` | **新增** | 三色狀態標籤元件（active=綠 / scheduled=藍 / expired=灰），用於列表 |
| 17 | `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/PowerEditorField.tsx` | **新增**（如無共用元件） | 包裝既有的 Power Editor 為 `Form.Item` 可控元件（章節編輯頁應已有，可直接 import） |

### 前台 vanilla JS（可選）— 1 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 18 | `inc/assets/src/events/announcement.ts` | **新增**（可選） | 若手風琴互動採純 CSS（`<input type="checkbox">` + label）則不需要；若需 JS 控制（如預設只展開最新一則），用 jQuery 在 DOM ready 時遍歷 `.pc-announcement-item` 並設置第一個為 `checked`。**建議使用既有 `<input type="checkbox" checked>` 的純 CSS 模式（與 qa.php line 41 同），最新一則加 `checked` 屬性即可，無需 JS。** |

### 整合測試 — 4 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 19 | `tests/Integration/Announcement/AnnouncementCPTStructureTest.php` | **新增** | 測試 CPT 註冊參數、post_parent 關係、post_status 與 post_date、end_at meta、visibility meta 預設值與排序 |
| 20 | `tests/Integration/Announcement/AnnouncementCrudTest.php` | **新增** | 測試 `Service\Crud` create/update/delete/restore：標題必填、parent_course_id 校驗（含外部課程）、visibility 校驗、end_at 校驗、軟刪除冪等性、過期公告 end_at 更新後重生效 |
| 21 | `tests/Integration/Announcement/AnnouncementQueryTest.php` | **新增** | 測試 `Service\Query` list / list_public：排序、status_label 計算、預設不含 trash、未購/已購可見性過濾 |
| 22 | `tests/Integration/Announcement/AnnouncementApiTest.php` | **新增** | REST 端點完整測試（GET list、POST create、POST update、DELETE single/bulk、POST restore、GET public） |

### E2E 測試 — 2 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 23 | `tests/e2e/01-admin/announcement-crud.spec.ts` | **新增** | 管理端 CRUD 完整流程：建立 → 編輯 → 軟刪除 → 還原 → 永久刪除 |
| 24 | `tests/e2e/02-frontend/announcement-display.spec.ts` | **新增** | 前台銷售頁公告區塊：未購者看 public、已購者看 public+enrolled、無公告時隱藏、過期公告隱藏 |

## REST API 端點（同 specs/api/api.yml）

namespace 統一為 `power-course`，所有 endpoint 繼承既有 nonce 驗證與 `manage_woocommerce` capability check（除 `/announcements/public` 開放）：

| Method | Endpoint | Service | 說明 |
| --- | --- | --- | --- |
| GET | `/announcements` | `Query::list($params)` | 後台列表（管理員） |
| POST | `/announcements` | `Crud::create($data, $meta)` | 建立 |
| DELETE | `/announcements` | `Crud::delete_many($ids, $force)` | 批次刪除 |
| GET | `/announcements/public` | `Query::list_public($course_id, $user_id)` | 前台公開列表，**permission_callback = `__return_true`**（不需 nonce） |
| GET | `/announcements/(?P<id>\d+)` | `Query::get($id)` | 取得單一公告 |
| POST | `/announcements/(?P<id>\d+)` | `Crud::update($id, $data, $meta)` | 更新 |
| DELETE | `/announcements/(?P<id>\d+)` | `Crud::delete($id, $force)` | 軟刪除（`force=true` 永久刪除） |
| POST | `/announcements/(?P<id>\d+)/restore` | `Crud::restore($id)` | 還原 |

## 資料模型（meta keys）

| Meta Key | 型別 | 用途 |
| --- | --- | --- |
| `parent_course_id` | int | 與 `post_parent` 同值，便於 `meta_query` |
| `end_at` | int (Unix timestamp) | 結束時間；空字串 / 0 / null 代表永久 |
| `visibility` | string | `public` / `enrolled`，預設 `public` |
| `editor` | string | 固定 `power-editor`（與章節一致） |

## 實作順序（依依賴關係）

### Phase 1：後端骨架（可同 Test-First）
1. 新增 `Resources/Announcement/Core/CPT.php` + `Resources/Announcement/Core/Loader.php`
2. 新增 `Resources/Announcement/Utils/Utils.php`（`format_announcement_details`、`is_active`、`get_cache_key` 框架）
3. 在 `Resources/Loader.php` 註冊 `Announcement\Core\Loader::instance()`
4. **驗收**：跑 `pnpm run lint:php`、確認 `register_post_type` 觸發；寫 `AnnouncementCPTStructureTest` 並通過

### Phase 2：後端業務邏輯
5. 新增 `Service\Crud`（create/update/delete/delete_many/restore）含參數驗證
6. 新增 `Service\Query`（list/list_public/get）含可見性過濾與 status_label 計算
7. 新增 `Core\LifeCycle`（save_post 清快取、課程刪除連動 trash 公告）
8. **驗收**：`AnnouncementCrudTest`、`AnnouncementQueryTest` 通過

### Phase 3：REST API
9. 新增 `Core\Api`，註冊 6 個端點
10. **驗收**：`AnnouncementApiTest` 通過；用 `curl` 或 Postman 手動驗 8 個 endpoint 的 happy path 與錯誤路徑

### Phase 4：前台銷售頁渲染
11. 新增 `inc/templates/pages/course-product/announcement.php`，呼叫 `Service\Query::list_public()` server-side 渲染
12. 修改 `inc/templates/pages/course-product/body.php` 插入 `load_template`
13. **驗收**：以 `playwright-cli` 開啟銷售頁，截圖確認公告位置正確，無公告時不顯示

### Phase 5：管理 SPA UI
14. 新增 `js/src/pages/admin/Courses/Edit/tabs/CourseAnnouncement/types.ts` 與 `StatusTag.tsx`
15. 改寫 `CourseAnnouncement/index.tsx`：列表 + 新增按鈕
16. 新增 `AnnouncementForm.tsx`：Drawer 編輯表單（Power Editor）
17. 取消 `Edit/index.tsx` line 182~187 的註解（啟用 `CourseAnnouncement` Tab）
18. **驗收**：`pnpm run lint:ts && pnpm run build`，瀏覽器手動測 CRUD 流程

### Phase 6：i18n 與打包
19. 跑 `pnpm run i18n:build`，補繁中翻譯到 `scripts/i18n-translations/manual.json`
20. **驗收**：`languages/power-course-zh_TW.po` 含本次新增的字串，介面切繁中時正確顯示

### Phase 7：E2E 測試
21. 寫 `tests/e2e/01-admin/announcement-crud.spec.ts`
22. 寫 `tests/e2e/02-frontend/announcement-display.spec.ts`
23. **驗收**：`pnpm run test:e2e:admin && pnpm run test:e2e:frontend` 全綠

## 測試策略

### PHP Integration Test（PHPUnit + WP_UnitTestCase）

採 `Tests\Integration\TestCase` 基類（與 Chapter 同），覆蓋：

- **CPT 結構**（`AnnouncementCPTStructureTest`）：對應 `公告CPT結構.feature` 全部 Examples
- **CRUD 業務邏輯**（`AnnouncementCrudTest`）：對應 `建立公告.feature`、`更新公告.feature`、`刪除公告.feature`
- **查詢過濾**（`AnnouncementQueryTest`）：對應 `查詢公告列表.feature` 全部 Examples（後台列表、前台公開列表、可見性過濾、排序）
- **REST 端點**（`AnnouncementApiTest`）：以 `WP_REST_Request` 模擬完整呼叫，含 401/403/404/400 錯誤路徑

每個測試類在 `set_up()` 建立獨立 `admin_id` / `enrolled_user_id` / `course_id`，避免測試間污染。`tear_down()` 用 `wp_delete_post()` 清理。

### E2E（Playwright）

- **管理端**：`announcement-crud.spec.ts`
  1. 登入管理員 → 進入 PHP 基礎課編輯頁
  2. 切到「課程公告」Tab → 點「新增公告」
  3. 填寫標題、Power Editor 內容、設定發佈起 / 結束時間、可見性
  4. 提交 → 驗證列表出現新公告 + 狀態標籤正確
  5. 編輯 → 修改後驗證
  6. 刪除（軟刪除）→ 驗證列表標記為已刪除
  7. 還原 → 驗證重新生效

- **前台**：`announcement-display.spec.ts`
  1. 未購者瀏覽銷售頁 → 確認看到 public 公告、看不到 enrolled 公告、不看過期公告
  2. 已購學員瀏覽 → 額外看到 enrolled 公告
  3. 無公告課程 → 確認 `#pc-announcement-section` 不存在
  4. 多則公告 → 確認最新展開、其餘折疊

## 風險評估與注意事項

### 高優先級
- **可見性過濾必須在 SQL 層**：不能 fetch 完所有 publish 公告再 PHP filter，否則大量公告時效能差。`list_public` 用 `meta_query` + `WP_Query` 一次性過濾。
- **`end_at` 的時區**：specs 規定使用站台時區，但 `end_at` 為 Unix timestamp（GMT-naive）。一律用 `current_time('timestamp')`（站台時區當下時間 in seconds）比較。
- **`post_status='future'` 的查詢隱式行為**：`WP_Query` 預設 `post_status='publish'` 只回 publish；查詢公告列表（後台）需顯式傳 `post_status=['publish','future','trash']`。
- **i18n msgid 一律英文**：所有新增 PHP/TSX 字串遵守 `.claude/rules/i18n.rule.md`，不能寫中文 msgid。

### 中優先級
- **Power Editor 整合**：章節編輯已用 Power Editor，公告應重用同一套元件。先確認 `js/src/components/post/` 或 `js/src/components/chapters/` 是否已有 `<PowerEditor>` 包裝元件，若有直接 import；若無則參照章節編輯頁建立同名元件。
- **不要建 announcements resource 在 `js/src/resources/index.tsx`**：公告是課程子資源，不應出現在主選單。`useList` 直接傳 `resource='announcements'` 即可。
- **Course delete 連動**：課程被 trash 時，其公告應一併 trash（避免孤兒）。在 `Announcement\Core\LifeCycle` 監聽 `wp_trash_post` 與 `before_delete_post`，檢查被刪 post 的 `_is_course=yes` 與否，是則查同 `parent_course_id` 的公告連動處理。

### 低優先級
- **首版不做 MCP**：不要新增 `inc/classes/Api/Mcp/Tools/Announcement/`，留待下個 Issue。
- **首版不做通知**：不要串郵件系統。
- **首版不做學習頁顯示**：嚴格限制在銷售頁。

## 驗收標準（對應 Issue 旅程）

- [ ] 旅程 1（管理員建立公告）：可在 `CourseAnnouncement` Tab 新增、設定發佈區間、儲存後狀態標籤正確
- [ ] 旅程 2（學員瀏覽公告）：銷售頁價格區下方出現公告區塊，多則排序正確（新到舊）、最新展開
- [ ] 旅程 3（編輯刪除）：可編輯內容、刪除有確認對話框、刪除後 trash
- [ ] 旅程 4（預約發佈）：`post_status=future` 公告不顯示於前台，到期 `wp_cron` 自動轉 publish
- [ ] 旅程 5（無公告）：銷售頁不出現公告區塊
- [ ] 旅程 6（外部課程）：external 課程的銷售頁也能正常顯示公告區塊

### 跨功能驗收
- [ ] `pnpm run lint:php` 全綠（PHPCS + PHPStan level 9）
- [ ] `pnpm run lint:ts` 全綠
- [ ] `pnpm run build` 成功
- [ ] `composer run test`（PHPUnit）含本次新增 4 個測試類全綠
- [ ] `pnpm run test:e2e:admin && pnpm run test:e2e:frontend` 全綠
- [ ] `pnpm run i18n:build` 後 `.po` / `.mo` / `.json` 含新字串繁中翻譯
- [ ] 取消註解 `js/src/pages/admin/Courses/Edit/index.tsx` line 182-187 並改用 `__('Announcements', 'power-course')`
- [ ] 取消註解 `inc/templates/pages/course-product/tabs/index.php` line 104-107（若公告 Tab 不再需要，直接刪除這 4 行）

## 與既有架構的對齊點

- **Resource 模式**：`Resources/Announcement/{Core,Service,Utils}/` 結構與 `Chapter/`、`ChapterProgress/`、`Student/` 完全一致
- **API 註冊**：`ApiBase` + `SingletonTrait`，namespace `power-course`，依 `endpoint+method` 自動推導 callback 名
- **CPT 註冊**：`show_ui=Plugin::$is_local`（正式環境隱藏 WP Admin UI），與章節同模式
- **Service-Centric 業務邏輯**：REST callback 與未來 MCP tool 共用 `Service\Crud` 與 `Service\Query`，避免重複（雖然首版不做 MCP，但保留擴充點）
- **前台模板**：`inc/templates/pages/course-product/announcement.php` + `Plugin::load_template`，與 chapters / qa / review 同
- **手風琴 UI**：使用既有 `pc-collapse pc-collapse-arrow` CSS 類別（見 `qa.php` line 38-50），純 CSS 不需 JS
- **i18n**：text domain 一律 `'power-course'`、msgid 英文、PHP escape 變體輸出 HTML、React 用 `@wordpress/i18n`

## 後續延伸（不在本 Issue 範圍）

1. MCP tools — 新增 `Resources/Announcement/Mcp/` 與 5 個 tools（list / get / create / update / delete）
2. 學習頁顯示公告（教室頁面整合）
3. 公告發佈時自動寄信通知學員
4. 公告數量上限與管理員警示
5. 公告排程結束前 N 小時站長提醒
