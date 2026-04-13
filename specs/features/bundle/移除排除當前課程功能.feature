@ignore @command
Feature: 移除排除當前課程功能

  移除 exclude_main_course 開關，改以統一的商品列表管理當前課程。
  當前課程視為普通商品，可自由加入/移除/設定數量。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | React 課程 | yes        | publish |
    And 系統中有以下 WooCommerce 商品：
      | productId | name          | status  |
      | 200       | Power T-shirt | publish |

  # ========== 新建方案 - 自動加入當前課程 ==========

  Rule: 新建銷售方案時，預設自動加入「當前課程」×1

    Example: 新建方案時當前課程自動出現在商品列表中
      When 管理員 "Admin" 在課程 100 下新建銷售方案，參數如下：
        | name     | link_course_id | price | bundle_type   |
        | 月費方案 | 100            | 399   | single_course |
      Then 操作成功
      And 方案的 pbp_product_ids 應包含 [100]
      And 方案的 pbp_product_quantities 應包含 {"100": 1}

    Example: 新建方案並加入額外商品，當前課程 + 額外商品都在列表中
      When 管理員 "Admin" 在課程 100 下新建銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 3        |
      Then 操作成功
      And 方案的 pbp_product_ids 應包含 [100, 200]

  # ========== 移除當前課程 ==========

  Rule: 管理員可以從銷售方案中移除當前課程，交互與移除其他商品一致

    Example: 成功移除當前課程
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 3        |
      When 管理員 "Admin" 從銷售方案 300 中移除商品 100
      Then 操作成功
      And 方案的 pbp_product_ids 應包含 [200]
      And 方案的 pbp_product_ids 不應包含 100

  Rule: 移除當前課程後，可透過搜尋重新加入

    Example: 當前課程被移除後出現在商品搜尋結果中
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品如下（不含當前課程）：
        | product_id |
        | 200        |
      When 管理員在銷售方案 300 的商品搜尋框中搜尋 "PHP"
      Then 搜尋結果應包含課程 "PHP 基礎課"
      When 管理員將課程 100 加入銷售方案 300
      Then 方案的 pbp_product_ids 應包含 [200, 100]

  # ========== 向下相容 - exclude_main_course ==========

  Rule: 向下相容 - 舊方案啟用「排除當前課程」時，商品列表維持不變（不含當前課程）

    Example: 舊方案 exclude_main_course=yes，讀取時不自動加入當前課程
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | price | bundle_type   | exclude_main_course |
        | 300      | 不含課程方案 | 100            | 299   | single_course | yes                 |
      And 銷售方案 300 包含商品如下：
        | product_id |
        | 200        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_ids 應包含 [200]
      And 回傳的 pbp_product_ids 不應包含 100

  Rule: 向下相容 - 舊方案未啟用「排除當前課程」時，自動補入當前課程 ×1

    Example: 舊方案 exclude_main_course=no，讀取時自動加入當前課程
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   | exclude_main_course |
        | 300      | 含課程方案 | 100            | 999   | single_course | no                  |
      And 銷售方案 300 的 pbp_product_ids 不含課程 100（舊資料由前端 runtime 補入）：
        | product_id |
        | 200        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_ids 應包含 [100, 200]
      And 回傳的 pbp_product_quantities 中課程 100 的數量應為 1

    Example: 舊方案 exclude_main_course 為空值，等同 no，自動加入當前課程
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   | exclude_main_course |
        | 300      | 含課程方案 | 100            | 999   | single_course |                     |
      And 銷售方案 300 的 pbp_product_ids 不含課程 100：
        | product_id |
        | 200        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_ids 應包含 [100, 200]

  # ========== 向下相容 - 舊方案已含當前課程不重複加入 ==========

  Rule: 向下相容 - 舊方案的 pbp_product_ids 已含當前課程時，不重複加入

    Example: 舊方案 pbp_product_ids 已含當前課程，不重複
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   | exclude_main_course |
        | 300      | 含課程方案 | 100            | 999   | single_course | no                  |
      And 銷售方案 300 的 pbp_product_ids 已包含課程 100：
        | product_id |
        | 100        |
        | 200        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_ids 應為 [100, 200]
      And 課程 100 在列表中只出現一次

  # ========== UI - exclude_main_course 開關移除 ==========

  Rule: UI 不再顯示 exclude_main_course 開關

    Example: 銷售方案編輯介面不包含「排除當前課程」選項
      When 管理員 "Admin" 進入課程 100 的銷售方案編輯頁面
      Then 頁面不應顯示 "排除當前課程" 開關
      And 頁面不應包含 exclude_main_course 表單欄位

  # ========== 課程複製 ==========

  Rule: 複製課程時，銷售方案的數量資訊與商品列表一併複製

    Example: 複製課程時銷售方案數量被複製
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      When 管理員 "Admin" 複製課程 100
      Then 操作成功
      And 新課程應有 1 個銷售方案
      And 新銷售方案的 pbp_product_quantities 中商品數量應為：
        | product_name  | quantity |
        | 新課程（複製）| 2        |
        | Power T-shirt | 3        |
