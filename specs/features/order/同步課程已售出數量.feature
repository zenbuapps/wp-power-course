@ignore @command
Feature: 同步課程已售出數量

  # 修正 Issue #228：課程「已售出數量」(total_sales) 過去只進不出，
  # 訂單取消 / 退費 / 付款失敗時不會扣減，導致數值虛高。
  #
  # 設計決策（來自 clarifier 釐清 C B A A A A C）：
  # - Q1 (C)：「計入已售出」與「開通課程」綁同一訊號，跟著 course_access_trigger 設定走。
  #           訂單進入觸發狀態（預設 completed）即計入；離開觸發狀態即扣減。
  # - Q2 (B)：每筆訂單以 order meta `_pc_counted_in_total_sales` 記錄「是否已計入」，
  #           保證冪等——首次進入計入狀態 +1、首次離開 -1，重複切換不重複增減。
  # - Q3 (A)：訂閱型課程首次付款成功時 +1，後續取消 / 過期不扣減（曾成立即算成立過）。
  # - Q4 (A)：銷售方案 (Bundle) 一併修正，按 pbp_product_quantities × 購買份數
  #           累計到方案內各課程；退費時對應扣減。
  # - Q6 (A)：沿用 WooCommerce 原生 `total_sales` meta，由本外掛主動 increment / decrement，
  #           所有現有 UI（前台徽章、後台列表色階、報表）自動同步。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | total_sales |
      | 100      | PHP 基礎課 | yes        | publish | 50          |
      | 101      | React 課程 | yes        | publish | 30          |
    And 系統中有以下 WooCommerce 商品：
      | productId | name         | price |
      | 500       | 全端課程套餐 | 3000  |
    And 商品 500 的 bind_courses_data 如下：
      | course_id |
      | 100       |
      | 101       |
    And 系統設定 course_access_trigger 為 "completed"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 只有進入 course_access_trigger 狀態的訂單才計入已售出

    Example: 付款失敗的訂單不計入已售出數量
      Given 用戶 "Alice" 建立訂單 "ORDER-FAIL" 購買商品 500
      When WooCommerce 訂單 "ORDER-FAIL" 狀態變更為 "failed"
      Then 課程 100 的 total_sales 應為 50
      And 課程 101 的 total_sales 應為 30

    Example: 僅處理中（未達觸發狀態）的訂單不計入已售出數量
      Given 系統設定 course_access_trigger 為 "completed"
      And 用戶 "Alice" 建立訂單 "ORDER-PROC" 購買商品 500
      When WooCommerce 訂單 "ORDER-PROC" 狀態變更為 "processing"
      Then 課程 100 的 total_sales 應為 50

    Example: 觸發狀態設為 processing 時付款即計入
      Given 系統設定 course_access_trigger 為 "processing"
      And 用戶 "Alice" 建立訂單 "ORDER-PROC2" 購買商品 500
      When WooCommerce 訂單 "ORDER-PROC2" 狀態變更為 "processing"
      Then 課程 100 的 total_sales 應為 51
      And 課程 101 的 total_sales 應為 31

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 訂單進入觸發狀態時，綁定課程的 total_sales 各 +1（依購買份數）

    Example: 訂單完成時綁定課程的已售出數量增加
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      Then 課程 100 的 total_sales 應為 51
      And 課程 101 的 total_sales 應為 31
      And 訂單 "ORDER-1" 的 meta "_pc_counted_in_total_sales" 應為 "yes"

    Example: 購買多份時依份數累加
      Given 用戶 "Alice" 建立訂單 "ORDER-QTY" 購買商品 500 數量 3
      When WooCommerce 訂單 "ORDER-QTY" 狀態變更為 "completed"
      Then 課程 100 的 total_sales 應為 53
      And 課程 101 的 total_sales 應為 33

  Rule: 後置（狀態）- 訂單離開觸發狀態（取消 / 退費）時，對應課程的 total_sales 扣減

    Example: 已完成訂單被取消時扣減已售出數量
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      And WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "cancelled"
      Then 課程 100 的 total_sales 應為 50
      And 課程 101 的 total_sales 應為 30
      And 訂單 "ORDER-1" 的 meta "_pc_counted_in_total_sales" 應為 "no"

    Example: 已完成訂單被全額退費時扣減已售出數量
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      And WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "refunded"
      Then 課程 100 的 total_sales 應為 50
      And 課程 101 的 total_sales 應為 30

    Example: total_sales 不會被扣減為負數
      Given 課程 100 的 total_sales 為 0
      And 用戶 "Alice" 建立訂單 "ORDER-ZERO" 購買商品 500
      And 訂單 "ORDER-ZERO" 的 meta "_pc_counted_in_total_sales" 為 "yes"
      When WooCommerce 訂單 "ORDER-ZERO" 狀態變更為 "cancelled"
      Then 課程 100 的 total_sales 應為 0

  # ========== 冪等性（Q2-B）==========

  Rule: 後置（狀態）- 以 order meta 旗標保證冪等，狀態來回切換不重複增減

    Example: 訂單狀態 completed → cancelled → completed 最終只 +1
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      And WooCommerce 訂單 "ORDER-1" 狀態變更為 "cancelled"
      And WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      Then 課程 100 的 total_sales 應為 51
      And 訂單 "ORDER-1" 的 meta "_pc_counted_in_total_sales" 應為 "yes"

    Example: 已計入的訂單再次進入觸發狀態不重複 +1
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      And 訂單 "ORDER-1" 的 meta "_pc_counted_in_total_sales" 為 "yes"
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "completed"
      Then 課程 100 的 total_sales 應為 50

    Example: 未計入的訂單離開觸發狀態不會誤扣
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500
      And 訂單 "ORDER-1" 的 meta "_pc_counted_in_total_sales" 為 "no"
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "cancelled"
      Then 課程 100 的 total_sales 應為 50

  # ========== 部分退費（邊界情境）==========

  Rule: 後置（狀態）- 部分退費時僅扣減被退費品項對應的課程

    Example: 訂單含兩商品僅退費其一時只扣減該商品的課程
      Given 系統中有以下 WooCommerce 商品：
        | productId | name       | price |
        | 501       | 單課-PHP   | 999   |
        | 502       | 單課-React | 1299  |
      And 商品 501 的 bind_courses_data 如下：
        | course_id |
        | 100       |
      And 商品 502 的 bind_courses_data 如下：
        | course_id |
        | 101       |
      And 用戶 "Alice" 建立訂單 "ORDER-MIX" 購買商品 501 與商品 502
      And WooCommerce 訂單 "ORDER-MIX" 狀態變更為 "completed"
      When 管理員對訂單 "ORDER-MIX" 的商品 501 執行部分退費
      Then 課程 100 的 total_sales 應為 50
      And 課程 101 的 total_sales 應為 31

  # ========== 訂閱型課程（Q3-A）==========

  Rule: 後置（狀態）- 訂閱首次付款成功時 +1，後續取消 / 過期不扣減

    Example: 訂閱首次付款完成時計入已售出
      Given 用戶 "Alice" 有訂閱 "SUB-1" 綁定到商品 500
      When WooCommerce 訂閱 "SUB-1" 首次付款完成
      Then 課程 100 的 total_sales 應為 51
      And 課程 101 的 total_sales 應為 31

    Example: 訂閱取消時不扣減已售出
      Given 用戶 "Alice" 有訂閱 "SUB-1" 綁定到商品 500
      And WooCommerce 訂閱 "SUB-1" 首次付款完成
      When WooCommerce 訂閱 "SUB-1" 狀態變更為 "cancelled"
      Then 課程 100 的 total_sales 應為 51

    Example: 訂閱續訂不重複計入已售出
      Given 用戶 "Alice" 有訂閱 "SUB-1" 綁定到商品 500
      And WooCommerce 訂閱 "SUB-1" 首次付款完成
      When WooCommerce 訂閱 "SUB-1" 產生續訂付款
      Then 課程 100 的 total_sales 應為 51

  # ========== 銷售方案 Bundle（Q4-A）==========

  Rule: 後置（狀態）- 銷售方案計入時按 pbp_product_quantities × 購買份數累計到各課程

    Example: 購買銷售方案時依數量設定累計各課程已售出
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities |
        | 600      | 合購方案 | 100            | bundle      | [500]           | {"500": 2}             |
      And 用戶 "Alice" 建立訂單 "ORDER-BDL" 購買商品 600 數量 1
      When WooCommerce 訂單 "ORDER-BDL" 狀態變更為 "completed"
      Then 課程 100 的 total_sales 應為 52
      And 課程 101 的 total_sales 應為 32

    Example: 銷售方案訂單退費時對應扣減各課程已售出
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities |
        | 600      | 合購方案 | 100            | bundle      | [500]           | {"500": 2}             |
      And 用戶 "Alice" 建立訂單 "ORDER-BDL" 購買商品 600 數量 1
      And WooCommerce 訂單 "ORDER-BDL" 狀態變更為 "completed"
      When WooCommerce 訂單 "ORDER-BDL" 狀態變更為 "refunded"
      Then 課程 100 的 total_sales 應為 50
      And 課程 101 的 total_sales 應為 30

  # ========== 語意分離：手動授權不影響銷售 ==========

  Rule: 後置（狀態）- 管理員手動將學員加入課程（非透過訂單）不影響 total_sales

    Example: 後台手動新增學員不增加已售出數量
      When 管理員手動將用戶 "Alice" 加入課程 100
      Then 用戶 "Alice" 的 avl_course_ids 應包含課程 100
      And 課程 100 的 total_sales 應為 50
