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
    And 系統中有以下商品：
      | productId | name       | type   | status  | regular_price | stock_quantity |
      | 200       | T-shirt    | simple | publish | 500           | 100            |
      | 201       | 筆記本     | simple | publish | 150           | 50             |

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

  Rule: 前置（參數）- pbp_product_quantities 中的數量必須為正整數（≥ 1）

    Example: 數量為 0 時建立失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type |
        | 合購方案 | 100            | 1399  | bundle      |
      And 銷售方案包含以下商品與數量：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 0        |
      Then 操作失敗

    Example: 數量為負數時建立失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type |
        | 合購方案 | 100            | 1399  | bundle      |
      And 銷售方案包含以下商品與數量：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | -1       |
      Then 操作失敗

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

  Rule: 後置（狀態）- 銷售方案包含商品時，需儲存每個商品的數量（pbp_product_quantities）

    Example: 建立含商品數量的銷售方案
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type |
        | 合購方案 | 100            | 2499  | bundle      |
      And 銷售方案包含以下商品與數量：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      Then 操作成功
      And 新建商品的 pbp_product_ids 應為 [100, 200]
      And 新建商品的 pbp_product_quantities 應為 {"100": 2, "200": 3}

    Example: 未指定數量時預設為 1
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type |
        | 合購方案 | 100            | 1399  | bundle      |
      And 銷售方案包含以下商品（不指定數量）：
        | product_id |
        | 100        |
        | 200        |
      Then 操作成功
      And 新建商品的 pbp_product_quantities 應為 {"100": 1, "200": 1}

  Rule: 後置（狀態）- 更新銷售方案時可修改商品數量

    Example: 更新銷售方案的商品數量
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 1, "200": 1}      |
      When 管理員 "Admin" 更新銷售方案 300，商品數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      Then 操作成功
      And 銷售方案 300 的 pbp_product_quantities 應為 {"100": 2, "200": 3}

    Example: 新增商品到銷售方案時設定數量
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100]           | {"100": 2}                |
      When 管理員 "Admin" 更新銷售方案 300，商品與數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
        | 201        | 5        |
      Then 操作成功
      And 銷售方案 300 的 pbp_product_ids 應為 [100, 200, 201]
      And 銷售方案 300 的 pbp_product_quantities 應為 {"100": 2, "200": 3, "201": 5}

  # ========== 訂單處理：庫存扣減 ==========

  Rule: 後置（狀態）- 結帳時每個商品的庫存扣減 = 商品數量 × 銷售方案購買數量

    Example: 購買 1 份銷售方案，庫存按商品數量扣減
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 2, "200": 3}      |
      When 學員購買 1 份銷售方案 300
      Then 課程 100 的庫存扣減 2
      And 商品 200 的庫存扣減 3

    Example: 購買 2 份銷售方案，庫存按乘積扣減
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 2, "200": 3}      |
      When 學員購買 2 份銷售方案 300
      Then 課程 100 的庫存扣減 4
      And 商品 200 的庫存扣減 6

  # ========== 訂單處理：課程授權 ==========

  Rule: 後置（狀態）- 課程商品數量僅影響庫存扣減，課程授權只給 1 次

    Example: 課程數量為 2 時，授權仍為 1 次
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 2, "200": 3}      |
      When 學員 "Student" 購買 1 份銷售方案 300
      Then 學員 "Student" 獲得課程 100 的觀看權限 1 次
      And 課程 100 的庫存扣減 2
