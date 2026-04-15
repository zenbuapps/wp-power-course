@ignore @frontend
Feature: 短代碼渲染

  Power Course 提供 4 個 WordPress 短代碼供站長嵌入到一般頁面 / 文章中。
  所有短代碼由 `Shortcodes/General.php` 註冊，並提供 REST 端點 `/shortcode`
  讓後台編輯器即時預覽渲染結果。

  **已註冊的短代碼（`General::$shortcodes`）:**

  | 短代碼 | 用途 |
  |--------|------|
  | `[pc_courses]`        | 課程卡片列表（使用 `wc_get_products` 查詢） |
  | `[pc_my_courses]`     | 目前登入用戶的「我的課程」列表 |
  | `[pc_simple_card]`    | 單一簡單/訂閱商品卡片 |
  | `[pc_bundle_card]`    | 單一銷售方案卡片 |

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
      | 10     | Alice | customer      |
    And 系統中有以下課程：
      | courseId | name         | _is_course | status  | _price |
      | 100      | PHP 基礎課   | yes        | publish | 1200   |
      | 101      | Laravel 課程 | yes        | publish | 1500   |
      | 102      | React 進階   | yes        | publish | 2000   |

  # ========== [pc_courses] ==========

  Rule: [pc_courses] 預設列出所有 publish 狀態的課程，分頁 12 筆 × 3 欄

    Example: 不帶參數
      When 頁面內容包含 "[pc_courses]"
      And 訪客瀏覽該頁面
      Then 頁面渲染 3 筆課程卡片（100、101、102）
      And 使用 list/pricing 模板，columns=3

  Rule: [pc_courses] 支援 limit、columns、orderby、order、status 參數

    Example: 每頁 2 筆、2 欄
      When 頁面內容包含 "[pc_courses limit=2 columns=2]"
      Then 頁面渲染 2 筆課程卡片
      And 每行顯示 2 欄

  Rule: [pc_courses exclude_avl_courses=true] 排除當前登入用戶已擁有的課程

    Example: Alice 已擁有課程 100
      Given Alice 已被加入課程 100
      And Alice 登入狀態
      When 頁面內容包含 "[pc_courses exclude_avl_courses=true]"
      Then 頁面渲染 2 筆課程卡片（101、102）
      And 不包含課程 100

  Rule: [pc_courses include / exclude / tag / category] 支援逗號分隔或陣列

    Example: include 指定
      When 頁面內容包含 "[pc_courses include=100,101]"
      Then 頁面渲染 2 筆課程卡片（100、101）

    Example: exclude 排除
      When 頁面內容包含 "[pc_courses exclude=102]"
      Then 頁面渲染 2 筆課程卡片（100、101）

  # ========== [pc_my_courses] ==========

  Rule: [pc_my_courses] 渲染目前登入用戶的我的課程列表（使用 my-account template）

    Example: Alice 登入後看到已擁有的課程
      Given Alice 已被加入課程 100
      And Alice 登入狀態
      When 頁面內容包含 "[pc_my_courses]"
      Then 頁面使用 my-account template 渲染
      And 顯示課程 100 的卡片
      And 與 WooCommerce 我的帳戶 → 我的課程 tab 呈現相同內容

  # ========== [pc_simple_card] ==========

  Rule: [pc_simple_card product_id={id}] 渲染簡單/訂閱商品卡片

    Example: 渲染課程 100
      When 頁面內容包含 "[pc_simple_card product_id=100]"
      Then 頁面使用 card/single-product 模板渲染課程 100

  Rule: [pc_simple_card] 要求商品 type 為 simple 或 subscription

    Example: 商品為銷售方案
      Given 商品 300 的 type 為 simple 但為銷售方案
      When 頁面內容包含 "[pc_simple_card product_id=300]"
      Then 若商品 type 不在 [simple, subscription]，回傳字串 "《商品不是簡單商品》"

  Rule: [pc_simple_card] 商品不存在時顯示錯誤訊息

    Example: 商品 ID 不存在
      When 頁面內容包含 "[pc_simple_card product_id=99999]"
      Then 回傳字串 "《找不到商品》"

  # ========== [pc_bundle_card] ==========

  Rule: [pc_bundle_card product_id={id}] 渲染銷售方案卡片

    Example: 渲染銷售方案 300
      Given 商品 300 為銷售方案（Helper::instance().is_bundle_product = true）
      When 頁面內容包含 "[pc_bundle_card product_id=300]"
      Then 頁面使用 card/bundle-product 模板渲染

  Rule: [pc_bundle_card] 商品不是銷售方案時顯示錯誤訊息

    Example: 商品非銷售方案
      Given 商品 100 為課程商品（不是 bundle）
      When 頁面內容包含 "[pc_bundle_card product_id=100]"
      Then 回傳字串 "《商品不是銷售方案》"

  # ========== 後台預覽（/shortcode GET endpoint） ==========

  Rule: 管理員後台可透過 /shortcode 端點即時渲染短代碼預覽

    Example: 後台預覽 [pc_courses limit=3]
      When 管理員 "Admin" 呼叫 GET /shortcode?shortcode=[pc_courses limit=3]
      Then 操作成功
      And 回應 code 為 "get_shortcode_success"
      And 回應 data 為渲染後的 HTML 字串
      And HTML 包含 3 筆課程卡片的結構
