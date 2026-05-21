@ignore @frontend @ui-only @issue-225
Feature: 銷售方案卡片庫存顯示

  使用者透過 `[pc_pricing_table]` 短代碼瀏覽多個銷售方案時，
  銷售方案卡片（`card/pricing` 模板）必須與其他銷售卡片
  （`single-product-sale.php` / `bundle-product.php`）一樣，
  在啟用庫存管理時顯示「剩餘 X 個」庫存資訊；
  低庫存時切換紅色警示；售完時切換灰色「已售完」標示並停用卡片進入購買的入口。

  **Issue**: #225 — 銷售方案的庫存無法顯示
  **Code source**:
    - `inc/templates/components/card/pricing.php`（主要修改檔案）
    - `inc/templates/components/stock/index.php`（重用既有庫存模板）
    - `inc/templates/components/list/pricing.php`（呼叫端）
    - `inc/classes/Shortcodes/General.php::pc_pricing_table_callback()`（短代碼進入點）

  Background:
    Given 系統中有以下銷售方案商品（皆為已發佈、可銷售狀態）：
      | productId | name             | manage_stock | stock_quantity | stock_status   | low_stock_amount |
      | 500       | PHP 課程基礎方案 | yes          | 50             | instock        | 5                |
      | 501       | PHP 課程進階方案 | yes          | 3              | instock        | 5                |
      | 502       | PHP 課程旗艦方案 | yes          | 0              | outofstock     | 5                |
      | 503       | PHP 課程訂閱方案 | no           | -              | instock        | -                |
    And `manage_stock = yes` 的商品其 `show_rest_stock` meta 預設為 `yes`
    And 訪客透過短代碼 `[pc_pricing_table]` 瀏覽銷售方案列表頁面

  # ========== Q1 / Q2：庫存顯示位置與樣式 ==========

  Rule: 銷售方案卡片在「價格」下方重用既有 stock 模板渲染庫存資訊

    Example: 啟用庫存管理且有充足庫存時顯示「剩餘 X 個」
      Given 商品 500 啟用庫存管理且剩餘 50 個
      When 訪客瀏覽銷售方案列表頁
      Then 商品 500 的卡片 DOM 結構依序為：
        | 區塊            |
        | 商品圖片        |
        | 標籤（熱門/精選）|
        | 商品名稱        |
        | 講師名稱        |
        | 價格            |
        | 庫存資訊        |
        | 課程時長 / 學員數 |
      And 卡片顯示綠色 badge `50 left in stock`（繁中翻譯為「剩餘 50 個」）
      And 庫存 badge 透過 `Plugin::load_template( 'stock', [ 'product' => $product ], false )` 渲染
      And 庫存 badge 的樣式來自 `inc/templates/components/stock/index.php`，與其他卡片完全一致

  # ========== Q3：未啟用庫存管理時的行為 ==========

  Rule: 未啟用庫存管理（manage_stock = no）時不渲染庫存區塊

    Example: 訂閱方案沒有庫存概念
      Given 商品 503 `manage_stock = no`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 503 的卡片 **不顯示** 任何庫存 badge
      And 卡片其他區塊（圖片、價格、課程時長、學員數）正常顯示
      And 庫存區塊的容器在卡片內完全不存在（不是隱藏而是不輸出）

    Example: 啟用庫存管理但站長關閉「顯示剩餘庫存」設定
      Given 商品 500 啟用庫存管理但 `show_rest_stock` meta 為 `no`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 500 的卡片 **不顯示** 庫存 badge
      And 沿用 `stock/index.php` 既有的 early return 邏輯

  # ========== Q4：售完時的卡片狀態 ==========

  Rule: 售完（stock_quantity = 0 且 stock_status = outofstock）時，卡片切換為「已售完」狀態並停用購買入口

    Example: 售完商品顯示灰色「已售完」標示
      Given 商品 502 `manage_stock = yes`、`stock_quantity = 0`、`stock_status = outofstock`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 502 的卡片顯示灰色 badge（樣式 class 含 `bg-gray-100 text-gray-500`）
      And badge 文字顯示「已售完」（msgid: `Sold out`）
      # 註：既有 stock template 當庫存 ≤ 0 時雖已切換灰色但文字仍顯示「0 left in stock」，
      #     本 issue 要求售完時切換為更明確的「Sold out」字樣。
      And 卡片整體加上 `pc-course-card--sold-out` 修飾 class（透明度降低、滑鼠 hover 不再放大圖片）
      And 卡片上的 `<a href="permalink">` 連結被停用（改為 `<span>` 或加上 `aria-disabled="true"` 且 `pointer-events: none`）
      And 點擊卡片任何區塊都不會導向商品頁

    Example: 售完商品在卡片上不再呈現可購買狀態
      Given 商品 502 已售完
      Then 商品 502 的卡片視覺上明顯與其他可購買卡片區隔（透明度 / 灰階）
      And 訪客無法透過卡片任何操作將商品加入購物車或前往商品頁
      And accessibility：螢幕閱讀器朗讀「已售完」標示

  # ========== Q5：低庫存警示 ==========

  Rule: 庫存數量低於或等於 low_stock_amount 時切換紅色警示

    Example: 個別商品自訂低庫存量
      Given 商品 501 `manage_stock = yes`、`stock_quantity = 3`、`low_stock_amount = 5`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 501 的卡片庫存 badge 顯示紅色（樣式 class 含 `bg-red-100 text-red-500`）
      And badge 文字仍為「剩餘 3 個」（沿用既有 stock template 的文字格式）
      And 視覺警示由 `stock/index.php` 既有邏輯處理：
        """
        if ($stock_quantity <= $notify_low_stock_amount) {
            $color_class = 'bg-red-100 text-red-500';
        }
        """

    Example: 未自訂個別商品低庫存量則使用 WooCommerce 全站設定
      Given 商品 501 未設定 `low_stock_amount`（空字串）
      And WooCommerce 全站 `notify_low_stock_amount` 設定為 2
      And 商品 501 的 `stock_quantity = 3`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 501 庫存 badge 顯示綠色（3 > 2，未達低庫存閾值）

    Example: 庫存等於低庫存閾值也屬於警示範圍
      Given 商品 501 `stock_quantity = 5`、`low_stock_amount = 5`
      When 訪客瀏覽銷售方案列表頁
      Then 商品 501 庫存 badge 顯示紅色（沿用 `<=` 比較邏輯）

  # ========== Q6：修復範圍邊界 ==========

  Rule: 本 issue 僅修改 `card/pricing.php`，不動其他既有卡片模板

    Example: 不變更 single-product-sale.php / bundle-product.php
      Given 既有 `single-product-sale.php` 與 `bundle-product.php` 已正確呼叫 stock 模板
      Then 本 issue 不修改這兩個檔案
      And 不抽取共用 partial（留待未來重構 issue 處理）
      And 不變更 `stock/index.php` 既有行為（除非售完文字需從「0 left in stock」改為「Sold out」，此調整影響全站庫存顯示，本 issue 範圍內須同步驗證其他卡片仍可接受此文字）

  # ========== Q7：E2E 測試覆蓋 ==========

  Rule: Playwright E2E 測試覆蓋三種庫存狀態 + 兩種未顯示狀態

    Example: 補測試於 tests/e2e/frontend/
      Given 測試檔位於 `tests/e2e/frontend/pricing-card-stock.spec.ts`
      And 測試以 `[pc_pricing_table]` 短代碼建立的頁面為目標
      When 執行 `pnpm run test:e2e:frontend`
      Then 通過以下情境：
        | scenario               | assertion                                                              |
        | 充足庫存               | 卡片顯示綠色 badge「剩餘 50 個」                                       |
        | 低庫存                 | 卡片顯示紅色 badge「剩餘 3 個」                                        |
        | 售完                   | 卡片顯示灰色 badge「已售完」、卡片連結被停用、視覺呈現灰階             |
        | 未啟用庫存管理         | 卡片完全不顯示庫存 badge                                               |
        | show_rest_stock = no   | 卡片完全不顯示庫存 badge                                               |

  # ========== i18n 規範 ==========

  Rule: 新字串遵循 power-course i18n 規範

    Example: 新增的 msgid 一律為英文
      Given 新增 msgid `Sold out`（若 stock template 文案調整時使用）
      Then msgid 為英文完整句子（首字大寫，sentence case）
      And 繁中翻譯加入 `scripts/i18n-translations/manual.json`：
        """
        { "msgid": "Sold out", "msgstr_zh_TW": "已售完", "context": "inc/templates/components/stock/index.php" }
        """
      And 跑 `pnpm run i18n:build` 同步 .pot / .po / .mo / .json 四個檔
      And 該四個檔的 diff 與 PHP 程式碼變更一起 commit
      And 禁止手改 `.po`
