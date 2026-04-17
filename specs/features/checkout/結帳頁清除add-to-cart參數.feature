@checkout @bugfix @issue-200
Feature: 結帳頁清除 add-to-cart URL 參數

  修正使用者在結帳頁按重整後，因 URL 中的 `?add-to-cart=XXX` 參數持續存在，
  導致 WooCommerce 重複將商品加入購物車的問題。

  **Issue:** #200
  **Root cause:** 課程銷售頁的「立即報名」按鈕直接導向 `checkout?add-to-cart={product_id}`，
  WooCommerce 每次頁面載入時都會處理 `add-to-cart` GET 參數，因此重整會重複加入商品。

  **Solution:** 在 PHP 端使用 `template_redirect` hook，偵測結帳頁面含有 `add-to-cart`
  GET 參數時，讓 WooCommerce 先處理加入購物車，再 302 redirect 到乾淨的結帳頁 URL
  （移除 `add-to-cart` 及 `quantity` 參數）。

  **Code target:** `inc/classes/FrontEnd/CheckoutRedirect.php`（新建）

  Background:
    Given WooCommerce 已啟用並設定結帳頁
    And 系統中有以下商品：
      | productId | name       | type     | price |
      | 101       | PHP 入門   | course   | 1000  |
      | 102       | JS 入門    | course   | 0     |
      | 201       | 全端方案   | bundle   | 2000  |
      | 301       | 月費方案   | subscription | 299 |

  # ========== 核心修正：結帳頁 redirect ==========

  Rule: 結帳頁含 add-to-cart 參數時，WC 處理完後 302 redirect 到乾淨 URL

    Example: 付費課程 — 首次點擊加入購物車後 redirect
      Given 購物車為空
      When 使用者訪問 "/checkout/?add-to-cart=101"
      Then WooCommerce 將商品 101 加入購物車
      And 系統執行 302 redirect 到 "/checkout/"（不含 add-to-cart 參數）
      And 購物車中商品 101 的數量為 1

    Example: 免費課程 — 同樣 redirect
      Given 購物車為空
      When 使用者訪問 "/checkout/?add-to-cart=102"
      Then WooCommerce 將商品 102 加入購物車
      And 系統執行 302 redirect 到 "/checkout/"（不含 add-to-cart 參數）

    Example: Bundle 銷售方案 — 同樣 redirect
      Given 購物車為空
      When 使用者訪問 "/checkout/?add-to-cart=201"
      Then WooCommerce 將商品 201 加入購物車
      And 系統執行 302 redirect 到 "/checkout/"（不含 add-to-cart 參數）

    Example: 訂閱制商品 — 同樣 redirect
      Given 購物車為空
      When 使用者訪問 "/checkout/?add-to-cart=301"
      Then WooCommerce 將商品 301 加入購物車
      And 系統執行 302 redirect 到 "/checkout/"（不含 add-to-cart 參數）

  # ========== 重整防護 ==========

  Rule: 重整結帳頁不會導致商品重複加入購物車

    Example: 重整後購物車數量維持不變
      Given 使用者已透過 "/checkout/?add-to-cart=101" 加入商品
      And 系統已 redirect 到乾淨的 "/checkout/" URL
      When 使用者在 "/checkout/" 頁面按下重整（F5）
      Then 購物車中商品 101 的數量仍為 1
      And URL 中不含 "add-to-cart" 參數

    Example: 多次重整後購物車數量仍然不變
      Given 使用者已透過 "/checkout/?add-to-cart=101" 加入商品
      And 系統已 redirect 到乾淨的 "/checkout/" URL
      When 使用者連續重整頁面 3 次
      Then 購物車中商品 101 的數量仍為 1

  # ========== 不影響正常購物車行為 ==========

  Rule: 購物車中已有其他商品時，新加入的商品不會影響既有商品

    Example: 購物車已有商品時加入新商品
      Given 購物車中已有商品 101（數量 1）
      When 使用者訪問 "/checkout/?add-to-cart=201"
      Then WooCommerce 將商品 201 加入購物車
      And 系統執行 302 redirect 到 "/checkout/"
      And 購物車中商品 101 的數量為 1
      And 購物車中商品 201 的數量為 1

  Rule: 允許多份購買（不啟用 sold_individually）

    Example: 使用者可手動增加同一商品的數量
      Given 購物車中已有商品 101（數量 1）
      When 使用者在購物車頁面將商品 101 的數量改為 2
      Then 購物車中商品 101 的數量為 2

  # ========== 非結帳頁不受影響 ==========

  Rule: 非結帳頁的 add-to-cart 行為不受影響

    Example: 一般商品頁的 add-to-cart 行為維持不變
      When 使用者在商品頁透過 AJAX 加入購物車
      Then WooCommerce 正常處理 AJAX 加入購物車
      And 不觸發 redirect 邏輯

    Example: 購物車頁面的 add-to-cart 不受影響
      When 使用者訪問 "/cart/?add-to-cart=101"
      Then WooCommerce 正常處理加入購物車
      And 不觸發 redirect 邏輯（僅結帳頁觸發）

  # ========== URL 參數清理 ==========

  Rule: redirect 時保留非 add-to-cart 的 query 參數

    Example: 保留 coupon 等其他參數
      When 使用者訪問 "/checkout/?add-to-cart=101&coupon=SAVE10"
      Then WooCommerce 將商品 101 加入購物車
      And 系統 redirect 到 "/checkout/?coupon=SAVE10"
      And URL 中不含 "add-to-cart" 參數
      And URL 中保留 "coupon=SAVE10" 參數

    Example: 清除 quantity 參數
      When 使用者訪問 "/checkout/?add-to-cart=101&quantity=3"
      Then WooCommerce 將商品 101 加入購物車（數量 3）
      And 系統 redirect 到 "/checkout/"
      And URL 中不含 "add-to-cart" 和 "quantity" 參數

  # ========== 已購學員 Modal ==========

  Rule: 已購學員的 Modal 確認流程不受影響

    Example: 已購學員點擊「立即報名」仍跳出 Modal
      Given Alice 已購買課程 101
      When Alice 在課程銷售頁點擊「立即報名」
      Then 系統顯示「您已經購買課程，確定要再次購買？」Modal
      And Alice 點擊「確認購買」後導向結帳頁
      And 結帳頁 redirect 到乾淨 URL
