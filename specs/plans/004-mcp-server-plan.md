# Power Course MCP Server — 實作計畫

> 範圍模式：**EXPANSION**（全新功能模組，跨 9 個領域 + 1 個前端）
> 對應 issue：#192
> 套件選型：`wordpress/mcp-adapter` v0.5.0 + `wordpress/abilities-api`
> REST namespace：沿用 `power-course/v2`，新增 `power-course/v2/mcp/*` 子前綴

---

## 0. 用戶決策紀錄（2026-04-15）

| # | 決策項 | 敲板結果 |
|---|--------|---------|
| Q1 | Token 粒度 | ✅ 支援 categories 子集，`wp_pc_mcp_tokens` 新增 `capabilities` JSON 欄位 |
| Q2 | ActivityLog 保留期 | ✅ 30 天 + `wp_cron` 每日清理 |
| Q3 | Server 架構 | ✅ 只做 `power-course-mcp`，**Email 領域（6 tools）本期 OUT OF SCOPE** |
| Q4 | 現有 REST 權限 | ✅ 另開 issue 處理，本 PR 不碰 |
| Q5 | 執行模式 | ✅ 混合 TDD：Phase 0/1 走 tdd-coordinator，Phase 2 領域 tools 直接分派 wordpress-master × 3 並行 |

**範圍調整**：原 10 領域 × 45 tools → **9 領域 × 41 tools**（扣除 Email 6 tools）

---

## ✅ 實作完成紀錄（2026-04-16）

| Phase | Commit(s) | 內容 |
|---|---|---|
| Phase 0+1 基建 | `7734bda` | AbstractTool/Auth/Settings/ActivityLogger/Server/Migration + 44 tests |
| Wave 1 | `0fa9a90` / `0d45e22` / `29fa800` / `f2027ef` | Course(6)+Chapter(7)+Comment(3) = 16 tools |
| Wave 2 | `c07ff0c` / `b476a7e` / `5eafc99` | Student(9)+Bundle(4)+Teacher(4) = 17 tools |
| Wave 3 | `59ce6dd` / `bb0531a` / `2514bf1` / `1d049cb` | Order(3)+Progress(3)+Report(2) = 8 tools |
| Phase 1.5 REST | `d6f8c11` | 6 REST endpoints (settings/tokens/activity) |
| Wave 4 前端 | `dcff440` / `9d9957f` | MCP Settings Tab + toolCount 修正 |
| **總計** | **15 commits** | **41 tools / 6 REST endpoints / 前端 Settings Tab** |

> ⚠️ **後續調整（2026-05）**：Settings > MCP tab 下架，後端 tokens / activity 4 條管理 endpoint 一併移除，
> RestController 目前只剩 settings 兩條（GET/POST），由 AI tab 共用 useMcpSettings() hook 讀寫
> allow_update / allow_delete。對外 mcp-adapter server endpoint（`power-course/v2/mcp`）、abilities
> 註冊、41 個 tools、`wp_pc_mcp_tokens` / `wp_pc_mcp_activity` DB tables 與 ActivityLogger 寫入功能皆未動。

---

## 1. 現況掃描結果

### 1.1 Service 層復用對照表（45 tools × 10 領域）

> ⚠️ 標記說明：
> - ✅ 已存在，可直接呼叫
> - ⚠️ 部分存在，需新增方法或 thin wrapper
> - ❌ 不存在，需在實作 tool 之前先新增 Service method（標出但不腦補簽名）

#### 1.1.1 Course 領域（6 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `course_list` | `J7\PowerCourse\Api\Course::get_courses_callback` + `format_course_records` | ✅ 已實作（建議抽出為 `Service\Query::list()`） |
| `course_get` | `J7\PowerCourse\Api\Course::get_courses_with_id_callback` + `format_course_base_records` | ✅ |
| `course_create` | `J7\PowerCourse\Api\Course::post_courses_callback` + `handle_save_course_data` | ✅ |
| `course_update` | `J7\PowerCourse\Api\Course::post_courses_with_id_callback` | ✅ |
| `course_delete` | `J7\PowerCourse\Api\Course::delete_courses_with_id_callback` / `delete_courses_callback` | ✅ |
| `course_duplicate` | `J7\PowerCourse\Utils\Duplicate` | ⚠️ 需確認 public method 是否可在 controller 外呼叫 |

> 復用策略：MCP tool **不直接呼叫 callback**（callback 簽名為 `WP_REST_Request`），而是在 `AbstractTool::execute()` 內組裝 `WP_REST_Request` 物件後呼叫 callback。或更乾淨的做法是把 callback 內的業務邏輯抽出為 `Resources\Course\Service\Query / Crud` static method，由 callback 與 MCP tool 共用。**建議走第二條路**，保持 SRP。

#### 1.1.2 Chapter 領域（7 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `chapter_list` | `Resources\Chapter\Core\Api::get_chapters_callback` | ✅ |
| `chapter_get` | （callback 中無單獨 get）| ❌ 需新增 `Service::get( int $id )` |
| `chapter_create` | `Resources\Chapter\Core\Api::post_chapters_callback` | ✅ |
| `chapter_update` | `Resources\Chapter\Core\Api::post_chapters_with_id_callback` | ✅ |
| `chapter_delete` | `Resources\Chapter\Core\Api::delete_chapters_with_id_callback` / `delete_chapters_callback` | ✅ |
| `chapter_sort` | `Resources\Chapter\Core\Api::post_chapters_sort_callback` | ✅ |
| `chapter_toggle_finish` | `Resources\Chapter\Core\Api::post_toggle_finish_chapters_with_id_callback` | ✅ |

> 字幕相關 tools（如要納入）：`Resources\Chapter\Service\Subtitle::upload_subtitle / delete_subtitle / get_subtitles` 已是乾淨可直接呼叫的 service。

#### 1.1.3 Student 領域（9 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `student_list` | `Resources\Student\Service\Query::__construct + get_users` | ✅ |
| `student_get` | （無）| ❌ 需新增 `Service::get( int $user_id )`（封裝 `get_user_by` + course relations） |
| `student_export_csv` | `Resources\Student\Service\ExportCSV` / `ExportAllCSV` | ✅ |
| `student_export_count` | `Resources\Student\Core\Api::get_students_export_count_callback` | ✅ |
| `student_add_to_course` | `Resources\Course\Service\AddStudent::add_item` | ✅ |
| `student_remove_from_course` | （無，目前在 `Api\Course` 內 inline） | ❌ 需新增 `Service::remove_student( int $course_id, int $user_id )` |
| `student_get_progress` | 需 join `wp_pc_avl_courses` + `StudentLog` | ❌ 需新增 `Service\Progress::get_progress( int $course_id, int $user_id )` |
| `student_update_meta` | `update_user_meta` 包裝 | ⚠️ 需明確列出 allowed meta keys whitelist |
| `student_get_log` | `Resources\StudentLog\CRUD::get_list` | ✅ |

#### 1.1.4 Teacher 領域（4 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `teacher_list` | `Resources\Teacher\Core\ExtendQuery` 提供查詢 | ⚠️ 目前只有 ExtendQuery hook，需新增 `Service::list()` thin wrapper |
| `teacher_get` | （無） | ❌ 需新增 `Service::get( int $user_id )` |
| `teacher_assign_to_course` | （無，目前在 `Api\Course\UserTrait`） | ❌ 需新增 `Service::assign( int $course_id, int $user_id )` |
| `teacher_remove_from_course` | 同上 | ❌ 需新增 `Service::remove( int $course_id, int $user_id )` |

> ⚠️ Teacher 領域 Service 層幾乎是空的，本批次需先建立 `inc/classes/Resources/Teacher/Service/` 目錄。

#### 1.1.5 Bundle 領域（4 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `bundle_list` | （透過 WC_Product_Query 過濾 bundle_type） | ❌ 需新增 `BundleProduct\Service\Query::list()` |
| `bundle_get` | `BundleProduct\Helper::instance` + `get_bundle_products` + `get_product_quantities` | ✅ |
| `bundle_set_products` | `BundleProduct\Helper::set_bundled_ids` + `set_product_quantities` | ✅ |
| `bundle_delete_products` | `BundleProduct\Helper::delete_bundled_ids` | ✅ |

#### 1.1.6 Order 領域（3 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `order_list` | （需 wrapper `wc_get_orders` + 課程過濾） | ❌ 需新增 `Resources\Order\Service\Query::list()`（HPOS 相容） |
| `order_get` | `wc_get_order` + `Resources\Order::add_meta_to_avl_course` 等讀取邏輯 | ⚠️ 需新增 read-only `Service::get_with_courses( $order_id )` |
| `order_grant_courses` | `Resources\Order::handle_bind_courses` | ✅ |

> ⚠️ HPOS 相容必走 `wc_get_order` / `wc_get_orders`，**禁止**直接 query `wp_posts`。

#### 1.1.7 Progress 領域（3 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `progress_get_by_user_course` | `Resources\StudentLog\CRUD::get_list` 過濾條件 | ⚠️ 需新增 `Service::get_progress( $course_id, $user_id )` |
| `progress_mark_chapter_finished` | `Resources\Chapter\Core\Api::post_toggle_finish_chapters_with_id_callback` 內邏輯 | ⚠️ 需抽出為 `Service::mark_finished( $chapter_id, $user_id )` |
| `progress_reset` | （無） | ❌ 需新增 `Service::reset( $course_id, $user_id )`，刪除對應 StudentLog 並 reset avl_course meta |

#### 1.1.8 Email 領域（6 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `email_list` | `PowerEmail\Resources\Email\Api::get_emails_callback` | ✅ |
| `email_get` | `PowerEmail\Resources\Email\Api::get_emails_with_id_callback` | ✅ |
| `email_create` | `PowerEmail\Resources\Email\Api::post_emails_callback` | ✅ |
| `email_update` | `PowerEmail\Resources\Email\Api::post_emails_with_id_callback` | ✅ |
| `email_send_now` | `PowerEmail\Resources\Email\Api::post_emails_send_now_callback` | ✅ |
| `email_send_schedule` | `PowerEmail\Resources\Email\Api::post_emails_send_schedule_callback` | ✅ |

> Email 領域是最完整的 Service-friendly，callback 已將業務邏輯封裝得很乾淨。

#### 1.1.9 Comment 領域（3 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `comment_list` | `Api\Comment::get_comments_callback` | ✅ |
| `comment_create` | `Api\Comment::post_comments_callback` | ✅ |
| `comment_toggle_approved` | `Api\Comment::post_comments_with_id_toggle_approved_callback` | ✅ |

> Bonus tool 候選：`comment_delete` 對應 `delete_comments_with_id_callback`。

#### 1.1.10 Report 領域（2 tools）

| Tool | 對應 PHP class::method | 狀態 |
| --- | --- | --- |
| `report_revenue_stats` | `Api\Reports\Revenue\Api::get_reports_revenue_stats_callback` | ✅ |
| `report_student_count` | `Api\Reports\Revenue\Api::extend_student_count_stats` / `extend_finished_chapters_count_stats` | ⚠️ 需新增獨立 `Service::get_student_count_stats( $args )` 包裝 |

---

### 1.2 REST Controller 模式

- **註冊位置**：每個 controller class 在 `__construct` 內 `add_action('rest_api_init', [$this, 'register_apis'])`，定義 `$apis` 陣列為 `[ ['endpoint' => ..., 'method' => ..., 'permission_callback' => ...] ]` 後迴圈呼叫 `register_rest_route`。
- **namespace**：統一 `power-course/v2`（Email 模組則為 `power-email/v1`，需另外處理）。
- **permission_callback**：目前不一致——多數設為 `null`（formally 等同 `__return_true`）或 `is_user_logged_in`，**沒有 capability check**。⚠️ 這是 MCP 規劃的 **CRITICAL GAP**：MCP tools **必須統一改為呼叫 `current_user_can()`**，不可繼承現有寬鬆策略。
- **建議**：MCP `AbstractTool::permission_callback()` 一律強制 capability check（read-only 用 `read`，寫入用 `manage_woocommerce` / `edit_posts` 視 tool 而定）。

### 1.3 前端 Settings 架構

- 入口：`js/src/pages/admin/Settings/index.tsx`
- pattern：頂層 `<Tabs>`，每個 sub-tab 為獨立 component（`General` / `Appearance` / `AutoGrant`）
- 表單管理：單一 `<Form>` 包整個 Settings，子 tab 使用 `<Form.Item>` 共用 `form` instance
- 資料層：`hooks/useSettings.tsx` (GET) + `hooks/useSave.tsx` (POST)，呼叫 `power-course/settings`
- API 後端：`Resources\Settings\Core\Api`（`get_settings_callback` / `post_settings_callback`）+ `Model\Settings`
- **新增 MCP Tab 的 pattern**：在 `getItems()` 加入 `{ key: 'mcp', label: ..., children: <Mcp /> }`，建立 `Settings/Mcp/index.tsx` 子目錄

> ⚠️ MCP 設定（啟用 categories、token 列表）有獨立的 list/CRUD 操作（Token 管理），單純沿用 `useSettings` 的 single-form 模型不夠，需要額外的 `useMcpTokens` hook + `useMutation` for revoke/create token。

### 1.4 Composer / Autoloader 策略

- 現況 `composer.json`：
  - PSR-4：`J7\PowerCourse\` → `inc/classes/`, `inc/src/`
  - **未使用 jetpack-autoloader**，純 Composer autoload
  - 已有 `wpackagist.org` repository
  - require: 只有 `kucrut/vite-for-wp`、`j7-dev/wp-plugin-trait`
- **要新增**：
  - `"wordpress/mcp-adapter": "^0.5.0"`
  - `"wordpress/abilities-api": "^0.1"` (MCP adapter peer dep)
- **風險**：`wordpress/mcp-adapter` 自帶 `WP\MCP\` namespace，與專案無衝突。但若日後 Powerhouse 也載入相同套件，需評估是否要切到 `mozart` / `php-scoper` 重新命名 namespace 以避免重複載入衝突。**Phase 0 先不做 scoper，先用 require-dev 的 conflict 偵測**。

---

## 2. 共用基礎建設設計

### 2.1 `J7\PowerCourse\Api\Mcp\AbstractTool`

**檔案**：`inc/classes/Api/Mcp/AbstractTool.php`

```
Class AbstractTool (abstract)
├── const ABILITY_PREFIX = 'power-course/'
├── abstract get_name(): string             // ability name, e.g. 'course_list'
├── abstract get_label(): string
├── abstract get_description(): string
├── abstract get_input_schema(): array      // JSON Schema
├── abstract get_output_schema(): array     // JSON Schema
├── abstract get_capability(): string       // e.g. 'edit_posts'
├── abstract execute( array $args ): mixed  // 業務邏輯
├── final permission_callback(): bool       // current_user_can( $this->get_capability() )
├── final register(): void                  // wp_register_ability( ... )
└── final get_category(): string            // 用於 Settings 啟用判斷
```

依賴：`wp_register_ability`（abilities-api）、`Settings::is_category_enabled()`、`ActivityLogger`

### 2.2 `J7\PowerCourse\Api\Mcp\Server`

**檔案**：`inc/classes/Api/Mcp/Server.php`

```
Class Server
├── const SERVER_ID = 'power-course-mcp'
├── const ROUTE_NAMESPACE = 'power-course/v2'
├── const ROUTE = 'mcp'
├── __construct(): hook 'mcp_adapter_init' → bootstrap()
├── bootstrap(): 註冊 categories、實例化每個 tool class、呼叫 McpAdapter::create_server
├── get_enabled_tools(): array  // 依 Settings 過濾
└── get_all_tool_classes(): array  // hard-coded 10 個領域陣列
```

### 2.3 `J7\PowerCourse\Api\Mcp\Auth`

**檔案**：`inc/classes/Api/Mcp/Auth.php`

```
Class Auth
├── verify_bearer_token( WP_REST_Request $req ): WP_User|false
├── add_authorization_header_filter(): void   // hook 'rest_authentication_errors'
├── create_token( int $user_id, string $name, array $caps ): string
├── revoke_token( string $token_id ): bool
└── list_tokens( int $user_id = 0 ): array
```

Token 儲存：`wp_pc_mcp_tokens` 表（見 2.6）。

### 2.4 `J7\PowerCourse\Api\Mcp\Settings`

**檔案**：`inc/classes/Api/Mcp/Settings.php`

```
Class Settings
├── const OPTION_KEY = 'pc_mcp_settings'
├── get_enabled_categories(): array  // ['course', 'chapter', ...]
├── set_enabled_categories( array $cats ): bool
├── is_category_enabled( string $cat ): bool
├── is_server_enabled(): bool
└── get_rate_limit(): int  // requests per minute per token
```

### 2.5 `J7\PowerCourse\Api\Mcp\ActivityLogger`

**檔案**：`inc/classes/Api/Mcp/ActivityLogger.php`

```
Class ActivityLogger
├── log( string $tool_name, int $user_id, array $args, mixed $result, bool $success ): void
├── get_recent_logs( int $limit = 100 ): array
└── prune_old_logs( int $days = 30 ): int  // wp_cron daily
```

儲存：複用 `wp_pc_student_logs` 表？❌ **不建議**，欄位不一致。建議新增 `wp_pc_mcp_activity` 表或寫入 PHP error_log + WP debug.log（取捨：DB 表方便前端查詢，log 檔便宜）。**建議：新表**。

### 2.6 DB Migration

新增兩張表 + 一組 options：

```sql
CREATE TABLE wp_pc_mcp_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash VARCHAR(255) NOT NULL,         -- hashed bearer token
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,                -- 使用者標記
  capabilities LONGTEXT,                     -- JSON: 允許的 tool categories
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY token_hash (token_hash),
  KEY user_id (user_id)
);

CREATE TABLE wp_pc_mcp_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tool_name VARCHAR(100) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_id BIGINT UNSIGNED NULL,
  request_payload LONGTEXT,                  -- JSON, 大 payload 截斷
  response_summary VARCHAR(500),
  success TINYINT(1) NOT NULL,
  duration_ms INT,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY tool_name (tool_name),
  KEY user_id (user_id),
  KEY created_at (created_at)
);
```

Options：
- `pc_mcp_settings` (array): { enabled: bool, enabled_categories: [], rate_limit_per_min: 60 }
- `pc_mcp_db_version` (string): '1.0.0' — 用於 migration 偵測

Migration 入口：在 `inc/classes/Bootstrap.php` 註冊 activation hook，呼叫 `Mcp\Migration::install()`。

### 2.7 Composer 依賴變更

```diff
 "require": {
   "kucrut/vite-for-wp": "^0.8",
-  "j7-dev/wp-plugin-trait": "^0.2"
+  "j7-dev/wp-plugin-trait": "^0.2",
+  "wordpress/mcp-adapter": "^0.5.0",
+  "wordpress/abilities-api": "^0.1"
 }
```

> ⚠️ 執行 `composer require` 後須驗證：
> 1. PHPStan level 9 通過（可能需 add stubs to `phpstan.neon`）
> 2. autoload 不會與 Powerhouse 載入的同名 namespace 衝突

---

## 3. Dependency Graph

```
Phase 0: Composer 依賴 + DB Migration（序列化執行，1 個 agent）
  │
  ├── 0.1 修改 composer.json + composer install
  ├── 0.2 建立 inc/classes/Api/Mcp/Migration.php + activation hook
  └── 0.3 建立 wp_pc_mcp_tokens / wp_pc_mcp_activity 表
        │
        ▼
Phase 1: 共用基礎建設（序列化執行，1 個 agent）
  │
  ├── 1.1 AbstractTool
  ├── 1.2 Auth
  ├── 1.3 Settings
  ├── 1.4 ActivityLogger
  ├── 1.5 Server (整合上述)
  └── 1.6 PHPUnit base test class（IntegrationTestCase + MCP fixtures）
        │
        ▼
Phase 2: 領域 tools 實作（推薦平行度 = 3，理由見下方）
  │
  ├── 2A: 後端結構良好的領域（並行 wave 1，3 個 agents）
  │   ├── Course (6 tools) → wordpress-master #1
  │   ├── Email (6 tools)  → wordpress-master #2
  │   └── Comment (3 tools) → wordpress-master #3
  │
  ├── 2B: 需新增 Service 層的領域（並行 wave 2，3 個 agents）
  │   ├── Chapter (7 tools) → wordpress-master #4
  │   ├── Student (9 tools) → wordpress-master #5
  │   └── Bundle (4 tools)  → wordpress-master #6
  │
  ├── 2C: 重度 Service 補洞 + HPOS（並行 wave 3，3 個 agents）
  │   ├── Teacher (4 tools) → wordpress-master #7
  │   ├── Order (3 tools, HPOS) → wordpress-master #8
  │   └── Progress (3 tools) → wordpress-master #9
  │
  ├── 2D: 報表與前端（並行 wave 4，2 個 agents）
  │   ├── Report (2 tools) → wordpress-master #10
  │   └── 前端 Settings MCP Tab → react-master
  │
  ▼
Phase 3: 整合測試 + E2E
  │
  ├── 3.1 wp mcp-adapter list 驗證 45 tools 全部註冊
  ├── 3.2 PHPUnit integration test 驗證 permission/schema
  └── 3.3 Playwright E2E：MCP Settings tab 啟用/停用 + Token CRUD
        │
        ▼
Phase 4: 文件更新（1 個 agent）
  │
  ├── 4.1 README MCP 章節
  ├── 4.2 specs/features/mcp/ 各領域 .feature 檔案
  └── 4.3 specs/api/api.yml 新增 mcp endpoint schemas
```

### 推薦平行度說明

- **建議：3 個 wordpress-master 同時跑（wave 1 → 2 → 3 → 4 序列推進）**，理由：
  1. 每個 agent 操作獨立檔案目錄（`inc/classes/Api/Mcp/Tools/{Domain}/`），衝突風險低
  2. 但都會修改 `Bootstrap.php` 註冊新 tool class，**規定每個 wave 結束時由協調者集中註冊**
  3. 共用 `composer.json` 已在 Phase 0 完成，不會再動
- **序列化 fallback**：若 worktree 衝突嚴重，改為 1 agent 連跑 10 個領域（預估 10 倍時間）。
- **不建議**：10 個並行（高機率撞 Bootstrap 註冊衝突 + PHPStan baseline 競爭寫入）。

---

## 4. Phase 2 Task Prompts（10 領域 + 前端）

> 以下每個 prompt 可直接餵給 `@wp-workflows:tdd-coordinator`，由其再分派給 `wordpress-master` / `react-master`。

### 共用前置條件（每個 prompt 都必須包含）

```markdown
## 前置條件
- Phase 0 已完成：composer 依賴 + 兩張 DB table 已建立
- Phase 1 已完成：以下 class 已可使用
  - J7\PowerCourse\Api\Mcp\AbstractTool
  - J7\PowerCourse\Api\Mcp\Settings
  - J7\PowerCourse\Api\Mcp\ActivityLogger

## 共用規範
1. 所有 tool class 繼承 `AbstractTool`
2. JSON Schema 必須完整：每個 input field 標 `type` / `required` / `enum` / `min`/`max` / `description`（中文）
3. `permission_callback` 必須呼叫 `current_user_can( $cap )`，**禁止**留 null 或 `__return_true`
4. 每個 tool 必須有 PHPUnit test：
   - happy path（管理員執行）
   - 權限不足（一般訂閱者執行 → 403）
   - 驗證失敗（缺 required field → 422）
5. 業務邏輯**必須**呼叫 Service method，不可 inline DB / WP Query
6. 中文註解；class file 採 `declare(strict_types=1)`
7. 完成後在 `inc/classes/Bootstrap.php` 增加註冊（或統一寫入 `Api\Mcp\Server::get_all_tool_classes()` 陣列）
8. 跑 `composer phpstan` level 9 必須無新增錯誤
9. 跑 `composer test` 確認測試全綠

## 驗收
- 執行 `wp mcp-adapter list` 看到本領域所有 tools
- 對應的 PHPUnit test 全綠
```

### 4.1 Course domain task prompt

```markdown
# Phase 2.1 — Course 領域 MCP Tools (6 tools)

## Tools 清單
| Tool name | 功能 | Service 來源 | Capability | Feature file |
|---|---|---|---|---|
| `course_list` | 列出課程（支援分頁/篩選/排序） | `Api\Course::format_course_records`（先抽出為 `Resources\Course\Service\Query::list`） | `read` | specs/features/course/list.feature |
| `course_get` | 取得單一課程詳情 | 同上 `Service\Query::get( $id )` | `read` | specs/features/course/get.feature |
| `course_create` | 建立新課程 | `Api\Course::handle_save_course_data`（抽出為 `Service\Crud::create`） | `manage_woocommerce` | specs/features/course/create.feature |
| `course_update` | 更新課程 | `Service\Crud::update` | `manage_woocommerce` | specs/features/course/update.feature |
| `course_delete` | 刪除課程 | `Service\Crud::delete` | `manage_woocommerce` | specs/features/course/delete.feature |
| `course_duplicate` | 複製課程 | `Utils\Duplicate` 相關 method | `manage_woocommerce` | specs/features/course/duplicate.feature |

## 必做
1. 抽出 `Resources\Course\Service\Query` 與 `Resources\Course\Service\Crud`，避免從 callback 重複實作
2. 在 `inc/classes/Api/Mcp/Tools/Course/` 建立 6 個 tool class
3. 對應 PHPUnit test 在 `tests/Integration/Api/Mcp/Tools/Course/`
```

### 4.2 Chapter domain task prompt

```markdown
# Phase 2.2 — Chapter 領域 MCP Tools (7 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `chapter_list` | `Resources\Chapter\Core\Api::get_chapters_callback`（抽出 `Service\Query::list`） | `read` |
| `chapter_get` | ❌ 新增 `Service\Query::get( int $id )` | `read` |
| `chapter_create` | 抽出 `Service\Crud::create` | `edit_posts` |
| `chapter_update` | 抽出 `Service\Crud::update` | `edit_posts` |
| `chapter_delete` | 抽出 `Service\Crud::delete` | `edit_posts` |
| `chapter_sort` | 抽出 `Service\Crud::sort( array $sortable_data )` | `edit_posts` |
| `chapter_toggle_finish` | 抽出 `Service\Progress::toggle_finish( int $chapter_id, int $user_id, bool $is_finished )` | `read` |

## 必做
- Service 補洞優先（`Service\Query::get`、`Service\Crud`、`Service\Progress`）
- `chapter_toggle_finish` 注意：MCP caller 可指定 user_id，但需要 capability check（自己只能改自己；管理員可改任何人）
```

### 4.3 Student domain task prompt

```markdown
# Phase 2.3 — Student 領域 MCP Tools (9 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `student_list` | `Resources\Student\Service\Query` | `list_users` |
| `student_get` | ❌ 新增 `Service\Query::get( int $user_id )` | `list_users` |
| `student_export_csv` | `Resources\Student\Service\ExportCSV` | `list_users` |
| `student_export_count` | `Resources\Student\Core\Api::get_students_export_count_callback`（抽出 Service） | `list_users` |
| `student_add_to_course` | `Resources\Course\Service\AddStudent::add_item` | `edit_users` |
| `student_remove_from_course` | ❌ 新增 `Resources\Course\Service\RemoveStudent::remove_item` | `edit_users` |
| `student_get_progress` | ❌ 新增 `Resources\Student\Service\Progress::get_progress( int $course_id, int $user_id )` | `read` |
| `student_update_meta` | `update_user_meta` 包裝 + whitelist | `edit_users` |
| `student_get_log` | `Resources\StudentLog\CRUD::get_list` | `list_users` |

## 必做
- `student_update_meta` 必須有 **allowed meta keys whitelist**，禁止任意 meta 寫入（防權限提升）
- `student_export_csv` 可能產生大檔，回傳 attachment URL 而非 inline base64
```

### 4.4 Teacher domain task prompt

```markdown
# Phase 2.4 — Teacher 領域 MCP Tools (4 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `teacher_list` | ❌ 新增 `Resources\Teacher\Service\Query::list` | `list_users` |
| `teacher_get` | ❌ 新增 `Resources\Teacher\Service\Query::get( int $user_id )` | `list_users` |
| `teacher_assign_to_course` | ❌ 新增 `Resources\Teacher\Service\Assignment::assign( $course_id, $user_id )` | `edit_users` |
| `teacher_remove_from_course` | ❌ 新增 `Resources\Teacher\Service\Assignment::remove` | `edit_users` |

## 必做
- 全新建立 `inc/classes/Resources/Teacher/Service/` 目錄
- 既有 `Core\ExtendQuery` 內的查詢條件可參考
```

### 4.5 Bundle domain task prompt

```markdown
# Phase 2.5 — Bundle 領域 MCP Tools (4 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `bundle_list` | ❌ 新增 `BundleProduct\Service\Query::list` | `manage_woocommerce` |
| `bundle_get` | `BundleProduct\Helper::instance` + `get_bundle_products` + `get_product_quantities` | `manage_woocommerce` |
| `bundle_set_products` | `BundleProduct\Helper::set_bundled_ids` + `set_product_quantities` | `manage_woocommerce` |
| `bundle_delete_products` | `BundleProduct\Helper::delete_bundled_ids` | `manage_woocommerce` |

## 必做
- `bundle_set_products` 為原子操作：set_bundled_ids 與 set_product_quantities 須成對成功，建議 wrap 在 try/catch 並還原
```

### 4.6 Order domain task prompt

```markdown
# Phase 2.6 — Order 領域 MCP Tools (3 tools, HPOS-aware)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `order_list` | ❌ 新增 `Resources\Order\Service\Query::list`（純 `wc_get_orders`） | `manage_woocommerce` |
| `order_get` | ❌ 新增 `Service\Query::get_with_courses( $order_id )` | `manage_woocommerce` |
| `order_grant_courses` | `Resources\Order::handle_bind_courses` | `manage_woocommerce` |

## 必做（HPOS 強規定）
- ❌ 禁止使用 `get_post`、`WP_Query`、直接 SQL 對 wp_posts/wp_postmeta
- ✅ 必須使用 `wc_get_order` / `wc_get_orders` / `OrderUtil::custom_orders_table_usage_is_enabled()`
- 啟動時 declare HPOS 相容（若尚未 declare）
- 參考 `@wp-workflows:woocommerce-hpos` skill
```

### 4.7 Progress domain task prompt

```markdown
# Phase 2.7 — Progress 領域 MCP Tools (3 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `progress_get_by_user_course` | ⚠️ 新增 `Resources\Student\Service\Progress::get_progress` | `read` |
| `progress_mark_chapter_finished` | ⚠️ 抽出 `Resources\Chapter\Service\Progress::mark_finished` | `read` |
| `progress_reset` | ❌ 新增 `Resources\Student\Service\Progress::reset` | `edit_users` |

## 必做
- 與 Chapter (4.2) 共用 `Service\Progress`，建議 Chapter agent 與 Progress agent **不能同時跑**，Progress 排在 Chapter 之後 wave
- `progress_reset` 是高破壞操作，必須要 confirm flag（input schema 加 `confirm: true`）+ 寫 ActivityLog
```

### 4.8 Email domain task prompt

```markdown
# Phase 2.8 — Email 領域 MCP Tools (6 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `email_list` | `PowerEmail\Resources\Email\Api::get_emails_callback`（抽 Service） | `manage_options` |
| `email_get` | 同上 `get_emails_with_id_callback` | `manage_options` |
| `email_create` | `post_emails_callback` | `manage_options` |
| `email_update` | `post_emails_with_id_callback` | `manage_options` |
| `email_send_now` | `post_emails_send_now_callback` | `manage_options` |
| `email_send_schedule` | `post_emails_send_schedule_callback` | `manage_options` |

## 注意
- Email namespace 是 `power-email/v1`，但 MCP tool 統一在 `power-course-mcp` server，呼叫時注意 namespace 別寫錯
- `email_send_now` 是高負載操作（觸發實際寄送），應加 rate limit 與 ActivityLog
```

### 4.9 Comment domain task prompt

```markdown
# Phase 2.9 — Comment 領域 MCP Tools (3 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `comment_list` | `Api\Comment::get_comments_callback`（抽 Service） | `moderate_comments` |
| `comment_create` | `Api\Comment::post_comments_callback` | `read` (logged in) |
| `comment_toggle_approved` | `Api\Comment::post_comments_with_id_toggle_approved_callback` | `moderate_comments` |
```

### 4.10 Report domain task prompt

```markdown
# Phase 2.10 — Report 領域 MCP Tools (2 tools)

## Tools 清單
| Tool name | Service 來源 | Capability |
|---|---|---|
| `report_revenue_stats` | `Api\Reports\Revenue\Api::get_reports_revenue_stats_callback`（抽 Service） | `view_woocommerce_reports` |
| `report_student_count` | ⚠️ 新增 `Service\Stats::get_student_count_stats( $args )` | `view_woocommerce_reports` |

## 注意
- Revenue 查詢可能跑 heavy SQL，input schema 必須限制 date range（max 365 天）
```

### 4.11 前端 Settings MCP Tab task prompt

```markdown
# Phase 2.11 — 前端 MCP Settings Tab（react-master）

## 範圍
- 在 `js/src/pages/admin/Settings/` 新增 `Mcp/index.tsx`
- 在 `index.tsx` 的 `getItems()` 加入新 tab
- 新增 sub-components：
  - `Mcp/EnabledCategories.tsx` — 10 個 categories 的 checkbox
  - `Mcp/Tokens/` — Token list + create/revoke modal
  - `Mcp/ActivityLog/` — recent activity table（用 ProTable）

## API 對應（後端 Phase 1 已建立）

> 歷史紀錄：Phase 1.5 原本提供 6 條 REST endpoint（settings/tokens/activity），
> 後續因 Settings > MCP tab 下架（2026-05），tokens / activity 4 條管理 endpoint 已移除，
> 此 controller 現在只剩 settings 兩條，由 AI tab 共用 useMcpSettings() hook 讀寫
> allow_update / allow_delete（Issue #217）。

| Endpoint | Method | 用途 |
|---|---|---|
| `power-course/mcp/settings` | GET/POST | AI tab 共用：讀寫 enabled / enabled_categories / allow_update / allow_delete |

## 規範
- 使用 Refine.dev 的 `useCustom` / `useMutation`
- Token 建立後**只顯示一次**明文（之後只儲存 hash），UI 須有 copy-to-clipboard
- 國際化：所有字串走 `__('...', 'power-course')`
- TanStack Query 5.x cache key 規範：`['mcp', 'tokens']` etc.

## 驗收
- Playwright E2E：建立 token → 啟用 categories → 儲存 → 重新整理仍持久
```

---

## 5. 風險清單

### 高風險

| 風險 | 影響 | 緩解 |
|---|---|---|
| **Capability 設計錯誤導致權限提升** | 任意 MCP caller 可繞過 WP 權限取得管理員等級操作 | AbstractTool 強制 `current_user_can()`；PHPUnit 必含 forbidden test |
| **Service 層大量補洞拖慢進度** | Phase 2 卡關 | wave 排序：先做 Service 完整領域（Course/Email/Comment），同時補洞分批進行 |
| **HPOS 不相容** | Order 操作在 HPOS 啟用的站台炸掉 | 強制 wc_get_orders；CI 跑兩次 PHPUnit（HPOS on/off） |
| **Bootstrap 註冊衝突（並行 worktree）** | 多 agent 同時改 `Server::get_all_tool_classes` 陣列 | 規定 wave 結束由協調者統一 merge；或改用 `do_action('pc_mcp_register_tools')` 自動發現 |

### 中風險

| 風險 | 影響 | 緩解 |
|---|---|---|
| **Bearer Token 外洩** | 帳號被未授權使用 | Token 只存 hash；明文僅顯示一次；支援 expires_at 與 revoked_at |
| **Rate limit 實作** | 高頻調用打爆 DB | Phase 1 加入簡易 transient-based limiter（每 token 每分鐘 60 reqs） |
| **JSON Schema 不完整** | LLM call 帶錯參數導致 PHP fatal | AbstractTool 在 execute 前先用 schema 驗證 |
| **Powerhouse 載入相同套件重複定義** | namespace 衝突 fatal | composer.json 加 conflict 偵測；上線前驗證 vendor folder 結構 |
| **PHPStan level 9 + 第三方 stubs 缺失** | CI 紅 | 預先在 phpstan.neon 加 abilities-api stubs 或 ignore 規則 |

### 低風險

| 風險 | 影響 | 緩解 |
|---|---|---|
| **前端 UI tab 過長** | UX 不佳 | Settings tab 採 sub-tab 巢狀（Categories / Tokens / Activity） |
| **specs 文件落後** | Phase 4 才補 | Phase 2 每個 agent 須同步更新對應 feature file |

---

## 6. 推薦執行順序

```
Day 1:
  Phase 0 (composer + DB)         ── 1 agent, ~2h
  Phase 1 (基礎建設)               ── 1 agent, ~6h

Day 2:
  Phase 2A (Course/Email/Comment) ── 3 agents 並行, ~6h
  Phase 2B (Chapter/Student/Bundle) ── 3 agents 並行, ~8h（含 Service 補洞）

Day 3:
  Phase 2C (Teacher/Order/Progress) ── 3 agents 並行, ~8h
  Phase 2D (Report + 前端 Tab)     ── 2 agents 並行, ~6h

Day 4:
  Phase 3 (整合 + E2E)             ── 1 agent, ~4h
  Phase 4 (文件)                   ── 1 agent, ~3h
```

**總計**：約 4 個工作天，10 個獨立 wordpress-master agent + 1 個 react-master + 1 個 e2e/docs agent。

---

## 7. 限制條件（本計畫不做的事）

- ❌ 不實作 MCP Resource / Prompt（只做 Tool）
- ❌ 不做 stream-based response（adapter 預設同步）
- ❌ 不做 OAuth2 authorization code flow（只做 Bearer Token）
- ❌ 不對 vendor 跑 php-scoper / mozart（保留為未來 Phase 5）
- ❌ 不做前端 chat playground（只做 Settings 管理介面）

---

## 8. 開放問題（待用戶確認）

1. **Token 管理粒度**：每個 token 是否可指定「允許呼叫的 categories 子集」？（建議：可以，存於 `wp_pc_mcp_tokens.capabilities` JSON）
2. **ActivityLog 保留期**：預設保留 30 天 + wp_cron 每日清理。是否同意？
3. **Email 領域 namespace**：MCP server 是否要把 Email tools 與 Course tools 放在同一個 server，還是分為 `power-course-mcp` + `power-email-mcp` 兩個 server？建議**同一個 server**，方便 LLM 一次發現所有能力。

---

> 此計畫完成後，將交給 `@wp-workflows:tdd-coordinator` 依 Phase 順序執行。
