@ignore @command
Feature: 更新課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | limit_type | price |
      | 100      | PHP 基礎課 | yes        | publish | unlimited  | 1200  |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes

    Example: 不存在的課程更新失敗
      When 管理員 "Admin" 更新課程 9999，參數如下：
        | name     |
        | 新名稱   |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 不可為空且必須為正整數

    Example: 未提供課程 ID 時更新失敗
      When 管理員 "Admin" 更新課程 ""，參數如下：
        | name     |
        | 新名稱   |
      Then 操作失敗

  Rule: 前置（參數）- 同 CreateCourse 的參數驗證規則

    Example: limit_type 為 fixed 但 limit_value 為空時更新失敗
      When 管理員 "Admin" 更新課程 100，參數如下：
        | limit_type | limit_value | limit_unit |
        | fixed      |             | day        |
      Then 操作失敗，錯誤訊息包含 "limit_value"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新 WC_Product 後持久化

    Example: 成功更新課程名稱與價格
      When 管理員 "Admin" 更新課程 100，參數如下：
        | name           | price |
        | PHP 進階實戰課 | 1800  |
      Then 操作成功
      And 課程 100 的 name 應為 "PHP 進階實戰課"
      And 課程 100 的 price 應為 "1800"

  Rule: 後置（狀態）- teacher_ids 先 delete_meta_data 再 add_meta_data loop

    Example: 更新課程講師列表
      Given 課程 100 的 teacher_ids 為 [10]
      When 管理員 "Admin" 更新課程 100，參數如下：
        | teacher_ids |
        | 11,12       |
      Then 操作成功
      And 課程 100 應有 2 筆 teacher_ids meta rows
      And 課程 100 的 teacher_ids meta 應不包含 userId 10
      And 課程 100 的 teacher_ids meta 應包含 userId 11
      And 課程 100 的 teacher_ids meta 應包含 userId 12
