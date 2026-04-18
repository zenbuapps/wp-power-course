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

  # ========== 後置（狀態）- 清空選填欄位規則 (Issue #203) ==========

  Rule: 後置（狀態）- 收到欄位值為空字串時，視為「清空」並寫入空字串到 DB

    # 詳細逐欄位規則請參考 specs/features/course/清空選填欄位並儲存.feature

    Example: 清空 sale_price 生效
      Given 課程 100 的 sale_price 為 "888"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | sale_price |
        |            |
      Then 操作成功
      And 課程 100 的 sale_price 應為 ""

  Rule: 後置（狀態）- 欄位未出現在 request body 時，視為「保持原狀」（向下相容既有合約）

    Example: request 未帶 sale_price key，原 sale_price 保留
      Given 課程 100 的 sale_price 為 "888"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | name         |
        | PHP 進階實戰 |
      Then 操作成功
      And 課程 100 的 sale_price 應為 "888"

  Rule: 後置（狀態）- date_on_sale 單側為空但另一側有值時，視為兩側都清空

    Example: 只送 date_on_sale_from 空而 to 有值 → 兩側同步清空
      Given 課程 100 的 date_on_sale_from 為 "2026-01-01 00:00:00"
      And 課程 100 的 date_on_sale_to 為 "2026-12-31 23:59:59"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | date_on_sale_from | date_on_sale_to     |
        |                   | 2026-06-30 23:59:59 |
      Then 操作成功
      And 課程 100 的 date_on_sale_from 應為空
      And 課程 100 的 date_on_sale_to 應為空
