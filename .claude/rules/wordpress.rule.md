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

9 張自訂表（由 `AbstractTable` 管理 DDL，版本記於 `pc_db_version` option，目前 `1.2.0`）：

| 表名 | 常數 | 用途 |
|------|------|------|
| `pc_avl_coursemeta` | `Plugin::COURSE_TABLE_NAME` | 學員課程授權與到期日 |
| `pc_avl_chaptermeta` | `Plugin::CHAPTER_TABLE_NAME` | 學員章節進度（首次瀏覽、完成時間） |
| `pc_email_records` | `Plugin::EMAIL_RECORDS_TABLE_NAME` | 郵件發送紀錄 |
| `pc_student_logs` | `Plugin::STUDENT_LOGS_TABLE_NAME` | 學員活動日誌 |
| `pc_chapter_progress` | `Plugin::CHAPTER_PROGRESS_TABLE_NAME` | 章節續播進度（last_position_seconds） |
| `pc_user_access_pass` | `Plugin::USER_ACCESS_PASS_TABLE_NAME` | 使用者持有權限包關係（user_id / pass_id / source_order_id / expire_date / granted_by / granted_at）。compute-on-read，不 materialize avl_course_ids |
| `pc_assignment_submissions` | `Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME` | 學員作業繳交（text_body / attachments / status / feedback / graded_by / graded_at）。`UNIQUE KEY (assignment_id, user_id)` —— 重新繳交是**覆寫同一列**，不是新增版本（Issue #131 D 決議） |
| `pc_assignment_attachments` | `Plugin::ASSIGNMENT_ATTACHMENTS_TABLE_NAME` | 作業附件（original_name / stored_path / mime_type / file_size）。實體檔放受保護目錄，`stored_path` 不對外輸出，下載一律走權限檢查後 streaming |
| `pc_assignment_messages` | `Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME` | 作業討論串訊息往返（sender_type / sender_id / sender_email 快照 / body / read_at / deleted_at），軟刪除；`sender_id` / `sender_email` 只存不對外輸出（第二波起有讀寫，見下方「討論串」） |

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
    ├── Crud.php             # 建立 / 更新 / 刪除 AccessPass post
    ├── Gate.php             # user_has_valid_pass_for_course()：scope×expire compute-on-read
    ├── Grant.php            # 訂單完成 / 訂閱付款後授予；後台手動發放（grant_manually）與撤銷（revoke）
    ├── Query.php            # 後台通行證列表查詢
    ├── Holdings.php         # 持有關係的展示層：剩餘天數 / 快到期 / 來源 / 涵蓋課程數
    ├── Scope.php            # 正向展開「這張通行證涵蓋哪些課」（Gate 是反向判定）
    ├── ExpiringNotifier.php # 每日掃描即將到期的持有列，觸發到期預警信
    └── Repository.php       # pc_user_access_pass 表的 CRUD
```

**CPT meta keys**（postmeta，非資料表）：`scope_type`、`limit_type`、`limit_value`、`limit_unit`、`access_pass_status`、`scope_term_ids`（多列）、`scope_course_ids`（多列）

`limit_type` enum 四態（對齊課程 WatchLimit 模型，DB 版本 1.1.0 起）：
- `unlimited`：永久無到期（取代舊 `permanent`）
- `fixed`：相對期限，取得後依 `limit_value` + `limit_unit`（day/month/year）計算到期（取代舊 `limited`）
- `assigned`：絕對到期，`limit_value` 為 10 位 Unix timestamp，grant 時直接寫入 expire_date
- `follow_subscription`：跟隨 WooCommerce 訂閱生命週期，僅 active/pending-cancel 有效

**訂單 → 訂閱解析**（Issue #280）：follow_subscription 的 `subscription_{id}` 一律由
`Utils\Subscription::get_subscription_id_for_order()` 推導（依 parent → renewal → switch，第一個查得到的類型決定，多張回 null），
課程 `Limit::calc_expire_date()` 與通行證 `Grant::resolve_subscription_expire()` 共用。**禁止**再各自呼叫
`wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'parent' ] )`——開通 hook 對續訂單 / 升降級單也會觸發，
只查 parent 會讓課程被改成永久（`0`）、通行證被改成失效（`'0'`）。測試環境沒有 WCS 時，用
`power_course_subscription_available` + `power_course_order_subscription_ids` 兩個 filter 模擬（見 `FollowSubscriptionRenewalTest`）。

**REST namespace**：`power-course`（無 v2），端點：

後台（權限：`manage_options || manage_woocommerce`）
- `GET/POST/DELETE /access-passes`
- `PUT /access-passes/{id}`
- `POST /access-passes/{id}/disable`
- `POST /access-passes/{id}/attach`（掛載 `access_pass_id` 到商品）
- `GET /access-passes/{id}/users`（持有學員名單，回 `{items,total,total_pages}`）
- `POST /access-passes/{id}/grant`（批次手動發放，body `{user_ids}`）
- `POST /access-passes/{id}/revoke`（批次撤銷，body `{user_ids}`）

前台學員視角（權限：已登入 / 持有者）
- `GET /access-passes/me`（自己持有的通行證）
- `GET /access-passes/{id}/courses`（該通行證涵蓋的課程，分頁）

**手動發放規則**：到期日一律由 `Grant::calc_expire_date()` 依通行證的 `limit_type` 自動計算，
不接受逐次指定。`follow_subscription` 的通行證**拒絕**手動發放（`Grant::assert_grantable` 擋下）——
它的 expire_date 必須綁定一張真實訂閱，手動發放無訂單可推導，發了會立刻失效。
`granted_by` 欄位區分來源：有值=手動發放、`source_order_id` 有值=一次性訂單、兩者皆空=訂閱開通。

**Gate 觀看判定疊加**：`Utils/Course::is_avl()` 與 `is_expired()` 皆 pass-aware，最終 OR 疊加 `Gate::user_has_valid_pass_for_course()`。Gate 內部支援 scope（all / category 含子分類 / specific）× expire（`unlimited` / `fixed` / `assigned` / `follow_subscription`）的完整組合，compute-on-read 不展開 avl_course_ids。

**商品掛載**：`access_pass_id` 與既有 `bind_courses_data` 並存，觀看判定以 OR 疊加。

**前台「我的通行證」**：WooCommerce My Account 的 `access-passes` endpoint（`FrontEnd\MyAccount`），
模板 `inc/templates/pages/my-passes/index.php` + `components/card/access-pass.php`。
新增 endpoint 後必須 flush rewrite rules——`MyAccount::maybe_flush_rewrite_rules()` 以
`pc_myaccount_rewrite_version` option 版本閘門處理（`admin_init`）。
⚠️ 新增 page 模板時記得把目錄名加進 `plugin.php` 的 `$template_page_names`，否則會被導向 `components/`。

**到期預警**：`ExpiringNotifier` 以專屬 daily recurring action（`pc_access_pass_expiring_scan`）掃描，
只掃 `fixed` / `assigned`（有絕對到期 timestamp 者）。命中即 `do_action('power_course_access_pass_expiring')`，
由 `PowerEmail\Trigger\At` 排信（trigger slug `access_pass_expiring`）。
去重靠 `Email::is_sent()` 查 `pc_email_records.identifier`，identifier 含 `expire_timestamp`——
一次到期只寄一封，續期後到期日改變才會再提醒。門檻天數為 `Settings::access_pass_expiring_days`（預設 7），
前台快到期警示與預警信共用同一個值。

**Extensibility Hooks**（本模組對外提供）：
- `power_course_access_pass_granted_manually`（`$user_id, $pass_id, $granted_by`）
- `power_course_access_pass_revoked`（`$user_id, $pass_id`）
- `power_course_access_pass_expiring`（`$user_id, $pass_id, $expire_timestamp`）

**DB 版本**：`pc_access_pass_db_version` option，目前 `1.2.0`（1.2.0 新增 `granted_by` 欄位與
`idx_user_pass_expire` 索引）。migration 掛 `plugins_loaded` **priority 20**——
`Loader` 自己就是在 `plugins_loaded:10` 期間被建構的，掛回 10 會隨宿主的 Bootstrap priority 而靜默失效。
⚠️ `AbstractTable::create_*_table()` 一律「表已存在就早退」，**不會**替既有站台補欄位，
新增欄位必須寫獨立的冪等 `ALTER TABLE`（見 `Loader::migrate_add_granted_by_column`）。

### Assignment Resource（Issue #131）

`Resources/Assignment/` 是 issue/131 新增的 Resource，結構：

```
Resources/Assignment/
├── Core/
│   ├── Api.php              # 管理端 REST endpoints（assignments CRUD/sort、assignment-submissions 查詢/批改/匯出/匯入/討論串、assignment-attachments 下載）
│   ├── MyApi.php             # 學員端 REST endpoints（my/assignments 查詢/繳交、my/assignment-attachments 上傳、my/assignment-submissions 討論串）
│   ├── CPT.php                # pc_assignment CPT（public=false, show_in_rest=false）
│   ├── LifeCycle.php          # 章節 trash / 還原 / 刪除 ↔ 作業連動（DEC-06）
│   └── Loader.php             # 模組初始化、自訂表升級補掛、截止提醒排程掛載
├── Model/
│   ├── Assignment.php         # 作業 Model（wp_posts + postmeta 題型設定）
│   ├── Submission.php         # 繳交 Model（pc_assignment_submissions 單列）
│   ├── Attachment.php         # 附件 Model（pc_assignment_attachments 單列）
│   └── Message.php            # 討論訊息 Model（SENDER_STUDENT / SENDER_TEACHER 常數；to_view( viewer ) 白名單輸出 6 鍵，不載入 sender_id / sender_email）
├── Service/
│   ├── Crud.php / Query.php / MyQuery.php                    # 作業 CRUD 與查詢（管理端 / 學員端）
│   ├── Submission.php / SubmissionRepository.php / SubmissionFilter.php  # 繳交 UPSERT 與查詢過濾
│   ├── Attachment.php / AttachmentRepository.php              # 附件上傳與歸屬檢查
│   ├── Grading.php            # 批改：狀態機 + pass 時章節自動完成 + PowerEmail 觸發
│   ├── Csv.php                # 批改用 CSV 匯出 / 單一作業 CSV 匯出；grading_headers() 是匯出與匯入共用的表頭
│   ├── CsvImport.php          # 匯入批改結果 CSV：表頭別名對應 → 逐列 Grading::grade() → 摘要（不開交易）
│   ├── Thread.php             # 討論串：學員 / 管理員讀（先標對方訊息已讀）與發；ownership 與 body 驗證
│   ├── Reporting.php          # 統計（Stats / 有繳交紀錄的課程 / 學員背景資料）
│   ├── DueReminder.php        # 截止提醒每日排程（Action Scheduler）
│   └── MessageRepository.php  # 作業討論訊息（pc_assignment_messages）寫入 / 讀取 / 標已讀 / 列表徽章統計（thread_summary）
└── Utils/
    ├── Utils.php               # can_access_course() 觀看權閘門、附件大類判斷、狀態標籤、學員姓名快取
    ├── AssignmentException.php # 領域例外（400 / 403 / 404 / 500 → WP_Error）
    ├── Storage.php             # 受保護儲存目錄（.htaccess / web.config / index.php）
    ├── Stream.php               # 下載 streaming（權限檢查後才輸出檔案）
    ├── Clock.php                # 可測試時鐘（截止提醒 / 繳交時間）
    ├── CsvParser.php            # 匯入用嚴格 CSV 解析器（自寫：fgetcsv 對未閉合引號等不報錯）；表頭正規化 normalize_header()
    └── UploadedFile.php         # 從 REST 請求取單一上傳檔：PHP 錯誤碼對應、大小上限、is_uploaded_file()（學員附件上傳與匯入共用，訊息由呼叫端提供）
```

⚠️ `inc/classes/Resources/Teacher/Service/Assignment.php` 是「講師指派到課程」服務，命名撞了但與本
Resource 完全無關（R-01），不要混淆。

**CPT `pc_assignment`**：`post_parent` = 所屬 `pc_chapter` post ID（作業掛在章節下，本身無父子關係）；
`public` / `publicly_queryable` / `show_in_rest` 皆 `false`，避免經 `/wp/v2/pc_assignment` 外洩作業說明（DEC-05），
一律走下方 `power-course` namespace 端點。題型設定存於 postmeta：`allow_text` / `allow_file` /
`required_text` / `required_file`（yes/no）、`max_files`、`due_at`（10 位 Unix timestamp，0 = 無截止）、
`parent_course_id`。

**REST namespace**：`power-course`（無 v2）：

管理端（`Core/Api.php`，權限同既有課程管理端點）
- `GET/POST /assignments`、`PUT/DELETE /assignments/{id}`、`POST /assignments/sort`
- `GET /assignments/{id}/submissions/export`（單一作業 CSV 匯出）
- `GET /assignment-submissions`（列表）、`/stats`、`/courses`、`/student-overview`、`/export`（批改用 CSV 匯出）
- `PATCH /assignment-submissions/{id}/grade`
- `GET /assignment-submissions/{id}/messages`（讀討論串，**會把學員的未讀訊息標已讀**）、`POST /assignment-submissions/{id}/messages`（以老師身分發訊息）（第二波）
- `POST /assignment-submissions/import`（匯入批改結果 CSV，multipart，field 名 `file`）（第二波）
- `GET /assignment-attachments/{id}/download`（僅要求登入，管理員 / 學員的實際授權在 callback 內判斷）

學員端（`Core/MyApi.php`，僅要求登入）
- `GET /my/assignments`、`GET /my/assignments/{id}`
- `POST /my/assignments/{id}/submit`
- `POST /my/assignment-attachments`（附件上傳）
- `GET /my/assignment-submissions/{id}/messages`（讀自己繳交的討論串，**會把老師的未讀訊息標已讀**）、`POST /my/assignment-submissions/{id}/messages`（發訊息）（第二波）

**路由不宣告 `args`**（第二波，包含討論串 POST 與匯入）：WP REST 先驗參數（`has_valid_params()`）、後驗權限（`permission_callback`），宣告 `required` / `validate_callback` 會讓「非管理員 → 403」變成 400。body / file 的驗證一律在 callback 內、permission 之後做。

**繳交一人一作業一列**：`pc_assignment_submissions` 有 `UNIQUE KEY (assignment_id, user_id)`，
`Service/Submission::submit()` 走 UPSERT——**重新繳交是覆寫同一列**（text_body / attachments /
submitted_at），不是新增版本，且會把 `status` 重置為 `pending` 並清空 `feedback` /
`graded_by` / `graded_at`。學員無有效觀看權，或作業不存在 / 在垃圾桶，一律回同一句
「尚未報名此課程」（403），不洩漏作業是否存在（DEC-12，見 `Utils::not_enrolled_message()`）——
這點與管理端 `Service/Query.php` 對外回的 404「作業不存在」是兩個獨立的錯誤路徑，勿互相套用。

**附件受保護儲存**（`Utils/Storage`）：實體檔存 `wp_upload_dir()['basedir'] . '/power-course-private/assignments/'`
（可用常數 `PC_ASSIGNMENT_STORAGE_DIR` 或 `power_course_assignment_storage_dir` filter 搬到 web root 外），
根目錄自動寫入 `.htaccess`（Apache）/ `web.config`（IIS）/ `index.php` 擋直連與列目錄；實體檔名為 64 碼
隨機 hex、不帶副檔名。`stored_path` **一律不對外輸出**，下載一律走 `assignment-attachments/{id}/download`：
學員限本人上傳（否則 403）、管理員限附件出現在某筆繳交的 `attachments` 內（否則 404），通過後才由
`Utils/Stream` streaming 吐檔案（Nginx 不吃 `.htaccess`，不可猜的檔名是最後一道防線，R-04）。

**批改狀態機**：`pending | pass | fail`（`Service/Grading::ALLOWED_STATUSES`）。批改為 `pass` 時，若作業
所屬章節尚未完成會自動標記完成；`pass` / `fail` 都觸發 PowerEmail trigger `assignment_graded`
（action `power_course_assignment_graded`，`Trigger/AtHelper::ASSIGNMENT_GRADED`）；改回 `pending`
不寄信。兩個副作用都包在 `catch (\Throwable)` 內只記 log，不影響批改結果本身（D23）。
章節自動完成由「管理員」替學員觸發，連帶的課程完成判斷（`Chapter\Core\LifeCycle::add_chapter_finish_log` /
`add_chapter_unfinished_log`）必須以**該學員**（而非目前登入者）的進度計算——
`CourseUtils::get_course_progress( $course_id, $user_id )`，否則會拿管理員自己的進度判斷（commit 46246fa5）。

**討論串**（第二波，`Service/Thread`、`Model/Message`、`Service/MessageRepository`）：
- 檢查順序：未登入 401（學員端）→ 繳交不存在 404「繳交紀錄不存在」→ 非本人繳交 403「無權存取此繳交紀錄」（只有學員端）→
  body 400。只要求繳交存在：**不檢查課程觀看權**（被移出課程仍可讀寫）、**不檢查線性觀看**（D29）、
  **不檢查所屬作業是否在垃圾桶**（與批改的「作業 trash 視為不存在」不同）
- **GET 有副作用**：先把「對方」發的、`read_at IS NULL`、`deleted_at IS NULL` 的訊息 `read_at` 標為現在
  （`MessageRepository::mark_read`），**再**讀取，所以回應裡對方訊息的 `read_at` 已是現在；已有 `read_at` 的不覆寫；
  POST 不標任何訊息已讀。所以四個 callback 第一句都要 `\nocache_headers()`——被邊緣快取會不標已讀、讀到舊訊息。
  標已讀失敗只記 log、照常回傳列表（後果是徽章晚一點歸零）
- **`mine` 依端別**：`Message::to_view( $viewer )` 只看 `sender_type` 是否等於查詢端（學員端 = `student`、管理端 = `teacher`），
  **不看 `sender_id`**。後果（R-W2-13）：多位管理員時，彼此的訊息在後台全部是 `mine = true`；學員端只看到「老師」，
  看不出是哪一位。`sender_type` 一律用 `Message::SENDER_STUDENT / SENDER_TEACHER` 常數，寫入端與批改列表「討論」徽章
  （`thread_summary()`：學員發、`read_at IS NULL`、未刪除 = 未讀）共用同一組字串，不可各寫一份
- body 必須是字串且 `mb_strlen` 介於 1–10000，否則 400「訊息內容長度必須為 1 至 10000 字」（沒帶 / null / 陣列 / 數字同訊息）。
  **後端不 trim、純空白合法**，原樣儲存、不 sanitize（輸出端負責跳脫）；前後台 UI 在送出前 trim、純空白不送，那只是 UI 行為
- 回應欄位白名單 `id / sender_type / body / read_at / created_at / mine`，永不輸出 `sender_id` / `sender_email`
  （讀取的 SELECT 欄位清單根本不含）；老師訊息會把管理員 email 快照寫進 `sender_email`（只存不輸出）。成功一律 200
- **發訊息不觸發任何通知**：不寄信、不寫學員日誌、沒有 PowerEmail trigger。本期沒有刪除 / 編輯訊息的 API，
  讀取、標已讀、統計一律排除 `deleted_at IS NOT NULL`；學員重新繳交不影響討論串（UPSERT 保留同一列 id）

**匯入批改結果 CSV**（第二波，`Service/CsvImport`、`Utils/CsvParser`、`Utils/UploadedFile`）：
- **上傳層**（callback 開頭 `wc_set_time_limit( 0 )`）檢查順序：沒有檔案 → 400「請選擇要匯入的 CSV 檔案」→
  PHP 上傳錯誤碼（INI_SIZE / FORM_SIZE）或 body 超過 `post_max_size` → 400「檔案過大（上限 10MB）」→
  `size > 10485760` → 同訊息 → `is_uploaded_file()`（失敗視為沒有檔案）→ 讀檔（失敗 500）。不驗副檔名 / MIME，
  內容不合法自然落入 row 0。`MyApi::translate_upload_error()` 必須維持 public static（第一波測試直接呼叫）
- **依表頭名稱定位欄位，不依位置**。表頭正規化：去 BOM → trim → 小寫 → 連續空白（含全形）換成 `_`。別名（依序取第一個「非空」的值）：
  id = `submission_id`、`submissionId`；狀態 = `狀態(可改 pending/pass/fail)`、`狀態(可改)`、`狀態`、`status`、
  英文 msgid `Status (editable: pending/pass/fail)` 與它在目前請求語系的譯文；回饋 = `講師回饋(可改)`、`講師回饋`、
  `Feedback (editable)` 與它的譯文。匯出與匯入共用 `Csv::grading_headers()`，兩邊表頭不會漂移。
  限制：匯出後站台才改語系，再匯入時中英文以外的譯文表頭會辨識不到（中文別名與英文 msgid 永遠可用）。
  「目前狀態」欄（翻譯標籤）不是別名，不會被誤用
- 狀態值（trim + 小寫後比對）：`pending / 待批改`、`pass / passed / 通過`、`fail / failed / 未通過 / 不通過`；
  其餘 → 該列 **skipped**「狀態無法辨識（原值），需為 pending/pass/fail」；缺 `submission_id` → skipped「缺少 submission_id」；
  `submission_id` 不是正整數、繳交不存在、作業在垃圾桶 → **failed**「繳交紀錄不存在」
- **逐列呼叫 `Grading::grade()`，不開交易**（禁止 `START TRANSACTION`：測試環境會提前提交 PHPUnit 的測試交易）。
  單列失敗不影響其他列；同一 `submission_id` 出現多列 → 依序套用、最後一列為準、每列各自觸發副作用；
  `AssignmentException` → failed + 例外訊息，其他 `Throwable` → failed + 通用訊息（msgid `Failed to save grading result`，不外洩 SQL）並記 log
- **整份不處理**（HTTP 200、四個計數皆 0、`errors = [{ row: 0, message }]`）：非 UTF-8（Big5 / UTF-16）、引號未閉合、
  閉合引號後接殘字、未加引號的欄位出現引號 →「CSV 解析失敗」；空檔 →「CSV 檔案為空」；只有表頭 →「CSV 沒有任何資料列」；
  資料列超過 5000（不含表頭與全空列）→「單批匯入上限 5000 筆，請拆分後重試（目前 N 筆）」。為何自寫解析器：
  `fgetcsv` / `str_getcsv` 對未閉合引號等都不報錯，會靜默吞到檔尾。列號 = 非空資料列序號 + 2（第一筆資料為第 2 列，全空列不計）
- 回應 `{ total, updated, skipped, failed, errors: [{ row, message }] }`，errors 不截斷（UI 只顯示前 20 筆）
- **重匯的後果（用戶裁決 UC-W2-2 = A：整份重匯不防、不加提醒文字，維持現行行為）**：匯入的每一列都是一次完整批改，所以
  1. `graded_at` / `graded_by` 每次匯入都會更新；
  2. pass / fail 列**每次匯入都再寄一次批改通知**（identifier 含新的 `graded_at`），整份重匯 = 已批改學員再收一次信；
  3. pending 列也會寫入 `graded_at`，被算進「平均批改時間」；
  4. CSV **沒有回饋欄**（或回饋欄空白）→ 該列評語被清空
- **批改頁預設篩選為「待批改」**（對齊參考實作 `SubmissionsManager.tsx:513`；commit ebeda1b9）：「匯出批改用 CSV」沿用目前篩選，
  所以預設匯出只含待批改列，整份匯出再匯回不會碰到已批改學員——這是上面「不防重匯」成立的前提。
  不可把預設改回「全部」，否則老師匯出 → 填寫 → 匯回會讓所有已批改學員再收一次通知。
  待批改為 0 筆時，「匯出單一作業 CSV」的作業選單為空（選項取自目前列表），切到「全部」即可

**截止提醒**：`Service/DueReminder` 以 Action Scheduler daily recurring action
（`SCAN_HOOK = pc_assignment_due_soon_scan`，站台時區每日 09:00 首次執行）掃描，lead time 固定
172800 秒（48 小時），命中觸發 PowerEmail trigger `assignment_due_soon`
（`Trigger/AtHelper::ASSIGNMENT_DUE_SOON`）。掛載時機同 AccessPass 的 `ExpiringNotifier`：
排程註冊掛 `init`，不在建構子直接排（建構時 Action Scheduler 未必已載入）。

**章節生命週期連動**（`Core/LifeCycle.php`，DEC-06）：
- 章節 `trash`：其 `publish` 作業一併 `trash`（繳交 / 討論訊息保留）
- 章節自垃圾桶還原：其 `trash` 作業還原為 `publish`
- 章節 `before_delete_post`（永久刪除）：強制刪除該章節所有作業（任何狀態），避免孤兒作業（R-11）
- 作業 `before_delete_post`：刪除其討論訊息與繳交；附件列與實體檔案保留
連動失敗只記 log，不擋章節 / 作業本身的操作。

**DB 版本**：三張作業表併入既有 `pc_db_version`（目前 `1.2.0`），由 `AbstractTable::maybe_upgrade()`
於 `plugins_loaded` **priority 20** 補建——`Loader` 自己在 `plugins_loaded:10` 期間被建構，掛回 10
會靜默失效（同 AccessPass 的理由）；另因學員端 REST 請求不會觸發 `admin_init`，`maybe_upgrade()`
才需要額外掛一次 `plugins_loaded`。

**UI 顯示用語**「作業」「作業批改」；程式碼內部代號保留 Assignment。

## 安全規範

- **輸入清理**: 所有使用者輸入必須使用 `sanitize_text_field()`, `sanitize_email()`, `absint()` 等
- **輸出跳脫**: 使用 `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- **SQL 查詢**: 禁止直接拼接 SQL，必須使用 `$wpdb->prepare()` 或 `WP_Query`
- **Nonce 驗證**: REST API 端點透過 WordPress REST API 內建的 cookie nonce 驗證
- **權限檢查**: API 端點預設需要 `manage_woocommerce` capability；`users/{id}` 系列端點（GET/POST/reset-password/orders-summary）改用 `edit_users` capability

## License Check（LC）旗標禁令

**`plugin.php` 的 `init()` 參數絕對禁止出現 `'lc' => false`（或任何 `'lc' =>` 形式）。**

`vendor/j7-dev/wp-plugin-trait` 的 `PluginTrait::set_lc()` 會依此旗標決定 `self::$need_lc`：

```php
if (isset($args['lc'])) {
    $fa            = in_array($args['lc'], [ 'ZmFsc2', false ], true);
    self::$need_lc = $in ? $args['lc'] : !$fa;   // false → 授權驗證整條關閉
}
```

`$need_lc = false` 會使外掛跳過授權驗證、後台選單不再 redirect 到 Powerhouse LC 頁面。此旗標僅供測試環境臨時使用，**不得進入版本控制**。

### 測試環境要跳過授權時的正確做法

| 場景 | 手段 | 還原機制 |
|------|------|---------|
| 本地 E2E | `tests/e2e/helpers/lc-bypass.ts` 的 `applyLcBypass()` | global-teardown 呼叫 `revertLcBypass()`，備份檔 `plugin.php.e2e-backup`；併發鎖 `plugin.php.e2e-lock` 決定誰負責還原（見下方「併發時不要手動清」） |
| CI 驗收 | `.github/workflows/pipe.yml` 於 runner 臨時注入 | 驗收步驟後 `git checkout -- plugin.php`（`if: always()`） |

兩者皆**只在 runner / 本機工作區生效，不得 commit**。

### 防線

`.github/workflows/lc-guard.yml` 於每次 push / PR 檢查 `plugin.php` 是否含 `'lc' =>` 與是否殘留 `plugin.php.e2e-backup`，命中即 fail。

### 已知污染路徑

此旗標曾因 CI 注入後未還原，被後續 commit 一併帶入版本控制。故 workflow 的注入步驟必須成對搭配 `if: always()` 的還原步驟，且 `lc-guard` 為最終攔截。

### 併發時不要手動清（2026-09-22 新增）

`applyLcBypass()` 會把自己的 process PID 寫進 **`plugin.php.e2e-lock`**，
**只有最後一個離開的 run 才還原** `plugin.php`。沒有它的話，同時跑兩個 playwright
會互相拆台：後啟動的 run 看到 `'lc'` 已注入就跳過（也不會留備份），先結束的 run
卻把 `plugin.php` 還原回去，後者剩下的測試全部撞上 License Check——而且會紅得像
「功能不存在」，正是紅燈階段最難判讀的假紅。

因此**判斷是不是殘留，要先看鎖，不要只看 `plugin.php`**：

| 你看到 | 代表 | 該做什麼 |
|---|---|---|
| `'lc' => false` 存在、`.e2e-lock` 裡有**活著**的 PID | 有別的 run 正在跑 | **什麼都不要做**。不是殘留 |
| `.e2e-lock` 裡的 PID 都已死 | 上一輪崩潰留下的 | 下次 `applyLcBypass()` 會自己用 `process.kill(pid, 0)` 剔除，不需人工清 |
| `.e2e-lock` 不存在、但 `'lc'` 或 `.e2e-backup` 還在 | 真的殘留 | 跑 `revertLcBypass()`（見 `tests/e2e/README.md`） |

確認鎖裡的 PID 是否還活著：`cat plugin.php.e2e-lock`，再 `tasklist //FI "PID eq <PID>"`（Windows）。

**測試中斷導致本機殘留時**：先照上表確認真的沒有 run 在跑，再跑 `revertLcBypass()`。
**不要用 `git checkout -- plugin.php`**——它會把別的 run 正在使用的 bypass 一起砍掉，
且若 `plugin.php` 另有未提交的正當改動也會一併遺失。

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
