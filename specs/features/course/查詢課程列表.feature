@ignore @query
Feature: 查詢課程列表
  # 本 Feature 描述「後台管理端」課程列表查詢（管理員經 REST /courses）。
  # 後台預設回傳非垃圾桶狀態課程：publish / draft / future（排程中）/ private（私密）。
  # 前台學員端課程列表走獨立查詢路徑（shortcode [pc_courses]，僅 publish + visible），
  # 排程課程不會外洩至前台——見 features/shortcode/課程列表分頁.feature，本次不變更。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name         | _is_course | status  | price | created_at          | publish_date        |
      | 100      | PHP 基礎課   | yes        | publish | 1200  | 2025-01-01 00:00:00 |                     |
      | 101      | React 實戰課 | yes        | publish | 2000  | 2025-02-01 00:00:00 |                     |
      | 102      | Vue 入門     | yes        | draft   | 800   | 2025-03-01 00:00:00 |                     |
      | 103      | AI 繪圖入門  | yes        | future  | 2500  | 2025-04-01 00:00:00 | 2025-08-01 09:00:00 |
      | 104      | 內部教育訓練 | yes        | private | 3000  | 2025-05-01 00:00:00 |                     |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- posts_per_page 必須為正整數

    Example: posts_per_page 為負數時使用預設值
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page |
        | -1             |
      Then 操作成功
      And 回應課程數量為 5

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 後台預設回傳 publish / draft / future / private 四種狀態的課程

    Example: 預設查詢回傳所有非垃圾桶狀態的課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page | paged |
        | 10             | 1     |
      Then 操作成功
      And 回應課程數量為 5
      And HTTP Header X-WP-Total 應為 5
      And HTTP Header X-WP-TotalPages 應為 1

    Example: 排程中的課程不會從後台列表消失
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page | paged |
        | 10             | 1     |
      Then 操作成功
      And 回應中應包含課程 "AI 繪圖入門"

    Example: 私密課程出現在後台列表
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | posts_per_page | paged |
        | 10             | 1     |
      Then 操作成功
      And 回應中應包含課程 "內部教育訓練"

  Rule: 後置（回應）- 排程課程回應中應帶有預計上架時間

    Example: 排程課程回應包含預計上架的發佈時間
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | status |
        | future |
      Then 操作成功
      And 回應中課程 "AI 繪圖入門" 的 status 應為 "future"
      And 回應中課程 "AI 繪圖入門" 的預計上架時間應為 "2025-08-01 09:00:00"

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

    Example: 篩選排程中課程以盤點即將上架的內容
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | status |
        | future |
      Then 操作成功
      And 回應課程數量為 1
      And 回應中應包含課程 "AI 繪圖入門"

    Example: 篩選私密課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | status  |
        | private |
      Then 操作成功
      And 回應課程數量為 1
      And 回應中應包含課程 "內部教育訓練"

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
      And 回應中第一筆課程應為 "內部教育訓練"
