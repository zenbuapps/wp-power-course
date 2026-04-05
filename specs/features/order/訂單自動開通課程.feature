@ignore @command
Feature: 訂單自動開通課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | limit_type | limit_value | limit_unit |
      | 100      | PHP 基礎課 | yes        | publish | unlimited  |             |            |
      | 101      | React 課程 | yes        | publish | fixed      | 30          | day        |
    And 系統中有以下 WooCommerce 商品：
      | productId | name         | price |
      | 500       | 全端課程套餐 | 3000  |
    And 商品 500 的 bind_courses_data 如下：
      | course_id | limit_type | limit_value | limit_unit |
      | 100       | unlimited  |             |            |
      | 101       | fixed      | 30          | day        |
    And 系統設定 course_access_trigger 為 "completed"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 訂單狀態必須符合 course_access_trigger 設定

    Example: 訂單狀態不符合觸發條件時不開通
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "processing"
      Then 用戶 "Alice" 的 avl_course_ids 應不包含課程 100

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 訂單完成時自動加入學員到綁定的所有課程

    Example: 訂單完成後自動開通課程
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      Then 用戶 "Alice" 的 avl_course_ids 應包含課程 100
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 101
      And 課程 100 對用戶 "Alice" 的 coursemeta expire_date 應為 "0"
      And 課程 101 對用戶 "Alice" 的 coursemeta expire_date 應為 30 天後的 timestamp

  Rule: 後置（狀態）- 銷售方案（Bundle Product）展開所含商品後各自處理

    Example: 購買銷售方案時展開 pbp_product_ids 並逐一開通
      Given 系統中有以下銷售方案：
        | productId | name     | bundle_type   | link_course_id | pbp_product_ids |
        | 600       | 月費方案 | single_course | 100            | 500             |
      And 用戶 "Alice" 建立訂單 "ORDER-2" 購買商品 600
      When WooCommerce 訂單 "ORDER-2" 狀態變更為 "completed"
      Then 用戶 "Alice" 的 avl_course_ids 應包含課程 100

  Rule: 後置（狀態）- 課程商品不允許訪客結帳

    Example: 訪客無法購買課程商品
      When 訪客嘗試購買包含課程商品 500 的訂單
      Then 操作失敗，錯誤為「必須登入才能購買課程」

  Rule: 後置（狀態）- 訂閱付款完成時同步更新課程到期日（expire_date 設為 subscription_{id} 格式，跟隨訂閱狀態）

    Example: 訂閱付款完成時更新課程到期日
      Given 用戶 "Alice" 有訂閱 "SUB-1" 綁定到商品 500
      When WooCommerce 訂閱 "SUB-1" 付款完成
      Then 課程 100 對用戶 "Alice" 的 coursemeta expire_date 應更新

    Example: 訂閱相關訂單跳過 add_course_item_meta（由訂閱 hook 處理）
      Given 用戶 "Alice" 有訂閱 "SUB-1" 的續訂訂單 "ORDER-R1"
      When WooCommerce 訂單 "ORDER-R1" 建立
      Then 不應執行 add_course_item_meta

  Rule: 後置（狀態）- 透過 AddStudent 服務去重（相同 user + course 不重複開通）

    Example: 重複開通相同課程時去重
      Given 用戶 "Alice" 已擁有課程 100 的存取權
      And 用戶 "Alice" 建立訂單 "ORDER-3" 購買商品 500
      When WooCommerce 訂單 "ORDER-3" 狀態變更為 "completed"
      Then 用戶 "Alice" 的課程 100 存取權僅有一筆記錄

  Rule: 後置（狀態）- 開通課程後觸發 LifeCycle::ADD_STUDENT_TO_COURSE_ACTION

    Example: 開通後觸發標準 action hook
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      Then action "power_course_after_add_student_to_course" 應被觸發
