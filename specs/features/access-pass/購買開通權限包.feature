@ignore @command
Feature: 購買開通權限包

  # 訂單達開通條件（course_access_trigger，預設 completed；訂閱走 woocommerce_subscription_payment_complete）時，
  # 若商品掛載了權限包，授予該使用者「權限包持有關係」。
  # 採 compute-on-read：不展開課程 id 寫入 avl_course_ids，僅記錄「user 持有 pass X，取得時間/到期依 limit_mode」。
  # 這讓動態範圍（全站/分類）能涵蓋日後新增課程，無需站長補開通。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role          |
      | 1      | Admin   | admin@test.com   | administrator |
      | 2      | UserA   | usera@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
    And 系統中有以下課程權限包：
      | passId | name         | scope_type | limit_mode          | status | limit_value | limit_unit |
      | 300    | 全站課程權限 | all        | permanent           | active |             |            |
      | 301    | 限時全站權限 | all        | limited             | active | 30          | day        |
      | 302    | 訂閱全站權限 | all        | follow_subscription | active |             |            |
    And 系統中有以下商品：
      | productId | name       | type         | status  | regular_price | access_pass_id |
      | 500       | 全站通行證 | simple       | publish | 1999          | 300            |
      | 501       | 限時通行證 | simple       | publish | 999           | 301            |
      | 510       | 月費暢看   | subscription | publish | 299           | 302            |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 訂單未達開通條件時，使用者尚未取得權限包

    Example: 訂單仍為處理中時尚未授予權限包
      Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "processing"
      When 系統檢查訂單開通條件
      Then 學員 "UserA" 尚未持有權限包 300

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 訂單完成後，永久權限包授予使用者且無到期

    Example: 購買全站永久權限包後取得持有關係
      Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "completed"
      When 系統處理訂單開通
      Then 操作成功
      And 學員 "UserA" 應持有權限包 300
      And 學員 "UserA" 持有的權限包 300 無到期時間

  Rule: 後置（狀態）- 訂單完成後，限時權限包依 limit_value 計算到期時間

    Example: 購買限時 30 天權限包後設定到期時間
      Given 學員 "UserA" 下單購買商品 501，訂單狀態為 "completed"
      When 系統處理訂單開通
      Then 操作成功
      And 學員 "UserA" 應持有權限包 301
      And 學員 "UserA" 持有的權限包 301 到期時間為購買後 30 天

  Rule: 後置（狀態）- 訂閱商品首期付款完成後，跟隨訂閱權限包綁定訂閱

    Example: 訂閱首期付款完成後取得跟隨訂閱權限包
      Given 學員 "UserA" 訂閱商品 510，首期付款完成，訂閱狀態為 "active"
      When 系統處理訂閱開通
      Then 操作成功
      And 學員 "UserA" 應持有權限包 302
      And 學員 "UserA" 持有的權限包 302 綁定該訂閱

  Rule: 後置（狀態）- 不展開課程 id 寫入 avl_course_ids（動態範圍由觀看時計算）

    Example: 購買全站權限包不寫入個別課程到 avl_course_ids
      Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "completed"
      When 系統處理訂單開通
      Then 操作成功
      And 學員 "UserA" 的 avl_course_ids 不包含課程 100
