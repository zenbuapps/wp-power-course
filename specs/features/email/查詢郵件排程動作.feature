@ignore @query
Feature: 查詢郵件排程動作

  管理員可查詢所有屬於 Power Email 系統的 Action Scheduler 排程紀錄，
  包含自動觸發（course_granted、course_finish、course_launch、chapter_enter、chapter_finish）
  以及手動發送（power_email_send_users）。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And Action Scheduler 中存在以下屬於 group "power_email" 的 actions：
      | actionId | hook                          | status     | schedule            |
      | 9001     | power_email_send_course_granted | complete   | 2026-04-12 09:00:00 |
      | 9002     | power_email_send_users          | pending    | 2026-04-15 10:00:00 |
      | 9003     | power_email_send_chapter_finish | failed     | 2026-04-10 15:30:00 |
      | 9004     | power_email_send_users          | in-progress| 2026-04-13 10:30:00 |

  # ========== 核心：基本查詢 ==========

  Rule: 查詢回傳所有狀態的 action（不受 status 篩選）

    Example: 預設查詢回傳所有 power_email group 的 actions
      When 管理員 "Admin" 呼叫 GET /emails/scheduled-actions
      Then 操作成功
      And 回應為陣列，包含 actionId 9001、9002、9003、9004
      And 回應依 schedule 降序排列
      And 回應 header X-WP-Total 為 4

  # ========== 分頁 ==========

  Rule: 支援 posts_per_page 與 paged 分頁參數

    Example: 每頁 2 筆、取第 2 頁
      When 管理員 "Admin" 呼叫 GET /emails/scheduled-actions?posts_per_page=2&paged=2
      Then 操作成功
      And 回應包含 2 筆 actions
      And 回應 header X-WP-TotalPages 為 2

  # ========== 回應結構 ==========

  Rule: 每筆 action 包含 Action Scheduler 欄位與人類可讀的 recurrence 標籤

    Example: 回應結構完整
      When 管理員 "Admin" 呼叫 GET /emails/scheduled-actions
      Then 每筆 action 包含下列欄位：
        | field       |
        | id          |
        | hook        |
        | status      |
        | status_name |
        | args        |
        | group       |
        | log_entries |
        | claim_id    |
        | recurrence  |
        | schedule    |
      And 非週期 action 的 recurrence 為 "Non-repeating"
      And log_entries 為 "<ol>" 標籤包裹的日誌 HTML 片段
