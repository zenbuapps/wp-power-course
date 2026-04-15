@ignore @command
Feature: 銷售方案商品數量設定

  管理員可以在銷售方案編輯介面，為每個加入的商品設定數量（含當前課程）。
  數量影響庫存扣減與訂單明細，不影響課程存取權。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | 小明  | ming@test.com  | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | stock_quantity |
      | 100      | PHP 基礎課 | yes        | publish | 50             |
      | 101      | React 課程 | yes        | publish | 30             |
    And 系統中有以下 WooCommerce 商品：
      | productId | name             | status  | stock_quantity |
      | 200       | Power T-shirt    | publish | 100            |
      | 201       | 學習筆記本        | publish | 200            |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 數量必須為 1 ~ 999 的正整數

    Example: 數量為 0 時自動修正為 1
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        | 0        |
        | 200        | 3        |
      Then 操作成功
      And 商品 100 的數量應為 1

    Example: 數量為空值時自動修正為 1
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        |          |
        | 200        | 3        |
      Then 操作成功
      And 商品 100 的數量應為 1

    Example: 數量超過 999 時操作失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 1000     |
      Then 操作失敗

    Example: 數量為負數時操作失敗
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        | -1       |
      Then 操作失敗

  # ========== 後置（狀態）- 數量儲存 ==========

  Rule: 後置（狀態）- pbp_product_quantities meta 以 JSON 物件儲存各商品數量

    Example: 成功建立含數量的銷售方案
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      Then 操作成功
      And 方案的 pbp_product_ids 應包含 [100, 200]
      And 方案的 pbp_product_quantities 應為 {"100": 2, "200": 3}

    Example: 數量預設為 1
      When 管理員 "Admin" 建立銷售方案，參數如下：
        | name       | link_course_id | price | bundle_type   |
        | 全套學習包 | 100            | 1999  | single_course |
      And 設定方案包含商品如下（不指定數量）：
        | product_id |
        | 100        |
        | 200        |
      Then 操作成功
      And 方案的 pbp_product_quantities 應為 {"100": 1, "200": 1}

    Example: 儲存後重新載入數量正確顯示
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_quantities 應為 {"100": 2, "200": 3}

  # ========== 後置（狀態）- 更新數量 ==========

  Rule: 後置（狀態）- 可更新既有方案的商品數量

    Example: 成功更新銷售方案中的商品數量
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 3        |
      When 管理員 "Admin" 更新銷售方案 300，設定商品數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 5        |
      Then 操作成功
      And 方案的 pbp_product_quantities 應為 {"100": 2, "200": 5}

  # ========== 後置（狀態）- 不允許重複商品 ==========

  Rule: 後置（狀態）- 同一商品只能在方案中出現一次

    Example: 重複加入同一商品時應提示已存在
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 3        |
      When 管理員嘗試將商品 200 再次加入銷售方案 300
      Then 操作失敗，提示「該商品已在方案中，請直接修改數量」

  # ========== 後置（狀態）- 訂單處理 ==========

  Rule: 後置（狀態）- 購買方案時 bundled item 數量 = 方案設定數量 × 購買份數

    Example: 購買 1 份方案，各商品依設定數量扣減庫存
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      When 學員 "小明" 購買 1 份銷售方案 300，訂單狀態為 "completed"
      Then 訂單中應包含以下 bundled items：
        | product_id | name                          | quantity | subtotal |
        | 100        | 全套學習包 - PHP 基礎課       | 2        | 0        |
        | 200        | 全套學習包 - Power T-shirt    | 3        | 0        |
      And PHP 基礎課庫存應為 48
      And Power T-shirt 庫存應為 97

    Example: 購買 2 份方案，庫存扣減 = 購買份數 × 方案設定數量
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
      When 學員 "小明" 購買 2 份銷售方案 300，訂單狀態為 "completed"
      Then 訂單中應包含以下 bundled items：
        | product_id | name                          | quantity | subtotal |
        | 100        | 全套學習包 - PHP 基礎課       | 4        | 0        |
        | 200        | 全套學習包 - Power T-shirt    | 6        | 0        |
      And PHP 基礎課庫存應為 46
      And Power T-shirt 庫存應為 94

  Rule: 後置（狀態）- 課程存取權不受數量影響

    Example: 課程數量為 2 時，學員仍只獲得 1 個課程存取權
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
      And 銷售方案 300 的 bind_courses_data 如下：
        | course_id | limit_type |
        | 100       | unlimited  |
      When 學員 "小明" 購買 1 份銷售方案 300，訂單狀態為 "completed"
      Then 學員 "小明" 應有 PHP 基礎課 的存取權
      And 學員 "小明" 的 PHP 基礎課 存取權數量為 1（非 2）

  # ========== 向下相容 ==========

  Rule: 向下相容 - 缺少 pbp_product_quantities meta 的舊方案，所有商品預設數量為 1

    Example: 舊銷售方案（無 pbp_product_quantities）數量 fallback 為 1
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 舊方案     | 100            | 999   | single_course |
      And 銷售方案 300 包含商品如下（無 pbp_product_quantities meta）：
        | product_id |
        | 100        |
        | 200        |
      When 管理員 "Admin" 讀取銷售方案 300
      Then 操作成功
      And 回傳的 pbp_product_quantities 應為 {"100": 1, "200": 1}

    Example: 舊銷售方案購買時各商品數量為 1
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 舊方案     | 100            | 999   | single_course |
      And 銷售方案 300 包含商品如下（無 pbp_product_quantities meta）：
        | product_id |
        | 100        |
        | 200        |
      When 學員 "小明" 購買 1 份銷售方案 300，訂單狀態為 "completed"
      Then 訂單中應包含以下 bundled items：
        | product_id | quantity |
        | 100        | 1        |
        | 200        | 1        |

  # ========== 前台顯示 ==========

  Rule: 前台銷售卡片 - 每個商品名稱後方顯示 ×N（含 N=1）

    Example: 前台卡片顯示各商品數量
      Given 系統中有以下銷售方案：
        | bundleId | name       | link_course_id | price | bundle_type   |
        | 300      | 全套學習包 | 100            | 1999  | single_course |
      And 銷售方案 300 包含商品及數量如下：
        | product_id | quantity |
        | 100        | 2        |
        | 200        | 3        |
        | 201        | 1        |
      When 訪客瀏覽課程 100 的銷售頁面
      Then 銷售方案 "全套學習包" 卡片應顯示：
        | product_name  | display_quantity |
        | PHP 基礎課    | ×2               |
        | Power T-shirt | ×3               |
        | 學習筆記本    | ×1               |
