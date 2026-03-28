@ignore @command
Feature: 綁定課程到商品

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | React 課程 | yes        | publish |
    And 系統中有以下 WooCommerce 商品：
      | productId | name         | status  |
      | 500       | 全端課程套餐 | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- product_id 對應的商品必須存在

    Example: 商品不存在時綁定失敗
      When 管理員 "Admin" 綁定課程到商品 9999，bind_courses_data 如下：
        | course_id | limit_type | limit_value | limit_unit |
        | 100       | unlimited  |             |            |
      Then 操作失敗

  Rule: 前置（狀態）- bind_courses_data 中的課程必須存在且 _is_course 為 yes

    Example: 課程不存在時綁定失敗
      When 管理員 "Admin" 綁定課程到商品 500，bind_courses_data 如下：
        | course_id | limit_type | limit_value | limit_unit |
        | 9999      | unlimited  |             |            |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- product_id 不可為空

    Example: 未提供 product_id 時操作失敗
      When 管理員 "Admin" 綁定課程到商品 ""，bind_courses_data 如下：
        | course_id | limit_type |
        | 100       | unlimited  |
      Then 操作失敗

  Rule: 前置（參數）- bind_courses_data 不可為空陣列

    Example: 未提供綁定資料時操作失敗
      When 管理員 "Admin" 綁定課程到商品 500，bind_courses_data 如下：
        | course_id | limit_type |
      Then 操作失敗

  Rule: 前置（參數）- limit_type 為 fixed 時 limit_value 與 limit_unit 不可為空

    Example: fixed 類型但缺少 limit_value 時操作失敗
      When 管理員 "Admin" 綁定課程到商品 500，bind_courses_data 如下：
        | course_id | limit_type | limit_value | limit_unit |
        | 100       | fixed      |             | day        |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- bind_courses_data meta 儲存到商品

    Example: 成功綁定多門課程到商品（各自期限設定不同）
      When 管理員 "Admin" 綁定課程到商品 500，bind_courses_data 如下：
        | course_id | limit_type | limit_value | limit_unit |
        | 100       | unlimited  |             |            |
        | 101       | fixed      | 365         | day        |
      Then 操作成功
      And 商品 500 的 bind_courses_data 應包含 2 筆綁定
      And 商品 500 綁定課程 100 的 limit_type 應為 "unlimited"
      And 商品 500 綁定課程 101 的 limit_type 應為 "fixed"
