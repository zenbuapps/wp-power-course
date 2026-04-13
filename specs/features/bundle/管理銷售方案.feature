@ignore @command
Feature: 管理銷售方案

  # Note: exclude_main_course 已廢棄（Issue #185）
  # 當前課程改為普通商品，可在 pbp_product_ids 中自由加入/移除
  # 詳見：銷售方案商品數量設定.feature、移除排除當前課程功能.feature

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- link_course_id 對應的課程必須存在（每個銷售方案綁定一門課程，1:N 關係）

    Example: 連結不存在的課程時建立失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type    |
        | 月費方案 | 9999           | 399   | single_course  |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Scenario Outline: 缺少 <缺少參數> 時建立失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name   | link_course_id   | price   | bundle_type   |
        | <name> | <link_course_id> | <price> | <bundle_type> |
      Then 操作失敗

      Examples:
        | 缺少參數       | name     | link_course_id | price | bundle_type   |
        | name           |          | 100            | 399   | single_course |
        | link_course_id | 月費方案 |                | 399   | single_course |
        | bundle_type    | 月費方案 | 100            | 399   |               |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 建立 WooCommerce 商品並設定 bundle_type meta

    Example: 成功建立銷售方案
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | regular_price | bundle_type   |
        | 月費方案 | 100            | 399   | 599           | single_course |
      Then 操作成功
      And 新建商品的 bundle_type meta 應為 "single_course"
      And 新建商品的 link_course_ids meta 應為 100

  Rule: 後置（狀態）- 支援設定 bind_courses_data（與普通商品共用綁定課程到商品邏輯）

    Example: 建立含多課程綁定的銷售方案
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 年度套餐   | 100            | 2999  | annual_course |
      And 銷售方案的 bind_courses_data 如下：
        | course_id | limit_type | limit_value | limit_unit |
        | 100       | fixed      | 365         | day        |
      Then 操作成功
