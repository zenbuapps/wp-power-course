@ignore @command
Feature: 管理員重發票券

  # 端點：POST /power-course/tickets/{id}/reissue，權限 manage_woocommerce。
  # 客服高頻需求：原票券代碼外流 / 遺失時，管理員作廢舊票並以相同 grant_payload 快照重新產生一張新票券。
  # 重發沿用原票券的授權快照（course_ids / access_pass_id / limit_*）與來源訂單資訊，僅代碼與 id 改變。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下票券：
      | id  | code            | order_id | course_ids | purchaser_id | redeemer_id | status   | limit_type | limit_value | limit_unit |
      | 701 | PC-OLDLEAKED001 | 9001     | [100]      | 2            |             | pending  | fixed      | 30          | day        |
      | 702 | PC-REDEEMEDONE1 | 9001     | [100]      | 2            | 3           | redeemed | fixed      | 30          | day        |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 非管理員不可重發票券

    Example: 一般用戶嘗試重發票券被拒
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 嘗試重發票券 701
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 已兌換的票券無法重發

    Example: 管理員嘗試重發已兌換票券被拒
      Given 管理員 "Admin" 已登入
      When 管理員重發票券 702
      Then 操作失敗，錯誤為「票券已被兌換，無法重發」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 重發時作廢舊票券並產生一張新票券

    Example: 管理員重發外流的票券
      Given 管理員 "Admin" 已登入
      When 管理員重發票券 701
      Then 操作成功
      And 票券 701 的 status 應為 "voided"
      And 應產生一張新的 "pending" 票券
      And 新票券代碼應符合格式 "PC-[A-Z0-9]{12}"
      And 新票券代碼不應等於 "PC-OLDLEAKED001"

  Rule: 後置（狀態）- 重發的新票券沿用原票券的授權快照與來源訂單

    Example: 新票券繼承原快照
      Given 管理員 "Admin" 已登入
      When 管理員重發票券 701
      Then 新票券的 grant_payload 應包含 course_ids "[100]"
      And 新票券的 grant_payload 的 limit_type 應為 "fixed"
      And 新票券的 order_id 應為 9001
      And 新票券的 purchaser_id 應為 2
