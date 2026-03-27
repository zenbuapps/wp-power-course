@ignore @query
Feature: 查詢課程列表

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name           | _is_course | status  | price | created_at          |
      | 100      | PHP 基礎課     | yes        | publish | 1200  | 2025-01-01 00:00:00 |
      | 101      | React 實戰課   | yes        | publish | 2000  | 2025-02-01 00:00:00 |
      | 102      | Vue 入門       | yes        | draft   | 800   | 2025-03-01 00:00:00 |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- posts_per_page 必須為正整數

    Example: posts_per_page 為負數時使用預設值
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page |
        | -1             |
      Then 操作成功
      And 回應課程數量為 3

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應回傳分頁的課程列表

    Example: 預設查詢回傳所有已發布和草稿課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page | paged |
        | 10             | 1     |
      Then 操作成功
      And 回應課程數量為 3
      And HTTP Header X-WP-Total 應為 3
      And HTTP Header X-WP-TotalPages 應為 1

  Rule: 後置（回應）- 無符合條件的課程時回傳空列表

    Example: 搜尋無結果時回傳空列表
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | search        |
        | 不存在的課程名 |
      Then 操作成功
      And 回應課程數量為 0

  Rule: 後置（回應）- 支援依 status 篩選

    Example: 篩選已發布課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | status  |
        | publish |
      Then 操作成功
      And 回應課程數量為 2

  Rule: 後置（回應）- 支援依課程名稱搜尋

    Example: 搜尋包含關鍵字的課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | search |
        | PHP    |
      Then 操作成功
      And 回應課程數量為 1
      And 回應中應包含課程 "PHP 基礎課"

  Rule: 後置（回應）- 支援排序

    Example: 依建立日期降序排列
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | orderby | order |
        | date    | DESC  |
      Then 操作成功
      And 回應中第一筆課程應為 "Vue 入門"
