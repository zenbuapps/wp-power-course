# Power Course — WordPress 課程外掛

[English](./README.md) | 繁體中文

[![Version](https://img.shields.io/badge/版本-1.1.0--rc1-blue)](https://github.com/zenbuapps/wp-power-course/releases)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-5.7%2B-blue)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.6.0%2B-purple)](https://woocommerce.com/)
[![License](https://img.shields.io/badge/授權-GPL%20v2-green)](./LICENSE)

> WordPress 最好用的課程外掛 — 透過 WooCommerce 銷售與管理線上課程，配備現代化 React 管理介面。

---

## 概覽

Power Course 將 WooCommerce 商品轉化為線上課程，提供階層式章節管理、視訊串流、學習進度追蹤與自動化郵件流程，全部透過以 RESTful API 驅動的 React SPA 後台管理。

---

## 功能特色

### 課程管理
- 建立、更新、刪除、複製課程
- 課程基於 WooCommerce 商品構建 — 定價、庫存與結帳流程原生支援
- 課程列表篩選與搜尋
- 設定課程可見性與開課時程（開課日期到達時自動發送通知）

### 章節管理
- 無限層級的章節 / 單元結構
- 拖曳排序（透過 API 更新章節順序）
- 每章節可上傳、刪除 `.srt` 字幕檔
- 每位學員的章節完成狀態獨立追蹤

### 學員管理
- 手動或批次（CSV 匯入）加入學員至課程
- 移除學員課程存取權限
- 更新個別學員到期日
- 學員列表搜尋與分頁
- 匯出學員列表為 CSV
- 查看每位學員的活動紀錄（進章、完成、存取事件）

### 學習進度追蹤
- 每位學員可獨立切換章節完成狀態
- 課程進度以百分比呈現（0–100%），依完成章節數計算
- 進度達 100% 時觸發 `power_course_course_finished` 動作鉤

### 存取控制
| 類型 | 說明 |
|------|------|
| 無限期 | 無到期時間 |
| 固定天數 | 加入後 N 天到期 |
| 指定日期 | 存取至指定日期為止 |
| 訂閱制 | 跟隨 WooCommerce Subscription 生命週期 |

### 銷售方案（Bundle Products）
- 將多個商品組合為銷售方案，購買後授予所有連結課程的存取權
- 在商品列表中顯示方案內的商品數量
- 銷售方案不可包含其他銷售方案

### 自動化郵件（PowerEmail）
- 基於 MJML 的拖曳式郵件編輯器
- 5 個觸發事件：

| 觸發點 | 觸發時機 |
|--------|---------|
| `course_granted` | 學員獲得課程存取權 |
| `course_finish` | 學員完成 100% 課程 |
| `course_launch` | 預定開課日期到達 |
| `chapter_enter` | 學員首次進入章節 |
| `chapter_finish` | 學員標記章節完成 |

- 支援變數替換：`{user.display_name}`、`{user.email}`、`{course.title}`、`{chapter.title}` 等
- 可建立、更新、刪除郵件模板
- 自動記錄郵件發送歷史至資料庫

### WooCommerce 整合
- 訂單達到指定狀態（預設：`completed`）時自動開通課程存取
- 課程商品防止訪客結帳
- 將多門課程綁定至單一 WooCommerce 商品
- 透過 Action Scheduler 排程發送開課通知

### 講師管理
- 將一位或多位講師指派至課程
- 後台專屬講師管理介面

### 數據分析與報表
- 依課程 / 日期範圍查詢營收報表
- 課程完成率統計
- 可篩選的學員活動日誌

### 媒體上傳與浮水印
- 透過後台上傳媒體檔案（影片、圖片、PDF）
- 動態影片浮水印（覆蓋用戶資訊：名稱、信箱等）
- PDF 浮水印支援
- 可設定浮水印數量與樣板文字

### 視訊播放
- **Bunny Stream**（HLS 自適應串流）— 主要 CDN
- **YouTube** 嵌入
- **Vimeo** 嵌入
- 自訂嵌入碼
- 行動裝置上的固定視訊播放器與頁籤導覽

### 字幕
- 每章節可上傳 `.srt` 字幕檔
- 刪除字幕
- 支援預設空字幕選項（預設不顯示字幕）

---

## 系統需求

| 相依套件 | 最低版本 | 來源 |
|---------|---------|------|
| WordPress | 5.7 | [wordpress.org](https://wordpress.org/) |
| PHP | 8.0 | |
| WooCommerce | 7.6.0 | [wordpress.org/plugins/woocommerce](https://wordpress.org/plugins/woocommerce/) |
| [Powerhouse](https://github.com/zenbuapps/wp-powerhouse) | 3.3.41 | GitHub |

**選用：**
- WooCommerce Subscriptions — 訂閱制存取控制所需

---

## 安裝方式

### 正式環境

1. 從 [GitHub Releases](https://github.com/zenbuapps/wp-power-course/releases) 下載最新 `.zip`
2. 進入 **WordPress 後台 → 外掛 → 新增外掛 → 上傳外掛**
3. 先安裝並啟用 **WooCommerce** 與 **Powerhouse**
4. 安裝並啟用 **Power Course**
5. 外掛啟用時自動建立 4 張所需資料表

### 開發環境

```bash
# 安裝 PHP 套件
composer install

# 安裝 JS 套件
pnpm install

# 啟動 Vite 開發伺服器（port 5174）
pnpm run dev

# 建置正式版本
pnpm run build
```

---

## 系統架構

### 技術棧

| 層級 | 技術 |
|------|------|
| 後端語言 | PHP 8.0+，`declare(strict_types=1)` |
| 後端框架 | WordPress、WooCommerce 7.6.0+、Powerhouse 3.3.41+ |
| PHP 命名空間 | `J7\PowerCourse`（PSR-4：`inc/classes/`、`inc/src/`） |
| 前端語言 | TypeScript 5.5（strict mode） |
| 前端框架 | React 18、Refine.dev 4.x、Ant Design 5.x |
| 狀態管理 | Jotai（UI 狀態）、TanStack Query 4.x（伺服器狀態） |
| 建構工具 | Vite + @kucrut/vite-for-wp |
| 視訊播放 | VidStack、hls.js、Bunny CDN |
| 郵件編輯 | j7-easy-email（基於 MJML） |
| 測試 | Playwright E2E、PHPUnit |
| PHP 品質 | PHPCS（WordPress 標準）+ PHPStan level 9 |
| TS 品質 | ESLint + Prettier |

### 目錄結構

```
power-course/
├── plugin.php              # 外掛入口（Singleton）
├── inc/                    # PHP 後端
│   ├── classes/            # PSR-4 autoload
│   │   ├── Api/            # REST API 端點
│   │   ├── Resources/      # 領域資源（Course、Chapter、Student…）
│   │   ├── BundleProduct/  # 銷售方案邏輯
│   │   ├── PowerEmail/     # 自動化郵件子系統
│   │   └── Utils/          # 工具類別
│   ├── src/Domain/         # DDD 風格：Product Events
│   └── templates/          # PHP 前台模板
├── js/src/                 # React 管理端 SPA
│   ├── pages/admin/        # 管理頁面（lazy-loaded）
│   ├── components/         # 可重用元件
│   ├── hooks/              # 自訂 React Hooks
│   ├── resources/          # Refine.dev resource 定義
│   └── types/              # TypeScript 型別定義
├── tests/e2e/              # Playwright E2E 測試
└── specs/                  # 業務規格文件
```

### 自訂資料表

| 資料表 | 用途 |
|--------|------|
| `{prefix}_pc_avl_coursemeta` | 用戶 ↔ 課程 metadata（到期日、進度） |
| `{prefix}_pc_avl_chaptermeta` | 用戶 ↔ 章節進度 |
| `{prefix}_pc_email_records` | 自動化郵件發送歷史 |
| `{prefix}_pc_student_logs` | 學員活動稽核日誌 |

### 自訂文章類型

| CPT | Slug | 說明 |
|-----|------|------|
| `pc_chapter` | `classroom` | 課程章節（階層式） |

---

## REST API

基礎 URL：`{site_url}/wp-json/power-course/v2/`

| 端點 | 方法 | 說明 |
|------|------|------|
| `courses` | GET, POST, DELETE | 列表 / 建立 / 批次刪除課程 |
| `courses/{id}` | GET, POST, DELETE | 取得 / 更新 / 刪除單一課程 |
| `courses/add-students` | POST | 新增學員至課程 |
| `courses/remove-students` | POST | 移除學員課程存取 |
| `courses/update-students` | POST | 更新學員到期日 |
| `courses/student-logs` | GET | 學員活動紀錄 |
| `chapters` | GET, POST, DELETE | 列表 / 建立 / 批次刪除章節 |
| `chapters/{id}` | POST | 更新章節 |
| `chapters/sort` | POST | 章節排序 |
| `chapters/{id}/subtitles` | POST, DELETE | 上傳 / 刪除字幕 |
| `toggle-finish-chapters/{id}` | POST | 切換章節完成狀態 |
| `products` | GET | WooCommerce 商品列表 |
| `bundle-products` | GET, POST | 管理銷售方案 |
| `teachers` | GET, POST | 管理講師指派 |
| `options` | GET, POST | 外掛設定 |
| `reports/revenue` | GET | 營收分析報表 |
| `comments` | GET, POST | 章節留言 |
| `media` | POST | 上傳媒體檔案 |

完整 OpenAPI 3.0.3 規格：[`specs/api/api.yml`](./specs/api/api.yml)

---

## WordPress Hooks

### Actions

```php
// 學員獲得課程存取後
add_action(
    'power_course_after_add_student_to_course',
    function( int $user_id, int $course_id, int|string $expire_date, ?\WC_Order $order ) {
        // 發送自訂通知、累積點數等
    },
    10, 4
);

// 學員完成 100% 課程時
add_action(
    'power_course_course_finished',
    function( int $course_id, int $user_id ) {
        // 頒發證書等
    },
    10, 2
);

// 課程 / 章節 meta 透過 REST API 儲存前
add_action(
    'power_course_before_update_product_meta',
    function( \WC_Product $product, array $meta_data ) {
        // 驗證或修改 meta 資料
    },
    10, 2
);
```

### 程式化授予課程存取

```php
use J7\PowerCourse\Resources\Course\LifeCycle;

// 一律透過 action hook，不直接呼叫底層函式
do_action(
    LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
    $user_id,      // int   — WordPress 用戶 ID
    $course_id,    // int   — WooCommerce 商品 ID
    $expire_date,  // 0 = 無限期 | Unix timestamp | 'subscription_123'
    $order         // \WC_Order|null
);
```

### 工具函式

```php
use J7\PowerCourse\Utils\Course as CourseUtils;

CourseUtils::is_course_product( $product );             // bool — 是否為課程商品？
CourseUtils::is_avl( $course_id, $user_id );            // bool — 用戶是否有存取權？
CourseUtils::is_course_ready( $product );                // bool — 是否已開課？
CourseUtils::is_expired( $product, $user_id );          // bool — 存取是否已到期？
CourseUtils::get_course_progress( $product, $user_id ); // float 0–100
```

---

## 外掛設定

在 **Power Course → 設定** 設定，或透過 `POST /wp-json/power-course/v2/options`。

| 設定 | 預設值 | 說明 |
|------|--------|------|
| `course_access_trigger` | `completed` | 觸發課程開通的 WC 訂單狀態 |
| `hide_myaccount_courses` | `no` | 在 WC 我的帳號隱藏課程頁籤 |
| `fix_video_and_tabs_mobile` | `no` | 行動裝置固定視訊 / 頁籤 |
| `pc_watermark_qty` | `0` | 影片浮水印數量（0 = 停用） |
| `pc_watermark_text` | `用戶 {display_name}...` | 浮水印樣板文字 |
| `pc_pdf_watermark_qty` | `0` | PDF 浮水印數量 |
| `hide_courses_in_main_query` | `no` | 從主 WordPress 查詢排除課程 |

**Bunny Stream CDN** 憑證（Library ID、CDN Hostname、API Key）在 **Powerhouse → 設定** 中設定，而非 Power Course。

---

## MCP Server（AI Agent 整合）

Power Course 提供 [Model Context Protocol（MCP）](https://modelcontextprotocol.io/) 伺服器，讓 AI 代理（Claude、GPT、Cursor 等）可以透過標準化工具介面，程式化地管理你的 LMS — 建立課程、加入學員、查詢報表等。

### 前置需求

| 相依套件 | 版本 | 下載 |
|---------|------|------|
| MCP Adapter | 0.5.0+ | [mcp-adapter.zip](https://github.com/WordPress/mcp-adapter/releases/latest) |
| Abilities API | 0.4.0+ | [abilities-api.zip](https://github.com/WordPress/abilities-api/releases/latest) |

兩者皆為 WordPress 外掛，下載後安裝並啟用即可。

### 快速開始

**1. 啟用 MCP Server**

前往 **Power Course → 設定 → MCP** 頁面，開啟 MCP 伺服器。

**2. 透過 WP-CLI 連接（STDIO 模式）**

```bash
# 列出所有已註冊的 MCP 伺服器
wp mcp-adapter list

# 啟動 Power Course MCP 伺服器（STDIO 傳輸，供 AI client 連接）
wp mcp-adapter serve --server=power-course-mcp --user=admin
```

**3. 透過 HTTP 傳輸連接**

```
POST {site_url}/wp-json/power-course/v2/mcp
```

### 可用工具一覽（41 工具 × 9 領域）

#### 課程 Course（6 個）

| 工具 | 說明 |
|------|------|
| `course_list` | 列出課程，支援分頁、狀態篩選、排序與關鍵字搜尋 |
| `course_get` | 取得課程完整詳情（章節、價格、限制、訂閱、銷售方案、講師） |
| `course_create` | 建立新課程（WooCommerce 商品 + `_is_course = yes`） |
| `course_update` | 更新課程欄位 — 僅修改傳入的欄位 |
| `course_delete` | 永久刪除課程（不可復原） |
| `course_duplicate` | 複製課程（含章節與銷售方案關聯，預設 draft 狀態） |

#### 章節 Chapter（7 個）

| 工具 | 說明 |
|------|------|
| `chapter_list` | 列出章節，可依課程 ID 或父章節 ID 篩選 |
| `chapter_get` | 取得章節完整詳情 |
| `chapter_create` | 在指定課程下建立新章節 |
| `chapter_update` | 更新章節標題、內容或其他欄位 |
| `chapter_delete` | 將章節移至垃圾桶 |
| `chapter_sort` | 原子操作重排章節順序（全成功或全失敗） |
| `chapter_toggle_finish` | 標記章節為已完成 / 未完成 |

#### 學員 Student（9 個）

| 工具 | 說明 |
|------|------|
| `student_list` | 列出學員，支援課程篩選、關鍵字搜尋與分頁 |
| `student_get` | 取得學員詳情（含已註冊課程 ID 清單） |
| `student_add_to_course` | 手動授權學員至課程（可設定到期日） |
| `student_remove_from_course` | 撤銷學員課程存取權 |
| `student_get_progress` | 取得學員課程進度摘要（完成章節數、百分比、到期狀態） |
| `student_get_log` | 查詢學員活動日誌（依 user/course 篩選、分頁） |
| `student_update_meta` | 更新學員 user_meta（僅限白名單欄位） |
| `student_export_count` | 預覽 CSV 匯出的學員 × 課程行數 |
| `student_export_csv` | 匯出課程學員名單為 CSV（回傳下載 URL） |

#### 講師 Teacher（4 個）

| 工具 | 說明 |
|------|------|
| `teacher_list` | 列出所有講師（具 `is_teacher = yes` meta 的用戶） |
| `teacher_get` | 取得講師詳情與授課課程清單 |
| `teacher_assign_to_course` | 指派講師到課程（idempotent） |
| `teacher_remove_from_course` | 從課程移除講師（idempotent） |

#### 銷售方案 Bundle（4 個）

| 工具 | 說明 |
|------|------|
| `bundle_list` | 列出銷售方案，支援分頁與課程篩選 |
| `bundle_get` | 取得方案詳情（綁定課程、商品 ID、數量） |
| `bundle_set_products` | 原子操作設定方案商品 ID 與數量（失敗自動回滾） |
| `bundle_delete_products` | 移除方案內全部或指定商品 |

#### 訂單 Order（3 個）

| 工具 | 說明 |
|------|------|
| `order_list` | 列出 WooCommerce 訂單，依狀態/客戶/日期篩選（相容 HPOS） |
| `order_get` | 取得訂單詳情與課程相關項目 |
| `order_grant_courses` | 手動重新觸發訂單課程授權（idempotent） |

#### 進度 Progress（3 個）

| 工具 | 說明 |
|------|------|
| `progress_get_by_user_course` | 取得學員在課程中的完整章節級進度 |
| `progress_mark_chapter_finished` | 明確標記章節為完成/未完成（非切換） |
| `progress_reset` | **危險操作**：刪除學員在課程中的所有進度（需 `confirm = true`） |

#### 留言 Comment（3 個）

| 工具 | 說明 |
|------|------|
| `comment_list` | 列出文章留言，支援分頁、類型與狀態篩選 |
| `comment_create` | 發表留言或評價（可指定其他用戶，需 `moderate_comments`） |
| `comment_toggle_approved` | 切換留言審核狀態（連帶子留言一併切換） |

#### 報表 Report（2 個）

| 工具 | 說明 |
|------|------|
| `report_revenue_stats` | 日期區間營收統計（訂單數、退款、學員數、完成數），上限 365 天 |
| `report_student_count` | 日期區間新加入學員數（依 interval 分組），上限 365 天 |

### MCP 設定

在 **Power Course → 設定 → MCP** 設定，或透過 REST API。

| 設定 | 預設值 | 說明 |
|------|--------|------|
| `enabled` | `false` | MCP 伺服器全域開關 |
| `enabled_categories` | `[]`（全部） | 啟用的工具分類 — 空陣列表示全部啟用 |
| `rate_limit_per_min` | `60` | 每分鐘最大請求數 |

### MCP 管理 REST API

基礎 URL：`{site_url}/wp-json/power-course/v2/`

所有 MCP 管理端點需要 `manage_options` 權限。

| 端點 | 方法 | 說明 |
|------|------|------|
| `mcp/settings` | GET | 取得 MCP 設定 |
| `mcp/settings` | POST | 更新 MCP 設定 |
| `mcp/tokens` | GET | 列出 API Token（雜湊後，不顯示明文） |
| `mcp/tokens` | POST | 建立新 Token（僅回傳一次明文） |
| `mcp/tokens/{id}` | DELETE | 撤銷 Token |
| `mcp/activity` | GET | 查詢工具活動日誌（可依 `tool_name` 篩選，分頁） |

### 安全機制

- 所有 MCP 工具強制執行 WordPress 權限檢查（預設需要 `manage_woocommerce`）
- Token 使用 SHA-256 雜湊儲存 — 明文僅在建立時顯示一次
- 每個 Token 支援 JSON `capabilities` 欄位，可限制可存取的工具
- 活動日誌記錄每次工具呼叫，透過 `wp_cron` 自動清理 30 天前的紀錄
- 危險操作（如 `progress_reset`）需明確傳入 `confirm = true` 參數

---

## 開發

### 指令

```bash
# 開發
pnpm run dev              # Vite 開發伺服器（http://localhost:5174）
pnpm run build            # i18n:build + 建置正式版 JS
pnpm run build:wp         # WordPress 優化建置

# 品質檢查
pnpm run lint:php         # PHPCS + PHPStan
pnpm run lint:ts          # ESLint
pnpm run format           # Prettier-ESLint 格式化
composer run phpstan      # PHPStan 靜態分析（level 9）

# 測試
composer run test             # PHPUnit
pnpm run test:e2e             # 全部 Playwright E2E 測試
pnpm run test:e2e:admin       # 管理端 E2E
pnpm run test:e2e:frontend    # 前台 E2E
pnpm run test:e2e:integration # 整合 E2E

# 國際化（i18n）
pnpm run i18n:pot         # 掃描 PHP + JS，產生 .pot 字串檔
pnpm run i18n:mo          # 編譯 .po → .mo（PHP 執行期使用）
pnpm run i18n:json        # 編譯 .po → JED JSON（React 執行期使用）
pnpm run i18n:build       # 依序執行以上三個 i18n 指令

# 發佈
pnpm run release          # Bump patch 版本 + 建置 + 發佈
pnpm run release:minor    # Bump minor 版本
pnpm run release:major    # Bump major 版本
pnpm run zip              # 打包發佈用 zip
pnpm run sync:version     # 同步版本：package.json → plugin.php
```

### 程式碼規範

- **PHP**：WordPress Coding Standards（WPCS）、PHPStan level 9（`phpstan.neon`）
- **TypeScript**：ESLint strict mode
- **格式化**：Prettier（Tab 縮排、單引號、不加分號）
- **Commit**：Conventional Commits（`feat:`、`fix:`、`chore:` 等），繁體中文描述

---

## 貢獻

1. Fork 此儲存庫
2. 建立 feature branch：`git checkout -b feat/my-feature`
3. 遵循上述程式碼規範
4. 確保所有測試通過：`pnpm run test:e2e` 與 `composer run test`
5. 對 `master` 分支開 Pull Request

---

## 授權

GPL v2 或更新版本 — 詳見 [LICENSE](./LICENSE)。

---

## 相關連結

- [GitHub 儲存庫](https://github.com/zenbuapps/wp-power-course)
- [作者](https://github.com/j7-dev)
- [Powerhouse 外掛](https://github.com/zenbuapps/wp-powerhouse)
- [API 規格文件](./specs/api/api.yml)
- [WordPress 開發者參考](https://developer.wordpress.org/reference/)
- [WooCommerce 程式碼參考](https://woocommerce.github.io/code-reference/)
- [Refine.dev 文件](https://refine.dev/docs/)
