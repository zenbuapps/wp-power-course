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
