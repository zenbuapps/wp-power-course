@ignore @command
Feature: 訂單退款作廢票券

  # 訂單退款 / 取消時，作廢該訂單所有「未兌換（pending）」的票券。
  # Hook：woocommerce_order_status_refunded / woocommerce_order_status_cancelled。
  # 策略（架構定案 十）：
  #   - 只作廢 pending 票；已兌換（redeemed）的不撤銷（避免影響已在上課的兌換者）
  #   - 退款金額由管理員依實際兌換情況手動調整
  #   - 票不足作廢時（例如都已兌換）後台顯示警示，由管理員處理

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 2      | Alice | alice@test.com | subscriber |
      | 3      | Bob   | bob@test.com   | subscriber |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下票券：
      | code            | order_id | course_ids | purchaser_id | redeemer_id | status   |
      | PC-ORDER1PEND01 | 9001     | [100]      | 2            |             | pending  |
      | PC-ORDER1USED01 | 9001     | [100]      | 2            | 3           | redeemed |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 訂單退款時作廢該訂單所有未兌換票券

    Example: 訂單 9001 退款作廢其未兌換票券
      Given 訂單 "9001" 尚有 1 張 pending 票券與 1 張 redeemed 票券
      When WooCommerce 訂單 "9001" 狀態變更為 "refunded"
      Then 票券 "PC-ORDER1PEND01" 的 status 應為 "voided"

  Rule: 後置（狀態）- 已兌換的票券在退款時不受影響

    Example: 訂單退款不撤銷已兌換的票券與兌換者權限
      Given 訂單 "9001" 尚有 1 張 pending 票券與 1 張 redeemed 票券
      And 用戶 "Bob" 已透過票券 "PC-ORDER1USED01" 取得課程 100
      When WooCommerce 訂單 "9001" 狀態變更為 "refunded"
      Then 票券 "PC-ORDER1USED01" 的 status 應維持 "redeemed"
      And 用戶 "Bob" 的 avl_course_ids 應仍包含課程 100

  Rule: 後置（狀態）- 訂單取消時同樣作廢未兌換票券

    Example: 訂單取消作廢未兌換票券
      Given 訂單 "9001" 尚有 1 張 pending 票券
      When WooCommerce 訂單 "9001" 狀態變更為 "cancelled"
      Then 票券 "PC-ORDER1PEND01" 的 status 應為 "voided"

  Rule: 後置（狀態）- 全部票券皆已兌換而無可作廢時，於後台顯示警示

    Example: 無 pending 票券可作廢時標記警示
      Given 訂單 "9002" 的票券皆已兌換
      When WooCommerce 訂單 "9002" 狀態變更為 "refunded"
      Then 系統於後台對訂單 "9002" 顯示「無可作廢的未兌換票券，請人工確認退款」警示
