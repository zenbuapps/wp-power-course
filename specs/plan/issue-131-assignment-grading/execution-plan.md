# Execution Plan — Issue #131 作業與作業批改（Assignment & Grading）

> 目標 codebase：`power-course`（wp-power-course）
> 業務依據：**zenbu-site-2.0 `apps/api-gateway/src/course-assignments/` 的業務行為**（使用者 2026-09-17 指示：不依 Issue #131，完全參照參考實作）
> 來源 clarify：`specs/clarify/2026-09-17-1415-issue131-assignment-grading.md`（Q1–Q4 已回覆，決策 D1–D26）
> 狀態：**Phase 01–04 完成**（Activity / Actor / Feature 含 Examples / erm.dbml / api.yml），可交接 planner

## 概覽

| 類型 | 數量 |
|------|------|
| Create | 20 feature + 句型.md + 系統抽象.md + 3 activity + 3 ui + 4 table + 3 enum + 18 path（23 operation）+ 11 schema |
| Modify | 4 既有 feature（追加 Rule + Examples）+ 1 activity + 4 actor + 1 ui + erm（email_trigger_at +2 值、header）+ api.yml（tag、version、401 response、parameters） |
| Delete | 0（對既有規格） |

## 保留的偏離（其餘一律照參考實作）

| # | 偏離 | 類別 | 理由 |
|---|------|------|------|
| 1 | 作業掛 `pc_chapter` 單一父實體（參考實作 lesson XOR chapter） | 架構 | PC 章節與單元同為 pc_chapter |
| 2 | 作業為 CPT `pc_assignment`；繳交 / 附件 / 訊息為自訂表 | 架構 | PC 儲存慣例 |
| 3 | 刪除走垃圾桶，永久刪除才清除繳交與訊息；章節 trash / untrash 連動作業 | 架構 | PC 章節刪除即 trash |
| 4 | 報名判定 → `is_avl`（含權限包）+ `!is_expired` + `is_course_ready` | 架構 | PC 既有觀看閘門 |
| 5 | 通知改走 PowerEmail 觸發時機（需站長建立模板）；提醒去重改 `pc_email_records.identifier` | 架構 | PC 郵件機制；順帶修正參考實作 in-memory 去重多機重寄 |
| 6 | 章節自動完成改用 `Chapter\Service\Progress::mark_finished`（連帶既有 chapter_finished 事件） | 架構 | PC 章節完成機制 |
| 7 | 管理員權限 → `manage_options \|\| manage_woocommerce`；時間欄位 → 10 位 timestamp；課程篩選 → course_id | 架構 | PC / WordPress 慣例 |
| 8 | 附件存受保護目錄、驗權串流下載、不回傳 URL | Q4 使用者決策 | 唯一業務面偏離 |
| 9 | 繳交帶入的附件必須為本人上傳；學員下載限上傳者本人 | Q4 衍生 | 受保護下載必須確保歸屬（參考實作未檢查附件歸屬） |
| 10 | 下載回應帶禁止快取標頭 | 架構（orchestrator 已決定保留） | PC 端點慣例（邊緣快取，Issue #216） |
| 11 | CSV 匯出上限 5000 筆 | 參考實作 bug 修正（orchestrator 已決定） | 參考實作傳 5000，但列表服務內部 clamp 到 200 |
| 13 | 線性觀看啟用時不可繳交鎖定章節的作業（沿用 LinearViewing，manage_woocommerce 豁免） | PC 專屬功能（使用者決定） | 參考實作無線性觀看；不擋則經 API 繳交 + 批改通過可繞過線性觀看 |
| 12 | 不引入 feature flag（assignments_enabled）、完課獎勵、學員通知 feed | 架構 | PC 無對應模組 |

## 實作波次（Q3）

| 波次 | 範圍 | Features |
|------|------|---------|
| **第一波** | 核心出題 + 學員繳交 + 批改 + 截止提醒 + CSV 匯出 | 建立作業、更新作業、刪除作業、排序作業、查詢章節作業列表、查詢學員作業、上傳作業附件、下載作業附件、繳交作業、查詢作業繳交列表、查詢作業批改統計、查詢有繳交紀錄的課程、查詢作業學員背景資料、批改作業、作業截止提醒、匯出單一作業繳交CSV、匯出批改用CSV；既有 feature 追加：chapter/刪除章節、course/複製課程、email/觸發自動郵件、student/移除學員課程權限 |
| **第二波** | 討論串 + CSV 匯入 | 查詢作業討論串、發送作業討論訊息、匯入批改結果CSV |

> 第一波的 `查詢作業繳交列表` 回應欄位 unread_count / message_count / last_message_at 在討論串上線前固定回傳 0 / 0 / null；`pc_assignment_messages` 表可於第一波一併建立（DB 版本只升一次）。
>
> **補註（第二波完成後）**：討論串與匯入批改結果 CSV 已實作（分支 `feat/issue-131-wave2`，計劃見 `wave2-implementation-plan.md`）。三欄由 `MessageRepository::thread_summary()` 依 `pc_assignment_messages` 即時統計，沒有訊息的繳交仍是 0 / 0 / null。第二波的用戶裁決：UC-W2-1 空檔 / 只有表頭 / 超過 5000 列 → row 0、不處理任何列；UC-W2-2 = A，整份重匯不防、不加提醒文字（每一列都是一次完整批改，已批改學員重匯會再收一次批改通知）；UC-W2-3 不做學員端未讀徽章；UC-W2-4 Big5 等非 UTF-8 → 「CSV 解析失敗」。

### 後續事項（第二波完成後補記，均未實作）

「類型」欄：**既有問題** = 與本功能無關、第二波期間發現的既有缺口；**本功能延伸** = 本功能留下、後續可再處理的事項；**測試儀器** / **測試穩定度** = 測試工具與環境面的缺口（同樣不屬於本功能的交付範圍）。

| 類型 | 事項 |
|------|------|
| 本功能延伸 | 學員端未讀徽章（UC-W2-3）：參考實作有，PC 規格沒有，第二波不做；建議另開 issue |
| 本功能延伸 | Big5 友善提示（UC-W2-4）：Excel「另存 CSV（逗號分隔）」在台灣是 Big5，匯入只回「CSV 解析失敗」；是否改成「請另存為 CSV UTF-8」之類的明確提示需新增 feature Example，另議 |
| 本功能延伸 | 大檔匯入仍可能被主機閘道（60～100 秒）截斷：前端 30 秒逾時已修（`useImportGradingCsv` 改 10 分鐘，commit `9d343353`），過去前端先報錯、後端卻照樣套用完並寄信；依 UC-W2-2 = A 不防重匯，若要根治需改背景分批處理 |
| 測試儀器 | 017 / 016 對已登入請求的 `Cache-Control` 斷言只證明 stack 沒有剝除標頭：WP 核心對已登入的 REST 請求本來就送 `no-cache`（`class-wp-rest-server.php` L487–501，WP 7.1.2），不能證明 callback 有呼叫 `nocache_headers()`；真正守門是 PHPUnit 的 `token_get_all` 靜態檢查 |
| 測試儀器 | `tests/e2e/README.md` 的 LC 還原指令 `node -e "import('./helpers/lc-bypass.js')…"` 已壞：`.js` 不存在（ERR_MODULE_NOT_FOUND），改 `.ts` 在 Node v22.17.1 為 ERR_UNKNOWN_FILE_EXTENSION；可用寫法需加 `--experimental-strip-types` 並 import `.ts`（import 已實測，`revertLcBypass()` 未實際執行過）。README 在 `tests/` 下，本次文件同步不改，只在此記錄 |
| 既有問題 | 只讀 `get_json_params()` 的 POST 端點（`chapters/sort`、`settings`、`bundle_products/sort`、`emails/send-now`、`send-schedule`）在共用 axios 實例的 header 被污染（antd-toolkit `custom()` 把呼叫端 headers 寫進 `defaults`）後同樣會壞；根治應在 antd-toolkit `custom()` 改為 per-request headers。現行 `ensureJsonForNonPostRequests` 只修非 POST 的物件 payload |
| 既有問題 | PowerEmail 觸發條件 `course_finish`（「課程完成時」）可建立模板，但沒有任何程式接上 `COURSE_FINISHED_ACTION`，永遠不會寄出 |
| 既有問題 | `Chapter\Core\LifeCycle::add_chapter_unfinished_log` 在「標記未完成」後判斷 `progress == 100` 就觸發 `COURSE_FINISHED_ACTION`，疑為複製貼上錯誤（第二波已補傳 `$user_id` 使其對正確學員計算，邏輯本身待另案） |
| 既有問題 | `courses/update-students` 把觀看期限設成與現值相同時回 400；`course-students-show-all.spec.ts` 使用的分頁名「學員管理」已改名為「學員」 |
| 既有問題 | `inc/assets`（前台 vanilla TS）不在 ESLint 與 tsconfig 範圍內，沒有常態 lint / 型別檢查；`phpcs.xml` 排除 `*/tests/*`，指名檢查測試檔時會靜默通過、看起來像全部通過 |
| 測試穩定度 | 本機 Local 只有 2 個 PHP 工作程序，頁面並行請求排隊可達 20～30 秒；`02-frontend` 專案預設 storageState 為管理員，學員 `loginAs` 逾時時仍以管理員身分落到 wp-admin、斷言訊息誤導；E2E helper 對 502 HTML 回應只丟 SyntaxError、狀態碼被吞 |

## Phase 01: Discovery（完成）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | activities/章節作業管理流程.activity | 管理員 CRUD / 排序 |
| create | activities/作業繳交與批改流程.activity | 學員 × 管理員 × ActionScheduler 主流程 |
| create | activities/作業批改批量處理流程.activity | 課程分頁、統計、CSV 匯出 / 匯入 |
| modify | activities/學員學習旅程.activity | 自由 / 線性模式各加「繳交章節作業」分支（作業不阻擋完成） |
| modify | actors/管理員.md、學員.md、講師.md、ActionScheduler.md | 作業權限邊界 |
| create | ui/作業批改頁.md、ui/章節作業管理.md、ui/教室作業區塊.md | 三個介面 |
| modify | ui/教室頁面.md | 加入作業區塊 |

## Phase 02: Entity Modeling（完成，DBML parser 驗證通過：22 tables / 17 enums / 25 refs）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | Enum email_trigger_at | +assignment_graded、+assignment_due_soon |
| create | Enum assignment_status | publish / trash |
| create | Enum assignment_submission_status | pending / pass / fail |
| create | Enum assignment_message_sender_type | student / teacher |
| create | Table assignments | CPT pc_assignment view |
| create | Table pc_assignment_submissions | UNIQUE(assignment_id, user_id)；attachments JSON |
| create | Table pc_assignment_attachments | 受保護目錄檔案（對應參考實作 media） |
| create | Table pc_assignment_messages | 掛 submission_id，含 read_at / sender_email / deleted_at |

## Phase 03: BDD Analysis（完成）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | features/系統抽象.md | 實體、操作全景、前置狀態拆解、共用契約 |
| create | features/assignment/句型.md | 句型清單、QA 五維分析、覆蓋矩陣 |
| create | features/assignment/*.feature × 20 | Rules + Examples，無 `@ignore` |
| modify | features/chapter/刪除章節.feature | +作業 trash / untrash / 永久刪除連動（3 Rule） |
| modify | features/course/複製課程.feature | +作業不隨課程複製（1 Rule） |
| modify | features/email/觸發自動郵件.feature | +assignment_graded / assignment_due_soon 與作業變數（2 Rule） |
| modify | features/student/移除學員課程權限.feature | +繳交紀錄保留（1 Rule） |

## Phase 04: API Contract（完成，swagger-parser 驗證通過）

| 操作 | 目標 | 波次 |
|------|------|------|
| create | GET / POST `/assignments` | 一 |
| create | GET / PUT / DELETE `/assignments/{id}` | 一 |
| create | POST `/assignments/sort` | 一 |
| create | GET `/assignments/{id}/submissions/export` | 一 |
| create | GET `/assignment-submissions`、`/stats`、`/courses`、`/student-overview` | 一 |
| create | GET `/assignment-submissions/export` | 一 |
| create | PATCH `/assignment-submissions/{id}/grade` | 一 |
| create | GET `/assignment-attachments/{id}/download` | 一 |
| create | GET `/my/assignments`、`/my/assignments/{id}`；POST `/my/assignments/{id}/submit` | 一 |
| create | POST `/my/assignment-attachments` | 一 |
| create | POST `/assignment-submissions/import` | 二 |
| create | GET / POST `/assignment-submissions/{id}/messages` | 二 |
| create | GET / POST `/my/assignment-submissions/{id}/messages` | 二 |
| modify | tags +Assignments、info.version 0.13.0、components.responses +401NotLoggedIn、components.parameters +AssignmentIdPath / SubmissionIdPath | 一 |

## Phase 05-08: Implementation（planner / tdd-coordinator）

| 操作 | 目標 | 說明 |
|------|------|------|
| red-green-refactor | `inc/classes/Resources/Assignment/`（Core/CPT、Core/Api、Core/MyApi、Core/LifeCycle、Model、Service/{Crud,Query,Submission,Grading,Attachment,Reporting,Csv,Thread,DueReminder}） | 對照 `Resources/AccessPass`、`Resources/Announcement` 結構 |
| red-green-refactor | `AbstractTable` 新增 3 張表，`CURRENT_DB_VERSION` 1.1.0 → 1.2.0 | 對照 `create_user_access_pass_table` 與 `maybe_upgrade()` |
| red-green-refactor | 受保護附件儲存與串流下載 | 隨機檔名、Apache .htaccess 拒絕直連（Nginx 需另設或置於 web root 外）、`nocache_headers()` |
| red-green-refactor | 章節生命週期連動 | pc_chapter 的 `trashed_post` / `untrash_post` / `before_delete_post` → 作業；pc_assignment 的 `before_delete_post` → 繳交與訊息 |
| red-green-refactor | 繳交時的線性觀看鎖定檢查 | 呼叫 `LinearViewing::is_enabled` / `is_exempt` / `is_chapter_locked`（`Utils/LinearViewing.php`），比照 `Chapter/Core/Api.php:339` 的 toggle-finish 寫法，不另寫解鎖演算法 |
| red-green-refactor | 批改副作用 | pass → `Chapter\Service\Progress::mark_finished`（先 `is_finished` 判斷）；pass / fail → PowerEmail；副作用 try/catch 只記 log |
| red-green-refactor | PowerEmail | `AtHelper` +2 slug、`Trigger/At.php` 註冊、Replace 變數 {assignment_title} {assignment_status} {assignment_feedback} {assignment_due_at} |
| red-green-refactor | 截止提醒排程 | 對照 `AccessPass/Service/ExpiringNotifier.php`（每日 09:00 站台時區 recurring action） |
| frontend-build | `js/src/pages/admin/Assignments/` | 跨課程批改頁（Refine + antd） |
| frontend-build | 課程編輯頁章節作業管理 | `js/src/pages/admin/Courses/Edit` |
| frontend-build | 教室作業區塊 | `inc/templates` + `inc/assets`（改完需 `pnpm run build:wp`） |
| i18n | 新字串 | `scripts/i18n-translations/manual.json` + `pnpm run i18n:build` |

## 未納入（參考實作沒有，或使用者未要求）

- 作業阻擋章節完成 / 線性觀看（Issue #131 提及，依使用者指示不做）
- 批改方式設定（需批改 / 自動通過）、重交歷史版本、逾期標記欄位、活動日誌 log_type
- 講師批改自己課程的作業；分數制；作品公開給同學；一次打包下載附件；防毒掃描
- MCP tools（可於實作完成後另立需求）
