@ignore @command
Feature: 重新計算課程已售出數量

  # 修正 Issue #228 的歷史資料修復機制。
  # 過去 total_sales 只進不出已累積虛高數據，需提供一次性重新計算能力。
  #
  # 設計決策（來自 clarifier 釐清 C B A A A A B）：
  # - Q7 (B)：純自動觸發——plugin 升級到含此修正的版本時，自動以 Action Scheduler
  #           背景跑一次性重算（分批避免 timeout）。
  #           **不提供設定頁手動按鈕，也不提供手動 REST 端點。**
  #           （原 Q7=C 的手動按鈕 / POST /courses/recalculate-total-sales 已移除。）
  # - 重新計算邏輯與「同步課程已售出數量.feature」一致：
  #           掃描所有有效訂單（達 course_access_trigger 狀態且未被取消 / 退費 / 失敗），
  #           依綁定課程與份數（含 Bundle 的 pbp_product_quantities）重建各課程 total_sales。
  # - 訂閱（Q3-A）：曾首次付款成功的訂閱計 1 次，後續取消 / 過期不扣。
  # - 重新計算為冪等操作：重複執行結果一致（直接覆寫，非累加）。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | customer      |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | total_sales |
      | 100      | PHP 基礎課 | yes        | publish | 200         |
      | 101      | React 課程 | yes        | publish | 0           |
    And 系統中有以下 WooCommerce 商品：
      | productId | name     | price |
      | 500       | PHP 課程 | 999   |
    And 商品 500 的 bind_courses_data 如下：
      | course_id |
      | 100       |
    And 系統設定 course_access_trigger 為 "completed"

  # ========== 觸發（升級自動遷移）==========

  Rule: 觸發 - plugin 升級到含此修正的版本時自動執行一次重新計算

    Example: 升級後自動排程一次性遷移
      Given 外掛由舊版本升級至含 total_sales 修正的版本
      When 外掛完成升級流程
      Then 系統應自動排入一次性重新計算背景任務

    Example: 已執行過遷移的站台升級不重複自動執行
      Given 站台已執行過 total_sales 一次性遷移
      When 外掛再次升級
      Then 系統不應再次自動排程一次性遷移

  # ========== 後置（狀態）：重新計算邏輯 ==========

  Rule: 後置（狀態）- 重新計算以有效訂單重建 total_sales，修正虛高數據

    Example: 重新計算後虛高數據被修正為有效訂單數
      Given 系統中存在以下訂單：
        | orderId  | user  | product | status    |
        | ORDER-A  | Alice | 500     | completed |
        | ORDER-B  | Alice | 500     | refunded  |
        | ORDER-C  | Alice | 500     | cancelled |
        | ORDER-D  | Alice | 500     | failed    |
      When 系統執行一次性重新計算所有課程已售出數量
      Then 課程 100 的 total_sales 應為 1
      And 課程 101 的 total_sales 應為 0

    Example: 重新計算為冪等操作，重複執行結果一致
      Given 系統中存在以下訂單：
        | orderId  | user  | product | status    |
        | ORDER-A  | Alice | 500     | completed |
      When 系統執行一次性重新計算所有課程已售出數量
      And 系統再次執行一次性重新計算所有課程已售出數量
      Then 課程 100 的 total_sales 應為 1

    Example: 重新計算依購買份數累加
      Given 系統中存在以下訂單：
        | orderId  | user  | product | quantity | status    |
        | ORDER-A  | Alice | 500     | 3        | completed |
      When 系統執行一次性重新計算所有課程已售出數量
      Then 課程 100 的 total_sales 應為 3

    Example: 重新計算同步標記每筆有效訂單的計入旗標
      Given 系統中存在以下訂單：
        | orderId  | user  | product | status    |
        | ORDER-A  | Alice | 500     | completed |
      When 系統執行一次性重新計算所有課程已售出數量
      Then 訂單 "ORDER-A" 的 meta "_pc_counted_in_total_sales" 應為 "yes"

  Rule: 後置（狀態）- 重新計算採 Action Scheduler 分批處理避免逾時

    Example: 課程數量龐大時排入背景批次任務
      Given 系統中有 500 門課程
      When 系統執行一次性重新計算所有課程已售出數量
      Then 系統應排入 Action Scheduler 背景任務分批處理
