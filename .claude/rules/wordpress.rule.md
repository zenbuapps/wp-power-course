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

4 張自訂表（由 `AbstractTable` 管理 DDL）：

| 表名 | 常數 | 用途 |
|------|------|------|
| `pc_avl_coursemeta` | `Plugin::COURSE_TABLE_NAME` | 學員課程授權與到期日 |
| `pc_avl_chaptermeta` | `Plugin::CHAPTER_TABLE_NAME` | 學員章節進度（首次瀏覽、完成時間） |
| `pc_email_records` | `Plugin::EMAIL_RECORDS_TABLE_NAME` | 郵件發送紀錄 |
| `pc_student_logs` | `Plugin::STUDENT_LOGS_TABLE_NAME` | 學員活動日誌 |

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
