@ignore @query
Feature: 查詢商品列表

  管理員可透過 /products 端點查詢所有 WooCommerce 商品，支援依 `is_course` meta 篩選，
  以及依 `link_course_ids` 查詢屬於某個課程的銷售方案。

  **Code source:** `inc/classes/Api/Product.php::get_products_callback`, `get_products_select_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下商品：
      | productId | name           | type   | status  | _is_course | link_course_ids |
      | 100       | PHP 基礎課     | simple | publish | yes        |                 |
      | 101       | Laravel 課程   | simple | publish | yes        |                 |
      | 200       | 加購包         | simple | publish | no         |                 |
      | 300       | PHP 銷售方案   | simple | publish | no         | 100             |
      | 301       | Laravel 方案   | simple | publish | no         | 101             |

  # ========== 基本查詢 ==========

  Rule: 預設回傳所有狀態為 publish 與 draft 的商品，分頁 10 筆

    Example: 不帶參數查詢
      When 管理員 "Admin" 呼叫 GET /products
      Then 操作成功
      And 回應為陣列，最多 10 筆
      And 回應 header 包含 X-WP-Total 與 X-WP-TotalPages

  # ========== is_course 篩選 ==========

  Rule: 可用 is_course=true 僅查詢課程商品

    Example: 只要課程商品
      When 管理員 "Admin" 呼叫 GET /products?is_course=true
      Then 回應包含 productId 100、101
      And 回應不包含 productId 200、300、301

    Example: 只要非課程商品
      When 管理員 "Admin" 呼叫 GET /products?is_course=false
      Then 回應包含 productId 200、300、301
      And 回應不包含 productId 100、101

  # ========== link_course_ids 篩選 ==========

  Rule: 可用 link_course_ids 查詢某課程的銷售方案

    Example: 查詢課程 100 的銷售方案
      When 管理員 "Admin" 呼叫 GET /products?link_course_ids=100
      Then 回應包含 productId 300
      And 回應不包含 productId 301

  # ========== 價格區間 ==========

  Rule: 可用 price_range=[min,max] 篩選價格區間

    Example: 查詢 500 ~ 2000 元的商品
      Given 商品 100 的 _price 為 "1200"
      And 商品 101 的 _price 為 "2500"
      When 管理員 "Admin" 呼叫 GET /products?price_range[0]=500&price_range[1]=2000
      Then 回應包含 productId 100
      And 回應不包含 productId 101

  # ========== 下拉選項版本（/products/select） ==========

  Rule: /products/select 回傳輕量版選項（id、name、type、is_course）

    Example: 管理員在 Analytics 頁面使用 useSelect
      When 前端呼叫 GET /products/select
      Then 操作成功
      And 回應為陣列
      And 每筆包含欄位 id、name、type、is_course
      And 不包含完整的商品詳情（description、images 等）
      And 預設 posts_per_page 為 20

  Rule: /products/select 支援關鍵字搜尋與 meta_key/meta_value 篩選

    Example: 搜尋 "PHP"
      When 前端呼叫 GET /products/select?s=PHP
      Then 回應包含 name 包含 "PHP" 的商品

    Example: 依 link_course_ids meta 查詢銷售方案
      When 前端呼叫 GET /products/select?meta_key=link_course_ids&meta_value=100&meta_compare=IN
      Then 回應包含 productId 300

  # ========== Options ==========

  Rule: /products/options 回傳商品管理頁需要的 lookup 選項

    Example: 取得商品類型與分類選項
      When 管理員 "Admin" 呼叫 GET /products/options
      Then 回應包含 product_types、product_statuses、product_cats、product_tags
