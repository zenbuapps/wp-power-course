# Action Scheduler

## 描述
WordPress 背景任務排程系統，負責執行非同步任務，包含定時課程開課通知、Email 排程發送、批量學員匯入等。

## 關鍵屬性
- 排程間隔：10 分鐘（`power_course_schedule_action`）
- 主要任務：
  - `pc_batch_add_students_task`：批量匯入學員（每批 50 筆）
  - PowerEmail 排程發送：根據觸發條件發送自動化郵件
  - 課程開課排程：`course_schedule` 到期時觸發 `power_course_course_launch`
- 防重複機制：Email 使用 `identifier` 唯一鍵避免重複發送

## 作業相關排程（Issue #131）

- `pc_assignment_due_soon_scan`（每日 09:00，站台時區）：掃描截止時間落在 [現在, 現在 + 48 小時] 的作業，對仍有有效觀看權（含權限包持有者、課程已開課）且尚未繳交的學員觸發 PowerEmail `assignment_due_soon`（`features/assignment/作業截止提醒.feature`）。以 `pc_email_records.identifier`（含作業 id 與 due_at）去重，改截止時間後會再提醒。
