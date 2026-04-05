@ignore @command
Feature: 新增學員到課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           |
      | 1      | Admin | admin@test.com  |
      | 2      | Alice | alice@test.com  |
      | 3      | Bob   | bob@test.com    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須是存在的 WordPress 用戶

    Example: 不存在的用戶無法被加入課程
      When 管理員 "Admin" 新增 userId 9999 到課程 100，expire_date 0
      Then 操作失敗

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes

    Example: 不存在的課程無法加入學員
      When 管理員 "Admin" 新增 userId 2 到課程 9999，expire_date 0
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- user_ids 不可為空陣列

    Example: 未提供 user_ids 時操作失敗
      When 管理員 "Admin" 新增 user_ids [] 到課程 100，expire_date 0
      Then 操作失敗，錯誤訊息包含 "user_ids"

  Rule: 前置（參數）- course_ids 不可為空陣列

    Example: 未提供 course_ids 時操作失敗
      When 管理員 "Admin" 新增 userId 2 到課程 []，expire_date 0
      Then 操作失敗，錯誤訊息包含 "course_ids"

  Rule: 前置（參數）- expire_date 若為 timestamp 必須是 10 位正整數

    Scenario Outline: 非合法格式的 expire_date 導致操作失敗
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date <expire_date>
      Then 操作失敗

      Examples:
        | 說明       | expire_date |
        | 位數不足   | 12345       |
        | 負數       | -1735689600 |
        | 非數字字串 | abc         |

  Rule: 前置（參數）- expire_date 若為 subscription 格式需符合 subscription_{id}

    Example: subscription 格式不符規範時操作失敗
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date "sub_123"
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 學員應取得課程存取權（avl_course_ids user meta）

    Example: 成功新增學員到課程
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date 0
      Then 操作成功
      And 用戶 "Alice" 的 avl_course_ids 應包含課程 100

  Rule: 後置（狀態）- coursemeta 應記錄 expire_date

    Example: 設定永久存取時 expire_date 應為 0
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date 0
      Then 操作成功
      And 課程 100 對用戶 "Alice" 的 coursemeta expire_date 應為 "0"

  Rule: 後置（狀態）- coursemeta 應記錄 course_granted_at

    Example: 新增學員後 course_granted_at 應自動設定
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date 0
      Then 操作成功
      And 課程 100 對用戶 "Alice" 的 coursemeta course_granted_at 應不為空

  Rule: 後置（狀態）- 支援 10 位 timestamp 和 subscription 格式的 expire_date

    Example: 成功設定固定到期日
      When 管理員 "Admin" 新增 userId 3 到課程 100，expire_date 1893456000
      Then 操作成功
      And 課程 100 對用戶 "Bob" 的 coursemeta expire_date 應為 "1893456000"

    Example: 成功設定跟隨訂閱到期
      When 管理員 "Admin" 新增 userId 3 到課程 100，expire_date "subscription_456"
      Then 操作成功
      And 課程 100 對用戶 "Bob" 的 coursemeta expire_date 應為 "subscription_456"

  Rule: 後置（狀態）- 應觸發 power_course_add_student_to_course 和 power_course_after_add_student_to_course action

    Example: 新增學員後觸發 action hooks
      When 管理員 "Admin" 新增 userId 2 到課程 100，expire_date 0
      Then 操作成功
      And action "power_course_add_student_to_course" 應被觸發
      And action "power_course_after_add_student_to_course" 應被觸發
