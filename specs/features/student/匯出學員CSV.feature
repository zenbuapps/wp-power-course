@ignore @query
Feature: 匯出學員 CSV

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           | role          | billing_last_name | billing_first_name | last_name | first_name |
      | 1      | Admin | admin@test.com  | administrator |                   |                    |           |            |
      | 2      | Alice | alice@test.com  | subscriber    | 劉                | 小明               |           |            |
      | 3      | Bob   | bob@test.com    | subscriber    |                   |                    | Wang      | Bob        |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 1893456000

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- course_id 對應的課程必須存在

    Example: 課程不存在時匯出失敗
      When 管理員 "Admin" 匯出課程 9999 的學員 CSV
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- course_id 不可為空

    Example: 未提供 course_id 時匯出失敗
      When 管理員 "Admin" 匯出課程 "" 的學員 CSV
      Then 操作失敗

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳 CSV 格式（Content-Type: text/csv）

    Example: 成功匯出學員名單
      When 管理員 "Admin" 匯出課程 100 的學員 CSV
      Then 操作成功
      And Content-Type 應為 "text/csv"
      And CSV 第一列應為欄位標頭
      And CSV 應包含 2 筆學員資料
      And CSV 應包含以下欄位：
        | email          | last_name | first_name | display_name | expire_date | course_granted_at | course_progress |
        | alice@test.com | 劉        | 小明       | Alice        | 0           |                   |                 |
        | bob@test.com   | Wang      | Bob        | Bob          | 1893456000  |                   |                 |

  # ============================================================
  # Issue #227 — 匯出 CSV 套用學員 Tab 的 Filter 條件
  # ============================================================

  Rule: 後置（回應）- 匯出時套用當前 Filter 條件（search + progress）

    Example: 帶 search 參數時只匯出符合的學員
      When 管理員 "Admin" 匯出課程 100 的學員 CSV，search = "alice"
      Then 操作成功
      And Content-Type 應為 "text/csv"
      And CSV 應包含 1 筆學員資料（僅 Alice）

    Example: 帶進度篩選參數時只匯出符合的學員
      Given 課程 100 共有 10 個章節
      And 用戶 "Alice" 已被加入課程 100，已完成 10 個章節（進度 100%）
      And 用戶 "Bob"   已被加入課程 100，已完成 5 個章節（進度 50%）
      When 管理員 "Admin" 匯出課程 100 的學員 CSV，progress_operator = "="，progress_value = 100
      Then 操作成功
      And CSV 應包含 1 筆學員資料（僅 Alice）

    Example: 無 Filter 參數時匯出全部學員（向下相容）
      When 管理員 "Admin" 匯出課程 100 的學員 CSV
      Then 操作成功
      And CSV 應包含 2 筆學員資料
