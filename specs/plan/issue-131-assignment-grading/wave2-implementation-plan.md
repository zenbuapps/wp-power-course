# 實作計劃：Issue #131 作業與作業批改（第二波：討論串 + CSV 匯入）

> 分支：`feat/issue-131-wave2`（疊在第一波 `feat/issue-131-assignment-grading` 之上，HEAD `0fdd4868` 已同步 master v1.8.11）｜產出日期：2026-10-01
> 規劃者：`@zenbu-powers:planner` → 交接 `@zenbu-powers:tdd-coordinator`
> 範圍依據：用戶在 clarify Q3 親自定的第二波範圍（`specs/clarify/2026-09-17-1415-issue131-assignment-grading.md`），本檔**不增減**
> 業務決策：同一份 clarify 的 D1–D29（不得推翻）；業務行為以 zenbu-site-2.0 為準（用戶裁決）
> 第一波計劃：`implementation-plan.md`（DEC-01 ~ DEC-18、R-01 ~ R-28 **全部沿用**，本檔只列第二波新增的 DEC-W2 / R-W2）

---

## 0. 範圍模式與規模判定

| 項目 | 判定 |
|------|------|
| 範圍模式 | **HOLD SCOPE**。3 支 feature 已定稿；規格沒寫、參考實作有的行為（學員端未讀徽章、收件匣輪詢、未讀優先排序）一律不做，列在 §10 規格疑義讓用戶決定 |
| 預估影響檔案 | 約 42 個（後端 PHP 新增 4、修改 4；PHPUnit 新增 8；Admin SPA 新增 4、修改 5；教室模板 + TS 新增 2、修改 4；E2E 新增 3；i18n 8；文件 3） |
| >30 檔案的處理 | 範圍是用戶定的，不回頭問要不要縮減；拆成 **W0–W5 六個可獨立驗證的階段**，每階段有關卡 |
| Schema | **不改**。`pc_assignment_messages` 第一波已建（DB 1.2.0），欄位與索引 `(submission_id, created_at)` 足以支撐本波所有查詢與 UPDATE；若實作中發現需要新欄位，停下回報，不得自行 ALTER（R-03） |
| 模式漂移防線 | 實作中若需要 feature 沒寫的業務行為，停下回報，不得自行補規則 |

---

## 1. 概述

在第一波的「繳交 / 批改」之上補兩件事：

1. **作業討論串**：學員在自己的繳交下、管理員在任一繳交下互傳訊息。讀取時把「對方發的未讀訊息」標為已讀；第一波批改列表的「討論」徽章因此開始有數字。
2. **匯入批改結果 CSV**：管理員上傳改過「狀態」「講師回饋」兩欄的批改用 CSV，系統逐列呼叫第一波的 `Grading::grade()`，自動完成章節與批改通知照常觸發；單列失敗不影響其他列。

## 2. 需求重述

- **服務對象**：學員（只能讀寫自己繳交的討論串，不檢查觀看權）、管理員（`manage_options || manage_woocommerce`，可讀寫任一繳交的討論串、可匯入 CSV）。講師不參與。
- **成功的樣子**：3 支 feature 共 43 個測試案例（Outline 展開後：討論串 11、發訊息 11、匯入 21）都有真的會執行、而且綠燈的 PHPUnit 測試；後台批改頁抽屜可以看與回覆討論串、工具列可以匯入並看到結果；前台教室作業卡片可以展開討論串；第一波 287 支 `issue-131` 測試與全套 PHPUnit 維持 0 failure。
- **第一波已完成、本波直接沿用**（不重做）：`pc_assignment_messages` 表、`MessageRepository::thread_summary()`（列表的 `unread_count / message_count / last_message_at`）與 `delete_by_submission_ids()`、`Grading::grade()`（含兩個副作用與 try/catch）、`Csv::write_grading_csv()`、`AssignmentTestCase` 的 `seed_message()` / `dispatch()` / `set_now()` / `capture_mail()` / `run_scheduled_actions()`、後台列表「討論」欄（`hooks/useColumns.tsx`）。

---

## 3. 第二波 feature 對照總表

| # | Feature / 驗證目標 | 階段 | 測試類型 | 測試檔（`tests/Integration/Assignment/…` 或 `tests/e2e/…`） | 案例數（約） |
|---|-------------------|------|---------|-----------------------------------------------------------|-------------|
| 1 | assignment/查詢作業討論串（11 Example / 9 Rule） | W1 | PHPUnit（全走 dispatch） | `ListAssignmentThreadTest.php` | 11 + 邊界 4 |
| 2 | assignment/發送作業討論訊息（10 Example / 9 Rule，Outline 展開 11） | W1 | PHPUnit（全走 dispatch） | `PostAssignmentMessageTest.php` | 11 + 邊界 6 |
| 3 | 未讀定義一致性（DEC-W2-04；寫入路徑與第一波 `thread_summary` 同一套定義） | W1 | PHPUnit | `ThreadUnreadSummaryConsistencyTest.php` | 5 |
| 4 | 5 條新路由的契約（註冊、nocache 靜態檢查、401 / 403 矩陣、DEC-W2-02 守門） | W1 / W2 | PHPUnit | `AssignmentWave2ApiContractTest.php` | 約 18 |
| 5 | assignment/匯入批改結果CSV（12 Example / 12 Rule，Outline 展開 21） | W2 | PHPUnit（權限 / 檔案層走 dispatch；內容走 Service） | `ImportGradingCsvTest.php` | 21 + 邊界 8 |
| 6 | CSV 解析器（DEC-W2-11） | W2 | PHPUnit（單元） | `AssignmentCsvParserTest.php` | 約 15 |
| 7 | 匯出 → 匯入往返（R-21 收斂，DEC-W2-09） | W2 | PHPUnit | `GradingCsvRoundTripTest.php` | 4 |
| 8 | 真實 HTTP：multipart 匯入、討論串 GET 的禁止快取標頭、401 / 403 | W2 | E2E | `03-integration/017-assignment-thread-import-http-contract.spec.ts` | 約 6 |
| 9 | ui/作業批改頁：討論串 + 匯入 | W3 | E2E | `01-admin/assignment-thread-import.spec.ts` | 約 6 |
| 10 | ui/教室作業區塊：討論串 | W4 | E2E | `02-frontend/022-classroom-assignment-thread.spec.ts` | 約 6 |

**不修改第一波任何測試檔**（含 `AssignmentApiContractTest.php`、`AssignmentTestCase.php`）：第一波 287 支是本波的回歸基準，Red Gate 記錄其 sha1，之後每個 Gate 驗證未被改動。第二波共用 helper 放新的 `AssignmentWave2TestCase.php`（abstract，繼承 `AssignmentTestCase`）。

---

## 4. Planner 技術決策（DEC-W2）

不改變業務行為，是規格沒寫到、實作必須選一個的做法。標「需用戶確認」者屬業務取捨，§15 集中列出，並註明只擋哪些步驟。

| # | 決策 | 理由 | 需用戶確認 |
|---|------|------|-----------|
| DEC-W2-01 | **落點**：`Core/Api.php` +3 路由（`GET`/`POST assignment-submissions/(?P<id>\d+)/messages`、`POST assignment-submissions/import`）；`Core/MyApi.php` +2 路由（`GET`/`POST my/assignment-submissions/(?P<id>\d+)/messages`，permission = `check_logged_in_permission`）；新增 `Model/Message.php`、`Service/Thread.php`、`Service/CsvImport.php`、`Utils/CsvParser.php`；擴充 `Service/MessageRepository.php`。每個 callback 第一個語句 `\nocache_headers();` | 對齊 execution-plan 的 `Service/{…,Thread,…}` 與第一波 Resource 結構；`import` 是靜態尾段，與 `(?P<id>\d+)` 不衝突 | 否 |
| DEC-W2-02 | **body / file 的驗證一律在 callback 內（permission 之後）做，路由不宣告 `args` 的 required / validate_callback** | WP REST 先驗參數、後驗權限（`wp-includes/rest-api/class-wp-rest-server.php` L1115 `has_valid_params()` 先於 L1259 `permission_callback`）。feature 的「Bob 呼叫 POST …/messages（不帶 body）→ 權限不足」「Alice 呼叫 POST …/import（不帶檔）→ 權限不足」若宣告 required args 會變成 400，測試紅且行為錯。ApiBase 本身不加 args（已讀 `powerhouse/vendor/j7-dev/wp-utils/src/classes/ApiBase.php`） | 否 |
| DEC-W2-03 | **討論串的檢查順序**：學員端 未登入 401 → 繳交不存在 404「繳交紀錄不存在」→ 非本人 403「無權存取此繳交紀錄」（`AssignmentException::forbidden`）→ body 400；管理端 401 / 403（ApiBase）→ 404 → 400。**不檢查**課程觀看權（feature）、線性觀看（D29）、**所屬作業是否在垃圾桶**（與參考實作 `getSubmissionOrThrow` 及 erm Note「繳交必須存在」一致；與 `grade` 的「作業 trash 視為不存在」不同，見 §10 S-05） | 參考實作只查 submission；先資源後參數與第一波 submit 的順序一致 | 否 |
| DEC-W2-04 | **已讀模型**：GET 時**先**把對方發的訊息標已讀（`UPDATE … SET read_at = wp_date(Clock::now()) WHERE submission_id = %d AND sender_type = <對方> AND read_at IS NULL AND deleted_at IS NULL`）**再**讀取，所以回應裡對方訊息的 `read_at` 已是現在；已有 `read_at` 的不覆寫；POST 不標任何訊息已讀；`mine` = `sender_type` 是否等於查詢端（學員端 = student、管理端 = teacher），**不看 sender_id**；未讀定義維持第一波 `thread_summary()`（student 發、`read_at IS NULL`、未刪除），`'student'` / `'teacher'` 改用 `Model\Message::SENDER_STUDENT / SENDER_TEACHER` 常數 | 參考實作 `listForStudent / listForTeacher` 先 `markRead` 再 `loadThread`；`toView` 以 senderType 判 mine；常數化避免寫入端與統計端字串漂移（R-W2-19） | 否 |
| DEC-W2-05 | **訊息內容**：`is_string` 且 `mb_strlen` 介於 1–10000，否則 400「訊息內容長度必須為 1 至 10000 字」（沒帶、null、陣列、數字一律同訊息）；**不 trim**（只有空白也合法）；原樣存，不跑 `sanitize_textarea_field` / `wp_kses`（會改動內容）；輸出端負責跳脫（React 文字節點、前台 `textContent`）；`$wpdb->insert` 不 `wp_slash`（slash 對稱表） | feature 說明「body 1-10000 字、不 trim」；參考實作 `@Length(1,10000)` + `@IsString`；`text` 欄 65535 bytes ≥ 10000 字 × 4 bytes | 否 |
| DEC-W2-06 | **回應**：成功一律 **200**（api.yml；參考實作 NestJS `@Post` 預設 201，以 api.yml 為準）；訊息欄位白名單 `id`(int)、`sender_type`、`body`、`read_at`(string\|null)、`created_at`、`mine`(bool)，由 `Model\Message::to_view( string $viewer )` 唯一產生（不可能帶出 `sender_id` / `sender_email`）；列表排序 `created_at ASC, id ASC`；時間為站台時區 `Y-m-d H:i:s`（第一波 DEC-03） | api.yml `AssignmentMessage`；tie-break 同 DEC-13 | 否 |
| DEC-W2-07 | **軟刪除**：本波**沒有**刪除訊息的 API 或 UI；讀取、標已讀、統計一律 `deleted_at IS NULL`；欄位保留 | erm Note「本期無刪除訊息 API，同參考實作」「查詢一律排除非 null 列」 | 否 |
| DEC-W2-08 | **發訊息不觸發任何通知**：不新增 PowerEmail trigger、不寫學員日誌、不寄信 | 參考實作 `CourseAssignmentThreadService` 與兩個 controller 的 thread 路由都沒有通知；clarify D11 只有 `assignment_graded`、`assignment_due_soon`；D12 移除 log_type；feature 沒寫。HOLD SCOPE | 否 |
| DEC-W2-09 | **R-21 決議：匯入依「表頭名稱」定位欄位，不依位置**。表頭正規化 = 去 BOM → trim → `mb_strtolower` → 連續空白（含全形空白，`/[\s\p{Z}]+/u`）換成 `_`。別名（依序取第一個「非空」的值，同參考實作 `pick`）：<br>• id：`submission_id`、`submissionId`<br>• 狀態：`狀態(可改 pending/pass/fail)`、`狀態(可改)`、`狀態`、`status`、英文 msgid `Status (editable: pending/pass/fail)`、該 msgid 在目前請求語系的 `_x(…, 'assignment csv')` 譯文<br>• 回饋：`講師回饋(可改)`、`講師回饋`、英文 msgid `Feedback (editable)`、其 `_x` 譯文<br>匯出與匯入共用同一組表頭字串：Refactor 時把 `Csv::write_grading_csv()` 的表頭抽成 `Csv::grading_headers()`，匯入的別名從它取，兩邊不可能漂移 | **依位置不可行**：feature 的 Example 是 3 欄 CSV（`submission_id, 狀態, 講師回饋`），匯出是 8 欄，依位置解析 feature 全紅。**只用參考實作的中文別名也不夠**：PC 匯出表頭會翻譯，en 站是 `Status (editable: pending/pass/fail)`、ja 站是 `ステータス(変更可 pending/pass/fail)`（已查 `manual.json` / `power-course-ja.po`），自己匯出的檔案會匯不回來。匯出（`window.open` 無 `_locale=user`）與匯入（`useCustomMutation` 無 `_locale=user`）都走站台語系，所以 `_x` 譯文一致。限制：匯出後站台改語系（例如 ja → en 以外的語系）才匯入會辨識不到，列入 R-W2-05 | 否 |
| DEC-W2-10 | **狀態值辨識**：trim + `mb_strtolower` 後比對固定清單 `pending/待批改 → pending`、`pass/passed/通過 → pass`、`fail/failed/未通過/不通過 → fail`；**不**加入翻譯後的狀態標籤 | feature 與參考實作 `normalizeStatus` 一致；匯出的「可改」欄寫的是原始值 `pending/pass/fail`（`Csv.php` 寫 `$submission->status`），與語系無關；「目前狀態」欄（翻譯標籤）不在別名內，不會被誤用 | 否 |
| DEC-W2-11 | **自寫嚴格 CSV 解析器**（`Utils/CsvParser`），「無法解析」= 下列任一：(a) 去 BOM 後**不是合法 UTF-8**；(b) 引號欄位到檔尾未閉合；(c) 閉合引號後接的不是逗號 / 換行 / 空白；(d) 未加引號的欄位中間出現引號。支援 BOM、CRLF / LF / CR、引號內逗號與換行、`""` 跳脫；分隔符固定逗號；每格 trim；全空的列（空行或 `,,,`）略過且**不計入 total**；列號 = 「非空資料列序號 + 2」（同參考實作，不是實體行號）；比表頭多的格忽略、少的視為空；正規化後表頭重複時後者覆蓋。解析失敗 → 200、四個計數皆 0、`errors = [{row: 0, message: 「CSV 解析失敗」}]`，**不處理任何列**。feature 的「無法解析的 CSV」測試資料用「未閉合引號」 | PHP 的 `fgetcsv` / `str_getcsv` 對 (b)(c)(d) 都不報錯（會靜默吞到檔尾），做不出 feature 要的 row 0；參考實作的 `csv-parse` 預設對 (b)(c)(d) 拋錯。(a) 是 PHP 端補的：台灣 Excel「另存 CSV（逗號分隔）」產生 Big5，若照參考實作以替代字元繼續，使用 `status` 英文表頭 + 中文評語的檔案會把亂碼評語寫進 DB 並寄給學員；整檔拒絕比較安全，且結果仍落在 feature 定義的「無法解析」 | 否（Big5 友善提示另見 UC-W2-4） |
| DEC-W2-12 | **空檔 / 只有表頭 / 超過 5000 列 → row 0、不處理任何列**，訊息照參考實作 `parseCsvBuffer`：「CSV 檔案為空」「CSV 沒有任何資料列」「單批匯入上限 5000 筆，請拆分後重試（目前 N 筆）」 | feature 沒寫這三種；參考實作明確處理（`MAX_CSV_ROWS = 5000`）。5000 列上限同時防 R-W2-06 逾時。若用戶不同意：三種都改用「CSV 解析失敗」、上限仍保留 | **是（UC-W2-1）**，只擋 W2-R 的 3 條邊界測試 |
| DEC-W2-13 | **上傳層（field 名 `file`）檢查順序**：沒有檔案 / `UPLOAD_ERR_NO_FILE` → 400「請選擇要匯入的 CSV 檔案」；`UPLOAD_ERR_INI_SIZE` / `UPLOAD_ERR_FORM_SIZE` / 整個 body 超過 `post_max_size` → 400「檔案過大（上限 10MB）」；`size > 10485760` → 400「檔案過大（上限 10MB）」；**之後**才 `is_uploaded_file()`（失敗 → 400「請選擇要匯入的 CSV 檔案」）；讀檔失敗 → 500 + log。不驗副檔名 / MIME（參考實作不驗，內容不合法自然落入 row 0）。Refactor 時與 `MyApi::get_uploaded_file()` 抽成共用 `Utils/UploadedFile`（上限與訊息參數化），第一波上傳測試守住不變；`MyApi::translate_upload_error()` 必須保留為 public static（第一波 `UploadAssignmentAttachmentTest` 直接呼叫它） | 大小檢查放在 `is_uploaded_file()` 之前，PHPUnit 才能用 dispatch + 假 `$_FILES` 驗「10485761 bytes → 檔案過大」；INI 對應沿用第一波 DEC-17 的取捨（主機上限小於 10MB 時訊息上限字樣會不準，見 R-W2-08） | 否 |
| DEC-W2-14 | **逐列處理、不開交易**：每列 `Grading::grade( id, ['status'=>…, 'feedback'=>…], 目前管理員 )`；`AssignmentException` → failed，訊息用例外訊息（例如「繳交紀錄不存在」「feedback 不可超過 10000 字」）；其他 `Throwable` → failed，訊息用既有「Failed to save grading result」並 `Plugin::logger`（不外洩 SQL）；同一 submission_id 出現多列 → 依序套用、最後一列為準、每列各自觸發副作用；`submission_id` 非空但不是正整數（`abc`、`500.0`、`-1`）→ failed「繳交紀錄不存在」，只有空白才算「缺少 submission_id」；callback 開頭 `wc_set_time_limit( 0 )`（`function_exists` 守門） | 參考實作逐列 `await grade()`、無交易；feature「單列失敗不影響其他列」。**禁止** `START TRANSACTION`：第一波 P3 已證實 production 自開交易會提前提交 PHPUnit 測試交易、造成跨次殘留 | 否 |
| DEC-W2-15 | **重複匯入與未變更列：照參考實作，每一列都是一次批改**。後果：(1) `graded_at` / `graded_by` 每次都更新；(2) pass / fail 列**每次都再寄一次批改通知**（identifier 含新的 graded_at）；(3) pending 列也寫入 graded_at，會被算進「平均批改時間」；(4) CSV **沒有回饋欄**時所有列的評語被清空（參考實作 `pick` 回 null → `feedback ?? undefined` → 清空）。PC 的放大效應：批改頁預設篩選是「全部」（參考實作預設「待批改」），直接匯出再整份匯回，所有已批改學員都會再收到一次信 | feature「每一列等同執行一次批改作業」；HOLD SCOPE。可選方案：A 照參考實作（建議預設）；B 略過「狀態與評語都沒變」的列（要改 feature）；C 只在 UI 匯入前加一段提醒文字（不改 API） | **是（UC-W2-2）**；選 A 不擋任何步驟，選 B 擋 W2-R，選 C 擋 W3-R |
| DEC-W2-16 | **錯誤明細訊息**：缺 id →「缺少 submission_id」；狀態 →「狀態無法辨識（%s），需為 pending/pass/fail」，`%s` 為 trim 後的原值（不轉小寫，同參考實作 `statusRaw`），空值時為「空白」；回應 `{total, updated, skipped, failed, errors: [{row, message}]}`，errors **不截斷**（UI 只顯示前 20 筆，同參考實作） | feature 字面；api.yml `AssignmentImportSummary` | 否 |
| DEC-W2-17 | **後台討論串 UI**：新元件 `SubmissionThread`，放在 `SubmissionDetail` 抽屜最後一段（`ui/作業批改頁.md` 詳情區的列出順序）；抽屜開啟即載入（= 參考實作收件匣的 embedded 模式），`key` 綁 submission id；載入成功後 `refetch` 列表讓「討論」徽章歸零；訊息顯示「老師 / 學員」、時間、`mine && read_at` 時顯示「已讀」；單行 `Input`、Enter 送出、前端 trim 後為空不可送（同參考實作 `TeacherThread`）；`useCustom`（`staleTime: 0`、`refetchOnWindowFocus: false`）/ `useCustomMutation`，不直接 fetch；**不輪詢**、不做未讀優先排序（規格沒有） | 參考實作 `SubmissionsManager.tsx` `TeacherThread`；react.rule 禁止直接 API 呼叫；GET 有副作用，不讓它在 focus 時自己重抓 | 否 |
| DEC-W2-18 | **後台匯入 UI**：工具列（`ExportButtons` 旁）新增 `ImportButton`：antd `Upload`（`accept=".csv,text/csv"`、`beforeUpload` 攔下不走 antd 自己的上傳）→ `FormData` + `useCustomMutation`（沿用 `components/formItem/VideoInput/hooks/useSubtitles.tsx` 的 multipart 寫法，`successNotification: false`）；結果用頁內可關閉的 `Alert` 顯示「共 N 筆・更新 X・略過 Y・失敗 Z」與錯誤列（前 20 筆 + 「另有 N 筆」）；完成後 refetch 列表、統計、課程分頁；**不**加 `_locale=user`（DEC-W2-09） | 參考實作 `handleImportFile` 與匯入摘要卡；結果放頁內而非 toast，E2E 才能穩定斷言（R-W2-11） | 否 |
| DEC-W2-19 | **教室討論串 UI**：`card.php` 永遠輸出 `data-submission-id`（無繳交為空字串）與一個討論串區塊（切換鈕「與老師討論」、訊息清單、輸入列、訊息節點的 `<template>`）；無繳交時整塊 `hidden`；狀態為通過時繳交表單隱藏但**討論串照常顯示**；頁內首次繳交成功後由 `submit.ts` 寫入 `data-submission-id` 並移除 `hidden`；**展開才載入**（展開 = 讀取 = 標老師訊息已讀）；訊息以 `<template>` clone 後 `textContent` 填值（維持「卡片 HTML 只在 PHP 一份」與 DEC-15 禁 `innerHTML`）；線性觀看鎖定**不影響**討論串（D29）；管理員預覽（`data-readonly="yes"`）不輸出討論串；時間顯示 `created_at` 去掉秒（站台時區字串，不做瀏覽器時區換算） | 參考實作 `AssignmentReviewView.tsx`：只有有繳交的卡片有「與老師討論」、展開才 `load()`、admin 預覽完全唯讀。不在 PHP 端預先渲染訊息：一渲染就得決定要不要標已讀，與「學員看到才算讀」的語意衝突 | 否 |
| DEC-W2-20 | **測試分組**：新 class 一律在 **class docblock** 寫 `@group assignment`、`@group issue-131`、`@group issue-131-w2`（寫在檔案 docblock 整檔永遠不跑）；Gate 以 `--group issue-131-w2` 計本波、`--group issue-131` 做第一波回歸（應 = 287 + 本波數） | 第一波 ADJ-02；memory `feedback_verification_lies` | 否 |

### 4.1 新 msgid（實作前先 `grep -n` `scripts/i18n-translations/manual.json` 去重；每筆要有 `msgstr_zh_TW` **與** `msgstr_ja`；`_x` 的 context 用 `msgctxt` 鍵，不是 `context` 鍵）

| zh_TW（= feature 文字，PHPUnit 斷言字面值） | msgid | 備註 |
|---------------------------------------------|-------|------|
| 繳交紀錄不存在 | `Submission not found` | **既有**，重用 |
| 無權存取此繳交紀錄 | `You do not have permission to access this submission` | 新 |
| 訊息內容長度必須為 1 至 10000 字 | `Message must be between 1 and 10000 characters` | 新 |
| 請選擇要匯入的 CSV 檔案 | `Please select a CSV file to import` | 新 |
| 檔案過大（上限 10MB） | `File too large (max 10MB)` | 新（第一波是 50MB 那條，不可共用） |
| CSV 解析失敗 | `Failed to parse CSV` | 新 |
| 缺少 submission_id | `Missing submission_id` | 新 |
| 狀態無法辨識（%s），需為 pending/pass/fail | `Unrecognized status (%s), must be pending/pass/fail` | 新，全形括號與逗號要一字不差；`/* translators: %s: CSV 中的狀態原值 */` |
| 空白 | `_x( 'blank', 'assignment import empty cell', 'power-course' )` | 新 |
| （UC-W2-1）CSV 檔案為空 / CSV 沒有任何資料列 / 單批匯入上限 %1$d 筆，請拆分後重試（目前 %2$d 筆） | `CSV file is empty` / `CSV has no data rows` / `Import is limited to %1$d rows per batch. Please split the file and try again (current: %2$d rows)` | 待確認 |
| （讀暫存檔失敗，500） | `Failed to import CSV` | 新 |
| UI：討論串 / 與老師討論 / 收合討論 / 載入討論中… / 尚無訊息 / 輸入訊息… / 送出 / 我 / 老師 / 學員 / 已讀 / 訊息送出失敗 / 討論串載入失敗 | `Discussion thread` / `Discuss with teacher` / `Collapse discussion` / `Loading discussion...` / `No messages yet` / `Type a message...` / `Send` / `Me` / `_x('Teacher','assignment thread sender')` / `Student`（既有） / `_x('Read','assignment message status')` / `Failed to send message` / `Failed to load discussion` | `Read` 必須加 context（與動詞衝突）；`Teacher` 加 context（與講師 Instructor 區隔） |
| UI：匯入批改 CSV / 匯入結果… / 第 N 列：… / 另有 N 筆錯誤 / 匯入失敗 | `Import grading CSV` / `Import result: %1$d total, %2$d updated, %3$d skipped, %4$d failed` / `Row %1$d: %2$s` / `_n('%d more error', '%d more errors')` / `Failed to import CSV`（同上） | 含 placeholder 一律 `sprintf` + translators 註解 |

**術語表先補**（W1-G 第 0 步，`.claude/rules/i18n.rule.md`）：討論串 → Discussion thread；老師（討論串中管理員一方的稱呼，**非**講師角色）→ Teacher；已讀（訊息狀態）→ Read（context `assignment message status`）；匯入 → Import。

### 4.2 介面契約（Red 與 Green 共同遵守；test-creator 若必須偏離，回報「介面契約」段，由主窗口轉給 Green）

```php
// Model
final class Message {
	public const SENDER_STUDENT = 'student';
	public const SENDER_TEACHER = 'teacher';
	public static function from_row( array $row ): self;
	/** @return array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool} */
	public function to_view( string $viewer ): array; // $viewer ∈ SENDER_*
}

// Service（討論串測試一律走 REST dispatch，不直呼 Service）
final class Thread {
	public static function list_for_student( int $submission_id, int $user_id ): array;
	public static function list_for_teacher( int $submission_id ): array;
	public static function post_as_student( int $submission_id, int $user_id, array $args ): array;
	public static function post_as_teacher( int $submission_id, int $admin_id, array $args ): array; // sender_email 由 admin_id 取 user_email 快照
}

// Service（匯入內容測試直呼這支；權限 / 檔案層測試走 dispatch）
final class CsvImport {
	/** @return array{total:int, updated:int, skipped:int, failed:int, errors:list<array{row:int, message:string}>} */
	public static function import( string $raw, int $actor_id ): array;
}

// Utils
final class CsvParser {
	/** @return list<array<string, string>> 鍵為正規化表頭，已略過全空列 @throws \UnexpectedValueException 無法解析 */
	public static function parse( string $raw ): array;
	public static function normalize_header( string $header ): string;
}
```

---

## 5. 計劃必答問題的答案

| # | 問題 | 答案 | 依據 |
|---|------|------|------|
| 1 | 發訊息是否觸發 PowerEmail？ | **不觸發**，也不寫日誌（DEC-W2-08） | 參考實作 thread service / controller 無通知；clarify D11 只有兩個 trigger；feature 沒寫 |
| 2 | 未讀定義與標記已讀時機 | 未讀 = 對方發、`read_at IS NULL`、未軟刪除。管理端徽章只算「學員發、老師未讀」（第一波 `thread_summary` 不變）。**讀取（GET）討論串時**、在回傳之前，把對方的未讀訊息標為現在；自己的訊息不動；POST 不標已讀（DEC-W2-04）。學員端沒有未讀數 API（規格缺口，§10 S-07） | feature 兩條「後置（狀態）」Rule、erm Note「已讀模型」、參考實作 `markRead` |
| 3a | 匯入欄位定位（R-21） | 依表頭名稱 + 別名，別名含參考實作中文、英文 msgid、站台語系譯文；不依位置（DEC-W2-09） | feature 的 3 欄 Example 使位置解析不可行 |
| 3b | 部分列失敗 | **逐列處理、不 rollback**，失敗列計入 failed 並附錯誤，不影響其他列（DEC-W2-14）；只有「整檔無法解析 / 空檔 / 超量」才整批不處理（DEC-W2-11、12） | feature「單列失敗不影響其他列」「CSV 無法解析時不處理任何列」 |
| 3c | 重複匯入的冪等性 | **最終狀態冪等**（status / feedback 一樣），**副作用不冪等**：graded_at 更新、通知再寄、pending 列寫入 graded_at（DEC-W2-15，UC-W2-2 請用戶確認） | 參考實作逐列呼叫 grade |
| 3d | 匯入是否寄 `assignment_graded` | **會**，pass / fail 列各寄一次、pending 不寄；自動完成章節也會觸發，失敗只記 log | feature「每一列觸發與批改作業相同的副作用」Example；共用 `Grading::grade()` |
| 4 | 軟刪除 | 只是保留欄位，本波無對外刪除操作；所有查詢 / 標已讀 / 統計排除（DEC-W2-07） | erm Note |
| 5 | 列表「討論」欄與詳情抽屜怎麼接 | 欄位不改（第一波已從表計算）；抽屜最後一段加 `SubmissionThread`，開抽屜即載入、載入成功後 refetch 列表使徽章歸零；回覆後訊息就地附加（DEC-W2-17） | `ui/作業批改頁.md`、參考實作 `TeacherThread` |

---

## 6. 架構變更

### 6.1 後端（PHP，`inc/classes/Resources/Assignment/`）

**新增**

| 檔案 | 職責 | 階段 |
|------|------|------|
| `Model/Message.php` | 訊息列 DTO、sender 常數、`to_view( viewer )` 白名單輸出 | W1 |
| `Service/Thread.php` | 學員 / 管理員的讀（先標已讀）與發；ownership 檢查；body 驗證 | W1 |
| `Service/CsvImport.php` | 解析 → 表頭別名對應 → 逐列 `Grading::grade()` → 摘要 | W2 |
| `Utils/CsvParser.php` | DEC-W2-11 嚴格解析器、表頭正規化 | W2 |
| `Utils/UploadedFile.php`（Refactor 才抽） | 從 request 取單一上傳檔、PHP 錯誤碼對應、大小上限、`is_uploaded_file()`；上限與訊息參數化，`MyApi`（50MB）與匯入（10MB）共用 | W2-F |

**修改**

| 檔案 | 變更 | 階段 |
|------|------|------|
| `Core/Api.php` | +3 路由與 callback；import callback：`wc_set_time_limit(0)` → 取檔（DEC-W2-13）→ `CsvImport::import( file_get_contents, get_current_user_id() )` | W1 / W2 |
| `Core/MyApi.php` | +2 路由與 callback | W1 |
| `Service/MessageRepository.php` | 新增 `insert()`、`find()`、`list_by_submission()`（排除刪除，`created_at ASC, id ASC`）、`mark_read( submission_id, sender_type, read_at )`；`thread_summary()` 改用 `Message` 常數（行為不變，第一波測試守住） | W1 |
| `Service/Csv.php` | Refactor：表頭抽成 `grading_headers()`，匯出輸出逐字不變（第一波匯出測試守住） | W2-F |

### 6.2 前端

| 檔案 | 變更 | 階段 |
|------|------|------|
| `js/src/pages/admin/Assignments/components/SubmissionThread.tsx`（新） | 討論串（DEC-W2-17） | W3 |
| `js/src/pages/admin/Assignments/components/ImportButton.tsx`、`ImportResult.tsx`（新） | 匯入按鈕與結果 Alert（DEC-W2-18） | W3 |
| `components/index.ts`、`components/SubmissionDetail.tsx`、`index.tsx` | 匯出新元件、嵌入討論串、工具列加匯入、匯入後 refetch | W3 |
| `hooks/index.ts` | `useSubmissionThread`、`usePostThreadMessage`、`useImportGradingCsv` | W3 |
| `types/index.ts` | `TAssignmentMessage`、`TImportSummary`；更新「第一波恆為 0」的註解 | W3 |
| `inc/templates/components/assignment/card.php` | `data-submission-id`、討論串區塊（可抽 `thread.php` partial） | W4 |
| `inc/assets/src/events/assignments/thread.ts`（新）、`index.ts`、`submit.ts` | 展開載入 / 送出 / 首次繳交後顯示討論串 | W4 |
| `inc/assets/src/styles/`（必要時） | 只在既有 class 不夠用時新增，並由 `main.ts` 匯入；優先沿用 `card.php` 已用的 class | W4 |

### 6.3 i18n 與文件

- `scripts/i18n-translations/manual.json` + `languages/` 7 檔（`power-course.pot`、`power-course-{zh_TW,ja}.{po,mo,json}`）
- `.claude/rules/i18n.rule.md` 術語表（W1-G 第 0 步）
- W5：`.claude/CLAUDE.md`（Assignment Resource 段補討論串與匯入）、`.claude/rules/wordpress.rule.md`（Assignment 結構補 `Thread` / `CsvImport` / `CsvParser`、端點清單 +5、匯入語意與 DEC-W2-15 的後果）；§10 規格疑義若用戶核可修正，由 doc-updater 改 specs

---

## 7. 資料流分析

### 7.1 讀討論串 `GET (my/)assignment-submissions/{id}/messages`

```
REQUEST ──▶ AUTH ──────────▶ FIND SUBMISSION ──▶ OWNERSHIP ─────────▶ MARK READ ─────────────▶ LOAD ─────────────▶ RESPONSE
   │          │                   │                  │（只有學員端）        │                       │                    │
   ▼          ▼                   ▼                  ▼                     ▼                       ▼                    ▼
[id 非數字  [學員端未登入→401   [不存在→404       [user_id≠本人→403    [UPDATE 對方 & 未讀     [deleted_at IS NULL  [to_view(viewer)
 → 路由不   rest_not_logged_in] 繳交紀錄不存在]    無權存取此繳交紀錄]   & 未刪除 → read_at=now] ORDER created_at,  白名單，無 sender_id
 符 404]   [管理端非管理員→403] [不查作業是否 trash、 [不查觀看權、        [SQL 失敗→log，        id ASC]               / sender_email]
                                 不查觀看權]         線性觀看（D29）]     仍回傳列表（R-W2-02）] [SQL 失敗→500]
```

### 7.2 發訊息 `POST (my/)assignment-submissions/{id}/messages`

```
REQUEST ──▶ AUTH ──▶ FIND ──▶ OWNERSHIP ──▶ VALIDATE BODY ───────────▶ INSERT ─────────────────────▶ RESPONSE（200）
                       │         │              │                         │                              │
                       ▼         ▼              ▼                         ▼                              ▼
                     [404]     [403]      [非字串 / 長度 0 /          [sender_type、sender_id、      [find(new id)->to_view，
                                           >10000 → 400；           teacher 時 sender_email 快照、   mine=true、read_at=null]
                                           不 trim、不 sanitize]     read_at NULL、created_at=now]
                                                                     [失敗→500 log；不標任何已讀]
```

### 7.3 匯入 `POST assignment-submissions/import`

```
REQUEST ──▶ ADMIN ──▶ FILE ─────────────────▶ READ ──▶ PARSE ─────────────────────▶ HEADER MAP ──▶ 每一列 ──────────────────────────────▶ RESPONSE（200）
              │         │                       │        │                            │               │
              ▼         ▼                       ▼        ▼                            ▼               ▼
            [401/403] [無檔→400 請選擇]       [讀失敗  [非 UTF-8 / 引號錯 → row 0    [別名 DEC-W2-09] [total++]
                      [INI/FORM/post_max →     →500    「CSV 解析失敗」]                              [id 空白 → skipped「缺少 submission_id」]
                       400 檔案過大]           log]   [空檔 / 只有表頭 / >5000 列 →                  [狀態無法辨識 → skipped（含原值）]
                      [size>10485760 →                 row 0（DEC-W2-12）]                             [Grading::grade()：
                       400 檔案過大]                   [全空列略過不計數]                               成功 → updated
                      [!is_uploaded_file →                                                              AssignmentException → failed + 訊息
                       400 請選擇]                                                                      其他 Throwable → failed + 通用訊息 + log]
                                                                                                       [副作用：pass 完成章節、pass/fail 排信
                                                                                                        （Grading 內 try/catch，D23）]
```

---

## 8. 錯誤處理登記表

| 方法 / 路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|------------|-------------|---------|---------|------------|
| 管理端 3 路由 | 未登入 / 非管理員 | 401 / 403 `rest_forbidden` | ApiBase 預設 permission | 是 |
| 學員端 2 路由 | 未登入 | 401 `rest_not_logged_in` | `MyApi::check_logged_in_permission` | 是 |
| `Thread::*` | 繳交不存在 | 404 `rest_not_found` | `AssignmentException::not_found` | 是 |
| `Thread::*_student` | 非本人繳交 | 403 `rest_forbidden` | `AssignmentException::forbidden` | 是 |
| `Thread::post_*` | body 不合法 | 400 `rest_invalid_param` | 單一訊息（DEC-W2-05） | 是 |
| `MessageRepository::insert` | SQL 失敗 | 500 | log + 通用訊息 | 是（通用訊息） |
| `MessageRepository::mark_read` | SQL 失敗 | — | log，**照常回傳列表**（讀取不因標已讀失敗而失敗；後果是徽章晚一點歸零） | 否 |
| `MessageRepository::list_by_submission` | SQL 失敗 | 500 | log + 通用訊息 | 是 |
| import callback | 無檔 / 過大 / 非上傳檔 | 400 | DEC-W2-13 | 是 |
| import callback | 讀暫存檔失敗 | 500 | log +「Failed to import CSV」 | 是 |
| `CsvParser::parse` | 非 UTF-8、引號錯、空檔、無資料列、超量 | `\UnexpectedValueException` | `CsvImport` 轉成 row 0 錯誤，回 200 | 是（錯誤明細） |
| `CsvImport` 單列 | 缺 id / 狀態無法辨識 | — | skipped + 錯誤明細 | 是 |
| `CsvImport` 單列 | `Grading::grade` 拋 `AssignmentException` | 404 / 400 / 500 | failed + 例外訊息 | 是 |
| `CsvImport` 單列 | 其他 `Throwable` | — | failed + 通用訊息 + log | 是（通用訊息） |
| `Grading` 副作用 | 完成章節 / 排信失敗 | Throwable | 第一波已 catch，只記 log（D23） | 否 |
| 匯入整體 | PHP 逾時 / 記憶體 | fatal | `wc_set_time_limit(0)`、5000 列上限；仍可能發生 → R-W2-06 | 是（請求失敗，前段列已套用） |

未發現「處理方式 = 無 且 靜默」的 CRITICAL GAP。`mark_read` 失敗只記 log 是刻意的：讀取是主操作，標已讀是附帶狀態。

---

## 9. 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|------------|---------|
| GET 討論串被邊緣快取 | 快取命中 → 不標已讀、看到舊訊息 | 是（nocache 第一句） | 是（靜態檢查 + 017 真實標頭） | 是 | 站長排除 `/wp-json/power-course/*` |
| 兩位管理員同時讀 | 同時 UPDATE 同一批列 | 是（`read_at IS NULL` 條件，後者 0 列） | 否 | 否 | 無需 |
| 同一秒兩則訊息 | 排序不穩 | 是（`id ASC` tie-break） | 是（邊界測試） | 否 | 無需 |
| 學員重新繳交 | 討論串消失 | 不會（UPSERT 保留同一列 id） | 是（一致性測試） | 否 | 無需 |
| 作業永久刪除 | 訊息變孤兒 | 是（第一波 DEC-06 連動刪除） | 是（第一波） | 否 | 無需 |
| 訊息含 HTML / script | XSS | 是（存原文、輸出端 textContent / React 文字節點） | 是（PHPUnit 原樣存 + 兩支 E2E 斷言不產生元素） | 否 | 無需 |
| Excel 存成 Big5 | 整檔 row 0「CSV 解析失敗」 | 是（DEC-W2-11） | 是（解析器測試） | 是 | 另存「CSV UTF-8」（UC-W2-4 決定是否加提示） |
| 匯出後站台改語系才匯入 | 狀態欄辨識不到，全部 skipped | 部分（英文 msgid + 中文別名永遠可用） | 是（往返測試含英文表頭） | 是（錯誤明細） | 用目前語系重新匯出 |
| 整份重匯 | 已批改學員重收信、pending 列寫入 graded_at | 照參考實作（UC-W2-2） | 是（「每列各寄一次」） | 是 | 依 UC-W2-2 決定 |
| CSV 沒有回饋欄 | 所有列評語被清空 | 照參考實作（UC-W2-2） | 是（邊界測試） | 是 | 依 UC-W2-2 決定 |
| 大檔匯入逾時 | 前半已套用、回應遺失；重試會重寄信 | 部分（時間上限、5000 列） | 否（W2 Gate 做一次耗時量測，不設門檻） | 是 | 拆小檔 |
| PHP 上傳上限 < 10MB | 「檔案過大（上限 10MB）」字樣與實際上限不符 | 接受（同第一波 DEC-17） | 是（錯誤碼對應） | 是 | 站長調 php.ini |

---

## 10. 規格疑義（不改規格；W5 由 doc-updater 依用戶裁決修正）

| # | 位置 | 疑義 | 本計劃的處理 | 建議 |
|---|------|------|-------------|------|
| S-01 | `匯入批改結果CSV.feature`、api.yml | 空檔、只有表頭、超過 5000 列沒寫；參考實作 `parseCsvBuffer` 三種都 row 0 並有各自訊息 | DEC-W2-12，UC-W2-1 | 用戶確認後在 feature 的「CSV 無法解析」Rule 補 3 個 Example |
| S-02 | 同上 | 參考實作的解析失敗訊息是「CSV 解析失敗：{細節}」，feature 只寫「CSV 解析失敗」 | 依 feature，不附細節（測試斷言字面值） | 不改 |
| S-03 | 同上，與第一波 `匯出批改用CSV.feature` | 匯出表頭會翻譯（R-21），feature 的別名清單只有中文與 `status`；en / ja 站自己的匯出檔無法匯回 | DEC-W2-09 加入英文 msgid 與站台語系譯文 | feature 的「表頭接受欄位名稱別名」Rule 補一句「亦接受匯出時使用的表頭（含各語系）」 |
| S-04 | 同上 | 「每一列等同執行一次批改」+ 匯出預填目前狀態 → 整份重匯會重寄信、pending 列寫入 graded_at；缺回饋欄清空評語 | DEC-W2-15，UC-W2-2 | 用戶裁決後視需要補 Rule |
| S-05 | erm `pc_assignment_messages` Note、api.yml 管理端 messages | 「繳交必須存在」沒說作業在垃圾桶時怎麼算；`grade` 是「作業 trash 視為不存在」 | DEC-W2-03 照參考實作只查繳交 | erm Note 補一句「不檢查作業狀態」 |
| S-06 | api.yml `postAssignmentMessageAsTeacher` / `postMyAssignmentMessage` | 成功碼 200；參考實作 NestJS `@Post` 預設 201 | 依 api.yml 200 | 不改 |
| S-07 | api.yml `MyAssignment`、`ui/教室作業區塊.md` | 參考實作學員端有未讀數（`CustomerCourseAssignmentsController.withUnread()`、`CourseAssignmentsOverview` 徽章），PC 規格沒有 | HOLD SCOPE，不做 | **需用戶確認（UC-W2-3）**：建議另開 issue |
| S-08 | `ui/作業批改頁.md` | 參考實作有收件匣輪詢、未讀優先排序、批改改為只改狀態（評語移到討論串）；PC 規格沒有，第一波 GradeForm 保留評語 | 不做；第一波 GradeForm 不動 | 不改 |
| S-09 | `句型.md` 操作清單 | 「查詢作業討論串」Then 欄位寫 `messageId, sender_type, body, mine`，feature 實際用 `id … created_at` | 依 feature | W5 順手修 |
| S-10 | `api.yml` 管理端 messages | 未列 401 回應（未登入時 WP 回 401 `rest_forbidden`） | 契約測試斷言 401 | W5 補 `401NotLoggedIn` |

---

## 11. 實作階段

> **通用 Red → Green → Refactor（沿用第一波 §9 與 `tdd-blueprint.md` §3 全域規則，下列為第二波差異）**
>
> - **Red**（`@zenbu-powers:test-creator`）：只寫測試，不碰 `inc/`（第一波 ADJ-03）；一個 Example 一個方法，Outline 用 `@dataProvider`；三個 `@group` 寫在 **class docblock**；檔名 `*Test.php`；回報「介面契約」段。紅燈驗收：新檔全部 `php -l` 通過 → `--group issue-131-w2` 失敗數 = 本階段新增數，失敗原因是缺功能（`rest_no_route`、class 不存在、斷言不符），不是語法錯或 bootstrap fatal → `--group issue-131` 第一波 287 支仍全綠 → 記錄新測試檔與第一波測試檔的 sha1。
> - **預期「意外綠」清單：無**。本波每個測試都必須先斷言「操作成功 / 期望的錯誤碼」再斷言 DB 或欄位；若某測試在 Red 就綠，代表它缺少前置斷言（例如只斷言「回應不含 sender_email」而沒斷言 200），退回重寫。
> - **Green**（PHP：general-purpose；SPA：`@zenbu-powers:react-master`）：最小實作 → `manual.json`（zh_TW + ja）→ `pnpm run i18n:build` → 跑測試（PHPUnit 斷言 zh_TW 譯文，沒重跑 i18n:build 一定紅）。
> - **Refactor**：分開跑 `vendor/bin/phpcbf <變更檔>` → `vendor/bin/phpcs <變更檔>` → `composer run phpstan`（不用 `pnpm run lint:php`，L6）。
> - **驗證關卡（每階段）**：
>   1. `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131-w2`：本階段測試全綠，**Tests 數 = manifest**，Incomplete / Skipped / Risky = 0
>   2. `… --group issue-131`：= 287 + 本波累計數，全綠
>   3. `… vendor/bin/phpunit`：全套 0 failure / 0 error（基線 1638 / 5422 / 11 skipped；`ExternalCourseCTATest` 的 DNS flaky 單獨重跑判定）
>   4. `composer run phpstan`：≤ 95，作業相關檔 0 條（需要時用 JSON 比「檔案 + message」）
>   5. `pnpm run i18n:build`：zh_TW 未翻譯 ≤ 5、ja ≤ 199；`git status languages/` 7 檔有變更
>   6. 第一波測試檔 sha1 未變；更新 `specs/plan/issue-131-assignment-grading/.progress-wave2.json`（**新檔**，不覆寫第一波 `.progress.json`）

### W0：前置基線（主窗口親跑，無派發）

- 記錄 `git rev-parse HEAD`、`git status`（`.serena/project.yml` 是既有變更，不屬於本功能、不得 commit）
- PHPUnit 全套與 `--group issue-131`（第一波結束為 287，確認現值）；PHPStan 文字數字 + JSON 快照；i18n 未翻譯數；`eslint`（不帶 `--fix`）errors / warnings；type-check；`js/dist` 是 prod 還是 dev-server
- E2E canary（`01-admin/smoke`、`02-frontend/008-classroom-chapters`、`03-integration/015-e2e-helper-contract`）**加上**第一波三支（`01-admin/assignment-grading`、`02-frontend/021-classroom-assignments`、`03-integration/016-assignment-http-contract`），各連跑兩次。理由：master v1.8.11（`a070a33a`）改了前台 React bundle 的載入時機，第一波 E2E 在新 HEAD 上還沒跑過；不先量，W3 / W4 的紅燈分不出是誰造成的
- 建立 `.progress-wave2.json`；向用戶確認 UC-W2-1 ~ 4（§15），只硬性擋標註的步驟

### W1：後端討論串（讀 / 發 / 已讀）

- **Feature**：查詢作業討論串、發送作業討論訊息｜**依賴**：W0｜**風險**：R-W2-01、02、09、10、19｜**規模**：M（約 9 檔、1500 行含測試）
- **Red**（test-creator）
  - `AssignmentWave2TestCase.php`（abstract）：`seed_thread_fixture()`（Background 的 Admin / Alice / Bob、課程 100、章節 201、作業 400、繳交 500 / 501、訊息 600–602，回傳 feature ID → 實際 ID 對照）、`nonexistent_id()`（= 目前最大 ID + 100000；**不要用字面 9999**——InnoDB auto_increment 不隨交易回滾，長跑後真實 ID 可能撞到 9999）、`get_last_message_row( submission_id )`、`dispatch_with_file( route, array $file )`（W2 用）
  - `ListAssignmentThreadTest.php`：feature 11 個 Example，加邊界：已有 `read_at` 的訊息不被覆寫；連讀兩次結果相同；POST 不會把任何訊息標已讀；管理員讀取作業在垃圾桶的繳交 → 200（DEC-W2-03）；同一秒兩則依 id 排序
  - `PostAssignmentMessageTest.php`：feature 11 個案例，加邊界：10000 字成功；只有空白成功且原樣保存；`body` 為陣列 / 數字 / 沒帶 → 400 同訊息；`<b>x</b>` 原樣保存；`C:\path "q"\` 存入後逐字相同（slash 對稱）；學員端回應無 `sender_id` / `sender_email`
  - `ThreadUnreadSummaryConsistencyTest.php`：學員發 → 列表 `unread_count=1 / message_count=1 / last_message_at=now`；管理員讀 → `unread_count=0`；管理員發 → `message_count=2`、`unread_count` 仍 0；學員讀 → 老師訊息有 `read_at`；學員重新繳交後討論串仍在（同一 submission id）
  - `AssignmentWave2ApiContractTest.php`（W1 部分）：4 條路由已註冊且 method 正確；4 個 callback 第一個語句是 `\nocache_headers()`（沿用 `assert_callback_starts_with_nocache_headers_for`）；訪客 4 條皆 401；Bob 呼叫管理端 GET / POST（**POST 不帶 body**）皆 403 `rest_forbidden`（DEC-W2-02 守門）
  - **防說謊要求**（寫進 prompt）：權限與所有 feature Example 一律 `dispatch()`；「不檢查觀看權」兩條在 When 之前先斷言 `Utils::can_access_course( 100, alice ) === false`；「不含 sender_email」要先 seed 一則**有** `sender_email = admin@test.com` 的老師訊息，同時斷言每筆鍵集合 = 6 個白名單鍵、且 `wp_json_encode( $data )` 不含 `admin@test.com`；`mine` 用 `assertSame( true, … )`；read_at 斷言字面 `2026-09-17 10:00:00`（來自 `set_now`），不可用 `assertNotNull`
- **Green**（general-purpose PHP）
  0. `.claude/rules/i18n.rule.md` 術語表補 4 個詞（§4.1）
  1. `Model/Message.php`
  2. `MessageRepository`：`insert / find / list_by_submission / mark_read`；`thread_summary` 改用常數
  3. `Service/Thread.php`（§7.1、7.2；時間一律 `wp_date( 'Y-m-d H:i:s', Clock::now() )`）
  4. `Core/MyApi.php`、`Core/Api.php` 路由與 callback（`respond()` 包例外；`get_body_args()` 沿用）
  5. i18n：`You do not have permission to access this submission`、`Message must be between 1 and 10000 characters`
- **Refactor**：`Thread` 的學員 / 管理員兩條路徑共用 `resolve_submission()` 與 `validate_body()`；每個方法 < 50 行
- **成功標準**：關卡全過；`grep -rnF "sender_email" inc/classes/Resources/Assignment/Core` 只出現在寫入路徑，不在任何輸出陣列

### W2：後端 CSV 匯入 + 真實 HTTP 契約

- **Feature**：匯入批改結果CSV｜**依賴**：W1（共用 `Api.php`、`manual.json`；業務上只依賴第一波 `Grading`）｜**風險**：R-W2-03 ~ 08｜**規模**：L（約 10 檔、2000 行含測試）
- **開工前**：UC-W2-1 已回覆（否則先寫其餘測試，3 條邊界測試暫缺並在 manifest 註明）；UC-W2-2 若選 B，先由用戶 / clarifier 改 feature 再開工
- **Red-1**（test-creator，PHPUnit）
  - `ImportGradingCsvTest.php`：
    - dispatch 層：Alice（不帶檔）→ 403；Admin 不帶檔 → 400「請選擇要匯入的 CSV 檔案」；Admin `size = 10485761` → 400「檔案過大（上限 10MB）」；`error = UPLOAD_ERR_INI_SIZE` → 同訊息；`size = 10485760` → 訊息**不是**「檔案過大」（邊界）
    - Service 層（`CsvImport::import( $csv, $admin_id )`，CSV 由 Data Table 組成：第一列表頭、CRLF、無 BOM）：無法解析（未閉合引號）→ 計數全 0 + `[{row:0, message:'CSV 解析失敗'}]` + 500 / 501 仍 pending 且 graded_at 為空；兩列 → 狀態 / 評語 / `graded_by = admin_id` / `graded_at = 2026-09-17 10:00:00` 與 `{2,2,0,0}`；狀態別名 9 例（dataProvider）；表頭別名 2 例；回饋空白清空（502）；副作用（`finished_at` + `capture_mail` → `run_scheduled_actions( 'power_email_send_assignment_graded' )` → 寄給 alice，**不可只看 `did_action`**）；缺 id（`{2,1,1,0}` + row 2，且 501 確實變 pass）；狀態 `ok`（`{1,0,1,0}` + 訊息字面值，500 仍 pending）；9999 + 500（`{2,1,0,1}` + row 2「繳交紀錄不存在」，500 為 pass）
    - 邊界：狀態空白 →「狀態無法辨識（空白），需為 pending/pass/fail」；中間夾全空列時不計 total 且列號依 DEC-W2-11；回饋超過 10000 字 → failed + 該訊息；`abc` → failed「繳交紀錄不存在」；作業在垃圾桶的繳交 → failed「繳交紀錄不存在」；同一 id 兩列 → 最後一列為準；沒有回饋欄 → 評語清空（DEC-W2-15 現行行為）；UC-W2-1 的三條（空檔、只有表頭、5001 列 → row 0 且 DB 不變）
  - `AssignmentCsvParserTest.php`：BOM、CRLF / LF / CR、引號內逗號與換行、`""`、表頭正規化（大小寫、半形 / 全形空白）、四種無法解析、比表頭多 / 少的格、表頭重複後者覆蓋、全空列略過
  - `GradingCsvRoundTripTest.php`：`Csv::write_grading_csv()` 的串流內容原封不動餵給 `CsvImport::import()` → updated = 列數、狀態不變、含逗號 / 雙引號 / 換行的評語逐字保留；英文 msgid 表頭的 CSV 可匯入；以 `gettext_with_context` filter 模擬 ja 譯文後匯出再匯入可辨識；把「可改」欄改成 fail 而「目前狀態」欄仍是「通過」→ 結果為 fail（目前狀態欄不被誤用）
  - `AssignmentWave2ApiContractTest.php` 補 import 路由：註冊、nocache、訪客 401、Alice 403
- **Red-2**（test-creator，E2E，比照第一波 ADJ-04 提前到後端階段）：`03-integration/017-assignment-thread-import-http-contract.spec.ts`
  - 管理員**真實 multipart** 匯入（BOM + CRLF；一列 pass、一列狀態 `ok`）→ 200、`{total:2, updated:1, skipped:1, failed:0}`、錯誤列號 3；再 GET 列表確認該繳交為 pass（驗 API 層確實把目前使用者當 actor、確實讀了暫存檔——PHPUnit 碰不到這段）
  - 以 REST 取得批改用 CSV 原始位元組 → 原封不動 multipart 匯入 → `failed = 0`、`skipped = 0`（**站台實際語系**下的 R-21 驗證）
  - 學員呼叫 import → 403；訪客 → 401；管理員不帶檔 → 400（斷言 status + code，訊息依站台語系）
  - 學員 GET 自己的討論串：200 且真實回應 `Cache-Control` 含 `no-cache`；他人的 → 403；訪客 → 401
  - 不用 `test.describe.serial`（第一波 016 的教訓：序列中一支紅，後面全部 did not run）
- **Green**（general-purpose PHP）：`Utils/CsvParser.php` → `Service/CsvImport.php`（§7.3）→ `Api.php` import callback（DEC-W2-13，`wc_set_time_limit(0)`）→ i18n
- **Refactor**：抽 `Utils/UploadedFile`（第一波 `UploadAssignmentAttachmentTest` 等必須維持綠）；`Csv::grading_headers()`（第一波兩支匯出測試必須維持綠）；`CsvImport` 每個方法 < 50 行
- **驗證關卡追加**：017 連跑兩次 + integration canary；非功能量測一次——本機以 500 列（全 pass、有模板）匯入記錄耗時與記憶體，寫進 `.progress-wave2.json`，**不設門檻**，超過 30 秒時回報主窗口評估 R-W2-06
- **選用**：此階段後由用戶喚醒 `@zenbu-powers:security-reviewer` 審上傳層與解析器

### W3：Admin SPA（批改頁的討論串與匯入）

- **UI**：`ui/作業批改頁.md` 第二波兩項｜**依賴**：W1、W2｜**風險**：R-W2-09、11｜**規模**：M（約 9 檔、900 行 TSX + 1 支 E2E）
- **Red**（test-creator，E2E）：`01-admin/assignment-thread-import.spec.ts`（新檔，不改第一波 `assignment-grading.spec.ts`）
  - 資料以 REST 準備（學員身分 nonce 繳交、發訊息；建使用者走 `ensureUserViaPowerhouse`，本站沒有 `/wp/v2/users`）
  - 列表「討論」徽章顯示 1 → 開詳情 → 討論串顯示學員訊息（「學員」）→ 關抽屜後徽章歸 0（等 network idle + 斷言徽章數字，不靠時間）
  - 輸入回覆按 Enter → 新訊息出現在討論串（「老師」）→ 以學員 REST 讀取，確認收到 `mine=false` 的老師訊息
  - XSS：學員訊息 `<img src=x onerror="window.__pcXss=1">` 以文字顯示，討論串內沒有 `img`，`window.__pcXss` 為 undefined
  - 匯入：`setInputFiles` 上傳兩列 CSV（一列 pass、一列 `ok`）→ 頁內結果顯示 1 / 1 / 0 與「第 3 列」錯誤 → 列表狀態變通過、統計更新
  - 往返：按「匯出批改用 CSV」取得 `download` → 把下載檔直接餵給匯入 → 失敗 0
  - 斷言 DOM 狀態，**不等 toast**（第一波的 `waitForMessage` 兩套提示管道、Skeleton 競態教訓）
- **Green**（`@zenbu-powers:react-master`）：DEC-W2-17、18；`hooks` / `types`；所有字串 `@wordpress/i18n` → `manual.json` → `pnpm run i18n:build`
- **Refactor**：討論串的 query key 統一；匯入成功只 invalidate 列表、統計、課程分頁；props 型別放 `types/`
- **驗證關卡（前端版）**：`eslint`（不帶 `--fix`）0 errors、type-check exit 0、`pnpm run build`（產出 `js/dist`；注意它內含 i18n:build）→ E2E 新檔連跑兩次 + `01-admin/smoke` canary + 第一波 `assignment-grading.spec.ts` 回歸；瀏覽器驗證前看 `reference_admin_spa_browser_verify` 的 license gate 與 dist 新鮮度

### W4：前台教室討論串

- **UI**：`ui/教室作業區塊.md`「討論串（第二波）」｜**依賴**：W1（可與 W3 平行，見 11.1）｜**風險**：R-W2-09、11、16、17｜**規模**：M（約 6 檔、600 行 + 1 支 E2E）
- **Red**（test-creator，E2E）：`02-frontend/022-classroom-assignment-thread.spec.ts`（新檔）
  - 有繳交的卡片有「與老師討論」、沒繳交的沒有；管理員預覽模式沒有討論串
  - 老師訊息以管理員 REST 準備 → 學員展開 → 看到「老師」訊息 → 再以學員 REST 讀一次，老師訊息 `read_at` 不為空（展開 = 標已讀）
  - 學員送出 → 出現「我」的訊息；管理員 REST 讀取後，學員重新整理並展開 → 自己的訊息顯示「已讀」
  - 頁內首次繳交成功後，不重新整理就出現「與老師討論」且可送訊息（`data-submission-id` 已寫入）
  - 狀態為通過（API 批改）後重新載入：繳交表單隱藏、討論串仍在
  - XSS：同 W3
  - （選做）線性觀看：先在未開啟時繳交，再開啟線性觀看使該章節鎖定 → 討論串仍可用（D29）
- **Green**（general-purpose，PHP 模板與 TS 同一個 agent）：DEC-W2-19；`thread.ts`（GET / POST、`X-WP-Nonce: pc_data.nonce`、`credentials: 'same-origin'`、全程 `textContent`）；`index.ts` 綁定；`submit.ts` 成功後寫 `data-submission-id` 並顯示討論串；模板用 `esc_html__` / `esc_attr__`
- **Refactor**：訊息節點只來自 PHP `<template>`，TS 不拼 HTML；`grep -nF "innerHTML" inc/assets/src/events/assignments/` 必須為空
- **驗證關卡（前端版）**：`pnpm run build:wp` 後 `grep` `inc/assets/dist/` 確認有 `Discuss with teacher`（dist 新鮮度）→ E2E 新檔連跑兩次 + `02-frontend/008` canary + 第一波 `021-classroom-assignments.spec.ts` 回歸；看前台前依 `reference_local_frontend_verify_playbook` 處理 license bypass、WP Rocket、`confirm_admin_email`；**LC 檢查先看 `plugin.php.e2e-lock`**，鎖內有別人的活 PID 時不動任何檔案、只在回報註明

### W5：收尾驗收與文件同步

- 全套 PHPUnit 0 failure / 0 error；`--group issue-131-w2` 與 `--group issue-131` 數字符合；PHPStan ≤ 95；本波變更檔 PHPCS 0 error；ESLint 0 errors；type-check exit 0；`build` 與 `build:wp` 都 exit 0
- E2E：本波 3 支 + 第一波 3 支 + 3 支 canary，連跑兩次都綠、無 flaky
- `git grep -nE "'lc'[[:space:]]*=>" -- plugin.php` 為空、`plugin.php.e2e-backup` 不存在（先看鎖）
- 文件同步（`@zenbu-powers:doc-updater`）：§6.3 列的檔案；§10 規格疑義依用戶裁決修正 specs
- 瀏覽器截圖：批改頁抽屜討論串、匯入結果（含錯誤列）、教室卡片討論串（展開 / 已讀）

### 11.1 階段依賴與執行順序

```
W0 ──▶ W1 ──▶ W2 ──▶ W3 ──┐
         │                 ├──▶ W5
         └─────────▶ W4 ───┘
```

- **建議預設：全部依序**（W0 → W1 → W2 → W3 → W4 → W5）。理由同第一波：`manual.json` 與 `Api.php` 會衝突、worktree 跑 PHPUnit 有坑（R-28）
- 若要平行：W4 可在 W1 通過後與 W2 / W3 同時進行，條件是 W4 lane **不跑 PHPUnit**、`manual.json` 由主窗口在 lane 結束時依序合併並統一跑 `pnpm run i18n:build`；兩條 lane 同時跑 E2E 時必然共用 lc-bypass 鎖，嚴禁手動還原 `plugin.php`

---

## 12. Agent 分工

| 階段 | Red | Green / Refactor | 必讀 |
|------|-----|------------------|------|
| W0 | — | 主窗口親跑 | 本計劃 §11 W0、`tdd-blueprint.md` §3.2 標準命令 |
| W1、W2 | `@zenbu-powers:test-creator` | general-purpose（PHP） | 12.1 |
| W3 | `@zenbu-powers:test-creator`（E2E） | `@zenbu-powers:react-master` | 12.2 |
| W4 | `@zenbu-powers:test-creator`（E2E） | general-purpose（PHP 模板 + vanilla TS） | 12.1 + 12.2 |
| W5 | — | `@zenbu-powers:doc-updater` + 主窗口跑驗證 | §11 W5 |
| 選用 | — | `@zenbu-powers:security-reviewer`（用戶喚醒才上場；建議 W2 後審上傳 / 解析、W4 後審 XSS） | — |

### 12.1 PHP 實作 agent 的 prompt 附件

- 專案規範：`.claude/CLAUDE.md`、`.claude/rules/wordpress.rule.md`、`.claude/rules/i18n.rule.md`
- Skill：`zenbu-powers:aibdd-auto-tdd`（green / refactor，php）、`wordpress-i18n`
- 規格：本計劃該階段段落 + §4 DEC-W2 + §4.2 介面契約；對應 feature；`specs/entity/erm.dbml`（`pc_assignment_messages` 與其 Note）；`specs/api/api.yml`（L1371 起的 schemas、L7386 import、L7479 管理端 messages、L7758 學員端 messages）
- 參考實作：`C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\course-assignment-thread.service.ts`、`course-submission-csv.service.ts::importGrades`、`apps\api-gateway\src\common\csv\csv-import.helper.ts`
- 對照程式：`Resources/Assignment/Service/Grading.php`、`Service/MessageRepository.php`、`Core/MyApi.php::get_uploaded_file()`、`Service/Csv.php::write_grading_csv()`
- Memory：`feedback_verification_lies.md`、`project_wp_slash_symmetry.md`、`project_test_baseline_202608.md`
- 硬性限制：不改 schema；不開交易（DEC-W2-14）；路由不宣告 args（DEC-W2-02）；callback 第一句 `\nocache_headers()`；SQL 一律 `$wpdb->prepare()`；`$wpdb->insert/update` 不 `wp_slash`；`final class` + `declare(strict_types=1)`；不得在 `plugin.php` 寫 `'lc' =>`；不得手改 `.po`；中文不得當 msgid；改 PHP 不得用非 raw 的 Python 字串（第一波 `rim(` 事故）

### 12.2 前端 agent 的 prompt 附件

- 專案規範：`.claude/rules/react.rule.md`、`.claude/rules/i18n.rule.md`、`.claude/rules/e2e-testing.rule.md`（E2E 作者）
- 規格：`specs/ui/作業批改頁.md`、`specs/ui/教室作業區塊.md`、api.yml 對應段落、§4 DEC-W2-17 ~ 19
- 參考實作：`zenbu-site-2.0\apps\web\app\zb-course\submissions\SubmissionsManager.tsx`（`TeacherThread`、`handleImportFile`、匯入摘要卡）、`apps\web\app\(public)\[locale]\account\courses\[slug]\AssignmentReviewView.tsx`（`SubmissionCard`、`Thread`）
- 對照程式：`js/src/pages/admin/Assignments/`、`js/src/components/formItem/VideoInput/hooks/useSubtitles.tsx`（multipart + `useCustomMutation`）、`inc/templates/components/assignment/card.php`、`inc/assets/src/events/assignments/`
- Memory：`reference_admin_spa_browser_verify.md`、`project_frontend_assets_build.md`、`reference_local_frontend_verify_playbook.md`、`project_e2e_helper_traps.md`、`project_lc_bypass_concurrency_lock.md`、`feedback_verification_lies.md`（E2E 側整段）

### 12.3 測試 agent（test-creator）的 prompt 附件

- Skill：`zenbu-powers:test-creation-playbook`、`zenbu-powers:aibdd-auto-tdd`（red，php）
- `tests/Integration/Assignment/AssignmentTestCase.php`（既有 helper，**唯讀**）、`AssignmentGradedEmailTest.php`（寄信斷言寫法）、`UploadAssignmentAttachmentTest.php`（上傳錯誤碼對應的測法）、`ExportGradingCsvTest.php`（讀 CSV 串流）
- 第一波**沒有**「dispatch 帶檔案」的前例：`dispatch_with_file()` 用 `WP_REST_Request::set_file_params( [ 'file' => [ name, type, tmp_name, error, size ] ] )`，不要碰 `$_FILES` 全域（API 層讀的是 `$request->get_file_params()`）
- E2E：`tests/e2e/03-integration/016-assignment-http-contract.spec.ts`（multipart helper、為何不用 serial）、`01-admin/assignment-grading.spec.ts`、`02-frontend/021-classroom-assignments.spec.ts`
- 規格：對應 feature、`specs/features/assignment/句型.md`（W24–W35、T27–T29、T35–T36）
- Memory：`feedback_verification_lies.md`、`project_e2e_helper_traps.md`、`project_test_baseline_202608.md`、`project_lc_bypass_concurrency_lock.md`
- 規則：斷言 zh_TW 譯文字面值，不用 `__()` 包期望值；不改第一波任何測試檔；不建 production stub

---

## 13. 測試策略

| 層級 | 範圍 | 工具 |
|------|------|------|
| 整合（主力） | 3 支 feature 的 43 個案例、DEC 邊界、往返相容、路由契約 | PHPUnit 9 + wp-phpunit（wp-env tests 容器） |
| 單元 | `CsvParser` | PHPUnit |
| E2E（HTTP 契約） | 真實 multipart 匯入、站台語系往返、討論串 GET 的禁止快取標頭、401 / 403 | Playwright `03-integration/017` |
| E2E（UI） | 批改頁討論串與匯入、教室討論串、XSS | Playwright `01-admin`、`02-frontend` |

**指令**

```bash
# 本波
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131-w2
# 第一波回歸
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131
# 全套
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit
# 靜態分析（分開跑）
vendor/bin/phpcbf <變更檔>; vendor/bin/phpcs <變更檔>; composer run phpstan
# 前端
pnpm run build                 # Admin SPA → js/dist（內含 i18n:build）
pnpm run build:wp              # 教室 vanilla TS → inc/assets/dist
cd tests/e2e && npx playwright test 03-integration/017-assignment-thread-import-http-contract.spec.ts
# i18n
pnpm run i18n:build
```

**關鍵邊界**：body 0 / 1 / 10000 / 10001 字、只有空白、非字串；檔案 10485760 / 10485761 bytes、INI_SIZE；九種狀態寫法與空白、`ok`；`submission_id` 空白 / `abc` / 不存在 / 作業在垃圾桶；同一 id 兩列；缺回饋欄；全空列夾在中間；未閉合引號、引號後殘字、欄內引號、Big5；空檔 / 只有表頭 / 5001 列（UC-W2-1）；已讀不覆寫；同秒兩則訊息。

**防止「綠燈但沒測到」**（本波專屬，另見第一波 §11）：
1. 每個測試先斷言成功狀態或期望錯誤碼，再斷言欄位 / DB（Red 階段不得出現意外綠）
2. 「不檢查觀看權」先斷言前置確實無權；「不含 email」先 seed 一個有 email 的訊息
3. 寄信用 `capture_mail()` + `run_scheduled_actions()`，不只看 `did_action`
4. 不存在的 ID 用 `nonexistent_id()`，不用字面 9999
5. 匯入的 API 層（讀暫存檔、actor = 目前使用者）PHPUnit 碰不到，一定要靠 017 的真實 multipart
6. E2E 斷言 DOM 狀態，不等 toast；每個 E2E Gate 連跑兩次並同跑 canary

---

## 14. 風險登記表（第一波 R-01 ~ R-28 沿用；以下為本波新增）

| ID | 等級 | 風險 | 緩解措施 | 階段 |
|----|------|------|---------|------|
| R-W2-01 | 高 | **參數驗證先於權限**：宣告 route args 會讓「非管理員 → 權限不足」變成 400 | DEC-W2-02；契約測試以「不帶 body / 不帶檔」斷言 403 | W1、W2 |
| R-W2-02 | 高 | **GET 有副作用**：被邊緣快取、預抓、React Query 自動重抓時會在使用者沒看的情況下標已讀，或快取命中時不標已讀 | nocache 第一句 + 017 真實標頭；UI 只在開抽屜 / 展開時載入，`refetchOnWindowFocus: false`，前台不預抓；`mark_read` 失敗不影響讀取 | W1–W4 |
| R-W2-03 | 高 | **整份重匯造成重寄信與統計失真**（DEC-W2-15），PC 預設篩選「全部」放大此效應 | UC-W2-2 由用戶裁決；預設照參考實作並寫進 wordpress.rule.md | W2、W3 |
| R-W2-04 | 中 | **Excel 實際產出的 CSV**：Big5、UTF-16、去掉 BOM、引號樣式 | DEC-W2-11 嚴格解析 + 單元測試；017 與 W3 E2E 用**真實下載檔**往返 | W2、W3 |
| R-W2-05 | 中 | **表頭本地化（R-21 收斂）**：匯出後改站台語系再匯入會辨識不到 | DEC-W2-09（中文別名 + 英文 msgid + 站台語系譯文，與匯出共用 `grading_headers()`）；往返測試含英文與模擬 ja | W2 |
| R-W2-06 | 中 | **大量匯入逾時**：每列 grade 都有 UPDATE、完成章節（連帶 chapter_finished 信與日誌）、排信 | 5000 列上限、`wc_set_time_limit(0)`；W2 做一次 500 列耗時量測；不做背景批次（HOLD SCOPE） | W2 |
| R-W2-07 | 中 | **測試交易被提前提交**：匯入若自開交易會造成跨次殘留（第一波 P3） | DEC-W2-14 禁止 `START TRANSACTION`；Refactor 時 `grep -rnF "START TRANSACTION" inc/classes/Resources/Assignment` 必須為空 | W2 |
| R-W2-08 | 中 | **`is_uploaded_file()` 在 PHPUnit 不成立**：匯入 API 層只能靠 E2E；主機上傳上限小於 10MB 時訊息上限字樣不準 | DEC-W2-13 大小檢查在前，dispatch 能驗 400；真實上傳交給 017 | W2 |
| R-W2-09 | 中 | **XSS**：訊息原樣存 | 前台 `textContent` + `<template>`、後台 React 文字節點、禁止 `innerHTML` / `dangerouslySetInnerHTML`；W3、W4 E2E 斷言不產生元素 | W3、W4 |
| R-W2-10 | 中 | **會說謊的測試**：空洞的前置、只斷言「沒有某欄位」、只看 action、字面 9999 撞真實 ID | §13 防線 1–5；Red Gate 意外綠 = 退回 | W1、W2 |
| R-W2-11 | 中 | **E2E 環境牆**：兩套提示管道、Skeleton 競態、`confirm_admin_email`、`/wp/v2/users` 不存在、單跑綠連跑紅 | 斷言 DOM、等 Skeleton 歸零、`ensureUserViaPowerhouse`、連跑兩次 + canary；W0 先量第一波三支在新 HEAD 的狀態 | W0、W2–W4 |
| R-W2-12 | 低 | **i18n**：`Read` / `Teacher` 未加 context 撞到既有或日後的同名 msgid；`manual.json` 的 context 鍵寫成 `context`（L15）；漏 ja | §4.1 指定 context；關卡比未翻譯數 | 全部 |
| R-W2-13 | 低 | **`mine` 依端別**：多位管理員時彼此訊息都是 mine；學員端只看到「老師」不知道是哪一位 | 照參考實作；寫進 wordpress.rule.md | W1 |
| R-W2-14 | 低 | **學員端沒有未讀提示**：學員要展開才知道有回覆（S-07） | UC-W2-3；本波不做 | — |
| R-W2-15 | 低 | **Schema 不得變更**（R-03 延伸） | 計劃已確認現有欄位與索引足夠；遇到需求停下回報 | 全部 |
| R-W2-16 | 低 | **master v1.8.11 改了前台 bundle 載入**（`a070a33a`），教室 TS 走 template handle 理論上不受影響但未驗 | W0 先跑第一波 `021` 兩次 | W0、W4 |
| R-W2-17 | 中 | **lc-bypass 併發鎖**：平行 lane 同時跑 E2E | 依 `project_lc_bypass_concurrency_lock`：先看鎖，嚴禁手動還原 / `git checkout -- plugin.php` | W2–W5 |
| R-W2-18 | 低 | **同一 CSV 重複 id 或同秒改判**：identifier 相同只寄一封（R-23 延伸） | 接受 | W2 |
| R-W2-19 | 低 | **sender_type 字串在寫入端與統計端漂移** | DEC-W2-04 常數化 + 一致性測試 | W1 |
| R-W2-20 | 低 | **規模超過 30 檔** | 六個階段、每階段關卡、`.progress-wave2.json` 檢查點 | 全部 |

---

## 15. 用戶確認點

| ID | 問題 | 建議 | 未回覆時的預設 | 擋住的步驟 |
|----|------|------|---------------|-----------|
| UC-W2-1 | 空檔 / 只有表頭 / 超過 5000 列要怎麼處理？（S-01） | 照參考實作：三種都 row 0、不處理任何列，訊息分別為「CSV 檔案為空」「CSV 沒有任何資料列」「單批匯入上限 5000 筆，請拆分後重試（目前 N 筆）」 | 照建議 | W2-R 的 3 條邊界測試 |
| UC-W2-2 | 整份重匯會讓已批改學員再收一次信、pending 列被寫入批改時間、缺回饋欄會清空評語，要不要防？（S-04） | A：照參考實作不防（HOLD SCOPE）；若擔心誤操作，可加 C：匯入按鈕旁一行提醒文字（不改 API）。B（略過未變更列）要改 feature | A | 選 B 擋 W2-R；選 C 擋 W3-R |
| UC-W2-3 | 參考實作學員端有未讀徽章，PC 規格沒有，要不要做？（S-07） | 本波不做，另開 issue | 不做 | 無 |
| UC-W2-4 | Excel 另存的 Big5 CSV 會得到「CSV 解析失敗」，要不要改成更明確的提示（例如「請另存為 CSV UTF-8」）或自動轉碼？ | 本波照 feature 回「CSV 解析失敗」；明確提示要新增 feature Example，另議 | 照 feature | 無 |

---

## 16. 限制條件（本波不做的事）

- 不改資料表 schema、不升 DB 版本
- 不做：刪除 / 編輯訊息、訊息附件、訊息通知（信件 / 站內）、學員端未讀數、收件匣輪詢、未讀優先排序、匯入預覽（dry-run）、背景批次匯入、Big5 轉碼
- 不改第一波的批改、匯出、列表行為（`Csv::grading_headers()` 抽取與 `UploadedFile` 抽取只是 Refactor，輸出逐字不變）
- 不修第一波以外的既有問題（例如 `add_student_to_course()` 自開交易）；只在發現時回報
- 禁止：`plugin.php` 寫入 `'lc' =>`、手改 `.po`、中文 msgid、`innerHTML` 插入使用者輸入、路由宣告 required args、匯入開交易

## 17. 成功標準

- [ ] §3 第 1–7 列每個案例都有 PHPUnit 測試，真的被執行、全部綠燈（`--group issue-131-w2` 計數 = manifest）
- [ ] §3 第 8–10 列 E2E 連跑兩次都綠、無 flaky；第一波三支 E2E 與 canary 同樣綠
- [ ] 全套 PHPUnit 0 failure / 0 error（測試數 = 1638 + 本波新增數）；`--group issue-131` = 287 + 本波新增數
- [ ] PHPStan ≤ 95、作業相關檔 0 條；本波變更檔 PHPCS 0 error；ESLint 0 errors；type-check exit 0
- [ ] i18n 未翻譯數 zh_TW ≤ 5、ja ≤ 199；`languages/` 7 檔隨功能 commit
- [ ] 任何討論串回應都不含 `sender_id` / `sender_email`；討論串 GET 的真實回應帶 `no-cache`
- [ ] 在站台實際語系下，「匯出批改用 CSV → 原檔匯入」失敗 0、略過 0
- [ ] 批改頁討論串、匯入結果、教室討論串都有截圖
- [ ] 文件同步完成；§10 規格疑義依用戶裁決處理

## 18. 預估複雜度：中高

後端約 5 個新檔，主要難點是 CSV 解析的嚴格度、表頭本地化與匯入的副作用語意；前端兩個介面各加一塊。依賴第一波已穩定的 `Grading`、`MessageRepository`、`Csv`，跨子系統的部分（PowerEmail、章節完成）全部經由既有 `Grading::grade()`，不新增耦合。

---

## 19. WHERE NEXT

1. **主窗口**：把 §15 UC-W2-1 ~ 4 問用戶（都有預設值；只有 UC-W2-2 選 B 會改 feature）
2. **派 `@zenbu-powers:tdd-coordinator`**：依本計劃產出 `specs/plan/issue-131-assignment-grading/wave2-tdd-blueprint.md`。沿用第一波藍圖 §3 全域規則、§4 共用 prompt 區塊（S-CONTEXT / S-RED-* / S-GREEN-* / S-REPORT-*）與 ADJ-01 ~ 12，只需新增：W0 基線（含第一波三支 E2E）、`.progress-wave2.json` 格式、第一波測試檔 sha1 凍結清單、W1–W5 的派發卡片
3. **主窗口**：依藍圖親跑 W0，然後派 W1-R（`@zenbu-powers:test-creator`）
4. **選用**：W2、W4 完成後由用戶喚醒 `@zenbu-powers:security-reviewer`
