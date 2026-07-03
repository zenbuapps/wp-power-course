---
paths:
  - "**/*.php"
---

# WordPress / PHP 後端開發規範

## 命名空間與 Autoload

- 主命名空間：`J7\PowerCourse`
- PSR-4 映射：
  - `J7\PowerCourse\` → `inc/classes/` 與 `inc/src/`
- 子命名空間反映目錄結構：
  - `J7\PowerCourse\Api\` → API 端點
  - `J7\PowerCourse\Resources\Chapter\Core\` → 章節核心邏輯
  - `J7\PowerCourse\PowerEmail\` → 郵件子系統

## PHP 編碼風格

- **PHPCS**: WordPress-Core + WordPress-Extra + WordPress-Docs 規則集
- **PHPStan**: level 9（最嚴格）
- **縮排**: Tab（4 spaces width）
- **陣列**: 短陣列語法 `[]`，禁止 `array()`
- **Trait 方法**: 必須標記 `final`
- **類別**: 鼓勵使用 `final class`（除非需要被繼承）
- **嚴格型別**: plugin.php 使用 `declare(strict_types=1)`

## REST API 開發

所有 API 端點遵循統一模式：

1. 繼承 `J7\WpUtils\Classes\ApiBase`，使用 `SingletonTrait`
2. 宣告 `$namespace = 'power-course'` 和 `$apis` 陣列
3. `$apis` 為 array of arrays，每項含 `endpoint` + `method`
4. 回呼方法自動由 ApiBase 根據 endpoint 和 method 組合推導

```php
// API 路由宣告模式
final class Course extends ApiBase {
    use \J7\WpUtils\Traits\SingletonTrait;

    protected $namespace = 'power-course';
    protected $apis = [
        ['endpoint' => 'courses',              'method' => 'get'],   // → get_courses_callback()
        ['endpoint' => 'courses',              'method' => 'post'],  // → post_courses_callback()
        ['endpoint' => 'courses/(?P<id>\d+)',  'method' => 'get'],   // → get_courses_with_id_callback()
    ];
}
```

回呼方法名由 ApiBase 自動生成：`{method}_{endpoint 轉 snake_case}_callback`，路徑參數 `(?P<id>\d+)` 轉為 `with_id`。

## 自訂資料表

6 張自訂表（由 `AbstractTable` 管理 DDL）：

| 表名 | 常數 | 用途 |
|------|------|------|
| `pc_avl_coursemeta` | `Plugin::COURSE_TABLE_NAME` | 學員課程授權與到期日 |
| `pc_avl_chaptermeta` | `Plugin::CHAPTER_TABLE_NAME` | 學員章節進度（首次瀏覽、完成時間） |
| `pc_email_records` | `Plugin::EMAIL_RECORDS_TABLE_NAME` | 郵件發送紀錄 |
| `pc_student_logs` | `Plugin::STUDENT_LOGS_TABLE_NAME` | 學員活動日誌 |
| `pc_chapter_progress` | `Plugin::CHAPTER_PROGRESS_TABLE_NAME` | 章節續播進度（last_position_seconds） |
| `pc_user_access_pass` | `Plugin::USER_ACCESS_PASS_TABLE_NAME` | 使用者持有權限包關係（user_id / pass_id / source_order_id / expire_date / granted_at）。compute-on-read，不 materialize avl_course_ids |

操作這些表時使用 `$wpdb->prepare()` 防止 SQL injection。

## Resource 模式

每個業務實體遵循 Resource 模式，典型結構：

```
Resources/{Entity}/
├── Core/
│   ├── Api.php         # REST API 端點
│   ├── CPT.php         # Custom Post Type 註冊
│   ├── LifeCycle.php   # WordPress hooks（save_post, delete, status change）
│   └── Loader.php      # 模組初始化
├── Model/
│   └── {Entity}.php    # 資料模型（properties + getters）
├── Service/
│   └── {Action}.php    # 業務邏輯服務（如 AddStudent, ExportCSV）
└── Utils/
    └── Utils.php       # 靜態工具方法
```

新增業務實體時遵循此結構，並在 `Resources/Loader.php` 中註冊。

### AccessPass Resource（Issue #252）

`Resources/AccessPass/` 是 issue/252 新增的 Resource，結構：

```
Resources/AccessPass/
├── Core/
│   ├── Api.php         # REST endpoints（access-passes CRUD + disable + attach）
│   ├── CPT.php         # pc_access_pass CPT（public=false, show_in_rest=true）
│   └── Loader.php      # 模組初始化
├── Model/
│   ├── AccessPass.php       # 通行證 Model（scope_type / limit_type / limit_value / limit_unit / access_pass_status / scope_term_ids / scope_course_ids）
│   └── UserAccessPass.php   # 持有關係 Model（對應 pc_user_access_pass 表）
└── Service/
    ├── Crud.php        # 建立 / 更新 / 刪除 AccessPass post
    ├── Gate.php        # user_has_valid_pass_for_course()：scope×expire compute-on-read
    ├── Grant.php       # 訂單完成 / 訂閱付款後授予持有關係
    ├── Query.php       # 查詢使用者持有的 pass 列表
    └── Repository.php  # pc_user_access_pass 表的 CRUD
```

**CPT meta keys**（postmeta，非資料表）：`scope_type`、`limit_type`、`limit_value`、`limit_unit`、`access_pass_status`、`scope_term_ids`（多列）、`scope_course_ids`（多列）

`limit_type` enum 四態（對齊課程 WatchLimit 模型，DB 版本 1.1.0 起）：
- `unlimited`：永久無到期（取代舊 `permanent`）
- `fixed`：相對期限，取得後依 `limit_value` + `limit_unit`（day/month/year）計算到期（取代舊 `limited`）
- `assigned`：絕對到期，`limit_value` 為 10 位 Unix timestamp，grant 時直接寫入 expire_date
- `follow_subscription`：跟隨 WooCommerce 訂閱生命週期，僅 active/pending-cancel 有效

**REST namespace**：`power-course`（無 v2），端點：
- `GET/POST/DELETE /access-passes`
- `PUT /access-passes/{id}`
- `POST /access-passes/{id}/disable`
- `POST /access-passes/{id}/attach`（掛載 `access_pass_id` 到商品）

**Gate 觀看判定疊加**：`Utils/Course::is_avl()` 與 `is_expired()` 皆 pass-aware，最終 OR 疊加 `Gate::user_has_valid_pass_for_course()`。Gate 內部支援 scope（all / category 含子分類 / specific）× expire（`unlimited` / `fixed` / `assigned` / `follow_subscription`）的完整組合，compute-on-read 不展開 avl_course_ids。

**商品掛載**：`access_pass_id` 與既有 `bind_courses_data` 並存，觀看判定以 OR 疊加。

## 安全規範

- **輸入清理**: 所有使用者輸入必須使用 `sanitize_text_field()`, `sanitize_email()`, `absint()` 等
- **輸出跳脫**: 使用 `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- **SQL 查詢**: 禁止直接拼接 SQL，必須使用 `$wpdb->prepare()` 或 `WP_Query`
- **Nonce 驗證**: REST API 端點透過 WordPress REST API 內建的 cookie nonce 驗證
- **權限檢查**: API 端點預設需要 `manage_woocommerce` capability；`users/{id}` 系列端點（GET/POST/reset-password/orders-summary）改用 `edit_users` capability

## WordPress Hooks 慣例

- 功能擴充優先使用 `add_action()` / `add_filter()`
- Hook 註冊集中在各 class 的 `__construct()` 中
- 使用 Action Scheduler（非 wp-cron）處理排程任務（如課程開課通知、排程郵件）

## Powerhouse / WpUtils 依賴

常用工具來自 `J7\WpUtils`：
- `PluginTrait`: 外掛生命週期管理
- `SingletonTrait`: 單例模式
- `ApiBase`: REST API 路由自動註冊
- `WC::logger()`: WooCommerce 日誌記錄
- `Post\Utils\CRUD`: 通用 Post CRUD 操作

參考路徑：
- Powerhouse: `../powerhouse/inc/classes/Domains/`
- WpUtils: `../powerhouse/vendor/j7-dev/wp-utils/src`

## PHP 品質檢查

```bash
pnpm run lint:php     # phpcbf (自動修正) + phpcs (規則檢查) + phpstan (靜態分析)
composer run phpstan  # 單獨執行 PHPStan
composer run test     # PHPUnit 測試
```

每次修改 PHP 程式碼後必須通過 `pnpm run lint:php`。

## BundleProduct/Helper — 銷售方案工具類

`J7\PowerCourse\BundleProduct\Helper` 是操作銷售方案（bundle product）的核心工具類，以 WC_Product 或 post_id 實例化。

```php
$helper = Helper::instance($product); // $product 可為 WC_Product 或 post_id
if ($helper?->is_bundle_product) { ... }
```

**常數（meta key）：**

| 常數 | Meta Key | 說明 |
|------|----------|------|
| `INCLUDE_PRODUCT_IDS_META_KEY` | `pbp_product_ids` | 方案包含的商品 ID 列表（多筆 meta） |
| `LINK_COURSE_IDS_META_KEY` | `link_course_ids` | 此方案歸屬的課程 ID |
| `PRODUCT_QUANTITIES_META_KEY` | `pbp_product_quantities` | 各商品數量 JSON：`{"product_id": qty}`，qty 範圍 1~999 |

**主要方法：**

- `get_product_ids()` — 取得原始 `pbp_product_ids` 列表
- `get_product_ids_with_compat()` — 含向下相容邏輯：若舊資料 `exclude_main_course ≠ 'yes'` 且課程不在列表中，自動補入課程 ID
- `get_product_quantities()` — 取得各商品數量（`array<string, int>`），缺少 meta 時 fallback 為 1
- `get_product_quantity(int $product_id)` — 取得單一商品數量（最小為 1）
- `set_product_quantities(array $quantities)` — 儲存各商品數量，自動 clamp 至 1~999

**訂單數量計算（Issue #185）：**

```
實際 bundled item 數量 = pbp_product_quantities[product_id] × 購買份數
```

**向下相容說明：** `exclude_main_course` meta 已廢棄（Issue #185），儲存時自動清除。Runtime 透過 `get_product_ids_with_compat()` 仍可讀取舊資料。

## REST API 快取控制（Issue #216）

### nocache_headers 注入

所有 `power-course` namespace 的 REST callback **必須**在第一行呼叫 `\nocache_headers()`，例如：

```php
public function get_chapters_callback( $request ) {
    \nocache_headers();   // ← 必須在 callback 開頭
    // ... 其餘邏輯
}
```

`nocache_headers()` 會輸出標準 HTTP 標頭：

- `Cache-Control: no-cache, must-revalidate, max-age=0, no-store`
- `Expires: Wed, 11 Jan 1984 05:00:00 GMT`

**目的**：避免 LiteSpeed Cache、WP Rocket、Cloudflare 等邊緣快取錯誤地快取 API 回應，造成排序 / 編輯後 refetch 仍拿到 stale 資料（Issue #216 Bug #1b）。

**站長雙保險建議**：在 LiteSpeed Cache 設定中將整個 `/wp-json/power-course/*` 路徑加入排除清單，搭配 callback 的 `nocache_headers()` 形成雙保險。

### Raw SQL 直寫 wp_posts / wp_postmeta 後必須清 cache

若 callback 或 Service / Utils 內使用 raw `$wpdb->query()` / `$wpdb->update()` 直接寫入 `wp_posts` 或 `wp_postmeta`，**必須**對所有異動的 `post_id` 逐筆呼叫 `\clean_post_cache( $id )`：

```php
$wpdb->query('COMMIT');

// 對所有被異動的 post_id 清除 object cache
foreach ( $updated_ids as $id ) {
    \clean_post_cache( $id );
}
```

**為什麼必須**：

- WordPress object cache 不會感知 raw SQL 寫入
- `wp_get_post_parent_id()`、`get_post_meta()`、`get_post()` 等 cache-aware API 會回傳更新前的舊值
- 後續 `wp_update_post` 在 `wp_insert_post_data` filter 中可能誤判 parent 不存在而清空 `post_parent`（即 Issue #216 Bug #2）
- `\clean_post_cache()` 會同時觸發 `clean_post_cache` action，讓下游外掛（SEO、cache、CDN）同步

**`wp_update_post()` 不需手動清**：函式內部已自動呼叫 `clean_post_cache()`。

**`wp_cache_flush_group()` 不可取代 `clean_post_cache()`**：部分 object cache 實作（如某些 Memcached 版本）的 group flush 為 no-op，仍須逐筆失效。`wp_cache_flush_group()` 可保留作為 fallback 補強。

## 編輯器內容與 JSON 欄位寫入規範（slash 對稱）

> 源自 JSON_PARSE_ERROR.md 根治案（2026-07，commit 67f492b4）。
> 病因：WordPress 寫入 API 內部會 `wp_unslash`，REST body params 是「乾淨（未加斜線）」字串，
> 直接傳入會讓 `\n`、`\"`、`\` 等跳脫字元**每存一次被咬掉一層**——簡單內容沒事、
> 真實編輯器內容（easy-email JSON、BlockNote HTML 程式碼區塊）逐次損毀，表現為「間歇性 JSON parse error / 內容變空白」。

### 核心原則

前端當 JSON 序列化唯一負責人（`JSON.stringify` 一次、`JSON.parse` 一次）；
PHP 把編輯器內容當**不透明字串**，全程不碰結構、不 `json_decode` 再 re-encode。

### slash 對照表（寫入 DB 前）

| 寫入路徑 | 要不要 `wp_slash` | 原因 |
|---|---|---|
| `wp_insert_post` / `wp_update_post`（含 `meta_input`） | **要** | 內部 `wp_unslash` |
| `update_post_meta` / `update_metadata` | **要**（深層遞迴 unslash，陣列內字串也會被咬） | 內部 `wp_unslash` |
| WC 商品 **post 欄位**（`set_name` / `set_slug` / `set_description` / `set_short_description` / `set_post_password`） | **要**（先 slash 再 set） | WC data store 非 `save_post` 情境走 `wp_update_post` 且不自行 slash |
| WC **自訂 meta**（`$product->update_meta_data()` → `save_meta_data()`） | **不要**（會雙重加斜線） | WC `add_meta()` 內部自行 `wp_slash`；`update_meta()` 走 `update_metadata_by_mid`（無 unslash） |
| `$wpdb->insert` / `$wpdb->update` 直寫 | **不要** | prepare 處理，無 unslash |

### JSON 欄位（如 email `post_excerpt`）額外守則

1. **寫入驗證**：存前必過 `J7\PowerCourse\Utils\JsonString::normalize()`——非法 JSON 回 `WP_Error` 400 拒存（壞資料進不去），並自癒多重編碼（decode 出字串仍是 JSON 時自動拆到裡層）
2. **條件式處理**：只在請求「有帶」該欄位時處理，**禁止** `$data['xxx'] ?? ''` 無條件覆寫——部分更新（只改標題/狀態）會把內容清成空白
3. **型別防守**：欄位非字串（巢狀陣列）→ 400 拒絕，禁止 `(string)` 轉型（陣列會變字面 `"Array"`）
4. **讀取不碰結構**：PHP 原樣回傳字串，交給前端 parse

### 前端守則（React）

- Email 內容唯一 parse 入口：`js/src/pages/admin/Emails/utils/tryParseEmailContent`（容錯多層編碼）
- 送出前先還原為物件再「唯一一次」stringify——禁止對可能仍是字串的 form 欄位直接 `JSON.stringify`（編輯器 lazy chunk 未載入完成時欄位是字串，會雙重編碼）
- 載入 parse 失敗 → 顯示警告、在用戶實際編輯（dirty）前**不覆寫 form 欄位**，避免下次儲存把空白寫回

### 驗證

改動任何 post / meta 寫入鏈後跑：

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group json-content
```

涵蓋刁鑽字串 round-trip（Windows 路徑、結尾反斜線、CRLF、emoji、\uXXXX、SQL 危險字元、多輪重存不劣化）。
