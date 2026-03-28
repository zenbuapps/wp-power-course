@ignore @query
Feature: 查詢營收報表

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下已完成訂單：
      | orderId | userId | productId | amount | completed_at        |
      | 1001    | 2      | 100       | 1200   | 2025-06-01 10:00:00 |
      | 1002    | 3      | 100       | 1200   | 2025-06-15 14:00:00 |
      | 1003    | 4      | 100       | 1200   | 2025-07-01 09:00:00 |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Scenario Outline: 缺少 <缺少參數> 時操作失敗
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date   | end_date   |
        | <start_date> | <end_date> |
      Then 操作失敗，錯誤為「必要參數未提供」

      Examples:
        | 缺少參數   | start_date | end_date   |
        | start_date |            | 2025-12-31 |
        | end_date   | 2025-01-01 |            |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應回傳總營收和訂單數

    Example: 查詢全部時間範圍的營收
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date | end_date   |
        | 2025-01-01 | 2025-12-31 |
      Then 操作成功
      And 回應中 total_revenue 應為 3600
      And 回應中 total_orders 應為 3

  Rule: 後置（回應）- 應回傳額外統計欄位（學員數、完成章節數）

    Example: 查詢含學員數和完成章節數的營收
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date | end_date   |
        | 2025-01-01 | 2025-12-31 |
      Then 操作成功
      And 回應中應包含 student_count
      And 回應中應包含 finished_chapters_count

  Rule: 後置（回應）- 無訂單的時間範圍回傳零值

    Example: 查詢無訂單時段
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date | end_date   |
        | 2024-01-01 | 2024-12-31 |
      Then 操作成功
      And 回應中 total_revenue 應為 0
      And 回應中 total_orders 應為 0

  Rule: 後置（回應）- 支援時間範圍篩選

    Example: 篩選特定月份的營收
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date | end_date   |
        | 2025-06-01 | 2025-06-30 |
      Then 操作成功
      And 回應中 total_revenue 應為 2400
      And 回應中 total_orders 應為 2

  Rule: 後置（回應）- 支援時間粒度（day/week/month/quarter/year）

    Example: 依月份粒度查詢
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | start_date | end_date   | view_type |
        | 2025-06-01 | 2025-07-31 | month     |
      Then 操作成功
      And 回應中 revenue_by_period 應有 2 筆資料

  Rule: 後置（回應）- 支援依課程篩選

    Example: 篩選特定課程的營收
      When 管理員 "Admin" 查詢營收報表，參數如下：
        | course_id |
        | 100       |
      Then 操作成功
      And 回應中 total_revenue 應為 3600
