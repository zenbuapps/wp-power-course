@ignore @command
Feature: 設定商品分票

  # 分票是「商品的屬性」（賣的是什麼），不是購買行為的副作用。
  # 站長在商品編輯頁以三個 postmeta 開關決定該商品是否為可分票商品，以及每份含幾個名額：
  #   - ticket_enabled            yes|no   是否啟用分票
  #   - ticket_seats_per_unit     1~999    每份（每 1 件購買數量）包含的名額數
  #   - ticket_purchaser_takes_seat yes|no 購買者是否自動占 1 席
  #                                        no = 企業採購模式，全部名額都發成票（購買者不自動開通）
  # 全域自動發票會讓既有站台行為突變，故一律以商品層級開關為準（預設 no）。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下 WooCommerce 商品：
      | productId | name         | type         | price |
      | 500       | 三人同行方案 | simple       | 2499  |
      | 510       | 月費暢看     | subscription | 299   |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- ticket_seats_per_unit 必須為 1~999 的整數，超出範圍自動修正為 1

    Example: 設定名額為 0 時自動修正為 1
      Given 管理員編輯商品 500
      When 管理員將 ticket_seats_per_unit 設為 "0" 並儲存
      Then 商品 500 的 ticket_seats_per_unit 應為 1

    Example: 設定名額超過上限時自動修正為 1
      Given 管理員編輯商品 500
      When 管理員將 ticket_seats_per_unit 設為 "1000" 並儲存
      Then 商品 500 的 ticket_seats_per_unit 應為 1

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 啟用分票後商品保存三個分票 meta

    Example: 啟用分票並設定每份 3 個名額、購買者占 1 席
      Given 管理員編輯商品 500
      When 管理員設定分票如下並儲存：
        | ticket_enabled | ticket_seats_per_unit | ticket_purchaser_takes_seat |
        | yes            | 3                     | yes                         |
      Then 商品 500 的 ticket_enabled 應為 "yes"
      And 商品 500 的 ticket_seats_per_unit 應為 3
      And 商品 500 的 ticket_purchaser_takes_seat 應為 "yes"

    Example: 企業採購模式—購買者不占席，全部名額發成票
      Given 管理員編輯商品 500
      When 管理員設定分票如下並儲存：
        | ticket_enabled | ticket_seats_per_unit | ticket_purchaser_takes_seat |
        | yes            | 3                     | no                          |
      Then 商品 500 的 ticket_purchaser_takes_seat 應為 "no"

  Rule: 後置（狀態）- 未啟用分票的商品維持既有行為（不產生任何 meta 影響）

    Example: 預設未啟用分票
      Given 管理員編輯商品 500 且未設定任何分票 meta
      When 系統讀取商品 500 的分票設定
      Then 商品 500 的 ticket_enabled 應視為 "no"

  Rule: 後置（狀態）- 訂閱商品不允許啟用分票（跟隨購買者訂閱的票，購買者退訂會讓兌換者斷線）

    Example: 訂閱商品啟用分票時拒絕
      Given 管理員編輯訂閱商品 510
      When 管理員嘗試將 ticket_enabled 設為 "yes"
      Then 操作失敗，錯誤為「訂閱型商品尚不支援分票」
      And 商品 510 的 ticket_enabled 應為 "no"
