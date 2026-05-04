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

5 張自訂表（由 `AbstractTable` 管理 DDL）：

| 表名 | 常數 | 用途 |
|------|------|------|
| `pc_avl_coursemeta` | `Plugin::COURSE_TABLE_NAME` | 學員課程授權與到期日 |
| `pc_avl_chaptermeta` | `Plugin::CHAPTER_TABLE_NAME` | 學員章節進度（首次瀏覽、完成時間） |
| `pc_email_records` | `Plugin::EMAIL_RECORDS_TABLE_NAME` | 郵件發送紀錄 |
| `pc_student_logs` | `Plugin::STUDENT_LOGS_TABLE_NAME` | 學員活動日誌 |
| `pc_chapter_progress` | `Plugin::CHAPTER_PROGRESS_TABLE_NAME` | 章節續播進度（last_position_seconds） |

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

## 安全規範

- **輸入清理**: 所有使用者輸入必須使用 `sanitize_text_field()`, `sanitize_email()`, `absint()` 等
- **輸出跳脫**: 使用 `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- **SQL 查詢**: 禁止直接拼接 SQL，必須使用 `$wpdb->prepare()` 或 `WP_Query`
- **Nonce 驗證**: REST API 端點透過 WordPress REST API 內建的 cookie nonce 驗證
- **權限檢查**: API 端點預設需要 `manage_woocommerce` capability

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
