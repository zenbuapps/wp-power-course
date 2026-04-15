@ignore @query
Feature: 銷售方案數量顯示

  Background:
    Given 系統中有以下課程：
      | courseId | name       | _is_course | status  | regular_price |
      | 100      | PHP 基礎課 | yes        | publish | 999           |
    And 系統中有以下商品：
      | productId | name       | type   | status  | regular_price |
      | 200       | T-shirt    | simple | publish | 500           |
      | 201       | 筆記本     | simple | publish | 150           |

  # ========== 後台管理介面：數量輸入 ==========

  Rule: 後台（UI）- 銷售方案編輯表單中每個商品旁顯示數量輸入框，最小值為 1

    Example: 當前課程有數量輸入框
      Given 管理員在課程 100 的銷售方案編輯頁
      When 管理員建立一個銷售方案
      Then 「目前課程」欄位旁應顯示數量輸入框
      And 數量輸入框預設值為 1
      And 數量輸入框最小值為 1

    Example: 新增的商品有數量輸入框
      Given 管理員在課程 100 的銷售方案編輯頁
      And 已將商品 "T-shirt" 加入銷售方案
      Then 商品 "T-shirt" 旁應顯示數量輸入框
      And 數量輸入框預設值為 1

    Example: 修改商品數量後自動計算建議售價
      Given 管理員在課程 100 的銷售方案編輯頁
      And 銷售方案包含以下商品與數量：
        | product    | quantity |
        | PHP 基礎課 | 2        |
        | T-shirt    | 3        |
      Then 建議原價應為 2 × 999 + 3 × 500 = 3498

  # ========== 後台管理介面：載入已有數量 ==========

  Rule: 後台（UI）- 編輯既有銷售方案時，數量輸入框應載入已儲存的數量

    Example: 載入既有銷售方案的商品數量
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 2, "200": 3}      |
      When 管理員編輯銷售方案 300
      Then 課程 "PHP 基礎課" 的數量輸入框應顯示 2
      And 商品 "T-shirt" 的數量輸入框應顯示 3

  # ========== 前台顧客端：數量顯示 ==========

  Rule: 前台（顯示）- 銷售方案商品列表顯示「商品名 × 數量」格式

    Example: 顯示含數量的商品列表
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids    | pbp_product_quantities              |
        | 300      | 合購方案 | 100            | bundle      | [100, 200, 201]    | {"100": 2, "200": 3, "201": 1}      |
      When 顧客瀏覽課程 100 的銷售頁面
      Then 銷售方案 "合購方案" 的商品列表應顯示：
        | display_text       |
        | PHP 基礎課 × 2     |
        | T-shirt × 3        |
        | 筆記本 × 1          |

  # ========== API 回傳格式 ==========

  Rule: API（回傳）- GET 銷售方案時回傳 pbp_product_quantities 欄位

    Example: API 回傳包含數量資訊
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids | pbp_product_quantities    |
        | 300      | 合購方案 | 100            | bundle      | [100, 200]      | {"100": 2, "200": 3}      |
      When 管理員查詢銷售方案列表（link_course_id = 100）
      Then 回應中銷售方案 300 應包含：
        | field                    | value                |
        | pbp_product_ids          | ["100", "200"]       |
        | pbp_product_quantities   | {"100": 2, "200": 3} |

    Example: 舊資料沒有數量時回傳空物件
      Given 系統中有以下銷售方案（舊格式，無數量欄位）：
        | bundleId | name     | link_course_id | bundle_type | pbp_product_ids |
        | 300      | 舊方案   | 100            | bundle      | [100, 200]      |
      When 管理員查詢銷售方案列表（link_course_id = 100）
      Then 回應中銷售方案 300 的 pbp_product_quantities 應為 {}
