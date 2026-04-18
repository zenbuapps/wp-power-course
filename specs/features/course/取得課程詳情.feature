@ignore @query
Feature: 取得課程詳情

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | price | limit_type |
      | 100      | PHP 基礎課 | yes        | publish | 1200  | unlimited  |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | menu_order |
      | 200       | 第一章     | 100         | 1          |
      | 201       | 第二章     | 100         | 2          |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes

    Example: 查詢不存在的課程失敗
      When 管理員 "Admin" 查詢課程 9999 的詳情
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 必須為正整數

    Example: id 非正整數時查詢失敗
      When 管理員 "Admin" 查詢課程 "abc" 的詳情
      Then 操作失敗

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應包含完整課程 meta 與章節列表

    Example: 成功取得課程詳情
      When 管理員 "Admin" 查詢課程 100 的詳情
      Then 操作成功
      And 回應資料應包含以下欄位：
        | 欄位       | 期望值     |
        | name       | PHP 基礎課 |
        | _is_course | yes        |
        | status     | publish    |
        | price      | 1200       |
        | limit_type | unlimited  |
      And 回應中 chapters 數量為 2
      And 章節按 menu_order ASC 排列

  Rule: 後置（回應）- 應包含銷售方案列表

    Example: 課程有銷售方案時應回傳
      Given 課程 100 有以下銷售方案：
        | productId | name     | price |
        | 300       | 月費方案 | 399   |
      When 管理員 "Admin" 查詢課程 100 的詳情
      Then 操作成功
      And 回應中 bundle_products 數量為 1

  # ========== 後置（回應）- 空值契約 (Issue #203) ==========

  Rule: 後置（回應）- 促銷日期欄位在 DB 為空時回 null（不回 0、不回字串 "0"）

    Example: sale_date_range 為空時 GET 回 null
      Given 課程 100 的 wp_postmeta 中 "_sale_price_dates_from" 為 ""
      And 課程 100 的 wp_postmeta 中 "_sale_price_dates_to" 為 ""
      When 管理員 "Admin" 查詢課程 100 的詳情
      Then 操作成功
      And 回應中 "date_on_sale_from" 應為 null
      And 回應中 "date_on_sale_to" 應為 null
      And 回應中 "sale_date_range" 應為 null

    Example: 只有 from 有值時，from 回 timestamp、to 回 null
      Given 課程 100 的 wp_postmeta 中 "_sale_price_dates_from" 為 "1735689600"
      And 課程 100 的 wp_postmeta 中 "_sale_price_dates_to" 為 ""
      When 管理員 "Admin" 查詢課程 100 的詳情
      Then 操作成功
      And 回應中 "date_on_sale_from" 應為 1735689600
      And 回應中 "date_on_sale_to" 應為 null

  Rule: 後置（回應）- sale_price 在 DB 為空時回空字串（對齊 WC_Product::get_sale_price）

    Example: sale_price 為空時 GET 回空字串
      Given 課程 100 的 wp_postmeta 中 "_sale_price" 為 ""
      When 管理員 "Admin" 查詢課程 100 的詳情
      Then 操作成功
      And 回應中 "sale_price" 應為 ""
      And 回應中 "on_sale" 應為 false
