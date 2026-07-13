@ignore @command
Feature: 訂單發放票券

  # 當訂單達開通條件（course_access_trigger 設定值，且一律含 completed）時，
  # 若購買的商品啟用了分票（ticket_enabled=yes），系統為「多出的名額」產生票券（pc_course_tickets）。
  #
  # 發票數 = ticket_seats_per_unit × item_qty − (ticket_purchaser_takes_seat ? 1 : 0)
  #   - purchaser_takes_seat=yes：購買者走既有 AddStudent 流程自動開通 1 份，其餘產生票券
  #   - purchaser_takes_seat=no（企業採購）：購買者不占席，全部名額都發成票券
  #
  # 票券售出當下把授權內容凍結進 grant_payload 快照（course_ids / access_pass_id / limit_*），
  # 商品事後改綁定/改期限時，舊票仍依快照兌現「賣的時候承諾的東西」。
  #
  # 兩個上線才會炸的坑（架構定案 坑 1、坑 2）：
  #   坑 1（Bundle 展開雙發票）：Order.php 把 bundle 子商品用 add_product() 加成獨立 order item。
  #     母 item（bundle 商品）不發票，子 item（展開的課程）才發票，否則同一份權益發兩次票。
  #     站長在母 bundle 設 ticket_*，展開時必須把分票設定寫進子 item meta。
  #   坑 2（發票冪等）：grant_statuses = trigger + 一律含 completed，processing→completed 會觸發兩次。
  #     發票必須以 order_item_id 為 key 冪等，否則重複發票。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 2      | Alice | alice@test.com | subscriber |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | limit_type | limit_value | limit_unit |
      | 100      | PHP 基礎課 | yes        | publish | fixed      | 30          | day        |
    And 系統中有以下 WooCommerce 商品：
      | productId | name         | price | ticket_enabled | ticket_seats_per_unit | ticket_purchaser_takes_seat |
      | 500       | PHP 基礎課   | 999   | yes            | 1                     | yes                         |
      | 501       | 未啟用分票課 | 999   | no             | 1                     | yes                         |
      | 502       | 企業採購方案 | 5000  | yes            | 5                     | no                          |
    And 商品 500 的 bind_courses_data 如下：
      | course_id | limit_type | limit_value | limit_unit |
      | 100       | fixed      | 30          | day        |
    And 系統設定 course_access_trigger 為 "completed"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 訂單未達開通觸發條件時不發放票券

    Example: 訂單仍為處理中時尚未發票
      Given 用戶 "Alice" 建立訂單 "ORDER-1" 購買商品 500，數量 3
      When WooCommerce 訂單 "ORDER-1" 狀態變更為 "processing"
      Then 訂單 "ORDER-1" 尚未產生任何票券

  Rule: 前置（狀態）- 商品未啟用分票時不發放票券（維持既有行為）

    Example: 未啟用分票的商品即使數量大於 1 也不發票
      Given 用戶 "Alice" 建立訂單 "ORDER-2" 購買商品 501，數量 3
      When WooCommerce 訂單 "ORDER-2" 狀態變更為 "completed"
      Then 訂單 "ORDER-2" 尚未產生任何票券
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 100

  Rule: 前置（狀態）- 購買數量為 1 且購買者占席時，發票數為 0（維持既有行為）

    Example: 購買 1 份且購買者占席時不產生票券
      Given 用戶 "Alice" 建立訂單 "ORDER-3" 購買商品 500，數量 1
      When WooCommerce 訂單 "ORDER-3" 狀態變更為 "completed"
      Then 訂單 "ORDER-3" 尚未產生任何票券
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 100

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 購買者占席時，購買者自動開通 1 份，其餘名額產生票券

    Example: 買 3 份—購買者開通後產生 2 張票券
      Given 用戶 "Alice" 建立訂單 "ORDER-4" 購買商品 500，數量 3
      When WooCommerce 訂單 "ORDER-4" 狀態變更為 "completed"
      Then 用戶 "Alice" 的 avl_course_ids 應包含課程 100
      And 訂單 "ORDER-4" 應產生 2 張 "pending" 票券
      And 訂單 "ORDER-4" 的票券 purchaser_id 應為 2

  Rule: 後置（狀態）- 企業採購模式（購買者不占席）時全部名額都發成票券

    Example: 買 1 份 5 名額且購買者不占席—產生 5 張票券
      Given 用戶 "Alice" 建立訂單 "ORDER-5" 購買商品 502，數量 1
      When WooCommerce 訂單 "ORDER-5" 狀態變更為 "completed"
      Then 訂單 "ORDER-5" 應產生 5 張 "pending" 票券
      And 用戶 "Alice" 的 avl_course_ids 不因商品 502 而新增課程

  Rule: 後置（狀態）- 票券格式為 PC- 前綴加 12 碼大寫英數，全域唯一

    Example: 產生的票券代碼符合格式
      Given 用戶 "Alice" 建立訂單 "ORDER-4" 購買商品 500，數量 3
      When WooCommerce 訂單 "ORDER-4" 狀態變更為 "completed"
      Then 訂單 "ORDER-4" 產生的每張票券代碼應符合格式 "PC-[A-Z0-9]{12}"
      And 訂單 "ORDER-4" 產生的票券代碼應全域唯一

  Rule: 後置（狀態）- 售出當下把授權內容凍結進 grant_payload 快照

    Example: 票券快照記錄課程與期限設定
      Given 用戶 "Alice" 建立訂單 "ORDER-4" 購買商品 500，數量 3
      When WooCommerce 訂單 "ORDER-4" 狀態變更為 "completed"
      Then 訂單 "ORDER-4" 產生的票券 grant_payload 應包含 course_ids "[100]"
      And 訂單 "ORDER-4" 產生的票券 grant_payload 的 limit_type 應為 "fixed"
      And 訂單 "ORDER-4" 產生的票券 grant_payload 的 limit_value 應為 30
      And 訂單 "ORDER-4" 產生的票券 grant_payload 的 limit_unit 應為 "day"

  Rule: 後置（狀態）- 發票以 order_item_id 為 key 冪等（坑 2：processing→completed 觸發兩次只發一次）

    Example: 訂單狀態重複進入 completed 時票券不重複產生
      Given 用戶 "Alice" 建立訂單 "ORDER-4" 購買商品 500，數量 3
      And WooCommerce 訂單 "ORDER-4" 狀態已為 "completed" 並已產生 2 張票券
      When WooCommerce 訂單 "ORDER-4" 再次觸發 "completed" 開通
      Then 訂單 "ORDER-4" 仍只有 2 張票券

  Rule: 後置（狀態）- Bundle 展開時只從子 item 發票、母 item 不發票（坑 1）

    Example: 購買銷售方案時母 bundle 不發票、展開的課程子項才發票
      Given 系統中有以下銷售方案：
        | productId | name         | bundle_type | link_course_id | pbp_product_ids | pbp_product_quantities | ticket_enabled | ticket_seats_per_unit | ticket_purchaser_takes_seat |
        | 600       | 三人同行方案 | bundle      | 100            | 500             | {"500": 3}             | yes            | 1                     | yes                         |
      And 用戶 "Alice" 建立訂單 "ORDER-6" 購買銷售方案 600，數量 1
      When WooCommerce 訂單 "ORDER-6" 狀態變更為 "completed"
      Then 母項商品 600 不應產生票券
      And 展開後的子項商品 500 應產生 2 張 "pending" 票券
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 100
