@ignore @frontend @ui-only
Feature: 我的帳戶課程頁

  Power Course 在 WooCommerce 前台「我的帳戶」頁面插入一個「我的課程」tab，
  供已登入的學員查看自己擁有的所有課程（含訂閱中的 follow_subscription 課程）。

  **Code source:** `inc/classes/FrontEnd/MyAccount.php`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role     |
      | 10     | Alice | customer |
    And Alice 已登入前台

  # ========== 啟用 / 停用 ==========

  Rule: 預設啟用「我的課程」tab；管理員可在設定中隱藏

    Example: 預設啟用
      Given 設定 hide_myaccount_courses 為 "no"
      When Alice 進入 /my-account/
      Then 左側 menu 顯示「我的課程」項目
      And 「我的課程」排在「控制台」下一項（透過 array_slice 重組 menu items）

    Example: 管理員隱藏後
      Given 設定 hide_myaccount_courses 為 "yes"
      When Alice 進入 /my-account/
      Then menu 不顯示「我的課程」項目
      And 直接訪問 /my-account/courses 會 fallback 到 WordPress 404 或 my-account 預設頁
      And MyAccount class 的 constructor 會 return early，完全不註冊任何 hook

  # ========== Endpoint 註冊 ==========

  Rule: 「我的課程」對應到 WooCommerce my-account 的 "courses" endpoint

    Example: 進入課程 tab
      Given 設定 hide_myaccount_courses 為 "no"
      And Alice 已獲得課程 100、101 的存取權
      When Alice 進入 /my-account/courses/
      Then 觸發 "woocommerce_account_courses_endpoint" action
      And 系統呼叫 MyAccount::render_courses
      And 頁面 DOM 結構為 <div class="tailwind">...</div>
      And 內容載入自 templates/my-account template
      And 顯示 Alice 擁有的課程列表

  # ========== Menu 排序 ==========

  Rule: 「我的課程」插入在 menu 的第 2 位（第 1 位之後）

    Example: WooCommerce 預設 menu 順序
      Given WooCommerce 預設 menu 順序為：
        | dashboard | orders | downloads | edit-address | edit-account | customer-logout |
      When MyAccount::courses_menu_items filter 執行
      Then 回傳的 menu 順序為：
        | dashboard | courses | orders | downloads | edit-address | edit-account | customer-logout |

  # ========== Rewrite 規則 ==========

  Rule: add_rewrite_endpoint 新增 /my-account/courses URL 結構

    Example: URL 規則初始化
      When WordPress 執行 "init" action
      Then MyAccount::custom_account_endpoint 呼叫 add_rewrite_endpoint("courses", EP_ROOT | EP_PAGES)
      And /my-account/courses 變成可路由的 URL
      And 需要在首次啟用時 flush_rewrite_rules（由外掛 LifeCycle 負責）
