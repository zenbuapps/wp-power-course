# 實作計劃：課程權限包（Access Pass）— Issue #252

> 目標 codebase：`power-course`（wp-power-course），milestone v1.8
> 上游規格：AIBDD Phase 01-04 已完成（execution-plan.md / activities / features / api.yml / erm.dbml / clarify）
> 本文件涵蓋 **Phase 05-08**：Backend TDD → Frontend Build → Frontend E2E → Integration
> 範圍模式：**EXPANSION**（新功能），但 blast radius 觸及關鍵既有閘門 `is_avl()`，故以「分階段可獨立交付」方式控管，不縮減 spec 範圍。

---

## 概述

新增「課程權限包」上層概念：站長定義一次權限範圍（全站 / 分類標籤 / 特定課程）並命名，掛到一個或多個 WC 商品；用戶購買結帳後取得「持有關係」，可觀看範圍內所有課程。核心架構為 **compute-on-read**——觀看判定時即時計算，不把範圍內課程 id 寫進 `avl_course_ids`，讓動態範圍（全站 / 分類）能涵蓋日後新增課程。

**為什麼是 compute-on-read（第一性原理）**：現行權限模型是「購買時把課程 id materialize 進 user meta `avl_course_ids`（static list）」。動態範圍在購買當下無法預知日後新增的課程，因此 static list 根本無法表達。唯一能表達「全站 / 某分類（含日後新課）」的方式，是把「範圍」存為規則、在「讀取（觀看判定）」當下展開計算。

---

## 需求重述

- 站長後台 CRUD 課程權限包（命名、選範圍、選期限、停用、刪除）。
- 權限包以 1:1 掛到商品（product meta `access_pass_id`），與既有逐課綁定 `bind_courses_data` **並存且效果並集（OR）**。
- 訂單達開通條件 → 授予持有關係（一次性 / 訂閱皆可），寫入新表 `pc_user_access_pass`，**不展開** `avl_course_ids`。
- 觀看判定 = `avl_course_ids` 命中（逐課綁定） **OR** 任一有效權限包涵蓋此課程（compute-on-read）。
- 期限三模式沿用既有 `expire_date` 慣例：`permanent`（0/null）/ `limited`（timestamp）/ `follow_subscription`（`subscription_{id}`，即時查 `active`/`pending-cancel`）。
- 停用：不可掛新商品、已購保留；刪除：confirm 二次確認 + 收回持有關係，但**不誤砍**單獨購買 / 逐課綁定的權限。

成功的樣子：8 個 `.feature` 全綠（PHPUnit Integration），前端 AccessPasses 管理頁 + 商品 `access_pass_id` 選擇器可用，E2E 通過，`is_avl()` 的 11 個消費點行為一致。

---

## 已知風險（來自研究 / 程式碼驗證）

- **風險：`is_avl()` 是熱路徑**（11 個消費點，部分在課程清單迴圈內）— compute-on-read 每次增加「載入該 user 持有的 pass + 各 pass scope + 分類子展開」查詢，N+1 效能風險。
  **緩解**：在 `AccessPass\Service\Gate` 做 request 級 memoization（`wp_cache_*` 非持久群組 + 以 `user_id` 快取「持有的有效 pass 清單」、以 `pass_id` 快取「展開後的 term/course 範圍」）。判定順序「先比對 `avl_course_ids`（既有零成本）→ 命中即短路」可避免大多數情況觸發 pass 計算。
- **風險：閘門雙重否決（keystone 正確性風險）** — classroom 模板同時呼叫 `is_avl()` **AND** `NOT is_expired()`（single-pc_chapter.php:39-57）。若僅讓 `is_avl()` pass-aware，當某課程「個別購買已到期（per-course `expire_date` 過期）」但「有有效 pass 涵蓋」時，`is_expired()` 會讀到舊的過期 meta 回 true → 否決掉 pass 應給的觀看權，違反「OR 疊加」spec。
  **緩解**：`is_avl()` **與** `is_expired()` 都必須 pass-aware，共用同一個 `Gate::user_has_valid_pass_for_course()`。詳見「架構變更 §A keystone」。
- **風險：升級既有站台不會建表** — 既有 table 只在 `plugin.php::activate()` 建立；已啟用的站台升級到含本功能版本時，`activate()` 不會重跑。
  **緩解**：採 MCP 既有先例 `Api\Mcp\Migration::install()` 的版本閘門 migration（plugins_loaded 時比對 DB 版本號 → 缺表則建）。詳見 Phase 05 步驟 1。
- **風險：刪除 / 收回誤砍其他來源** — 刪除 pass 必須只刪 `pc_user_access_pass` 對應列，**絕不**碰 `avl_course_ids` 或 `pc_avl_coursemeta`。
  **緩解**：刪除走獨立 Repository 方法（只 `DELETE FROM pc_user_access_pass WHERE pass_id=`），有專屬測試（刪除分類包後單獨購買課程仍可觀看）。
- **風險：`follow_subscription` 即時性** — compute-on-read 天然即時（每次讀都查 `wcs_get_subscription()->has_status()`），不需 cancel hook；但若 `wcs_get_subscription` 回 null（訂閱被刪）須視為失效。
  **緩解**：沿用 `Utils/Course.php::is_expired()` L590 既有 null→失效邏輯。
- **風險：`product_cat` 子分類展開遺漏** — 父分類包必須涵蓋子分類課程（spec 明確），`product_tag` 為非階層不展開。
  **緩解**：category 範圍判定用 `get_term_children()` 展開 product_cat；課程的 term 命中「所選 term ∪ 其子孫」即涵蓋。

> 其餘未發現額外已知風險。WordPress / WooCommerce / WC Subscriptions 版本相容性沿用既有專案約束（PHP 8.1+、HPOS 用 `wc_get_order`、訂閱依賴 WC Subscriptions plugin）。

---

## 架構變更

### §A（keystone）觀看判定疊加層 — compute-on-read

**新增** `inc/classes/Resources/AccessPass/Service/Gate.php`（Singleton/靜態）：

- `Gate::user_has_valid_pass_for_course( int $user_id, int $course_id ): bool`
  = 該 user 持有的每個 pass 中，存在「scope 涵蓋 $course_id」**且**「expire 有效」者。
  - scope：`all` 恆真；`category` → 課程 term ∈（pass term_ids ∪ 子分類）；`specific` → `$course_id ∈ pass course_ids`。
  - expire：`permanent`→true；`limited`→`now < expire_timestamp`；`follow_subscription`→`wcs_get_subscription()->has_status(['active','pending-cancel'])`。
  - request 級 memoize。

**修改** `inc/classes/Utils/Course.php`：

- `is_avl()`（L531）：在既有 `in_array($product_id, $avl_course_ids)` 之後，OR 上 `Gate::user_has_valid_pass_for_course($user_id, $product_id)`。維持「未登入→false」「avl 命中即短路」。
- `is_expired()`（L571）：**新增前置短路** — 若 `Gate::user_has_valid_pass_for_course($user_id, $product_id)` 為 true，回 `false`（有有效 pass = 未到期），避免舊的 per-course `expire_date` meta 否決 pass 觀看權。pass 本身的到期已在 Gate 內判定，故不會「明明 pass 過期卻說沒過期」。

> 為什麼改這兩個就夠：`get_avl_status()`（L658）內部呼叫 `is_avl`→`is_course_ready`→`is_expired`，三者皆已被涵蓋；classroom 模板（single-pc_chapter.php）、Comment、Announcement `is_enrolled`、ChapterProgress API、Chapter LifeCycle、各前台模板**全部**透過這兩個函式（或其包裝 `ChapterUtils::is_avl`）判定 → 單點修改即一致。**不需**逐一改 11 個消費點。

> ⚠️ 實作者注意：`is_course_ready()`（課程開課排程 `course_schedule`）是課程層屬性，與 pass 無關，**不**改。pass holder 仍受「課程未開課」限制（與個別購買者一致）。

### §B 新 Domain：AccessPass（對照 Announcement / ChapterProgress 雙模式）

**新增資料夾** `inc/classes/Resources/AccessPass/`：

```
AccessPass/
├── Core/
│   ├── CPT.php          # 註冊 pc_access_pass（對照 Announcement/Core/CPT.php）
│   ├── Api.php          # extends ApiBase，namespace 'power-course'（對照 Announcement/Core/Api.php）
│   └── Loader.php       # CPT::instance() + Api::instance()
├── Model/
│   ├── AccessPass.php   # CPT-backed DTO：instance($id) 載 meta；to_array()（對照 Chapter Model + ChapterProgress from_row 混合）
│   └── UserAccessPass.php # pc_user_access_pass 列 DTO：from_row()（對照 ChapterProgress Model）
└── Service/
    ├── Crud.php         # create/update/disable/delete/attach 商業邏輯（對照 Announcement/Service/Crud.php）
    ├── Query.php        # list（含 attached_product_count / status 篩選）（對照 Announcement/Service/Query.php）
    ├── Repository.php   # pc_user_access_pass 自訂表 CRUD（對照 ChapterProgress/Service/Repository.php）
    ├── Grant.php        # 訂單開通：寫入持有關係（對照 AddStudent 去重 + LifeCycle 寫入）
    └── Gate.php         # §A 觀看判定（compute-on-read）
```

**修改** `inc/classes/Resources/Loader.php`（L8-24）：加 `AccessPass\Core\Loader::instance();`。

### §C 資料表

**修改** `plugin.php`：

- 加常數 `const USER_ACCESS_PASS_TABLE_NAME = 'pc_user_access_pass';`（L49-53 區塊）。
- `activate()`（L96-109）加 `AbstractTable::create_user_access_pass_table();`。

**修改** `inc/classes/AbstractTable.php`：加 `create_user_access_pass_table()`（對照 `create_chapter_progress_table` L146-176，含 `WP::is_table_exists` 冪等 + `dbDelta`）。

**新增** 版本閘門 migration（對照 `Api\Mcp\Migration`）：`AccessPass\Core\Loader` 內 `plugins_loaded` 時比對 option 版本 → 缺表則建。讓既有站台升級也能建表。

> CPT `pc_access_pass`（access_passes 表的儲存載體）的範圍 / 期限 / 狀態存 postmeta：`scope_type` / `limit_mode` / `limit_value` / `limit_unit` / `access_pass_status` / `scope_term_ids`（多列）/ `scope_course_ids`（多列）。`pc_user_access_pass` 為**自訂關聯表**（持有關係 + expire_date），非 EAV、非 postmeta。

### §D 訂單開通掛鉤

**修改** `inc/classes/Resources/Order.php`：

- `add_meta_to_avl_course()`（L207，一次性 `woocommerce_order_status_{trigger}`）：迴圈 items 時，讀商品 `access_pass_id` meta → 若有，呼叫 `Grant` 授予持有關係（與既有 `handle_single_course` / `handle_bind_courses` 並列，互不影響）。
- `add_course_item_meta_by_subscription()`（L64，訂閱首期 `woocommerce_subscription_payment_complete`）：訂閱商品掛 pass 時，授予 `follow_subscription` 持有關係（expire_date = `subscription_{id}`）。

> ⚠️ 訂閱的開通時機：spec `購買開通權限包.feature` 的訂閱 example 走「首期付款完成」。既有 `add_course_item_meta_by_subscription` 已處理「只在 parent order 觸發、續訂不觸發」。Grant 須掛在訂單**達 trigger 狀態**之後（一次性走 `add_meta_to_avl_course`；訂閱的 `payment_complete` 即代表已付款）。實作時對齊既有 `_handle_*` 流程，避免「processing 就誤授予」（見 `購買開通權限包.feature` 前置 example）。

### §E 商品掛載寫入路徑（兩條，皆寫同一個 `access_pass_id` meta）

1. **API `/access-passes/{id}/attach`**（AccessPasses 管理頁用）→ `Crud::attach($pass_id, $product_id)`：驗證 pass 存在且 active、product 存在 → `update_post_meta($product_id, 'access_pass_id', $pass_id)`。
2. **商品編輯頁**（前端 Products 表單）→ 既有商品更新路徑加 `access_pass_id` 欄位寫入（與 `bind_courses_data` 並存）。

### §F 前端（React 18 + Refine + Ant Design）

- **新增** `js/src/pages/admin/AccessPasses/`（List / Create / Edit）— 對照既有 admin 頁（Announcement 為課程內 tab，Products 為 table 模式；AccessPasses 採獨立 Refine resource list/edit，較接近 Courses）。
- **修改** `js/src/resources/index.tsx`：註冊 `access-passes` resource → `/access-passes`。
- **修改** 商品編輯表單（`js/src/components/product/` 或 Products 表單區）：加 `access_pass_id` 選擇器（Select，options 來自 `GET /access-passes?status=active`），與 `bind_courses_data` 區塊並存。
- 範圍動態警告 + 刪除影響提示（「將影響 N 位已購用戶」）由前端在送出前以 `attached_product_count` / 查詢結果呈現。

---

## 資料流分析

### 流程 1：訂單開通 → 授予持有關係（§D）

```
WC訂單達trigger狀態 ──▶ 迴圈items ──▶ 讀access_pass_id meta ──▶ 計算expire ──▶ 寫pc_user_access_pass ──▶ (不寫avl_course_ids)
        │                  │               │                      │                   │
        ▼                  ▼               ▼                      ▼                   ▼
   [order不存在?]      [item非product?]  [無meta?跳過]        [pass已disabled?]    [(user,pass)重複?]
   [customer=0?]      [product不存在?]  [pass不存在?跳過]     [follow_sub無訂閱?]  [DB寫入失敗?]
```

- nil：order/customer/product 任一缺 → 該 item 跳過（沿用既有 continue 模式）。
- empty：商品無 `access_pass_id` → 不授予（正常路徑，非錯誤）。
- 去重：同 (user_id, pass_id) 已存在 → 更新而非重複插入（對照 `AddStudent` 去重；Repository upsert）。
- error：DB 寫入失敗 → log（`wc_get_logger`）+ 不中斷其他 item（開通是 best-effort，與既有課程開通一致）。

### 流程 2：觀看判定 compute-on-read（§A keystone）

```
is_avl(course,user) ──▶ user未登入? ──▶ avl_course_ids命中? ──▶ Gate.has_valid_pass? ──▶ true/false
        │                   │                │                      │
        ▼                   ▼                ▼                      ▼
   [user=0→false]      [短路:命中即true]  [載入user持有pass]     [逐pass: scope涵蓋? AND expire有效?]
                                          [memoize]              [category→展開子分類]
                                                                 [follow_sub→查訂閱狀態]
                                                                 [limited→比對timestamp]
```

- nil path：未登入 → false（spec「未登入一律不可觀看」）。
- empty path：user 無任何 pass 且 avl 未命中 → false（導銷售頁）。
- 短路：avl_course_ids 命中 → 直接 true，不觸發 pass 計算（效能）。
- 動態：`all`/`category` 不依賴購買時的快照，故日後新課自動涵蓋。
- partial/stale：pass 列存在但 CPT 已被刪 → Gate 載入時 `get_post` 回 null → 視為無效（跳過該列）。

### 流程 3：刪除權限包（§B Crud::delete）

```
DELETE /access-passes ──▶ confirm=true? ──▶ pass存在? ──▶ 算affected_user_count ──▶ DELETE pc_user_access_pass列 ──▶ wp_delete_post(CPT)
        │                    │                │              │                          │
        ▼                    ▼                ▼              ▼                          ▼
   [ids空?→400]         [confirm≠true→400] [不存在→? ]    [COUNT DISTINCT user_id]   [只刪pass_id列,不碰avl_course_ids]
```

- 關鍵不變式：**絕不** `delete_user_meta(avl_course_ids)`、**絕不** 動 `pc_avl_coursemeta`。OR 疊加保證單獨購買 / 逐課綁定不受影響。

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| `POST /access-passes` | name 空 / scope_type 非法 / category 無 term_ids / specific 無 course_ids / limited 無 limit_value | 400 rest_invalid_param | Crud::create 拋 RuntimeException → WP_Error 400 | 是（表單錯誤） |
| `PUT /access-passes/{id}` | pass 不存在 | 404 | WP_Error 404 | 是 |
| `PUT /access-passes/{id}` | name 提供但為空字串 | 400 | WP_Error 400 | 是 |
| `POST /access-passes/{id}/disable` | pass 不存在 | 404 | WP_Error 404 | 是 |
| `POST /access-passes/{id}/attach` | pass 不存在 / product 不存在 | 404 | WP_Error 404 | 是 |
| `POST /access-passes/{id}/attach` | pass 為 disabled | 403/400 | WP_Error（停用不可掛新商品） | 是 |
| `DELETE /access-passes` | ids 空 | 400 | WP_Error 400 | 是 |
| `DELETE /access-passes` | confirm≠true | 400 | WP_Error 400（二次確認） | 是 |
| `DELETE /access-passes` | pass 不存在 | 403/404 | WP_Error | 是 |
| `Grant`（訂單開通） | DB 寫入失敗 | 例外 | `wc_get_logger()->error` + 不中斷其他 item | 否（log） |
| `Grant`（follow_subscription） | 訂單無對應訂閱 | — | expire_date fallback 0 或跳過（對齊 Limit::calc_expire_date L73-99） | 否 |
| `Gate`（觀看判定） | pass CPT 已刪但持有列殘留 | — | `get_post` null → 跳過該 pass（視為無效） | 否（靜默正確降級） |
| `Gate`（follow_subscription） | `wcs_get_subscription` 回 null | — | 視為失效（回 false，對齊 is_expired L590） | 否 |
| 建表 migration | dbDelta 失敗 | 例外 | 對照 AbstractTable try/catch 拋 Exception | 否（log/啟用時） |

> 無「處理方式=無 + 靜默」的 CRITICAL GAP。所有靜默路徑皆為「正確降級」（缺 meta = 不授予；pass 失效 = 不可看），非未處理錯誤。

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| `is_avl()` + Gate | pass 計算拋例外 → 整頁掛 | 需 try/catch 包 Gate，例外時回 false | 是（Gate 單元 + is_avl 整合） | 否 | fallback 不授予，導銷售頁 |
| `is_expired()` + Gate | pass 短路誤判「未到期」 | Gate 內已含 expire 判定，pass 過期則 has_valid=false | 是（limited 已到期 example） | 是（顯示已失效） | — |
| 訂單開通 Grant | processing 狀態誤授予 | 掛 trigger 狀態 hook，非 new_order | 是（processing 尚未授予 example） | 否 | — |
| 訂單開通 Grant | 訂閱續訂重複授予 | 沿用既有「只 parent order 觸發」 | 是（訂閱首期 example） | 否 | upsert 去重 |
| 刪除 pass | 誤砍 avl_course_ids | 只 DELETE pass_id 列 | 是（刪除後單獨購買仍可觀看 example） | 是 | — |
| category scope | 子分類未涵蓋 | `get_term_children` 展開 | 是（父分類包看子分類課 example） | 是 | — |
| specific scope | 誤涵蓋日後新課 | 固定 course_ids，不展開 | 是（specific 不涵蓋新課 example） | 是 | — |
| 熱路徑 is_avl | N+1 拖慢列表頁 | memoize + avl 短路 | 效能非功能測試（人工驗證） | 否 | — |
| 升級既有站台 | 新表未建 | 版本閘門 migration | 是（migration 測試，對照 MigrationTest） | 否 | 補建表 |

---

## 實作步驟

> TDD 紀律：每一步先寫 failing test（Red）→ 最小實作通過（Green）→ 重構（Refactor）。測試對應到具體 `.feature`。
> 測試框架：PHPUnit 9 Integration（`tests/Integration/AccessPass/`），執行 `npm run test:phpunit`（wp-env）。
> 測試基底：`tests/Integration/TestCase.php`（已有 `add_user_meta avl_course_ids`、建課程 helper）。

### 第一階段（Phase 05A）：資料層地基 — CPT + 表 + Model + Repository

1. **建立 `pc_user_access_pass` 表 + 常數 + migration**（檔案：`plugin.php`、`inc/classes/AbstractTable.php`、`inc/classes/Resources/AccessPass/Core/Loader.php`）
   - Red：`AccessPass/MigrationTest`（對照 `tests/Integration/Mcp/MigrationTest.php`）斷言 `WP::is_table_exists($prefix.'pc_user_access_pass')`。
   - 行動：加常數、`create_user_access_pass_table()`（dbDelta + 冪等）、版本閘門 migration。
   - 依賴：無。風險：中（DB schema 一次到位，欄位對齊 erm.dbml `pc_user_access_pass`）。

2. **註冊 CPT `pc_access_pass`**（檔案：`inc/classes/Resources/AccessPass/Core/CPT.php`、`Loader.php`、`inc/classes/Resources/Loader.php`）
   - Red：`AccessPass/CPTStructureTest`（對照 `Announcement/AnnouncementCPTStructureTest.php`）斷言 `post_type_exists('pc_access_pass')` 且 `public=false`、`show_in_rest=true`。
   - 行動：複製 Announcement CPT 結構，POST_TYPE 改 `pc_access_pass`、menu_icon 改合適 dashicon、supports `['title','custom-fields','author']`。
   - 依賴：無。風險：低。

3. **AccessPass / UserAccessPass Model + Repository**（檔案：`Model/AccessPass.php`、`Model/UserAccessPass.php`、`Service/Repository.php`）
   - Red：`AccessPass/RepositoryTest`（對照 `ChapterProgress/ChapterProgressRepositoryTest.php`）— insert/find/find_by_user/delete_by_pass/count_users_by_pass。
   - 行動：
     - `AccessPass::instance($id)` 載 CPT + postmeta（scope_type/limit_mode/limit_value/limit_unit/status/term_ids/course_ids）；`to_array()` 輸出含 `attached_product_count`（由 Query 注入）。
     - `UserAccessPass::from_row()`（id/user_id/pass_id/source_order_id/expire_date/granted_at）。
     - Repository：`insert_or_update(user_id,pass_id,source_order_id,expire_date)`（去重 upsert）/ `find_by_user(user_id)` / `delete_by_pass(pass_id)` / `count_distinct_users_by_pass(pass_id)`。全程 `$wpdb->prepare`。
   - 依賴：步驟 1、2。風險：中。

### 第二階段（Phase 05B）：CRUD API — 對應 5 個 command/query feature

4. **Crud::create + API `POST /access-passes`**（檔案：`Service/Crud.php`、`Core/Api.php`）→ `features/access-pass/建立課程權限包.feature`
   - Red：`AccessPass/CreateAccessPassTest`，逐 Rule 對映：name 空、scope 非法、limit_mode 非法、category 無 term_ids、specific 無 course_ids、limited 無 limit_value / =0、成功建 all/category/specific/limited。
   - 行動：`Api` extends ApiBase，`$namespace='power-course'`，`$apis` 註冊 `access-passes` get/post/delete + `access-passes/(?P<id>\d+)` put + `.../disable` post + `.../attach` post（permission_callback `manage_options`）。`post_access_passes_callback` → `Crud::create`，驗證失敗拋 RuntimeException → WP_Error 400。term_ids / course_ids 寫多列 postmeta（對照 teacher_ids delete-then-add 模式）。
   - 依賴：步驟 3。風險：中（參數驗證面大，逐 example 覆蓋）。

5. **Crud::update + API `PUT /access-passes/{id}`**（檔案：`Service/Crud.php`、`Core/Api.php`）→ `features/access-pass/更新課程權限包.feature`
   - Red：`AccessPass/UpdateAccessPassTest`：不存在→失敗、name 空→失敗、改名、改 scope_type+內容、縮小分類（移除一個 term）、改期限為 limited。
   - 行動：`put_access_passes_with_id_callback` → `Crud::update`，partial update（未帶欄位保持原狀；對照 Course update 語義）。改 scope 時重寫對應多列 meta。
   - 依賴：步驟 4。風險：中。

6. **Crud::disable + API `POST /access-passes/{id}/disable`**（檔案：`Service/Crud.php`、`Core/Api.php`）→ `features/access-pass/停用課程權限包.feature`
   - Red：`AccessPass/DisableAccessPassTest`：不存在→失敗、停用後 status=disabled、停用後已購學員仍可觀看、停用後不可掛新商品。
   - 行動：`post_access_passes_with_id_disable_callback` → 設 `access_pass_status=disabled`。
   - 依賴：步驟 4；「停用後仍可觀看」需 §A Gate（步驟 10）就位後才全綠 → 該 example 可標記依賴步驟 10（或先寫斷言、Gate 完成後轉綠）。風險：中。

7. **Crud::attach + API `POST /access-passes/{id}/attach`**（檔案：`Service/Crud.php`、`Core/Api.php`）→ `features/access-pass/掛載權限包到商品.feature`
   - Red：`AccessPass/AttachAccessPassTest`：product 不存在→失敗、pass 不存在→失敗、pass disabled→失敗、掛一次性商品、掛訂閱商品、重新掛載覆蓋、與 bind_courses_data 並存。
   - 行動：`post_access_passes_with_id_attach_callback` → 驗證 active + product 存在 → `update_post_meta($product_id,'access_pass_id',$pass_id)`。
   - 依賴：步驟 4。風險：低。

8. **Crud::delete + API `DELETE /access-passes`**（檔案：`Service/Crud.php`、`Core/Api.php`、`Service/Repository.php`）→ `features/access-pass/刪除課程權限包.feature`
   - Red：`AccessPass/DeleteAccessPassTest`：不存在→失敗、confirm=false→失敗、confirm=true 成功刪除、刪除後已購學員失去觀看權、**刪除後單獨購買的課仍可觀看**（不誤砍）。
   - 行動：`delete_access_passes_callback` → 驗 confirm → `count_distinct_users_by_pass`（回 affected_user_count）→ `Repository::delete_by_pass`（只刪 pass_id 列）→ `wp_delete_post`。**絕不**碰 avl_course_ids。
   - 依賴：步驟 3；「失去/仍可觀看」需 §A Gate（步驟 10）。風險：高（不變式正確性 — 不誤砍其他來源）。

9. **Query::list + API `GET /access-passes`**（檔案：`Service/Query.php`、`Core/Api.php`）→ `features/access-pass/查詢課程權限包.feature`
   - Red：`AccessPass/QueryAccessPassTest`：列表含 status/scope_type、含 attached_product_count、status 篩選排除 disabled。
   - 行動：`get_access_passes_callback` → `Query::list`（WP_Query post_type=pc_access_pass + meta status 篩選；attached_product_count = 反查有多少 product 的 `access_pass_id` meta = 此 id）。
   - 依賴：步驟 3、7。風險：低。

### 第三階段（Phase 05C）：keystone 觀看判定 + 訂單開通

10. **Gate（compute-on-read）+ 接入 `is_avl` / `is_expired`**（檔案：`Service/Gate.php`、`inc/classes/Utils/Course.php`）→ `features/access-pass/權限包觀看判定.feature`
    - Red：`AccessPass/AccessPassGateTest`，逐 Rule：未登入→不可；全站永久→任意課可看；全站包→購買後新課可看（動態）；分類包→範圍內可看 / 範圍外不可；父分類→子分類課可看；specific→清單內可看 / 日後新課不可；follow_subscription Scenario Outline（active/pending-cancel 可看，on-hold/cancelled/expired 不可）；limited 未到期可看 / 已到期不可；OR 疊加（逐課綁定命中即可看、訂閱包失效但單獨購買仍可看、兩來源皆涵蓋）。
    - 行動：實作 `Gate::user_has_valid_pass_for_course`（scope 三模式 + expire 三模式 + 子分類展開 + memoize）；`is_avl` OR 上 Gate；`is_expired` pass 有效則短路 false；Gate 計算以 try/catch 包，例外→false。
    - 依賴：步驟 3。風險：**高**（本功能技術核心；同時是 11 個消費點的一致性樞紐）。
    - 回歸保護：跑既有 `Course/CourseAvailabilityTest.php`、`ChapterProgress/ChapterProgressApiTest.php`，確認無 pass 時行為不變。

11. **Grant（訂單開通授予持有關係）+ 接入 Order**（檔案：`Service/Grant.php`、`inc/classes/Resources/Order.php`）→ `features/access-pass/購買開通權限包.feature`
    - Red：`AccessPass/PurchaseGrantAccessPassTest`：processing 尚未授予；completed 後永久包無到期；completed 後 limited 依 limit_value 計到期（購買後 N 天）；訂閱首期完成綁訂閱（`subscription_{id}`）；**不寫 avl_course_ids**（斷言 avl_course_ids 不含該課程 id）。
    - 行動：`Grant::grant($user_id,$pass_id,$order)` 計 expire（permanent→0；limited→`strtotime("+{value} {unit}")` 對齊 Limit::calc_expire_date 慣例；follow_subscription→`subscription_{id}`）→ `Repository::insert_or_update`（去重）。`Order::add_meta_to_avl_course` 迴圈讀 `access_pass_id`；訂閱走 `add_course_item_meta_by_subscription`。
    - 依賴：步驟 3。風險：高（與既有開通鏈並列、訂閱時機）。

### 第四階段（Phase 06）：Frontend Build（React）

12. **AccessPasses 管理頁 + resource 註冊**（檔案：`js/src/pages/admin/AccessPasses/{List,Create,Edit}`、`js/src/resources/index.tsx`）
    - 行動：Refine resource `access-passes`；List（含 status / scope_type / attached_product_count 欄、刪除前「影響 N 位」確認 Modal、停用按鈕）；Create/Edit 表單（name + scope_type 動態切換 term/course 選擇 + limit_mode 動態切換 limit_value/unit）。範圍變更顯示動態警告。
    - 依賴：Phase 05B/05C API。風險：中。

13. **商品編輯頁 access_pass_id 選擇器**（檔案：`js/src/components/product/`（新增 selector）+ Products 表單區）
    - 行動：Select（options = `GET /access-passes?status=active`），與 `bind_courses_data` 區塊並存；寫入既有商品更新路徑。
    - 依賴：步驟 12（共用 access-passes 查詢）。風險：中（須確認既有商品更新 payload 接受 `access_pass_id`；若既有 Product update API 不放行此 meta，需在後端 product update 白名單放行 — 列為子任務）。

### 第五階段（Phase 07）：Frontend E2E（Playwright）

14. **E2E 旅程**（檔案：`tests/e2e/`，admin + frontend + integration projects）
    - 行動：admin — 建權限包 → 掛到商品 → 列表顯示；frontend/integration — 模擬購買後可觀看範圍內課程、範圍外導銷售頁、停用後已購仍可看、刪除後失去觀看權。執行 `npm run test:e2e`。
    - 依賴：Phase 06。風險：中（環境 + 訂單模擬）。

### 第六階段（Phase 08）：Integration 收尾

15. **回歸 + 靜態分析 + 文件**（檔案：全域）
    - 行動：`npm run test:phpunit`（全綠）、`npm run lint:php`（phpcs + phpstan level 9）、`npm run test:e2e`；更新 `.claude/CLAUDE.md`（新 CPT / REST endpoint / hook 登記）；在 Extensibility Hooks 登記任何新 action（如 `power_course_after_grant_access_pass` 若引入）。
    - 依賴：全部。風險：低。

---

## 測試策略

- **單元 / 整合（後端，主力）**：`tests/Integration/AccessPass/*Test.php`，1 feature ↔ 1 test class，1 Rule/Example ↔ 1 test method。涵蓋 8 feature 全部 Rule。
  - 執行：`npm run test:phpunit`（wp-env，`composer run test` = phpunit --testdox）。
- **回歸**：既有 `Course/CourseAvailabilityTest.php`、`ChapterProgress/ChapterProgressApiTest.php`、`Order/OrderAutoGrantCourseTest.php`、`Course/SubscriptionIntegrationTest.php` 必須維持綠（確認 §A/§D 修改未破壞既有逐課綁定 / 訂閱開通）。
- **E2E（Playwright）**：`tests/e2e/`，admin/frontend/integration 三 project（`npm run test:e2e`）。
- **靜態**：`npm run lint:php`（phpcbf + phpcs WPCS + phpstan level 9 over `inc`）。前端 `npm run lint:ts`。
- **關鍵邊界情況（必須覆蓋）**：
  1. compute-on-read 動態範圍 — 購買後新增的課程自動可看（all / category），specific 不擴張。
  2. 子分類展開 — 父分類包涵蓋子分類課程；product_tag 不展開。
  3. 多來源 OR — 訂閱包失效但單獨購買課仍可看（**不誤砍**）；兩來源皆涵蓋同課。
  4. 期限三模式 — follow_subscription 五種訂閱狀態、limited 未到期/已到期、permanent 恆有效。
  5. 停用 vs 刪除 — 停用保留已購；刪除收回但不動 avl_course_ids。
  6. 訂單時機 — processing 不授予、completed 授予、訂閱首期授予、續訂不重複、不寫 avl_course_ids。
  7. 升級既有站台 — migration 補建表。
  8. keystone 雙閘門 — 個別購買已到期 + 有效 pass 時仍可看（`is_expired` pass-aware 短路）。

---

## 依賴項目

- `powerhouse` 外掛提供 `J7\WpUtils\Classes\ApiBase` / `DTO` / `WP` / `SingletonTrait`。
- WooCommerce（`wc_get_product` / `wc_get_order` / HPOS）。
- WooCommerce Subscriptions（`wcs_get_subscription` / `wcs_get_subscriptions_for_order` / `woocommerce_subscription_payment_complete`）。
- WordPress：`register_post_type` / `dbDelta` / `get_term_children`（product_cat 子分類展開）/ `wp_cache_*`（memoize）。
- wp-env（PHPUnit 執行環境）、Playwright（E2E）。

---

## 風險與緩解措施

- **高**：keystone 觀看判定（§A）— `is_avl` + `is_expired` 雙閘門 pass-aware 的正確性，影響 11 個消費點。緩解：單一 `Gate` 樞紐 + 既有回歸測試護欄 + 逐 example 覆蓋 + Gate 例外 try/catch 降級。
- **高**：刪除不誤砍其他來源（§B Crud::delete）。緩解：刪除只 `DELETE FROM pc_user_access_pass`，專屬「刪除後單獨購買仍可觀看」測試。
- **高**：訂單開通時機（§D）— 訂閱首期 / 續訂不重複 / processing 不誤授予。緩解：沿用既有 `add_meta_to_avl_course` + `add_course_item_meta_by_subscription` 既驗證過的時機，Grant 並列不改既有流程。
- **中**：熱路徑效能（compute-on-read N+1）。緩解：avl 命中短路 + request 級 memoize。
- **中**：升級既有站台建表。緩解：版本閘門 migration（對照 MCP Migration 先例）。
- **中**：前端商品更新 payload 放行 `access_pass_id`。緩解：確認既有 Product update 白名單；必要時後端放行此 meta（子任務）。
- **低**：CPT / CRUD 樣板（對照 Announcement 既有模式，風險可控）。

---

## 錯誤處理策略

沿用既有專案約定：

- API 層：驗證失敗 → `Crud::*` 拋 `\RuntimeException` → callback 轉 `WP_Error`（400 參數 / 403 狀態 / 404 不存在），對齊 api.yml 錯誤碼規則與 Announcement Api 既有寫法。
- 開通 / 背景：best-effort，`wc_get_logger()->error` 記錄、不中斷其他 item（與既有課程開通一致）。
- 觀看判定：靜默正確降級（缺資料 / pass 失效 → 不授予觀看，導銷售頁）；Gate 計算以 try/catch 包，例外 → false（fail-closed，不開放未授權觀看）。
- DB 寫入（持有關係）：Repository upsert 去重；建表 dbDelta 冪等（`WP::is_table_exists` 前置）。

---

## 限制條件（本計劃不做）

- **不** materialize 範圍內課程 id 到 `avl_course_ids`（compute-on-read 的根本前提）。
- **不**改 `is_course_ready()`（課程開課排程與 pass 無關；pass holder 仍受未開課限制）。
- **不**逐一修改 11 個 `is_avl` 消費點（單點修改 `is_avl`/`is_expired` 即涵蓋）。
- **不**支援 1 商品掛多包（spec 鎖定 1:1）。
- **不**支援分類 AND 邏輯（spec 鎖定多 term 聯集 OR）。
- **不**新增「範圍外推薦銷售方案」設定（spec：導去該課自己的銷售頁，用現成）。
- **不**為 follow_subscription 加訂閱取消 hook（compute-on-read 即時查詢即可；無需事件同步）。
- **不**改既有 `bind_courses_data` / 逐課綁定行為（並存、互不影響）。
- **不**處理 pass 之間的優先序 / 互斥（OR 疊加，任一允許即可看，無優先序概念）。

---

## 成功標準

- [ ] 8 個 `features/access-pass/*.feature` 全部 Rule/Example 由 `tests/Integration/AccessPass/` 覆蓋且綠。
- [ ] 既有回歸測試（CourseAvailability / ChapterProgressApi / OrderAutoGrantCourse / SubscriptionIntegration）維持綠。
- [ ] compute-on-read：購買全站/分類包後，日後新增且落範圍的課程自動可觀看（無需補開通）；specific 不擴張。
- [ ] 多來源 OR：訂閱包失效不影響單獨購買 / 逐課綁定的課；刪除 pass 不誤砍 avl_course_ids。
- [ ] 期限三模式正確（permanent / limited 到期 / follow_subscription active+pending-cancel）。
- [ ] 停用保留已購、刪除收回（confirm 二次確認 + affected_user_count）。
- [ ] 訂單開通：一次性 / 訂閱皆授予持有關係，不寫 avl_course_ids；processing 不授予、續訂不重複。
- [ ] 前端 AccessPasses 管理頁 CRUD + 商品 access_pass_id 選擇器可用；E2E 綠。
- [ ] `npm run lint:php`（phpcs + phpstan level 9）通過；CLAUDE.md 更新（CPT / endpoint / hook 登記）。

---

## 預估複雜度：高

理由：技術核心（compute-on-read 觀看判定）觸及關鍵熱路徑與 11 個消費點；橫跨資料層（新表 + CPT）、API（6 endpoint）、訂單整合（一次性 + 訂閱）、前端（管理頁 + 商品選擇器）、E2E。影響檔案約 20-25 個（新 domain ~9 + 表/常數/migration 3 + Order/Course 修改 2 + 前端 ~6 + 測試 ~10）。已按「資料層 → CRUD API → keystone 判定/開通 → 前端 → E2E → 收尾」分 6 階段，各階段可獨立驗證與交付。

---

## ASM（工程假設，非 spec 決策；如有疑義 tdd-coordinator 可回報）

- **ASM-G1**：`is_avl()` 與 `is_expired()` 皆 pass-aware、共用 `Gate::user_has_valid_pass_for_course()`。理由：classroom 模板雙閘門組合，僅改其一會在「個別購買到期 + pass 有效」時誤否決，違反 OR-at-viewing spec。
- **ASM-G2**：判定順序「未登入→false；avl_course_ids 命中→短路 true；否則算 pass」。理由：效能 + 與既有零成本路徑相容。
- **ASM-G3**：Gate 計算以 try/catch 包，例外 → false（fail-closed）。理由：判定在熱路徑，不可因 pass 資料異常導致整頁 500，且安全上應拒絕而非放行。
- **ASM-C1**：升級既有站台採版本閘門 migration（對照 `Api\Mcp\Migration`）於 `plugins_loaded` 補建表。理由：`activate()` 不會在既有站台升級時重跑。
- **ASM-D1**：Grant 與既有 `handle_single_course` / `handle_bind_courses` 並列掛在相同 trigger 時機，不改既有流程。理由：最小變更 + 沿用已驗證的訂閱/續訂時機判斷。
- **ASM-R1**：`pc_user_access_pass` 同 (user_id, pass_id) 採 upsert 去重（後到覆蓋 expire/source_order）。理由：對照 `AddStudent` 去重語義，避免重複開通造成重複列。
- **ASM-F1**：AccessPasses 前端採獨立 Refine resource（list/edit），非 Products 的 table 模式。理由：權限包是可命名列管的獨立資產，CRUD 形態接近 Courses。
- **ASM-F2**：商品 `access_pass_id` 寫入沿用既有商品更新路徑；若既有 Product update API 未放行此 meta，於後端白名單放行（列為步驟 13 子任務）。
