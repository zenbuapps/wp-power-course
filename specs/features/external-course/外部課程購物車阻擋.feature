@ignore @constraint
Feature: 外部課程購物車阻擋

  Background:
    Given 系統中有以下用戶：
      | userId | name   | email            | role       |
      | 20     | Visitor | visitor@test.com | subscriber |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | product_url                    |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 |

  # ========== 購物車阻擋 ==========

  Rule: 外部課程的 is_purchasable() 回傳 false（WC External 天然行為）

    Example: 外部課程不可購買
      When 查詢課程 200 的 is_purchasable 狀態
      Then 結果應為 false

  Rule: 外部課程不可被加入站內購物車

    Example: 透過 AJAX 嘗試加入購物車時被阻擋
      When 用戶 "Visitor" 嘗試將課程 200 加入購物車
      Then 操作失敗
      And 課程 200 不存在於購物車中

  Rule: 外部課程不可透過 URL 操作加入購物車

    Example: 透過 ?add-to-cart=200 URL 嘗試加入購物車時被阻擋
      When 用戶 "Visitor" 透過 URL 參數 "?add-to-cart=200" 嘗試加入購物車
      Then 課程 200 不存在於購物車中
      And WooCommerce 應顯示適當的錯誤提示

  Rule: 外部課程不產生站內訂單

    Example: 外部課程無法透過任何方式產生訂單
      When 查詢與課程 200 相關的 WooCommerce 訂單
      Then 結果應為空列表
