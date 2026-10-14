# 實作計劃：Issue #131 作業與作業批改（第一波）

> 分支：`feat/issue-131-assignment-grading`｜規格 commit：`2397234f`｜產出日期：2026-09-17
> 規劃者：`@zenbu-powers:planner` → 交接 `@zenbu-powers:tdd-coordinator`
> 範圍依據：`specs/plan/issue-131-assignment-grading/execution-plan.md`（本檔只涵蓋**第一波**）
> 業務決策：`specs/clarify/2026-09-17-1415-issue131-assignment-grading.md`（D1–D29，本計劃**不得推翻**）

---

## 0. 範圍模式與規模判定

| 項目 | 判定 |
|------|------|
| 範圍模式 | **HOLD SCOPE**。規格已定稿（便條紙 0），業務行為一律照 zenbu-site-2.0，本計劃只決定「怎麼做」，不增減「做什麼」 |
| 預估影響檔案 | 約 95 個（後端 PHP 約 30、PHPUnit 約 25、Admin SPA 約 20、教室模板 + TS 約 10、E2E 4、i18n 7、文件 3） |
| >30 檔案的處理 | 第一波範圍是用戶在 clarify Q3 親自定的（「規格全留，分兩波實作：第一波 = 核心 + 截止提醒 + CSV 匯出」），所以不再回頭問要不要縮減。改用 **9 個可獨立合併的階段 + 每階段驗證關卡** 控制風險 |
| 模式漂移防線 | 若實作中發現需要新增 feature 未涵蓋的業務行為，一律停下回報，不得自行補規則 |

---

## 1. 概述

在 Power Course 新增「章節作業」：管理員在章節下出題（CPT `pc_assignment`），學員在教室上傳附件並繳交（每人每份一筆、重交覆寫），管理員在跨課程的批改頁批改（pass / fail / pending + 評語）。批改 pass 時自動完成章節，pass / fail 時寄 PowerEmail 通知；每日 09:00 寄截止提醒；提供兩種 CSV 匯出。附件存受保護目錄，伺服器驗權後串流下載。

## 2. 需求重述

- **服務對象**：管理員（`manage_options || manage_woocommerce`）出題、批改；學員（對課程有有效觀看權）繳交；Action Scheduler 寄截止提醒。講師不可批改。
- **成功的樣子**：第一波 17 支 feature 與 4 支既有 feature 的追加 Rule，每個 Example 都有會真的執行、而且綠燈的自動化測試（PHPUnit 為主，UI / 真實 HTTP 行為用 Playwright）；後台兩個畫面、教室作業區塊可實際操作；PHPUnit 全綠、PHPStan 錯誤數 ≤ 96、i18n 沒有新增未翻譯字串。
- **第二波（不在本計劃）**：`查詢作業討論串`、`發送作業討論訊息`、`匯入批改結果CSV`。但 `pc_assignment_messages` 表在第一波一起建，讓 DB 版本只升一次。

---

## 3. 第一波 feature 對照總表

| # | Feature（`specs/features/…`） | 階段 | 測試類型 | 測試檔（`tests/Integration/Assignment/…` 或 `tests/e2e/…`） |
|---|------------------------------|------|---------|------------------------------------------------------------|
| 1 | assignment/建立作業 | P2 | PHPUnit | `CreateAssignmentTest.php` |
| 2 | assignment/更新作業 | P2 | PHPUnit | `UpdateAssignmentTest.php` |
| 3 | assignment/刪除作業 | P2 | PHPUnit | `DeleteAssignmentTest.php` |
| 4 | assignment/排序作業 | P2 | PHPUnit | `SortAssignmentsTest.php` |
| 5 | assignment/查詢章節作業列表 | P2 | PHPUnit | `QueryAssignmentsTest.php` |
| 6 | chapter/刪除章節（+3 Rule） | P2 | PHPUnit | `ChapterAssignmentCascadeTest.php` |
| 7 | course/複製課程（+1 Rule） | P2 | PHPUnit | `CourseDuplicateExcludesAssignmentsTest.php` |
| 8 | assignment/上傳作業附件 | P3 | PHPUnit + E2E（真實 multipart） | `UploadAssignmentAttachmentTest.php`、`03-integration/016-assignment-http-contract.spec.ts` |
| 9 | assignment/下載作業附件 | P3 | PHPUnit + E2E（真實標頭 / 串流） | `DownloadAssignmentAttachmentTest.php`、`016-assignment-http-contract.spec.ts` |
| 10 | assignment/繳交作業 | P3 | PHPUnit | `SubmitAssignmentTest.php` |
| 11 | assignment/查詢學員作業 | P3 | PHPUnit | `QueryMyAssignmentsTest.php` |
| 12 | student/移除學員課程權限（+1 Rule） | P3 | PHPUnit | `RemoveStudentKeepsSubmissionsTest.php` |
| 13 | assignment/批改作業（狀態、章節完成、副作用隔離） | P4 | PHPUnit | `GradeAssignmentSubmissionTest.php` |
| 14 | assignment/查詢作業繳交列表 | P4 | PHPUnit | `ListAssignmentSubmissionsTest.php` |
| 15 | assignment/查詢作業批改統計 | P4 | PHPUnit | `AssignmentSubmissionStatsTest.php` |
| 16 | assignment/查詢有繳交紀錄的課程 | P4 | PHPUnit | `CoursesWithSubmissionsTest.php` |
| 17 | assignment/查詢作業學員背景資料 | P4 | PHPUnit | `AssignmentStudentOverviewTest.php` |
| 18 | assignment/匯出單一作業繳交CSV | P4 | PHPUnit + E2E（BOM / 檔名標頭） | `ExportAssignmentSubmissionsCsvTest.php`、`016-assignment-http-contract.spec.ts` |
| 19 | assignment/匯出批改用CSV | P4 | PHPUnit + E2E | `ExportGradingCsvTest.php`、`016-assignment-http-contract.spec.ts` |
| 20 | assignment/批改作業（寄信的 3 條 Rule） | P5 | PHPUnit | `AssignmentGradedEmailTest.php` |
| 21 | email/觸發自動郵件（+2 Rule） | P5 / P6 | PHPUnit | `AssignmentEmailReplaceTest.php`（graded）、`AssignmentDueReminderTest.php`（due_soon） |
| 22 | assignment/作業截止提醒 | P6 | PHPUnit | `AssignmentDueReminderTest.php` |
| 23 | ui/章節作業管理 | P7 | E2E | `01-admin/assignment-chapter-manage.spec.ts` |
| 24 | ui/作業批改頁 | P7 | E2E | `01-admin/assignment-grading.spec.ts` |
| 25 | ui/教室作業區塊 | P8 | E2E | `02-frontend/021-classroom-assignments.spec.ts` |

跨階段共用：`AssignmentApiContractTest.php`（路由已註冊、每個 callback 第一個語句是 `\nocache_headers()`、非管理員 403 / 未登入 401 矩陣），每階段新增端點時一併擴充。

---

## 4. Planner 技術決策（DEC）

以下都**不改變業務行為**，是規格沒寫到、實作必須選一個的技術做法。每條都可推翻，推翻時只影響本計劃對應步驟。

| # | 決策 | 理由 |
|---|------|------|
| DEC-01 | **錯誤文案與 i18n**：feature 的「權限不足」「必須登入」視為語意，對應 403 `rest_forbidden` / 401 `rest_not_logged_in`，沿用既有字串（`Sorry, you are not allowed to do that.`、`You are not currently logged in.`）；線性觀看鎖定沿用既有 `This chapter is not yet unlocked. Please complete the previous chapters first.`（D27）。其餘新錯誤訊息的 zh_TW 譯文**一字不差**等於 feature 文字（對照見 §4.1） | PHPUnit bootstrap 強制 zh_TW 並載入 `.mo`，斷言用譯文；權限不足是 WP core 字串（core 翻譯不在測試環境），只能斷言 status + code |
| DEC-02 | **時鐘接縫**：`Resources\Assignment\Utils\Clock::now(): int` = `(int) apply_filters( 'power_course_assignment_now', time() )`，作業模組內所有「現在」都走它；測試以 filter 注入「現在時間為」 | PHP 無法 mock `time()`；filter 在 `tear_down` 移除，不留 static 狀態 |
| DEC-03 | **時間欄位**：自訂表的 datetime 欄位存**站台時區**字串（`wp_date( 'Y-m-d H:i:s', Clock::now() )`，與 `pc_avl_chaptermeta.finished_at` 一致）；API 原樣回傳；CSV 以 `get_gmt_from_date()` 轉成 `Y-m-d\TH:i:s.000\Z`；`due_at` 為 10 位 timestamp（D4） | feature 的 `2026-09-16 09:00:00` 在 CSV 變成 `2026-09-16T01:00:00.000Z`，證明是站台時區（Asia/Taipei） |
| DEC-04 | **DB 升級**：`AbstractTable::CURRENT_DB_VERSION` 1.1.0 → **1.2.0**；`install()` 與 `Plugin::activate()` 各補 3 個 `create_assignment_*_table()`；另在 `Assignment\Core\Loader` 以 `plugins_loaded` **priority 20** 掛 `AbstractTable::maybe_upgrade`（沿用 AccessPass Loader 的理由），不只靠 `admin_init` | 學員端 REST 請求不會觸發 `admin_init` |
| DEC-05 | **CPT `pc_assignment`**：`public=false`、`publicly_queryable=false`、`exclude_from_search=true`、`hierarchical=false`、**`show_in_rest=false`**、`show_ui=Plugin::$is_local`、`show_in_menu=false`、`supports=[title, editor, custom-fields, page-attributes]` | 作業說明是付費內容，`show_in_rest=true` 會經 `/wp/v2/pc_assignment` 外洩 |
| DEC-06 | **章節連動**：`transition_post_status`（僅 `pc_chapter`）處理 trash / 還原兩個方向；`before_delete_post`（`pc_chapter`）強制刪除其作業；`before_delete_post`（`pc_assignment`）刪繳交與訊息；`wp_untrash_post_status` filter 讓 `pc_assignment` 還原為 `publish` | 課程還原走 `wp_update_post` 把章節改回 publish，不會觸發 `untrash_post`；WP 5.6+ 還原預設是 draft；`wp_delete_post` 不會處理不同 post_type 的子文章 |
| DEC-07 | 第一波就建 `pc_assignment_messages` 表，`查詢作業繳交列表` 的 `unread_count / message_count / last_message_at` **直接從表計算**（第二波上線前表是空的，所以實際回應就是 0 / 0 / null） | 與 execution-plan「固定回傳 0/0/null」在正式環境的回應完全相同，而且 feature 的 Example（直接寫入訊息）可以綠燈，不必 skip |
| DEC-08 | **串流輸出**：下載與 CSV 的 callback 回傳帶標頭的 `WP_REST_Response`（`Content-Type`、`Content-Disposition: attachment; filename*=UTF-8''…`、`X-Content-Type-Options: nosniff`），並登記一次性的 `rest_pre_serve_request` filter 負責 `readfile` / 輸出 stream。PHPUnit 以 `rest_get_server()->dispatch()` 拿到 response 後斷言，不會真的輸出 | 在 callback 內 `exit` 會殺掉 PHPUnit；檔案最大 50MB，不能讀進記憶體再 JSON 回傳 |
| DEC-09 | **受保護目錄**：預設 `wp_upload_dir()['basedir'] . '/power-course-private/assignments/{Y}/{m}/'`，檔名為 64 位隨機 hex、**不帶副檔名**；目錄建立時寫入 `.htaccess`（Apache 2.2 + 2.4 兩種語法）、`index.php`、`web.config`；可用常數 `PC_ASSIGNMENT_STORAGE_DIR` 或 filter `power_course_assignment_storage_dir` 改到 web root 外；API 永不回傳 `stored_path` 或 URL | Nginx 不吃 `.htaccess`（本機 turbo 站就是 Nginx），所以不可猜的檔名是最後一道防線 |
| DEC-10 | **PowerEmail**：沿用 `At` 的 Action Scheduler 非同步排信；作業兩種 trigger **立即寄送**（不套模板的延遲設定，同 `access_pass_expiring`）；模板的 `condition.course_ids` 有值時只寄該課程（空 = 全部，同 UI 的「留空 = 所有課程」）；identifier ids：graded = `[submission_id, graded_at 的 timestamp]`、due_soon = `[assignment_id, due_at]`；`pc_email_records.post_id` = 課程 ID | feature 寫明 due_soon 紀錄的 post_id 為課程 100；graded_at 進 identifier 才能「每次批改各寄一次」 |
| DEC-11 | **Email 模板 UI** 補兩個 trigger 選項與作業變數提示；選這兩個 trigger 時隱藏章節選擇、觸發條件、延遲寄送 | 站長必須能建模板，否則寄信功能等於不存在（execution-plan 寫「需站長建立模板」） |
| DEC-12 | 學員端作業不存在或在垃圾桶一律 **403「尚未報名此課程」**（依 feature）；`erm.dbml` Submit Note 第 2 點與 `系統抽象.md` Violation 表寫 404 的地方以 feature 為準 | feature 是測試依據，且 api.yml 也是 403 |
| DEC-13 | **排序 tie-break**：繳交列表 `submitted_at DESC, id DESC`；作業 `menu_order ASC, post_date ASC, ID ASC`；依課程查作業時先依 `ChapterUtils::get_flatten_post_ids()` 的位置排，不在攤平清單中的章節（非 publish）排在最後 | 結果必須穩定，測試才不會 flaky |
| DEC-14 | 背景資料的「本課程作業統計」與列表 / 統計一致，**不計入垃圾桶中作業的繳交** | 管理端所有查詢都排除 trash |
| DEC-15 | **教室作業區塊**：PHP 伺服器端渲染（跳脫依 i18n rule）+ vanilla TS 處理上傳 / 繳交 / 局部更新；放在 `body.php` 的 `the_content()` 之後、留言之前；課程作業總覽放在區塊頂部，可收合；使用者輸入一律用 `textContent`，不用 `innerHTML` | 參照既有教室結構；避免 XSS |
| DEC-16 | **MIME 判定**：依副檔名取得標準 MIME（`wp_check_filetype`），必須在白名單內；有 `finfo` 時再交叉比對（允許已知別名，例如 csv → text/plain）；DB 存標準 MIME。Service 介面收 `(tmp_path, original_name, mime, size)`，API 層負責 `is_uploaded_file()` | 瀏覽器送的 MIME 不可信；Service 保持可測 |
| DEC-17 | PHP `upload_max_filesize` / `post_max_size` 擋下檔案時（`UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE`，或 `$_FILES` 為空但 `CONTENT_LENGTH` > `post_max_size`）回「檔案過大（上限 50MB）」，不回「請選擇要上傳的檔案」 | 主機常見限制 2MB–8MB，否則錯誤訊息會誤導學員 |
| DEC-18 | 建立 / 更新作業時的「章節存在」= post_type 是 `pc_chapter` **且不在垃圾桶**，而且 `ChapterUtils::get_course_id()` 能解析出課程；否則「章節不存在」 | 參考實作是硬刪 FK，PC 的垃圾桶等同刪除 |

### 4.1 建議的新 msgid（實作前先 grep `scripts/i18n-translations/manual.json` 去重；msgid 已存在但譯文不同時改用 `_x( …, 'assignment', 'power-course' )`）

| zh_TW（= feature 文字） | 建議 msgid |
|------------------------|-----------|
| 章節不存在 | Chapter not found |
| chapter_id 為必填 | chapter_id is required |
| title 長度必須為 1 至 200 字 | title must be between 1 and 200 characters |
| instructions 不可超過 20000 字 | instructions must not exceed 20000 characters |
| 至少需允許文字或檔案其中一種繳交方式 | At least one submission type (text or file) must be allowed |
| max_files 必須為 1 至 20 的整數 | max_files must be an integer between 1 and 20 |
| due_at 必須為 0 或 10 位 Unix timestamp | due_at must be 0 or a 10-digit Unix timestamp |
| menu_order 必須為大於等於 0 的整數 | menu_order must be an integer greater than or equal to 0 |
| 作業不存在 | Assignment not found |
| 部分作業不存在 | Some assignments do not exist |
| 作業不屬於指定的章節 | Assignments do not belong to the specified chapter |
| ids 最多 200 筆 | ids must not exceed 200 items |
| chapter_id 或 course_id 至少需指定一個 | Either chapter_id or course_id is required |
| 尚未報名此課程 | You are not enrolled in this course |
| 請至少填寫文字或附件 | Please provide text or attachments |
| 此作業不允許文字繳交 | This assignment does not accept text submissions |
| 此作業不允許檔案繳交 | This assignment does not accept file submissions |
| 此作業需填寫文字內容 | This assignment requires text content |
| 此作業需上傳檔案 | This assignment requires a file upload |
| 附件數量超過上限（最多 %d 個） | Too many attachments (maximum %d)（`_n` 單複數） |
| 文字內容不可超過 50000 字 | Text content must not exceed 50000 characters |
| 附件不存在或無權使用 | Attachment does not exist or cannot be used |
| 請選擇要上傳的檔案 | Please select a file to upload |
| 檔案過大（上限 50MB） | File too large (max 50MB) |
| 不支援的檔案類型：%s | Unsupported file type: %s |
| 附件不存在 | Attachment not found |
| 無權存取此附件 | You do not have permission to access this attachment |
| 找不到對應的作業附件 | No matching assignment attachment found |
| 繳交紀錄不存在 | Submission not found |
| status 必須為 pending、pass、fail 之一 | status must be one of pending, pass, fail |
| feedback 不可超過 10000 字 | feedback must not exceed 10000 characters |
| user_id 為必填 | user_id is required |
| 待批改 / 通過 / 未通過 | `_x( 'Pending' / 'Passed' / 'Failed', 'assignment submission status' )` |
| CSV 標頭（繳交時間、學員 Email、學員姓名、狀態、文字內容、附件數、附件清單、批改時間、講師回饋、作業、目前狀態、狀態(可改 pending/pass/fail)、講師回饋(可改)） | 各自英文 msgid，一律加 context `'assignment csv'` |

---

## 5. 架構變更

### 5.1 後端（PHP）

**新增** `inc/classes/Resources/Assignment/`（命名空間 `J7\PowerCourse\Resources\Assignment`）

| 檔案 | 職責 | 階段 |
|------|------|------|
| `Core/Loader.php` | 初始化 CPT / Api / MyApi / LifeCycle / DueReminder；`plugins_loaded:20` 掛 `AbstractTable::maybe_upgrade` | P2（P3、P6 擴充） |
| `Core/CPT.php` | 註冊 `pc_assignment`（DEC-05），`POST_TYPE` 常數 | P2 |
| `Core/Api.php` | 管理端端點（預設權限 `manage_options \|\| manage_woocommerce`）＋下載端點（登入即可，callback 內分管理員 / 學員） | P2 / P3 / P4 |
| `Core/MyApi.php` | 學員端端點（`my/*`，permission_callback = 已登入，否則 401） | P3 |
| `Core/LifeCycle.php` | DEC-06 的 hook | P2 |
| `Model/Assignment.php` | 作業 DTO（post + meta → typed properties、`to_array()` 對齊 api.yml `Assignment`） | P2 |
| `Model/Submission.php` | 繳交列 DTO（`attachments` JSON decode 成 `[{attachment_id, file_name}]`） | P3 |
| `Model/Attachment.php` | 附件列 DTO（不含 `stored_path` 的輸出） | P3 |
| `Service/Crud.php` | 建立 / 更新 / 刪除（移至垃圾桶）/ 排序；共用驗證 `validate_fields()` | P2 |
| `Service/Query.php` | 管理端作業列表（chapter / course）與單筆 | P2 |
| `Service/MyQuery.php` | 學員端作業列表與單筆（含 `my_submission`） | P3 |
| `Service/Attachment.php` | 上傳存檔、下載驗權（學員 = 上傳者；管理員 = 出現在任一繳交） | P3 |
| `Service/Submission.php` | 繳交 UPSERT（驗證順序見 §6.1） | P3 |
| `Service/Grading.php` | 批改、pass → 完成章節、觸發 `power_course_assignment_graded`，副作用 try/catch | P4 |
| `Service/Reporting.php` | 繳交列表、統計、有繳交的課程、學員背景資料 | P4 |
| `Service/Csv.php` | 兩種 CSV 寫入 `php://temp` stream（BOM、CRLF、跳脫、檔名） | P4 |
| `Service/DueReminder.php` | 每日掃描排程與收件人計算 | P6 |
| `Service/SubmissionRepository.php` | `pc_assignment_submissions` CRUD（`INSERT … ON DUPLICATE KEY UPDATE`、`delete_by_assignment`、依附件 ID 搜尋） | P2（刪除）/ P3 |
| `Service/AttachmentRepository.php` | `pc_assignment_attachments` CRUD | P3 |
| `Service/MessageRepository.php` | `pc_assignment_messages`：第一波只有「依繳交刪除」與「未讀數 / 總數 / 最後時間」 | P2 / P4 |
| `Utils/Utils.php` | 課程觀看權閘門 `can_access_course()`、附件大類 `resolve_attachment_kind()`、狀態標籤 | P3 / P4 |
| `Utils/Clock.php` | DEC-02 | P2 |
| `Utils/Storage.php` | DEC-09 的目錄、保護檔、隨機檔名、路徑解析（防 path traversal） | P3 |
| `Utils/Stream.php` | DEC-08 的一次性 `rest_pre_serve_request` 輸出器 | P3 |
| `Utils/AssignmentException.php` | 帶 HTTP status 與 error code 的領域例外，Api 層轉成 `WP_Error` | P2 |

**修改**

| 檔案 | 變更 | 階段 |
|------|------|------|
| `plugin.php` | 新增 `ASSIGNMENT_SUBMISSIONS_TABLE_NAME`、`ASSIGNMENT_ATTACHMENTS_TABLE_NAME`、`ASSIGNMENT_MESSAGES_TABLE_NAME` 常數；`activate()` 補建 3 張表（**不得**碰 `init()` 的 `lc` 參數） | P1 |
| `inc/classes/AbstractTable.php` | 3 個 `create_*_table()`、`install()` 補呼叫、`CURRENT_DB_VERSION` → 1.2.0 | P1 |
| `inc/classes/Resources/Loader.php` | `Assignment\Core\Loader::instance()` | P2 |
| `inc/classes/PowerEmail/Resources/Email/Trigger/AtHelper.php` | `ASSIGNMENT_GRADED`、`ASSIGNMENT_DUE_SOON` 常數、`$allowed_slugs`、label | P5 |
| `inc/classes/PowerEmail/Resources/Email/Trigger/At.php` | 監聽兩個作業 action → 排信；AS callback | P5 |
| `inc/classes/PowerEmail/Resources/Email/Trigger/Condition.php` | 作業 trigger 不走「撈全站章節」分支（`required_ids = course_ids`）；新增 `get_course_ids()` getter | P5 |
| `inc/classes/PowerEmail/Resources/Email/CPT.php` | `SUPPORTED_TRIGGER_SLUGS` +2 | P5 |
| `inc/classes/PowerEmail/Resources/Email/Email.php` | `send_assignment_email()`（專屬 filter、EmailRecord） | P5 |
| `inc/classes/PowerEmail/Resources/Email/Replace/Assignment.php`（新增） | `{assignment_title}` `{assignment_status}` `{assignment_feedback}` `{assignment_due_at}` | P5 |
| `inc/classes/PowerEmail/Resources/Email/Api.php` | 變數提示回應加 `assignment_schema` | P5 |
| `inc/templates/pages/classroom/body.php` | 插入作業區塊 | P8 |
| `tests/bootstrap.php` | 補建 3 張表（既有 6 張表在這裡建成真實表） | P1 |
| `tests/Integration/TestCase.php` | `ensure_tables_exist()` 與 `clean_custom_tables()` 補 3 張表 | P1 |
| `tests/Integration/Email/SendNowTest.php` | slug 數 10 → 12（**刻意**更新，commit 訊息註明） | P5 |

### 5.2 前端

| 檔案 | 變更 | 階段 |
|------|------|------|
| `js/src/resources/index.tsx`、`js/src/App1.tsx` | 新增 `assignments` resource 與路由（選單名「作業批改」） | P7 |
| `js/src/pages/admin/Assignments/`（`index.tsx`、`components/{CourseTabs,StatsCards,SubmissionTable,SubmissionDetail,GradeForm,StudentOverview,ExportButtons}`、`hooks/`、`types/`） | 作業批改頁 | P7 |
| `js/src/components/chapters/Assignments/`（`index.tsx`、`AssignmentList`、`AssignmentFormModal`、`hooks/`、`types/`） | 章節作業管理，嵌入 `components/chapters/Edit.tsx` | P7 |
| `js/src/components/emails/SendCondition/{enum.ts,Condition.tsx}` ＋ 變數提示元件 | DEC-11 | P7 |
| `inc/templates/components/assignment/{section.php,card.php,overview.php}` | 教室作業區塊模板 | P8 |
| `inc/assets/src/events/assignments/{index.ts,upload.ts,submit.ts,render.ts}`、`events/index.ts`、`main.ts` | 教室互動 | P8 |

### 5.3 i18n 與文件

- `scripts/i18n-translations/manual.json`（每筆要有 `msgstr_zh_TW` **與** `msgstr_ja`，`build-po.mjs` 的 `LOCALES = ['zh_TW', 'ja']`）
- `languages/` 共 7 檔：`power-course.pot`、`power-course-{zh_TW,ja}.{po,mo,json}`
- `.claude/rules/i18n.rule.md` 術語表（P2 開工前先補：作業 Assignment、作業批改 Assignment grading、繳交 Submission / Submit、重新繳交 Resubmit、評語 Feedback、截止時間 Due date、已逾期 Overdue、附件 Attachment、通過率 Pass rate、平均批改時間 Average grading time）
- P9：`.claude/CLAUDE.md`（「6 張表」→ 9 張、Assignment Resource）、`.claude/rules/wordpress.rule.md`（自訂資料表、Assignment Resource、受保護目錄的 Nginx 設定範例）

---

## 6. 資料流分析

### 6.1 繳交作業 `POST /my/assignments/{id}/submit`

```
REQUEST ──▶ AUTH ──▶ ACCESS GATE ──▶ LINEAR LOCK ──▶ SHAPE ──▶ OWNERSHIP ──▶ UPSERT ──▶ RESPONSE
   │          │            │               │            │           │            │          │
   ▼          ▼            ▼               ▼            ▼           ▼            ▼          ▼
[body 非   [未登入]   [作業不存在/     [課程開線性   [文字 trim  [attachment [併發雙擊   [回傳覆寫
 JSON?]    → 401     垃圾桶 → 403     觀看 且 非    後空且無   不存在/他人 → UNIQUE   後的列，
[ids 非    您尚未     尚未報名]       exempt 且     附件 → 400] → 400]     key 合併   不含其他
 陣列?     登入]     [!is_avl →403]  章節 locked   [allow/     [重複 ID?  成一筆]    學員資料]
 → 400]              [is_expired     → 403 既有    required    → 以去重後 [DB 錯誤
                     → 403]          鎖定訊息]     不符 → 400] 計數]      → 500 log]
                     [!course_ready
                      → 403]
```

驗證順序（對齊參考實作 `course-assignment-submissions.service.ts::submit` 與 `assertSubmissionShape`）：登入 → 作業存在且 publish（否則 403 尚未報名）→ `is_avl && !is_expired && is_course_ready` → 線性觀看 → 至少文字或附件 → `allow_text` → `allow_file` → `required_text` → `required_file` → 附件數 ≤ `max_files` → 文字 ≤ 50000 → 附件存在且 `user_id` = 本人。
UPSERT：`INSERT … ON DUPLICATE KEY UPDATE text_body, attachments, submitted_at, status='pending', feedback=NULL, graded_by=NULL, graded_at=NULL, updated_at`。`attachments` 以 `wp_json_encode` 存 `[{"attachment_id":700,"file_name":"作業截圖.png"}]`（固定鍵順序，見 R-16）。

### 6.2 附件上傳與下載

```
UPLOAD  multipart ──▶ AUTH ──▶ $_FILES 檢查 ──▶ SIZE ──▶ MIME ──▶ MOVE ──▶ INSERT ──▶ {attachment_id,file_name,mime_type,file_size}
                        │           │             │        │         │        │
                        ▼           ▼             ▼        ▼         ▼        ▼
                     [401]    [無檔案→400     [>50MB  [不在白  [目錄不可 [INSERT 失敗
                              請選擇檔案]     →400]   名單→400] 寫→500 log] →刪除已搬檔案
                              [INI_SIZE→400                    [is_uploaded_ →500]
                               檔案過大 DEC-17]                 file 失敗→400]

DOWNLOAD GET ──▶ AUTH ──▶ FIND ROW ──▶ FILE EXISTS ──▶ ROLE CHECK ─────────────▶ STREAM
                  │          │             │            │                          │
                  ▼          ▼             ▼            ▼                          ▼
               [401]    [無→404       [realpath 不在 [學員：user_id≠本人→403]   [中途斷線 →
                        附件不存在]    儲存根目錄或   [管理員：未出現在任何     readfile 結束
                                      檔案不存在     繳交→404 找不到對應]      即可，無副作用]
                                      →404]                                    [nocache、nosniff、
                                                                               attachment]
```

### 6.3 批改 `PATCH /assignment-submissions/{id}/grade`

```
REQUEST ──▶ ADMIN ──▶ FIND ──▶ VALIDATE ──▶ UPDATE ──▶ SIDE EFFECT A ─────▶ SIDE EFFECT B ─────▶ RESPONSE
   │          │         │         │            │         (pass 才做)          (pass/fail 才做)       │
   ▼          ▼         ▼         ▼            ▼            ▼                     ▼                  ▼
            [403]  [無→404 /  [status 非  [寫 status、 [is_finished?→跳過]  [do_action graded]   [回傳更新後
                   作業 trash  法→400]    feedback(未  [mark_finished      [At 排 AS 非同步]     列；副作用
                   →404 繳交   [feedback  給=NULL)、   丟 RuntimeException [try/catch Throwable  失敗不影響]
                   紀錄不存在] >10000     graded_by、  → catch Throwable,  → Plugin::logger]
                              →400]      graded_at]   Plugin::logger]
                                         [DB 失敗
                                          →500]
```

改判 fail / pending：只做 UPDATE，不呼叫 `mark_unfinished`（D21）；pending 不觸發寄信。

### 6.4 截止提醒（Action Scheduler）

```
AS 每日 09:00 ──▶ 有 publish 的 assignment_due_soon 模板? ──▶ 查作業 ──▶ 每份作業 ──────────────────▶ 每位收件人 ──▶ do_action(due_soon)
                          │                                   │          │                             │                 │
                          ▼                                   ▼          ▼                             ▼                 ▼
                     [無 → 直接結束]                    [publish 且   [!is_course_ready → 跳過]   [候選 = avl_course_ids  [At：is_sent([aid,due])
                                                        due_at ∈     [課程已刪除 → 跳過]          usermeta ∪ 權限包持有者 → 跳過；已排程 → 跳過；
                                                        [now,         [無候選 → 跳過]             → is_avl && !is_expired  否則 enqueue async]
                                                        now+172800]]                              → 扣掉已繳交者]          [寄出 → EmailRecord
                                                                                                  [使用者已刪 → 跳過]      post_id=course_id]
```

### 6.5 章節生命週期連動

```
pc_chapter  publish → trash   ──▶ 該章節 post_parent 的 publish 作業 → wp_trash_post（繳交保留）
            （子章節由 Course\LifeCycle 逐一 trash，各自再觸發本 hook）
pc_chapter  trash → 非 trash  ──▶ 該章節的 trash 作業 → publish（涵蓋 wp_untrash_post 與課程還原的 wp_update_post）
pc_chapter  before_delete_post ──▶ 該章節所有作業（任何狀態）→ wp_delete_post(force)
pc_assignment before_delete_post ──▶ DELETE messages WHERE submission_id IN (…)；DELETE submissions WHERE assignment_id；附件列與檔案保留
複製課程 ──▶ Utils\Duplicate 的 allowed_post_types 不含 pc_assignment → 不複製（回歸測試守住）
```

---

## 7. 錯誤處理登記表

| 方法 / 路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|------------|-------------|---------|---------|------------|
| 所有管理端端點 | 非管理員 | `rest_forbidden` 403 | ApiBase 預設 permission_callback | 是 |
| `my/*`、下載 | 未登入 | `rest_not_logged_in` 401 | 自訂 permission_callback | 是 |
| `Crud::create / update` | 欄位格式錯、章節不存在、作業不存在 | `AssignmentException` 400 / 404 | Api 轉 `WP_Error` | 是 |
| `Crud::create` | `wp_insert_post` 回 WP_Error | 500 | 回傳錯誤並 log | 是 |
| `Crud::update`（改掛章節） | 新章節解析不到課程 | 404 章節不存在 | DEC-18 | 是 |
| `Crud::sort` | ids 超過 200、不存在、重複、跨章節 | 400 | 先全量驗證再寫入 | 是 |
| `Crud::sort` | 寫入中途失敗 | 500 | 逐筆 `wp_update_post`（自帶 `clean_post_cache`）；失敗即中止並回錯 | 是 |
| `Submission::submit` | 見 §6.1 | 400 / 401 / 403 | 依序拋 `AssignmentException` | 是 |
| `Submission::submit` | UPSERT SQL 失敗 | 500 | `$wpdb->last_error` 寫 log，回通用錯誤 | 是（通用訊息） |
| `Attachment::store` | 目錄不可寫 / 搬檔失敗 | 500 | log + 回錯，不寫 DB 列 | 是 |
| `Attachment::store` | 檔案已搬、DB INSERT 失敗 | 500 | 刪除已搬的檔案後回錯 | 是 |
| `Attachment::download` | 列不存在 / 檔案遺失 / 路徑逃逸 | 404 | `realpath` 必須在儲存根目錄內 | 是 |
| `Attachment::download` | 權限不符 | 403 / 404 | 依角色 | 是 |
| `Stream` 輸出 | 用戶端中斷、輸出緩衝 | — | 輸出前清空 `ob_*`，`readfile` 結束即可 | 否（無副作用） |
| `Grading::grade` | 繳交不存在 / 作業 trash | 404 | 回錯 | 是 |
| `Grading::grade` | `mark_finished` 拋例外 | Throwable | catch → `Plugin::logger`，批改照樣成功（D23） | 否（記 log） |
| `Grading::grade` | graded action listener 拋例外 | Throwable | catch → log（D23） | 否（記 log） |
| `At::send_assignment_*` | `wp_mail` 失敗 | false | 不寫 EmailRecord，AS 標記完成；log | 否（記 log） |
| `Reporting::*` | 使用者 / 課程不存在 | — | 欄位回 null / 空字串，不報錯（feature 規定） | 是（空值） |
| `Csv::*` | 超過 5000 筆 | — | SQL `LIMIT 5000` | 是（只到 5000） |
| `DueReminder::run` | AS 未載入 | — | `function_exists` 守門，靜默略過 | 否 |
| `DueReminder::run` | 單一作業計算例外 | Throwable | catch → log，繼續下一份作業 | 否（記 log） |
| `AbstractTable::maybe_upgrade` | dbDelta 失敗 | Exception | 不更新版本號，下次請求重試；log | 否（記 log） |
| `LifeCycle` 連動 | trash / delete 子作業失敗 | false | log；不擋章節本身的操作 | 否（記 log） |

未發現「處理方式 = 無 且 靜默」的 CRITICAL GAP。記 log 的四條（批改副作用、寄信失敗、掃描例外、DB 升級失敗）都是規格或既有慣例明定「不影響主流程」。

---

## 8. 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|------------|---------|
| 學員雙擊「繳交」 | 兩個請求同時 UPSERT | 是（UNIQUE + ON DUPLICATE KEY） | 是（連續呼叫兩次，筆數 = 1） | 否 | 無需 |
| 管理員同一秒改判兩次 | identifier 相同只寄一封 | 接受 | 否 | 學員少收一封 | 可接受（R-23） |
| 升級站台尚未觸發 migration | 學員端請求時表不存在 | 是（DEC-04 plugins_loaded:20） | 是（MigrationTest） | 否 | 下次請求自動補建 |
| Nginx 主機 | 受保護目錄可被直連 | 部分（隨機檔名 + 常數搬目錄 + 文件） | 部分（單元測試檔名 / 保護檔；E2E 斷言 API 不洩漏路徑） | 否 | 站長設定 Nginx `deny` 或 `PC_ASSIGNMENT_STORAGE_DIR` |
| PHP 上傳上限 < 50MB | `$_FILES` 為空 | 是（DEC-17） | 是（單元：錯誤碼對應；E2E 視主機設定） | 是 | 站長調整 php.ini |
| 章節永久刪除 | 作業變孤兒 | 是（DEC-06） | 是 | 否 | 無需 |
| 課程還原 | 作業卡在垃圾桶 | 是（transition hook） | 是（補一條課程還原測試） | 否 | 無需 |
| 批改 pass 但章節已被刪 | `mark_finished` 丟 RuntimeException | 是（catch） | 是（寄信服務錯誤那條 + 章節無課程的邊界） | 否 | 無需 |
| 作業改掛到別的課程 | 舊課程的批改頁計數改變 | 接受（D15，繳交表不存 course_id） | 是（更新作業 Example） | 是 | 符合規格 |
| 5000 筆 × 50000 字文字匯出 | 記憶體爆掉 | 是（`php://temp` stream） | 是（5001 筆測試） | 否 | 無需 |
| 附件 JSON 搜尋 | LIKE 誤中 7000 ⊃ 700 | 是（pattern 含結尾逗號，R-16） | 是（700 / 7000 邊界測試） | 否 | 無需 |
| 截止提醒大站 | 候選學員數萬、掃描逾時 | 部分（無模板提早結束、單次 SQL 撈已繳交者） | 否（效能不在第一波測試） | 否 | 後續批次化（見限制） |
| 前台 `dist` 過期 | 教室看不到作業區塊 | 流程處理（P8 gate 先 build） | — | 是（開發時） | `pnpm run build:wp` |

---

## 9. 實作階段

> 通用 Red → Green → Refactor 切法（每階段都適用）
>
> - **Red**（`@zenbu-powers:test-creator`）：一個 Example 一個測試方法；Scenario Outline 用 `@dataProvider`；`@group assignment` 與 `@group issue-131` **寫在 class docblock**（寫在檔案 docblock 不會生效）；檔名必須以 `Test.php` 結尾（`StudentActivityLogTest2.php` 就是因為檔名從未執行）。紅燈驗收：`php -l` 全部新檔通過 → 執行 `--group assignment`，確認「失敗數 = 本階段新增測試數」、失敗原因是缺功能（class 不存在 / 斷言不符），不是語法錯誤或 bootstrap fatal，而且舊測試沒有變紅。
> - **Green**（PHP：general-purpose；前端：react-master / general-purpose）：只寫讓測試通過的最小實作 → 把新字串加進 `manual.json`（zh_TW + ja）→ `pnpm run i18n:build` → 再跑測試。**PHPUnit 斷言的是 zh_TW 譯文，沒重跑 i18n:build 一定紅。**
> - **Refactor**（同一個實作 agent）：抽共用驗證、補繁中 docblock、`final class`、`declare(strict_types=1)`；分開跑 `vendor/bin/phpcbf` → `vendor/bin/phpcs <變更檔>` → `composer run phpstan`（不要只跑 `pnpm run lint:php`，phpcbf 有修東西就會 exit 1，後面兩步根本沒跑）。
> - **驗證關卡（每階段結束都要過，才能進下一階段）**：
>   1. `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group assignment`：本階段測試全綠，而且執行數符合預期（看輸出的 Tests 數，不是只看 OK）
>   2. `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit`：**全套 0 failure / 0 error**（基線 1341 全綠，任何紅燈都是這次改動造成的）
>   3. `composer run phpstan`：錯誤數 ≤ 96
>   4. `pnpm run i18n:build` 輸出的未翻譯數沒有上升；`git status languages/` 應有 7 檔變更
>   5. 更新 `specs/plan/issue-131-assignment-grading/.progress.json`（`{phase, status, tests_added, tests_total, phpstan_errors, commit}`），中斷時從最後一個通過的階段恢復

### P1：資料表與 DB 版本升級

- **目標**：3 張自訂表 + 版本 1.2.0，讓後續階段有地方寫資料
- **Feature**：無直接對應（支撐全部）；依據 `erm.dbml` 的 `pc_assignment_submissions / attachments / messages`
- **依賴**：無｜**複雜度**：低｜**風險**：中（R-03）｜**規模**：S（約 5 檔、300 行）
- **Red**（test-creator）：`tests/Integration/Assignment/AssignmentTablesMigrationTest.php`
  - 三張表存在，欄位型別符合下方 DDL；`uq_assignment_submissions_owner` 真的擋重複（插第二筆 `(assignment_id, user_id)` 相同應失敗）
  - `pc_db_version` = `1.1.0` 時呼叫 `AbstractTable::maybe_upgrade()` → 表補建、版本變 `1.2.0`；再呼叫一次不報錯（冪等）
  - 既有 6 張表的列不受影響；`Plugin::activate()` 也會建出新表
- **Green**（general-purpose PHP）：
  1. `plugin.php` 加三個 `*_TABLE_NAME` 常數，`activate()` 補呼叫
  2. `AbstractTable.php` 新增 `create_assignment_submissions_table()`、`create_assignment_attachments_table()`、`create_assignment_messages_table()`，`install()` 補呼叫，版本升 1.2.0，更新 docblock 版本紀錄
  3. `tests/bootstrap.php`（第 125–131 行，既有 6 張表在這裡建成**真實表**；在 `WP_UnitTestCase` 內建表會被轉成暫時表，行為不同）補建三張表；`tests/Integration/TestCase.php` 的 `ensure_tables_exist()`、`clean_custom_tables()` 也補三張表（tdd-coordinator 藍圖調整 #2）
  - DDL 要點（dbDelta 格式：每欄一行、`PRIMARY KEY  (id)` 兩個空格、JSON 用 `longtext` 以相容 MariaDB）：
    - submissions：`id, assignment_id, user_id, text_body longtext NULL, attachments longtext NOT NULL, submitted_at datetime NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', feedback text NULL, graded_by bigint(20) NULL, graded_at datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL`；`UNIQUE KEY uq_assignment_submissions_owner (assignment_id,user_id)`、`KEY idx_assignment_submissions_assignment (assignment_id,submitted_at)`、`KEY idx_assignment_submissions_user (user_id,submitted_at)`、`KEY idx_assignment_submissions_status (status,submitted_at)`
    - attachments：`id, user_id, original_name varchar(255), stored_path varchar(500), mime_type varchar(100), file_size bigint(20), created_at datetime`；`KEY idx_assignment_attachments_user (user_id)`
    - messages：`id, submission_id, sender_type varchar(20), sender_id bigint(20) NULL, sender_email varchar(100) NULL, body text, read_at datetime NULL, created_at datetime, deleted_at datetime NULL`；`KEY idx_assignment_messages_submission (submission_id,created_at)`
- **Refactor**：三個 create 方法的共用骨架（表存在就早退、charset、require upgrade.php）抽 private helper
- **成功標準**：關卡全過；三張表 schema 就是最終版（之後改欄位必須寫 ALTER migration，見 R-03）

### P2：作業出題（CPT、CRUD、排序、查詢、章節連動）

- **目標**：管理員可以透過 REST 管理作業；章節 trash / 還原 / 永久刪除與複製課程的連動正確
- **Feature**：建立作業、更新作業、刪除作業、排序作業、查詢章節作業列表；chapter/刪除章節（+3 Rule）；course/複製課程（+1 Rule）
- **依賴**：P1｜**複雜度**：中｜**風險**：中（R-01、R-05、R-10、R-11、R-12）｜**規模**：L（約 22 檔、1800 行含測試）
- **開工前**：`.claude/rules/i18n.rule.md` 術語表補作業相關詞彙（§5.3）
- **Red**（test-creator）
  - 共用基底 `tests/Integration/Assignment/AssignmentTestCase.php`（abstract，繼承 `Tests\Integration\TestCase`）：
    - `set_up` 設 `timezone_string=Asia/Taipei`、`gmt_offset=''`，`tear_down` 還原
    - `set_now( 'Y-m-d H:i:s' )`：以站台時區換成 timestamp，掛 `power_course_assignment_now` filter
    - `create_assignment( array )`：`wp_insert_post` + meta（`parent_course_id` 由章節解析）
    - `seed_submission( array )`、`seed_attachment( array, bool $with_file = true )`、`seed_message( array )`：直接 `$wpdb->insert`
    - `dispatch( method, route, params|body )`：走 `rest_get_server()->dispatch()`，**權限測試一定要走這條**（直呼 callback 會跳過 permission_callback，等於沒測）
    - `capture_mail()`：以 `pre_wp_mail` 攔截並記錄收件人 / 內容（不要用 `wp_mail` filter，那個 filter 過的是參數不是回傳值）
    - `run_scheduled_actions( string $hook )`：取出 pending action 逐筆 `do_action_ref_array` 後標記完成
  - 測試檔：`CreateAssignmentTest.php`、`UpdateAssignmentTest.php`、`DeleteAssignmentTest.php`、`SortAssignmentsTest.php`、`QueryAssignmentsTest.php`、`ChapterAssignmentCascadeTest.php`（feature 3 條 + 課程還原連帶還原作業 1 條）、`CourseDuplicateExcludesAssignmentsTest.php`、`AssignmentCPTStructureTest.php`（`show_in_rest=false`、`public=false`；有作業時 `ChapterUtils::get_flatten_post_ids()` 與 `CourseUtils::get_all_chapters()` 的結果不變，R-12）、`AssignmentApiContractTest.php`（本階段 6 條路由的註冊、nocache 靜態檢查、Alice 403 矩陣）
  - 「權限不足」斷言 status 403 + code `rest_forbidden`（DEC-01），其他錯誤斷言 zh_TW 譯文
  - 預期會「意外綠」的測試：`CourseDuplicateExcludesAssignmentsTest`（`Duplicate` 白名單本來就不含新 CPT），紅燈驗收時註明是回歸守門，不算異常
- **Green**（general-purpose PHP）
  1. `Utils/Clock.php`、`Utils/AssignmentException.php`
  2. `Core/CPT.php`（DEC-05）、`Model/Assignment.php`
  3. `Service/Crud.php`：`create()`、`update()`（部分更新；合併後再驗 allow_text / allow_file；改掛章節時重算 `parent_course_id`）、`trash()`、`sort( chapter_id, ids )`（`(index+1)*10`；空陣列不動並回列表）。寫入 `post_content` / `post_title` 時依 slash 對稱規則 `wp_slash`
  4. `Service/Query.php`：`list_by_chapter()`、`list_by_course()`（DEC-13）、`get()`
  5. `Service/SubmissionRepository::delete_by_assignment()`、`Service/MessageRepository::delete_by_submission_ids()`
  6. `Core/LifeCycle.php`（DEC-06）
  7. `Core/Api.php`：`GET/POST assignments`、`GET/PUT/DELETE assignments/(?P<id>\d+)`、`POST assignments/sort`；每個 callback 第一行 `\nocache_headers();`
  8. `Core/Loader.php`（含 `plugins_loaded:20` maybe_upgrade）；`Resources/Loader.php` 註冊
  9. i18n：§4.1 前 13 條
- **Refactor**：`Crud` 的欄位驗證只保留一份（create / update 共用）；`Model\Assignment::to_array()` 為唯一輸出格式
- **成功標準**：P2 feature 的每個 Example 都有對應測試且綠；全套測試不變紅

### P3：學員端（附件上傳 / 下載、繳交、我的作業）

- **目標**：學員可上傳附件、繳交、查看自己的作業；管理員與學員依規則下載附件
- **Feature**：上傳作業附件、下載作業附件、繳交作業、查詢學員作業；student/移除學員課程權限（+1 Rule）
- **依賴**：P2｜**複雜度**：高｜**風險**：高（R-04、R-13、R-14、R-15、R-16、R-17）｜**規模**：L（約 18 檔、2200 行含測試）
- **Red**（test-creator）
  - `UploadAssignmentAttachmentTest.php`：直接測 `Service\Attachment::store( user_id, tmp_path, original_name, mime, size )`（temp 檔用 `tempnam`）；50MB 邊界用 `file_size` 參數判斷，不必真的產生 50MB 檔；實體檔在儲存根目錄內、檔名是 64 位 hex 且 ≠ 原檔名、無副檔名；目錄內有 `.htaccess` 與 `index.php`；回應沒有 `url` / `stored_path`；`UPLOAD_ERR_INI_SIZE` 對應「檔案過大」（API 層的轉換函式單獨測）
  - `DownloadAssignmentAttachmentTest.php`：透過 `dispatch()` 取得 response，斷言 status、`Content-Disposition`（含原檔名）、`Content-Type`、`X-Content-Type-Options`；`Cache-Control` 用 `token_get_all` 靜態檢查（CLI 下 `nocache_headers()` 看不到，見 R-07）；補 `attachment_id` 700 vs 7000 的 LIKE 邊界
  - `SubmitAssignmentTest.php`：feature 全部 Example；「課程尚未開課」與「觀看權已到期」**用相對時間**（`course_schedule = time() + DAY_IN_SECONDS`、`expire_date = time() - DAY_IN_SECONDS`），因為 `is_course_ready()` / `is_expired()` 用的是 `time()` 不是 Clock（R-06）；`is_exempt` 用 `current_user_can`，測試要 `wp_set_current_user`；補一條連續繳交兩次仍只有 1 筆
  - `QueryMyAssignmentsTest.php`、`RemoveStudentKeepsSubmissionsTest.php`
  - `AssignmentApiContractTest.php` 擴充 5 條路由（學員 4 + 下載 1），未登入 401 矩陣
- **Green**（general-purpose PHP）
  1. `Utils/Storage.php`（DEC-09；`resolve_path()` 用 `realpath` 確保在根目錄內）、`Utils/Stream.php`（DEC-08）
  2. `Model/Attachment.php`、`Model/Submission.php`、`Service/AttachmentRepository.php`、`SubmissionRepository` 補 `upsert()`、`find_by_owner()`、`find()`、`exists_with_attachment()`
  3. `Utils/Utils::can_access_course( course_id, user_id )` = `CourseUtils::is_avl() && ! CourseUtils::is_expired( wc_get_product(), user_id ) && CourseUtils::is_course_ready()`
  4. `Service/Attachment.php`（DEC-16、DEC-17）、`Service/Submission.php`（§6.1 順序）、`Service/MyQuery.php`
  5. `Core/MyApi.php`：`GET my/assignments`、`GET my/assignments/(?P<id>\d+)`、`POST my/assignments/(?P<id>\d+)/submit`、`POST my/assignment-attachments`（API 層做 `is_uploaded_file`）
  6. `Core/Api.php` 補 `GET assignment-attachments/(?P<id>\d+)/download`（permission = 已登入；callback 內管理員 = `manage_options || manage_woocommerce`）
  7. 線性觀看：`LinearViewing::is_enabled( course_id ) && ! LinearViewing::is_exempt( user_id ) && LinearViewing::is_chapter_locked( chapter_id, course_id, user_id )`，寫法比照 `Resources/Chapter/Core/Api.php:339`，**不另寫解鎖演算法**
  8. i18n：§4.1 學員端字串
- **Refactor**：`Submission` 的形狀驗證拆成小方法（每個 < 50 行）；附件歸屬查詢一次撈完（不要逐筆查）
- **成功標準**：關卡全過；另用 `grep -rn "stored_path" inc/classes/Resources/Assignment/Core` 確認 Api 層沒有輸出路徑

### P4：管理端批改、報表與 CSV 匯出

- **目標**：批改（不含寄信）、批改頁需要的 4 個查詢、兩種 CSV
- **Feature**：批改作業（寄信以外的 Rule）、查詢作業繳交列表、查詢作業批改統計、查詢有繳交紀錄的課程、查詢作業學員背景資料、匯出單一作業繳交CSV、匯出批改用CSV
- **依賴**：P3｜**複雜度**：高｜**風險**：中（R-15、R-18、R-21）｜**規模**：L（約 14 檔、2000 行含測試）
- **Red**（test-creator）
  - `GradeAssignmentSubmissionTest.php`：狀態寫入、未給 feedback 清空、改判、pass 自動完成章節（`did_action( 'power_course_chapter_finished' )` 與參數 `(201, 100, 2)`）、已完成不重複、改判不撤銷、副作用失敗仍成功（掛一個會丟例外的 `power_course_assignment_graded` listener）、pass / fail 會觸發 `power_course_assignment_graded`、pending 不觸發（寄信本身留到 P5）
  - `ListAssignmentSubmissionsTest.php`（含 DEC-07 訊息計數）、`AssignmentSubmissionStatsTest.php`（`avg_grading_seconds` 55800 / 64800 / 35100）、`CoursesWithSubmissionsTest.php`、`AssignmentStudentOverviewTest.php`
    - `user_registered` 在 WP 存的是 GMT：seed 時寫 `2026-01-05 02:00:00`，期望 API 回 `2026-01-05 10:00:00`（用 `Utils\Datetime::to_site_timezone()`，避免重演 +16 小時 double-shift）
  - `ExportAssignmentSubmissionsCsvTest.php`、`ExportGradingCsvTest.php`：讀 response 的 stream 內容；BOM、CRLF、雙引號跳脫、檔名（UTC）、5001 筆只出 5000（用單一多列 INSERT 塞 5001 筆，user_id 用不存在的 100000+i，**不要建 5001 個 WP user**；實作必須 LEFT JOIN 使用者）
  - `AssignmentApiContractTest.php` 擴充 7 條路由
- **Green**（general-purpose PHP）
  1. `Service/Grading.php`（§6.3）；章節完成先 `Progress::is_finished()`，再 `Progress::mark_finished()`，外層 `catch ( \Throwable )`
  2. `Service/Reporting.php`：`list_submissions()`（status 非法 → all、limit 夾 1–200 預設 50、offset ≥ 0、course 透過作業的 `parent_course_id` meta JOIN、排除 trash、LEFT JOIN users）；`stats()`（`pass_rate = round(pass / (pass+fail) * 100)`、分母 0 → 0；`avg_grading_seconds` = `AVG(TIMESTAMPDIFF(SECOND, submitted_at, graded_at))` 四捨五入，無則 null）；`courses_with_submissions()`（count DESC、title ASC）；`student_overview()`（課程優先 course_id，否則由 assignment_id 反推；進度用 `ChapterUtils::get_flatten_post_ids()` 數量與 `CourseUtils::get_finished_sub_chapters()`，百分比取小數一位）
  3. `Utils/Utils::resolve_attachment_kind( mime, file_name )`（對照參考實作 `resolveAttachmentKind`）、`MessageRepository::thread_summary( submission_ids )`（一次查完）
  4. `Service/Csv.php`：寫入 `php://temp/maxmemory:5242880`；欄位與順序照 feature；檔名規則照參考實作 `course-submission-csv.service.ts`（標題非文字字元換 `_`、取前 40 字，`preg_replace( '/[^\p{L}\p{N}]+/u', '_', … )` 並以參考實作確認）
  5. `Core/Api.php` 補：`GET assignments/(?P<id>\d+)/submissions/export`、`GET assignment-submissions`、`…/stats`、`…/courses`、`…/student-overview`、`…/export`、`PATCH assignment-submissions/(?P<id>\d+)/grade`
  6. i18n：批改、狀態標籤、CSV 標頭
- **Refactor**：列表 / CSV 共用同一個 filter builder（status、course、assignment、排除 trash），避免三份 SQL 各自漂移
- **成功標準**：關卡全過；列表與 CSV 對同一組篩選的 ID 順序一致（加一條交叉測試）

### P5：PowerEmail 觸發（assignment_graded、寄送路徑）

- **目標**：批改 pass / fail 會依模板寄信；作業變數可替換；due_soon 的寄送路徑就緒（掃描在 P6）
- **Feature**：批改作業（寄信 3 條 Rule）；email/觸發自動郵件（assignment_graded Rule）
- **依賴**：P4｜**複雜度**：中｜**風險**：中（R-09、R-25）｜**規模**：M（約 10 檔、800 行含測試）
- **Red**（test-creator）
  - `AssignmentGradedEmailTest.php`：pass / fail 寄給學員、pending 不寄、同一繳交第二次批改仍寄（先寫入 identifier 含舊 graded_at 的 EmailRecord）、模板 `course_ids` 不含該課程時不寄（DEC-10）、草稿模板不寄；流程是批改 → `run_scheduled_actions( 'power_email_send_assignment_graded' )` → `capture_mail()`
  - `AssignmentEmailReplaceTest.php`：feature 範例「Alice 您的作業「練習一：變數宣告」批改結果：通過。寫得很好」；`{assignment_due_at}` 格式 `Y-m-d H:i`（站台時區）
  - 更新 `tests/Integration/Email/SendNowTest.php` 的 slug 數（10 → 12）與 label 對照；`EmailTriggerAtTest` 確認新 slug 可以存
- **Green**（general-purpose PHP）
  1. `AtHelper`：兩個常數、`$allowed_slugs`、label（`When assignment graded`、`Before assignment due`）
  2. `CPT::SUPPORTED_TRIGGER_SLUGS` +2；`Condition`：作業 trigger 的 `required_ids = course_ids`，加 `get_course_ids()`
  3. `Replace/Assignment.php`；`Email.php` 的 `send_assignment_email( user_id, array $ctx )`：專屬 filter `power_course_assignment_email_{subject,html}`（先掛 `Replace\User`，再掛 `Replace\Assignment`）；寄出後 `EmailRecord::add( course_id, …, identifier )`
  4. `At.php`：`add_action( 'power_course_assignment_graded', … )` 與 `add_action( 'power_course_assignment_due_soon', … )` → 過濾 course scope → `is_sent` → 已排程檢查 → `as_enqueue_async_action`；對應的 `power_email_send_assignment_*` callback
  5. `Email/Api.php`：變數提示加 `assignment_schema`
- **Refactor**：`schedule_access_pass_expiring_email` 與兩個作業排信的共同骨架（撈模板 → 去重 → enqueue）可抽 private helper，但**不得改變 access_pass 的既有行為**（它的測試必須維持綠）
- **成功標準**：關卡全過；`ExpiringNotifierTest` 等既有 Email / AccessPass 測試全綠

### P6：作業截止提醒排程

- **目標**：每日 09:00（站台時區）掃描並對未繳交學員觸發提醒
- **Feature**：作業截止提醒；email/觸發自動郵件（assignment_due_soon Rule）
- **依賴**：P5｜**複雜度**：中｜**風險**：中（R-06、R-24）｜**規模**：M（約 4 檔、600 行含測試）
- **Red**（test-creator）：`AssignmentDueReminderTest.php`
  - 視窗兩端（now、now+48h）含、外側 ±1 秒不含；已繳交不寄；觀看權到期不寄（`expire_date` 用相對時間）；權限包持有者會收到；課程未開課不寄（相對時間）；trash 作業不寄；草稿模板不寄；同 identifier 不重寄；截止時間改變後再寄；寄出後 `pc_email_records` 有 `email_id / user_id / post_id=100 / trigger_at / mark_as_sent`
  - 排程：`maybe_schedule_scan()` 後 `as_next_scheduled_action( 'pc_assignment_due_soon_scan' )` 換成站台時區是 09:00，間隔 86400；已排程時不重複
  - email feature 的 due_soon 範例：「「練習一：變數宣告」將於 2026-09-18 23:59 截止」
- **Green**（general-purpose PHP）：`Service/DueReminder.php`（`SCAN_HOOK = 'pc_assignment_due_soon_scan'`、`DUE_SOON_ACTION = 'power_course_assignment_due_soon'`；首次執行時間 = 站台時區下一個 09:00 的 timestamp，`as_schedule_recurring_action( $first, DAY_IN_SECONDS, SCAN_HOOK )`；流程見 §6.4）；`Core/Loader.php` 註冊
- **Refactor**：候選學員計算抽成 `get_candidate_user_ids( course_id )`，同一門課多份作業在同一次掃描內只算一次
- **成功標準**：關卡全過

### P7：Admin React SPA（章節作業管理、作業批改頁、Email trigger UI）

- **目標**：UI 規格三份中的兩份後台畫面可以完整操作
- **Feature / UI**：`ui/章節作業管理.md`、`ui/作業批改頁.md`；DEC-11
- **依賴**：P2（章節作業管理）、P4（批改頁）、P5（Email UI）｜**複雜度**：高｜**風險**：中（R-19、R-20）｜**規模**：L（約 20 檔、2000 行 TSX + 3 支 E2E）
- **Red**（test-creator，E2E）
  - `tests/e2e/01-admin/assignment-chapter-manage.spec.ts`：在章節編輯區新增作業（標題、允許檔案、上限、截止日）→ 列表顯示題型摘要 → 編輯標題 → 排序（拖拉或以 API 改序後驗證畫面順序）→ 刪除時確認框提示「學員繳交紀錄會保留」
  - `tests/e2e/01-admin/assignment-grading.spec.ts`：資料以 REST 準備（學員繳交用學員身分的 nonce 呼叫 `my/assignments/{id}/submit`；注意站台沒有 `/wp/v2/users`，建使用者要走既有 helper）→ 課程分頁顯示繳交數 → 統計卡數字 → 狀態篩選 → 開詳情 → 批改通過 + 評語 → 列表狀態與統計更新 → 背景資料顯示進度 → 兩個匯出按鈕觸發 `download` 事件且檔名符合規則
  - 擴充 `tests/e2e/01-admin/email-template.spec.ts`：trigger 下拉出現兩個作業選項，存檔後重新載入仍保留
- **Green**（`@zenbu-powers:react-master`）
  1. `resources/index.tsx`、`App1.tsx` 路由；`pages/admin/Assignments/` 各元件
  2. 列表回應是 `{items,total}` 加 `limit/offset`，不符合 Refine data provider 的預設分頁慣例 → 用 `useCustom` / `useCustomMutation`，不直接用 `fetch`
  3. 附件下載與 CSV 匯出沿用 `js/src/components/user/UserTable/index.tsx` 的做法：`window.open( url + '?_wpnonce=' + NONCE )`
  4. `components/chapters/Assignments/`（列表、拖拉排序沿用 `components/course/SortableChapters` 使用的函式庫、表單 Modal、作業說明用既有編輯器元件，截止時間 DatePicker 以站台時區換算 10 位 timestamp，清空 = 0）；嵌入 `components/chapters/Edit.tsx`
  5. `components/emails/SendCondition/{enum.ts,Condition.tsx}` 與變數提示
  6. 所有字串用 `@wordpress/i18n`，加進 `manual.json`，跑 `pnpm run i18n:build`
- **Refactor**：批改頁的查詢 key 統一，批改成功後只 invalidate 列表與統計；元件 props 型別放 `types/`
- **驗證關卡（前端版）**：`pnpm run lint:ts`、`pnpm run build`（產出 `js/dist`）、E2E 連跑兩次（`cd tests/e2e && npx playwright test 01-admin/assignment-chapter-manage.spec.ts 01-admin/assignment-grading.spec.ts 01-admin/email-template.spec.ts`）、i18n 未翻譯數不上升；瀏覽器驗證前先看 `reference_admin_spa_browser_verify` 的 license gate 與 dist 新鮮度
- **成功標準**：兩個畫面可完整操作；UI 規格列出的每個關鍵元素都有 E2E 斷言或截圖佐證

### P8：前台教室作業區塊

- **目標**：學員在教室看到作業、上傳、繳交、看批改結果；線性觀看鎖定與管理員預覽模式正確
- **Feature / UI**：`ui/教室作業區塊.md`
- **依賴**：P3（P4 之後做 E2E 才能驗批改結果）｜**複雜度**：中｜**風險**：中（R-04、R-14、R-20）｜**規模**：M-L（約 10 檔、1200 行 + 2 支 E2E）
- **Red**（test-creator，E2E）
  - `tests/e2e/02-frontend/021-classroom-assignments.spec.ts`：學員看到卡片 → 上傳檔案（`setInputFiles`）→ 繳交 → 徽章「待批改」、按鈕變「重新繳交」→ 以 API 批改 pass → 重新載入後徽章「通過」、顯示評語、隱藏重新繳交 → 已過截止時間且未繳交顯示「已逾期」但仍可繳交 → 點自己的附件可下載 → 線性觀看鎖定章節的表單停用並顯示鎖定訊息 → 管理員預覽模式表單全部停用 → 課程作業總覽數字正確
  - `tests/e2e/03-integration/016-assignment-http-contract.spec.ts`（真實 HTTP，P3 / P4 的 PHPUnit 驗不到的部分）：multipart 上傳成功且回應無 `url`；下載回應 `Cache-Control` 含 `no-cache`、`Content-Disposition` 含原檔名、`X-Content-Type-Options: nosniff`；學員下載他人附件 403；訪客 401；CSV 以 BOM 開頭、CRLF、`Content-Disposition` 檔名符合規則。**不斷言「直連檔案被擋」**，因為本機站是 Nginx（R-04）
- **Green**（general-purpose，同一個 agent 負責 PHP 模板與 TS，確保兩邊 data 屬性一致）
  1. `inc/templates/components/assignment/{section.php,card.php,overview.php}`；`body.php` 在 `the_content()` 之後、留言之前載入；資料直接呼叫 `Service\MyQuery`（學員）或 `Service\Query`（管理員預覽，表單停用）
  2. `inc/assets/src/events/assignments/`：上傳（`FormData` + `pc_data.nonce`）、繳交、局部更新卡片（`textContent`）；於 `events/index.ts` / `main.ts` 註冊
  3. 線性觀看鎖定：伺服器端判斷後輸出 `data-locked`，表單停用並顯示既有鎖定訊息
  4. i18n：模板用 `esc_html__` 等 escape 變體；TS 用 `@wordpress/i18n`（`vite.config-for-wp.ts` 已有 shim alias，新增 entry 時要確認）
- **Refactor**：卡片 HTML 只在 PHP 產生一份，TS 只更新狀態欄位，不在 TS 複製一份卡片模板
- **驗證關卡（前端版）**：`pnpm run build:wp` 後 `grep` `inc/assets/dist/index.js` 確認有新字串；E2E 連跑兩次；看前台前先處理 license bypass、WP Rocket 快取、`confirm_admin_email` 攔截頁（見 `reference_local_frontend_verify_playbook`）；驗完以 `plugin.php.e2e-backup`（`revertLcBypass()`）還原或只刪注入的那一行，**不要** `git checkout -- plugin.php`（P1 對 `plugin.php` 的改動若未 commit 會被一起抹掉）
- **執行時序調整（tdd-coordinator 藍圖）**：`016-assignment-http-contract.spec.ts` 的上傳 / 下載部分提前在 **P3** 寫並連跑兩次、CSV 部分在 **P4** 補；P8 只重跑確認
- **成功標準**：UI 規格列出的每個元素都有 E2E 斷言

### P9：收尾驗收與文件同步

- **依賴**：P1–P8｜**複雜度**：低｜**規模**：S
- 全套 PHPUnit 0 failure / 0 error；PHPStan ≤ 96；變更檔 PHPCS 0 error；`pnpm run lint:ts` 通過；E2E（本計劃新增的 4 支 + 被擴充的 email-template）連跑兩次都綠
- `git grep -nE "'lc'[[:space:]]*=>" plugin.php` 為空（E2E 注入的那行有對齊空白，單一空格的 pattern 比對不到）；`plugin.php.e2e-backup` 不存在
- 文件同步（`@zenbu-powers:doc-updater`）：`.claude/CLAUDE.md`、`.claude/rules/wordpress.rule.md`（新增 Assignment Resource 小節、自訂表 9 張、受保護目錄 Nginx 範例 `location ^~ /wp-content/uploads/power-course-private/ { deny all; }` 與 `PC_ASSIGNMENT_STORAGE_DIR` 說明）、`.claude/rules/i18n.rule.md`（修正「四個檔」→ zh_TW + ja 共 7 檔）
- 瀏覽器截圖：章節作業管理、批改頁（含詳情與背景資料）、教室作業區塊（未繳交 / 待批改 / 通過 / 鎖定）

### 9.1 階段依賴與執行順序

```
P1 ──▶ P2 ──▶ P3 ──▶ P4 ──▶ P5 ──▶ P6 ──┐
        │       │       │       │        ├──▶ P9
        │       │       └───────┴──▶ P7 ─┤
        │       └──────────────────▶ P8 ─┘
        └─（P7 的章節作業管理可在 P2 後先做）
```

- **建議預設：全部依序執行**（P1 → … → P9），最單純，也避免 `manual.json` 衝突與 worktree 的 PHPUnit 問題
- 若要平行：P7（前端）可在 P4 通過後與 P5 / P6 同時進行，P8 可在 P3 通過後進行；條件是前端 lane 開 worktree、**不在 worktree 跑 PHPUnit**，且 `manual.json` 的新增由主窗口在各 lane 結束時依序合併、統一跑 `pnpm run i18n:build`

---

## 10. Agent 分工

| 階段 | Red | Green / Refactor | 必讀規範與資料 |
|------|-----|------------------|---------------|
| P1–P6 | `@zenbu-powers:test-creator` | **general-purpose**（沒有 wordpress-master） | 見 10.1 |
| P7 | `@zenbu-powers:test-creator`（E2E） | `@zenbu-powers:react-master` | 見 10.2 |
| P8 | `@zenbu-powers:test-creator`（E2E） | general-purpose（PHP 模板 + vanilla TS） | 10.1 + 10.2 |
| P9 | — | `@zenbu-powers:doc-updater`（文件）＋ 主窗口跑驗證 | 本計劃 §9 P9 |
| 選用 | — | `@zenbu-powers:security-reviewer`（用戶喚醒才上場；建議在 P3 後審上傳 / 下載 / 受保護目錄） | — |

### 10.1 PHP 實作 agent（general-purpose）的 prompt 附件

- 專案規範：`.claude/CLAUDE.md`、`.claude/rules/wordpress.rule.md`、`.claude/rules/i18n.rule.md`
- Skill：`zenbu-powers:aibdd-auto-tdd`（stage = green / refactor，language = php 的 references）、`wordpress-i18n`
- 規格：本計劃該階段段落、對應 feature 檔、`specs/features/assignment/句型.md`、`specs/entity/erm.dbml`（assignments 一節起）、`specs/api/api.yml`（tag `Assignments`）、`specs/clarify/2026-09-17-1415-issue131-assignment-grading.md`
- 參考實作（業務行為有疑問時以此為準）：`C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\`
- 對照程式：`inc/classes/Resources/AccessPass/`（Resource 結構、`ExpiringNotifier`、`Loader::maybe_upgrade`）、`inc/classes/Resources/Chapter/Core/Api.php:339`（線性觀看檢查）、`inc/classes/Resources/Chapter/Service/Progress.php`、`inc/classes/PowerEmail/Resources/Email/Trigger/At.php`（access_pass 排信）
- Memory：`project_wp_slash_symmetry.md`、`feedback_verification_lies.md`、`project_test_baseline_202608.md`
- 硬性限制：不得在 `plugin.php` 寫 `'lc' =>`；不得手改 `.po`；REST callback 第一行 `\nocache_headers()`；SQL 一律 `$wpdb->prepare()`；`$wpdb->insert/update` 不 `wp_slash`、`wp_insert_post / update_post_meta` 要 `wp_slash`；類別 `final`、`declare(strict_types=1)`；Api 層不得輸出 `stored_path`

### 10.2 前端 agent 的 prompt 附件

- 專案規範：`.claude/rules/react.rule.md`、`.claude/rules/i18n.rule.md`、`.claude/rules/e2e-testing.rule.md`（E2E 作者）
- 規格：`specs/ui/{章節作業管理,作業批改頁,教室作業區塊}.md`、`specs/api/api.yml`（tag `Assignments`）、對應 feature（回應欄位）
- 參考實作：`zenbu-site-2.0/apps/web/app/zb-course/submissions/SubmissionsManager.tsx`、`zb-course/lessons/[lessonId]/assignments/AssignmentsManager.tsx`、`zb-course/courses/[id]/AssignmentsModal.tsx`、`(public)/[locale]/account/courses/[slug]/AssignmentsSection.tsx`、`…/assignments/CourseAssignmentsOverview.tsx`
- Memory：`reference_admin_spa_browser_verify.md`、`project_frontend_assets_build.md`、`reference_local_frontend_verify_playbook.md`、`project_e2e_helper_traps.md`、`project_antd_toolkit_style_css.md`

### 10.3 測試 agent（test-creator）的 prompt 附件

- Skill：`zenbu-powers:test-creation-playbook`、`zenbu-powers:aibdd-auto-tdd`（stage = red，language = php）
- `tests/Integration/README.md`、`tests/Integration/TestCase.php`、`tests/Integration/Chapter/ChapterApiNocacheHeadersTest.php`（nocache 靜態檢查寫法）、`tests/Integration/AccessPass/ExpiringNotifierTest.php`
- 規格：對應 feature、`specs/features/assignment/句型.md`（句型 → helper）
- Memory：`feedback_verification_lies.md`、`project_e2e_helper_traps.md`、`project_test_baseline_202608.md`
- 規則：斷言 zh_TW 譯文字面值，不要用 `__()` 包期望值（同義反覆，`.po` 清空也會綠）

---

## 11. 測試策略

| 層級 | 範圍 | 工具 |
|------|------|------|
| 整合（主力） | 21 個 feature / 追加 Rule 的每個 Example、API 契約（路由、nocache、權限矩陣） | PHPUnit 9 + wp-phpunit（wp-env tests 容器） |
| E2E（UI） | 章節作業管理、批改頁、Email trigger 選項、教室作業區塊 | Playwright：`01-admin`、`02-frontend` |
| E2E（HTTP 契約） | 真實 multipart、串流標頭、CSV BOM / 檔名、401 / 403 | Playwright：`03-integration/016-assignment-http-contract.spec.ts` |

**指令**

```bash
# 本功能
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group assignment
# 單一檔
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit tests/Integration/Assignment/SubmitAssignmentTest.php
# 全套（每階段關卡）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit
# 靜態分析（分開跑）
vendor/bin/phpcbf <變更檔>; vendor/bin/phpcs <變更檔>; composer run phpstan
# 前端
pnpm run lint:ts && pnpm run build        # Admin SPA → js/dist
pnpm run build:wp                         # 教室 vanilla TS → inc/assets/dist
cd tests/e2e && npx playwright test 01-admin/assignment-grading.spec.ts
# i18n
pnpm run i18n:build
```

**關鍵邊界**：50MB 整 / +1 byte；`max_files` 0 / 21；`due_at` 5 位數；截止視窗兩端 ±1 秒；`limit` 0 / 500、`offset` -5；空 ids 排序；重複 ids；附件 700 vs 7000；5001 筆匯出；已完成章節不覆寫；改判不撤銷；重交清空批改欄位；同一學員連續繳交；權限包持有者；`shop_manager` 豁免線性觀看；不存在的學員 / 課程查背景資料不報錯。

**防止「綠燈但沒測到」**：權限測試一律 dispatch；`@group` 寫在 class docblock；檔名 `*Test.php`；新測試檔先 `php -l`；每次看執行的 Tests 數；Red 時確認失敗數 = 新增數；nocache 只能靜態檢查 + E2E。

---

## 12. 風險登記表

| ID | 等級 | 風險 | 緩解措施 | 階段 |
|----|------|------|---------|------|
| R-01 | 中 | **命名衝突**：`inc/classes/Resources/Teacher/Service/Assignment.php` 是「講師指派到課程」，與本功能同名 | 新功能全部放 `J7\PowerCourse\Resources\Assignment\…`；同一檔案需要兩者時一律 `use … as TeacherAssignment` / `AssignmentModel`；Model 取名 `Model\Assignment`，不在 `Resources\Teacher` 底下建任何作業類別；MCP / 前端的講師指派用語不動；Refactor 時 `grep -rn "Teacher\\\\Service\\\\Assignment" inc/classes/Resources/Assignment` 必須為空 | P2 起 |
| R-02 | 高 | **i18n pipeline**：新字串要進 `manual.json`（zh_TW **與 ja**），跑 `pnpm run i18n:build` 產生 7 檔；禁止手改 `.po`；PHPUnit 載入 zh_TW `.mo`，沒重建就紅；`i18n:pot` 需要 WP-CLI（本機已確認有）；平行 lane 同時改 `manual.json` 會衝突；新詞彙要先進術語表 | 每階段 Green 的固定步驟是「寫碼 → manual.json → i18n:build → 跑測試」；P2 開工前補術語表；平行時由主窗口序列化合併；關卡檢查未翻譯數不上升；§4.1 統一 msgid | 全部 |
| R-03 | 高 | **自訂表與 DB 版本**：`create_*_table()` 表存在就早退，不會補欄位 → 第一波 schema 必須就是最終版；`AbstractTable::maybe_upgrade` 只掛 `admin_init`，學員端 REST 不會觸發；`pc_db_version`（1.1.0，AbstractTable）與 `pc_access_pass_db_version`（1.2.0，AccessPass）是兩個不同 option，不要混用；`TestCase::ensure_tables_exist()` 有 static 旗標，漏加新表就只在第一次跑時缺表 | P1 一次建好三張表（含第二波的 messages）；DEC-04 加掛 `plugins_loaded:20`；只升 `pc_db_version` → 1.2.0；`activate()` 同步；MigrationTest 覆蓋「1.1.0 升級」與「重跑冪等」；之後任何欄位變更一律寫獨立冪等 ALTER（比照 `Loader::migrate_add_granted_by_column`） | P1 |
| R-04 | 高 | **受保護目錄在 Nginx 無效**：`.htaccess` 只對 Apache 有效，本機 turbo 站就是 Nginx，檔案實際上可被直連 | DEC-09：64 位隨機 hex 檔名、無副檔名、目錄放 `index.php` 防列目錄、API 永不回傳路徑、支援 `PC_ASSIGNMENT_STORAGE_DIR` 搬到 web root 外；文件提供 Nginx `deny` 設定；E2E 只驗「不洩漏路徑」。**延後**：Site Health 探針（寫 canary 檔後以 HTTP 直連檢查，可直連時提示站長），列入後續需求 | P3、P9 |
| R-05 | 中 | **CPT 經 `/wp/v2` 外洩作業說明** | DEC-05 `show_in_rest=false`，`AssignmentCPTStructureTest` 守住 | P2 |
| R-06 | 中 | **時間與時區**：feature 的字面 timestamp 假設 Asia/Taipei；`is_course_ready()` / `is_expired()` 用 `time()` 不吃 Clock；`user_registered` 存 GMT；DST 站台的 09:00 會漂一小時 | 測試基底固定時區；這兩個閘門的 Example 用相對時間；背景資料用 `Datetime::to_site_timezone()`；recurring 間隔固定 86400，DST 漂移接受並記錄 | P2–P6 |
| R-07 | 中 | **nocache / 標頭在 PHPUnit 看不到**：`nocache_headers()` 在 CLI 第一行就 return | 靜態檢查 callback 第一個語句；`Content-Disposition` 等放在 `WP_REST_Response` 上可斷言；真實標頭由 016 E2E 驗 | P3、P4、P8 |
| R-08 | 中 | **測試說謊**：直呼 callback 跳過 permission_callback；`@group` 寫在檔案 docblock 不生效；檔名非 `*Test.php` 不執行；`lint:php` 短路 | §11 的防線；Red 驗收時比對失敗數 | 全部 |
| R-09 | 中 | **PowerEmail 既有耦合**：`Condition::$course_ids` 是 private；非課程 trigger 會撈全站章節（每次 new Email 都查一次）；`SendNowTest` 寫死 slug 數 10；`StudentLog\CRUD` 以 `$allowed_slugs` 驗 log_type | 加 getter、作業 trigger 不走章節分支；刻意更新 `SendNowTest` 並註明；log_type 白名單變寬不影響既有功能（D12 不新增 log） | P5 |
| R-10 | 中 | **WP 還原規則**：WP 5.6+ 還原預設 draft；課程還原不觸發 `untrash_post` | DEC-06 transition hook + `wp_untrash_post_status`；補課程還原測試 | P2 |
| R-11 | 中 | **章節永久刪除留下孤兒作業**：`wp_delete_post` 只處理同 post_type 的子文章 | DEC-06 `before_delete_post` 強制刪除 | P2 |
| R-12 | 低 | **`parent_course_id` meta 與章節 / 公告共用**：沒限定 post_type 的查詢會撈到作業 | 目前查詢都有限定 post_type（已檢查 `get_flatten_post_ids`、`get_all_chapters`、`Duplicate`、`Api\Course`）；加回歸測試 | P2 |
| R-13 | 中 | **上傳限制**：主機 `upload_max_filesize` / `post_max_size` 小於 50MB；`is_uploaded_file()` 在 PHPUnit 不成立 | DEC-17；Service 收暫存路徑，API 層才檢查 `is_uploaded_file()`；真實上傳交給 E2E | P3 |
| R-14 | 中 | **MIME 偽造 / 下載 XSS** | DEC-16 副檔名白名單 + finfo 交叉；一律 `attachment` + `nosniff`；不提供 inline 預覽 | P3 |
| R-15 | 中 | **大檔與大量 CSV 的記憶體**：50MB 附件、5000 × 50000 字 | `readfile` 前清空輸出緩衝；CSV 寫 `php://temp`；不 `file_get_contents` | P3、P4 |
| R-16 | 低 | **在 JSON 欄位裡找附件**：`JSON_CONTAINS` 在舊 MariaDB 不一定可用 | `wp_json_encode` 固定鍵順序，以 `LIKE '%"attachment_id":700,%'` 搜尋（結尾逗號防 7000 誤中），測試守邊界 | P3 |
| R-17 | 低 | **線性觀看豁免依賴目前使用者**：`is_exempt()` 用 `current_user_can` 而非傳入的 user_id | REST 情境下目前使用者就是學員；測試務必 `wp_set_current_user` | P3 |
| R-18 | 中 | **`Progress::mark_finished` 的副作用**：一定觸發 action、找不到課程會丟 RuntimeException、會連帶寄 chapter_finish 信與寫學員日誌 | 先 `is_finished()`；`catch ( \Throwable )`；連帶效果屬既有行為，不額外處理 | P4 |
| R-19 | 中 | **Refine 資料契約不合**：`{items,total}` + `limit/offset`；規範禁止直接 `fetch` | `useCustom` / `useCustomMutation`；下載用既有 `window.open + _wpnonce` 模式 | P7 |
| R-20 | 中 | **本機前端驗證的牆**：dist 過期、dev-server json 開關、license gate、WP Rocket、`confirm_admin_email`、E2E 單跑綠連跑紅 | 依 memory playbook；E2E 至少連跑兩次；驗完還原 `plugin.php` | P7、P8 |
| R-21 | 低 | **CSV 標頭本地化與第二波匯入**：標頭是翻譯後的中文，第二波匯入若依標頭名稱解析會受語系影響 | 第一波照 feature 輸出；在第二波計劃註記「匯入依欄位位置解析，或同時接受各語系標頭」 | P4（記錄） |
| R-22 | 低 | **PHPStan 基線**：新程式把錯誤數推過 96 | 每階段關卡比數字；需要精確比對時用 json 輸出比「檔案 + message」 | 全部 |
| R-23 | 低 | **同秒改判**：identifier 相同只寄一封 | 接受；graded_at 精度到秒已足夠 | P5 |
| R-24 | 中 | **截止提醒在大站的成本**：候選學員 = 課程 avl usermeta ∪ 全部權限包持有者，逐一 `is_avl` | 無模板提早結束；已繳交者一次 SQL；同課程候選快取；**延後**：批次化 / 分段排程 | P6 |
| R-25 | 低 | **模板課程範圍的解讀（DEC-10）**：規格沒寫 course_ids 對作業 trigger 是否生效 | 採 UI 既有語意「留空 = 全部」；可推翻，只影響 `At` 的一個過濾與一條測試 | P5 |
| R-26 | 中 | **規模超過 30 檔** | 9 個可獨立合併的階段、每階段關卡、`.progress.json` 檢查點 | 全部 |
| R-27 | 低 | **規格文件之間的小矛盾**：學員端不存在作業回 403（feature）vs 404（erm Note、系統抽象）；鎖定訊息既有譯文結尾有「。」 | DEC-12 以 feature 為準；鎖定訊息沿用既有譯文（D27），測試斷言既有譯文；P9 由 doc-updater 修正 erm Note 與系統抽象表 | P3、P9 |
| R-28 | 低 | **worktree 跑 PHPUnit 的坑**（port、core null、basename 掛載、vendor） | 預設在主 repo 依序執行；要開 worktree 前先讀 `project_worktree_wpenv_phpunit.md` | 平行時 |

---

## 13. 限制條件（本計劃不做的事）

- 第二波：討論串讀寫、CSV 匯入（只建 messages 表與列表計數）
- execution-plan「未納入」清單：作業擋章節完成、批改方式設定、重交歷史版本、逾期欄位、活動日誌 log_type、講師批改、分數制、作品公開、打包下載、防毒、MCP tools、feature flag、完課獎勵
- 不清除未綁定附件、不刪除附件實體檔（D9、D16）
- 不改 `LinearViewing` 解鎖演算法、不改 `Progress::mark_finished` 行為
- 不修 execution-plan 以外既有功能的 bug（例如 `ExpiringNotifier::unschedule_scan` 沒被呼叫、`StudentActivityLogTest2.php` 從未執行）；只在發現時回報
- 延後：受保護目錄 Site Health 探針（R-04）、截止提醒批次化（R-24）
- 禁止：`plugin.php` 寫入 `'lc' =>`、手改 `.po`、中文 msgid、Api 回傳附件路徑或 URL、`innerHTML` 插入使用者輸入

## 14. 成功標準

- [ ] §3 第 1–22 列每個 Example 都有對應的 PHPUnit 測試，且真的被執行、全部綠燈
- [ ] §3 第 23–25 列與 016 HTTP 契約的 E2E 連跑兩次都綠
- [ ] 全套 PHPUnit 0 failure / 0 error（測試數 = 1341 + 本功能新增數）
- [ ] `composer run phpstan` 錯誤數 ≤ 96；變更檔 PHPCS 0 error；`pnpm run lint:ts` 通過
- [ ] `pnpm run i18n:build` 未翻譯數不高於開工前；`languages/` 7 檔已隨功能 commit
- [ ] `pc_db_version` 從 1.1.0 升到 1.2.0 的站台，學員第一次呼叫作業 API 就有表可用
- [ ] API 回應中沒有任何附件路徑或 URL；下載回應帶 `no-cache`、`nosniff`、`attachment`
- [ ] 後台兩個畫面、Email trigger 選項、教室作業區塊都有截圖
- [ ] 文件同步完成（CLAUDE.md、wordpress.rule.md、i18n.rule.md）

## 15. 預估複雜度：高

後端約 30 個新檔、跨 PowerEmail / LinearViewing / Chapter 生命週期 / 檔案儲存四個既有子系統；前端兩個新畫面 + 教室互動。靠 9 個可獨立合併的階段把風險切小。

---

## 16. WHERE NEXT

1. **已完成**：`@zenbu-powers:tdd-coordinator` 產出協調藍圖 `specs/plan/issue-131-assignment-grading/tdd-blueprint.md`（共 32 次派發；新增 P0 基線、驗收以 `--group issue-131` 為準、test-creator 不得建 production stub 並須回報介面契約）
2. **主窗口**：依藍圖先做 **P0**（主窗口自己重量 PHPUnit / PHPStan / i18n / ESLint / E2E 對照組基線，建立 `.progress.json`），再派 P1-R（`@zenbu-powers:test-creator` 寫 `AssignmentTablesMigrationTest.php`）
3. **需要用戶確認的點（可在 P2 開工前順便確認，不擋 P1）**：DEC-10（模板課程範圍、作業信件立即寄送）、DEC-11（Email UI 補 trigger 選項）、DEC-15（總覽放在教室作業區塊頂部）
4. **選用**：P3 完成後由用戶喚醒 `@zenbu-powers:security-reviewer` 審上傳 / 下載 / 受保護目錄
