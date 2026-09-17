# Clarify Session 2026-09-17 14:15 — Issue #131 作業與作業批改

來源 Issue：https://github.com/zenbuapps/wp-power-course/issues/131（標籤：需要討論）
目標 codebase：`power-course`（WordPress + WooCommerce + Powerhouse）
參考實作：`C:\Users\User\DEV\zenbu\zenbu-site-2.0`（NestJS + TypeORM + PostgreSQL，非 WordPress）

## Idea

### Issue #131 原文（逐字）

> 1. 能綁在課程下，讓學生可以很好的知道要在哪個章節完成就好
> 2. 若能有像之前 learndash 的 linear 的進度控制是最好，例如要寫完作業、做完測驗才能往下
> 3. 老師要有一個畫面管理全部課程的作業
>
> 此討論串用來發想作業功能
>
> 以下為 AI 發想的（附圖）

Issue 附圖內容（人工轉錄，非使用者親寫，僅作參考不作為需求依據）：

- 圖1（AI 發想清單）：
  - 學生端：作業上傳（PDF、Word、圖片、程式碼壓縮檔）、進度顯示（已上傳 / 截止倒數）、版本控制（截止前重新上傳並保留歷史紀錄）、提交確認（成功訊息 + 時間戳記）、留言/備註
  - 老師端：作業收集面板（已交 / 未交 / 逾期）、批改工具（線上批註、打分數、給回饋）、下載功能（一次下載全部）、逾期管理（標記逾期、可設定是否接受補交）、評分與回饋
  - 系統：截止日期設定、通知提醒（即將到期）、權限控管（只有課程成員能上傳/查看）、安全性（防毒掃描）、整合性（與成績、進度追蹤連動）
- 圖2（競品批改列表，Hahow 風格）：篩選「單元（所有作業）/ 批改（所有狀態）/ 排列（從新到舊）」；欄位「單元（第 N 章・作業 N）/ 封面 / 標題（鎖頭圖示）/ 作者 / 發佈時間 / 批改狀態（未批改、已批改 + 日期、作業更新）」
- 圖3、圖4（競品作業詳情）：「僅限老師觀看」徽章、「第 2 章・作業 1・作者・日期」、學員作業內文；右欄「對這個作品有任何建議嗎？」按讚 + 回覆框「開始回覆同學吧」；回覆後顯示「已回覆 日期 / 再次回覆」；下方有留言串
- 圖5（競品手機版作業內文）

### 本次任務指令（dispatcher 原文摘要）

在 Power Course 釐清並產出「作業（Assignment）」與「作業批改（Grading）」功能的規格：roles、feature files、erm.dbml，把所有用戶情境、實體對應關係理清楚。範圍只到規格產出，不寫程式碼。參考 zenbu-site-2.0 的作業 / 批改實作；與 Power Course 架構不相容處標註差異並提出對應建模。

## 參考實作萃取（zenbu-site-2.0）

| 面向 | 參考實作做法 | 檔案 |
|------|-------------|------|
| 作業實體 | `course_assignments`：`lesson_id` XOR `chapter_id`（DB CHECK）、title、instructions、allow_text / allow_file / required_text / required_file、max_files（1-20，預設 1）、due_at（可空）、sort_order | `apps/api-gateway/src/course-assignments/entities/course-assignment.entity.ts`、`database/migrations/1786700000000-CreateCourseAssignments.ts`、`1791005000000-CourseIntegrityConstraints.ts` |
| 繳交實體 | `course_assignment_submissions`：UNIQUE(assignment_id, customer_id) 一人一份；text_body、attachments(jsonb: mediaId/fileName)、submitted_at、status(pending/pass/fail)、feedback、graded_by、graded_at | `entities/course-assignment-submission.entity.ts` |
| 討論串 | `course_assignment_messages`：submission_id、sender_type(student/teacher)、sender_id、sender_email、body、read_at（接收方已讀）、deleted_at | `entities/course-assignment-message.entity.ts`、`course-assignment-thread.service.ts` |
| 繳交規則 | 先驗報名（active 且未過期）→ 作業存在 → 形狀驗證（至少文字或附件、allow/required、附件數 ≤ max_files）→ UPSERT；重交覆寫同列並 reset status=pending、清 feedback/graded_* | `course-assignment-submissions.service.ts::submit` |
| 截止日 | 僅顯示（逾期標籤），**不擋繳交**；每日 09:00 掃 48 小時內到期作業，寄提醒給「有效報名且未繳交」學員（in-memory 去重，審查報告列為缺陷） | `course-assignments-cron.service.ts`、前端 `AssignmentsSection.tsx:215` |
| 重交限制 | 後端不限；前端在 status=pass 時隱藏重交按鈕 | `AssignmentsSection.tsx:313` |
| 批改 | status ∈ pending/pass/fail + feedback；任何時候可改判；pass → 自動將綁定 lesson 標記完成；pass/fail → 寄信通知 + 學員通知 feed | `course-assignment-submissions.service.ts::grade` |
| 批改權限 | `@Roles('admin','editor')` + staff 需 `students` 能力；**講師（instructor）角色不可批改**；無講師範圍過濾 | `admin-course-assignments.controller.ts`、`common/rbac/capability-routes.ts:158` |
| 批改頁 | 跨課程統一列表（status / course / assignment 篩選、分頁 ≤200）、統計（total/pending/pass/fail/passRate/avgGradingSeconds）、學生背景資料（本課程作業統計 + 學習進度）、附件 enrich、討論串未讀數 | `course-submission-reporting.service.ts`、`apps/web/app/zb-course/submissions/SubmissionsManager.tsx` |
| CSV | 單一作業繳交匯出；可往返 CSV（含 submission_id）匯出 → 批量改 status/feedback → 匯入（逐列重用 grade 以觸發副作用） | `course-submission-csv.service.ts` |
| 附件 | 學員先 `POST upload`（≤50MB）取得 mediaId，再於 submit 帶入；下載時驗證 mediaId 屬於本人 submission（防 IDOR） | `customer-course-assignments.controller.ts` |
| 刪除 | 硬刪；lesson/chapter/course 刪除 → FK CASCADE 刪作業 → 刪繳交；討論訊息需另外 purge | `hard-delete/unconstrained-relations.purge.ts` |
| 完課獎勵 | `all_assignments_completed` 觸發條件 = 課程所有作業皆 pass | `course-rewards/course-rewards.service.ts:167` |
| 模組開關 | `courses.assignments_enabled` feature flag | `packages/shared-types/src/course-feature-flags.ts` |

## 與 Power Course 架構差異與對應建模

| 參考實作 | Power Course 對應 | 理由 |
|----------|------------------|------|
| lesson / chapter 兩種父實體（XOR） | 只有一種父實體：`pc_chapter`（任意層級） | PC 章節與單元都是 `pc_chapter` CPT，`get_flatten_post_ids` 攤平後每一個都是可完成單位 |
| 作業為自訂表 | 作業為 CPT `pc_assignment`（post_parent = chapter_id，post_content = 說明，menu_order 排序） | PC 慣例：站長撰寫的內容實體用 CPT（chapter / announcement / access pass），每位學員的紀錄用自訂表（pc_chapter_progress / pc_user_access_pass） |
| UUID 主鍵、customer_id | bigint 自增主鍵、WordPress user_id | WordPress 慣例 |
| 報名判定 `EnrollmentAccessService` | `CourseUtils::is_avl()`（含權限包 OR 疊加）+ `!is_expired()` + `is_course_ready()` | 既有閘門 |
| 硬刪 + FK CASCADE | 移至垃圾桶（`wp_trash_post`）+ hook 連動；WP 清空垃圾桶（`before_delete_post`）時才清除繳交資料 | PC 章節刪除即 trash（`features/chapter/刪除章節.feature`） |
| 寄信寫死於 NotificationsService | 新增 PowerEmail 觸發時機（AtHelper slug），站長自建模板才寄 | PC 郵件以模板 + trigger_at 驅動（`features/email/觸發自動郵件.feature`） |
| 提醒 cron in-memory 去重 | Action Scheduler 每日掃描 + `pc_email_records.identifier` 去重 | 對齊 `AccessPass\Service\ExpiringNotifier`；修掉參考實作的多 instance 重寄缺陷 |
| admin/editor 角色 | `manage_options` 或 `manage_woocommerce`（ApiBase 預設 permission_callback） | PC 管理員定義（`specs/actors/管理員.md`） |
| 前端 Next.js | 後台 React SPA（Refine + antd）；學員端為 PHP 教室模板 + vanilla TS | PC 架構 |
| feature flag `assignments_enabled` | 不引入（PC 無子模組計費開關；是否要開關未被提及） | 不腦補 |
| `all_assignments_completed` 完課獎勵 | 不引入（PC 無完課獎勵模組） | 不腦補 |

## 組成分析（Composition Analysis）

| 組成塊 | 完整度 | 來源 | 備註 |
|--------|--------|------|------|
| 作業綁在章節下 | 高 | Issue #131 第 1 點 + 參考實作 | 一章多份、可排序（參考實作） |
| 作業題型（文字 / 檔案 / 必填 / 附件上限） | 高 | 參考實作 | — |
| 截止時間 | 高 | 參考實作 | 僅顯示 + 提醒，不擋繳交 |
| 學員繳交 / 重交 | 中 | 參考實作 | **歷史版本是否保留：參考實作覆寫、Issue AI 發想要保留 → Q2** |
| 批改（通過 / 未通過 + 評語） | 高 | 參考實作 | 分數制未被使用者提及（僅 AI 發想），不引入 |
| 作業與章節完成 / 線性觀看連動 | 低 | Issue #131 第 2 點明確要求；參考實作只有「通過 → 自動完成單元」 | **「寫完作業才能往下」的判準與擋法 → Q1** |
| 跨課程批改管理頁 | 高 | Issue #131 第 3 點 + 參考實作 | — |
| 批改者角色 | 高 | 參考實作（講師不可批改）+ PC 講師無後台權限 | 只有管理員批改 |
| 討論串 / CSV 匯出匯入 / 截止提醒 | 中 | 參考實作有；Issue 未提 | **Phase 1 是否全做 → Q3** |
| 附件儲存與隱私 | 低 | 參考實作用媒體庫 URL；PC 的 WP uploads 為公開路徑 | **→ Q4** |
| 通知（批改結果 / 截止提醒） | 高 | 參考實作 | 改用 PowerEmail 觸發時機 |
| 活動日誌 | 高 | PC 慣例 | 新增 log_type |
| 刪除作業 / 章節 / 課程後的繳交資料 | 高 | PC 慣例（trash） | — |
| 學員被移出課程 / 授權到期 | 高 | 參考實作 + PC 閘門 | 繳交資料保留；到期後不可操作 |
| 作品公開給同學看（競品圖） | — | 僅競品截圖，使用者未要求 | 不引入（BDY，未建模） |
| 防毒掃描（AI 發想） | — | 僅 AI 發想 | 不引入（BDY，未建模） |

## clarifier 決策（Round 1 答案套用後修訂版）

> 決策總原則（使用者 2026-09-17 指示）：**完全以 zenbu-site-2.0 的業務行為為準，不依 Issue #131**。
> 只保留兩類偏離：(a) WordPress / Power Course 架構不得不改；(b) Q4 受保護目錄。
> 下表「狀態」欄：維持 = 原決策與參考實作一致；修正 = 原決策偏離參考實作，已改回；移除 = 參考實作沒有，已拿掉。

| # | 決策（現行） | 狀態 | 依據 |
|---|------------|------|------|
| D1 | 作業掛在 `pc_chapter`（任意層級），一個章節可有多份作業；排序鍵 `menu_order`，預設 0，同值依建立時間 ASC | 修正（原為「預設排在最後」） | 參考實作 sort_order 預設 0、order by sortOrder, createdAt；單一父實體為 (a) |
| D2 | 作業為 CPT `pc_assignment`；繳交 / 附件 / 討論訊息為自訂表 | 維持 | (a) PC 儲存慣例 |
| D3 | 題型欄位：allow_text（預設 yes）/ allow_file（預設 no）/ required_text / required_file / max_files（1-20，預設 1）；allow_text 與 allow_file 至少一個為 yes；title 1-200 字；說明 ≤ 20000 字 | 修正（移除「required 需搭配 allow」驗證） | 參考實作 DTO + assertFormFlags |
| D4 | 截止時間 `due_at`（10 位 timestamp，0 = 無截止）；只用於學員端顯示逾期與截止提醒；不擋繳交 | 修正（移除 is_late 欄位） | 參考實作逾期只在前端計算；timestamp 格式為 (a) |
| D5 | 批改結果 status ∈ pending / pass / fail + feedback（≤ 10000 字）；不採分數制 | 修正（值域由 passed/failed 改回 pass/fail） | 參考實作 |
| D6 | 只有管理員（manage_options 或 manage_woocommerce）可出題與批改；講師不可 | 維持 | 參考實作 admin/editor；PC 權限對應為 (a) |
| D7 | 學員查看作業、繳交須有有效觀看權：is_avl（含權限包）且未到期且課程已開課；作業在垃圾桶視為不存在 | 修正（移除「章節 publish」；線性觀看鎖定章節不可繳交改由 D27 處理） | 參考實作 hasActiveAccess（active 且未過期；scheduled 無權）；PC 閘門對應為 (a) |
| D8 | 繳交 API 任何狀態都可重交（含已通過），重交覆寫同一筆、status 重設為 pending、清空 feedback / graded_by / graded_at；學員介面在已通過時隱藏重交按鈕 | 修正（原為「已通過不可重交」+ 保留版本） | 參考實作 submit() 與 AssignmentsSection.tsx:313；Q2 答案 |
| D9 | 刪除作業 = 移至垃圾桶；章節移至垃圾桶 / 還原時連帶作業；作業永久刪除時刪除其繳交與討論訊息（附件檔案保留，同參考實作不刪媒體） | 修正（附件檔案不刪） | 參考實作 FK CASCADE 刪繳交、訊息另行 purge、media 不刪；trash 為 (a) |
| D10 | 學員被移出課程時繳交紀錄保留，管理員仍可批改；重新取得權限後可看到先前結果 | 維持 | 參考實作 |
| D11 | PowerEmail 觸發時機 `assignment_graded`（每次批改為 pass / fail 各觸發一次）與 `assignment_due_soon` | 維持 | 參考實作兩封信；走 PowerEmail 模板為 (a) |
| D12 | ~~新增 log_type assignment_submitted / assignment_graded~~ | 移除 | 參考實作無活動日誌 |
| D13 | 繳交文字為純文字，≤ 50000 字；「有文字」以去除前後空白後非空判定 | 維持 | 參考實作 |
| D14 | 複製課程**不**複製作業 | 修正（原為複製作業設定） | 參考實作 duplicate() 只深 clone chapters / lessons / blocks |
| D15 | 作業可改掛到任一章節（含其他課程），parent_course_id 隨之更新 | 修正（移除「不可跨課程」） | 參考實作 update() 可改 lessonId / chapterId；繳交表不冗餘 course_id |
| D16 | 附件兩段式：學員先上傳（僅需登入，≤ 50MB，MIME 白名單同參考實作）取得 attachment_id，繳交時帶入；繳交表以 JSON 欄位 `attachments` 存 [{attachment_id, file_name}] | 修正（移除未綁定附件排程清除、覆寫時刪舊檔） | 參考實作 upload + submission.attachments(jsonb) |
| D17 | 程式碼命名 CPT `pc_assignment` / `Resources/Assignment`；注意既有 `Resources/Teacher/Service/Assignment.php` 指講師指派 | 維持 | 命名提醒 |
| D18 | 批改任何 status（含 pending）都寫入 graded_by 與 graded_at；統計平均批改時間計所有 graded_at 不為空的列 | 修正（原為 pending 清空） | 參考實作 grade() / stats() |
| D19 | 批改列表 / 統計 / CSV 不過濾學員觀看權狀態；每人每作業一筆 | 修正（移除「最新版」語意與「是否仍有觀看權」欄位） | 參考實作 listSubmissions |
| D20 | 截止提醒：每日 09:00 掃 [現在, 現在 + 48 小時] 內到期作業，寄給有效觀看權且尚未繳交的學員；以 `pc_email_records.identifier`（含 assignment_id、due_at）去重 | 維持（去重機制為 (a)） | 參考實作 cron；in-memory 去重改 DB 為 (a) |
| D21 | 批改為 pass 時，若作業所屬章節尚未完成，系統透過既有 `Chapter\Service\Progress::mark_finished` 標記完成（連帶既有 chapter_finished 事件）；已完成則不動；改判 fail / pending **不撤銷**完成；不檢查同章節其他作業 | 新增（取代原 Q1 推薦方案） | 參考實作 maybeMarkLessonComplete；使用 PC 完成機制為 (a) |
| D22 | 作業**不**阻擋章節完成：手動完成、影片 95% 自動完成、線性觀看解鎖邏輯皆不變 | 新增（取代原 Q1 推薦方案） | 參考實作無阻擋 |
| D23 | 連動完成章節或寄信失敗時只記錄 log，不影響批改結果 | 新增 | 參考實作 grade() 以 catch 包住副作用 |
| D24 | 學員下載附件：限上傳者本人；管理員下載：附件須出現在任一繳交中；兩者皆由伺服器驗權後串流 | 新增 | Q4 答案 (b)；參考實作管理員端 findSubmissionsByAttachment |
| D25 | 繳交時帶入的 attachment_id 必須是學員本人上傳 | 新增 | (b) 受保護下載需確保附件歸屬；參考實作未檢查（可把他人 mediaId 放進自己的繳交） |
| D26 | CSV 匯出上限 5000 筆（單一作業匯出與批改用匯出皆同） | 新增（orchestrator 決定） | 參考實作呼叫時傳 limit 5000，但列表服務 listByAssignment / listSubmissions 內部把 limit 夾到 200，屬 bug；照參考實作原本意圖寫 5000 |
| D27 | 課程啟用線性觀看時，學員不可繳交鎖定章節的作業；鎖定判定沿用 `LinearViewing::is_chapter_locked`（最遠進度模式），`LinearViewing::is_exempt`（manage_woocommerce）豁免；錯誤訊息沿用既有「此章節尚未解鎖，請先完成前面的章節」（403） | 新增（使用者決定） | 線性觀看是 Power Course 才有的功能，參考實作沒有對應行為；不擋的話學員可經 API 繳交鎖定章節作業，批改通過後章節被標為完成，依最遠進度模式連帶解鎖前面所有章節，繞過線性觀看 |
| D28 | 下載作業附件回應帶禁止快取標頭（nocache_headers） | 新增（orchestrator 決定） | 沿用 Power Course 端點慣例（Issue #216：防 LiteSpeed / Cloudflare 等邊緣快取）；參考實作回傳 URL 無此需求 |
| D29 | 其他端點不加線性觀看鎖定：上傳附件（不綁定作業、不影響進度）、討論串讀寫（需既有繳交、不影響進度）、查詢我的作業（唯讀，PC 既有鎖定也不擋留言等讀取 API）、批改與 CSV 匯入（管理員操作既有繳交） | 新增 | PC 既有線性觀看只擋「會改變完成狀態」的學員寫入（toggle-finish-chapters）與教室頁面；會導致章節完成的學員寫入只有繳交作業，已由 D27 擋下 |

## 待使用者澄清（Round 1）— 已全部回覆

| # | 類型 | 摘要 | 結果 |
|---|------|------|------|
| Q1 | 假設 | 作業與章節完成 / 線性觀看 | 照參考實作：不擋完成；通過時自動完成章節 |
| Q2 | 假設 | 重交是否保留歷史版本 | 照參考實作：覆寫 |
| Q3 | 邊界 | Phase 1 範圍 | 規格全留，兩波實作 |
| Q4 | 假設 | 附件儲存 | 受保護目錄 + 驗權下載 |

## Q&A

- Q: Q1 作業與章節完成 / 線性觀看的關係（「寫完作業才能往下」判準）→ A: 用戶原話「先不要管 github 上的 issue 怎麼說，先完全參照 zenbu-site-2.0 的作法」。作業不擋章節完成（手動完成、影片 95% 自動完成不受影響）；批改通過時自動把作業所屬章節標為完成；改判不撤銷（參考實作 grade() 只在 pass 時動作）。此原則擴及全部規則：來自 Issue #131 而非參考實作的規則（寫完才能往下、LearnDash 式批改方式設定）一律改回參考實作，參考實作沒有的拿掉。
- Q: Q2 重交是否保留歷史版本 → A: 用戶原話「看 zenbu site 2.0 怎麼做我們就怎麼做」。每人每份作業一筆，重交覆寫、status 重設 pending、清空評語；erm 移除 attempt_no / is_latest，唯一鍵 (assignment_id, user_id)；討論訊息改掛在繳交上，含已讀時間。
- Q: Q3 Phase 1 範圍（討論串 / CSV 匯出 / CSV 匯入 / 截止提醒）→ A: 規格全留，分兩波實作：第一波 = 核心 + 截止提醒 + CSV 匯出；第二波 = 討論串 + CSV 匯入。
- Q: Q4 附件儲存位置 → A: 存受保護目錄，驗權後才能下載（使用者明確選擇、唯一偏離參考實作的業務決定）。附件以獨立表（對應參考實作的 media 表）+ 繳交 JSON 欄位（對應參考實作 attachments jsonb）建模，由 clarifier 決定。
- Q: Round 2-1 線性觀看鎖定章節的作業能否繳交（拿掉鎖定檢查後可經 API 繞過線性觀看）→ A: 用戶選「擋下來 (Recommended)」。課程開啟線性觀看時，學員不能繳交鎖定章節的作業，鎖定判定沿用 PC 現有 LinearViewing 解鎖邏輯，不另寫一套。理由：線性觀看是 Power Course 才有的功能，參考實作沒有對應行為，因此此處無參考實作可依循（D27）。其他端點經檢查不需加鎖（D29）。
- Q: Round 2-2 CSV 匯出上限 → A: orchestrator 決定維持 5000。參考實作卡在 200 是 bug，照它原本的意圖寫（D26）。
- Q: Round 2-3 下載端點禁止快取標頭 → A: orchestrator 決定保留，沿用 PC 慣例（Issue #216）（D28）。
