@ignore @command
Feature: 管理員作廢票券

  # 端點：POST /power-course/tickets/{id}/void，權限 manage_woocommerce。
  # 客服場景：管理員在後台分票管理頁手動作廢票券。
  # 職責分離（架構定案 十）：作廢票券只把「未兌換」的票標記為 voided；
  # 撤銷已在上課的兌換者權限是另一件事，走既有「移除學員」流程，不由作廢票券連帶處理。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下票券：
      | id  | code            | course_ids | purchaser_id | redeemer_id | status   |
      | 701 | PC-PENDINGVOID1 | [100]      | 2            |             | pending  |
      | 702 | PC-REDEEMEDONE1 | [100]      | 2            | 3           | redeemed |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 非管理員不可作廢票券

    Example: 一般用戶嘗試作廢票券被拒
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 嘗試作廢票券 701
      Then 操作失敗，錯誤為「權限不足」
      And 票券 701 的 status 應維持 "pending"

  Rule: 前置（狀態）- 已兌換的票券無法作廢，需改走移除學員流程

    Example: 管理員嘗試作廢已兌換票券被拒
      Given 管理員 "Admin" 已登入
      When 管理員作廢票券 702
      Then 操作失敗，錯誤為「票券已被兌換，請改由移除學員撤銷權限」
      And 票券 702 的 status 應維持 "redeemed"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 管理員可作廢未兌換票券

    Example: 管理員作廢 pending 票券
      Given 管理員 "Admin" 已登入
      When 管理員作廢票券 701
      Then 操作成功
      And 票券 701 的 status 應為 "voided"

  Rule: 後置（狀態）- 作廢票券不連帶撤銷任何學員的課程存取權

    Example: 作廢票券後兌換者的既有權限不受影響
      Given 管理員 "Admin" 已登入
      And 用戶 "Bob" 已透過票券 702 取得課程 100
      When 管理員作廢票券 701
      Then 用戶 "Bob" 的 avl_course_ids 應仍包含課程 100
