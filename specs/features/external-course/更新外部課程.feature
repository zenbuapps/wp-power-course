@ignore @command
Feature: 更新外部課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | product_url                    | button_text |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 | 前往課程    |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes 且 type 為 external

    Example: 不存在的課程更新失敗
      When 管理員 "Admin" 更新外部課程 9999，參數如下：
        | name     |
        | 新名稱   |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- product_url 若有提供必須為合法 http/https URL

    Example: 更新為非法 URL 格式時失敗
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | product_url      |
        | ftp://example.com |
      Then 操作失敗，錯誤訊息包含 "product_url"

  Rule: 前置（參數）- product_url 不可更新為空

    Example: 將 product_url 清空時失敗
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | product_url |
        |             |
      Then 操作失敗，錯誤訊息包含 "product_url"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功更新外部課程基本資訊

    Example: 更新外部課程名稱與連結
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | name                 | product_url                        |
        | Python 資料科學進階  | https://hahow.in/courses/67890     |
      Then 操作成功
      And 課程 200 的 name 應為 "Python 資料科學進階"
      And 課程 200 的 product_url 應為 "https://hahow.in/courses/67890"

  Rule: 後置（狀態）- 成功更新 CTA 按鈕文字

    Example: 更新 CTA 按鈕文字
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | button_text          |
        | 前往 Hahow 購買課程  |
      Then 操作成功
      And 課程 200 的 button_text 應為 "前往 Hahow 購買課程"

  Rule: 後置（狀態）- 成功更新展示用價格

    Example: 更新展示價格
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | regular_price | sale_price |
        | 3000          | 2400       |
      Then 操作成功
      And 課程 200 的 regular_price 應為 "3000"
      And 課程 200 的 sale_price 應為 "2400"

  Rule: 後置（狀態）- 更新後 product type 仍為 external

    Example: 更新後產品類型不變
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | name           |
        | 新課程名稱     |
      Then 操作成功
      And 課程 200 的 product type 應為 "external"

  Rule: 後置（狀態）- 更新講師列表

    Example: 更新外部課程講師
      Given 課程 200 的 teacher_ids 為 []
      When 管理員 "Admin" 更新外部課程 200，參數如下：
        | teacher_ids |
        | 10,11       |
      Then 操作成功
      And 課程 200 應有 2 筆 teacher_ids meta rows
