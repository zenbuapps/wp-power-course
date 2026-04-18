@ignore @command
Feature: 清空選填欄位並儲存

  課程編輯頁 (Courses Edit Admin SPA) 對「可清空的選填欄位」必須支援：
  使用者清空欄位並儲存 → invalidate 後欄位確實為空值（不保留舊值）。
  對應 Issue #203。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | limit_type | price | sale_price | date_on_sale_from   | date_on_sale_to     | short_description | purchase_note | sku     | course_schedule | feature_video            | trial_video              | limit_value | limit_unit |
      | 109      | PHP 基礎課 | yes        | publish | fixed     | 1200  | 888        | 2026-01-01 00:00:00 | 2026-12-31 23:59:59 | 入門必修          | 感謝購買      | PHP-109 | 1735689600      | {"id":"demo1","type":"bunny-stream"} | {"id":"demo2","type":"bunny-stream"} | 30          | day        |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes

    Example: 不存在的課程清空欄位失敗
      When 管理員 "Admin" 清空課程 9999 的 "sale_price" 並儲存
      Then 操作失敗，HTTP 狀態為 403

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 前端必須把「被清空的欄位」顯式送為空字串 ""

    Example: 前端未送 sale_price key（undefined 被 axios 過濾）- 舊行為保留
      Given 課程 109 的 sale_price 為 "888"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | name       |
        | PHP 基礎課 |
      Then 操作成功
      And 課程 109 的 sale_price 應為 "888"
      # 未傳 key → 保持原狀，屬於設計契約

    Example: 前端顯式送 sale_price 為空字串 - 清空生效
      Given 課程 109 的 sale_price 為 "888"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | sale_price |
        |            |
      Then 操作成功
      And 課程 109 的 sale_price 應為 ""

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- sale_price 清空時，DB meta `_sale_price` 存為空字串

    Example: 清空 sale_price
      Given 課程 109 的 sale_price 為 "888"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | sale_price |
        |            |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "_sale_price" 的 meta_value 應為 ""
      And 課程 109 的 is_on_sale 應為 false

  Rule: 後置（狀態）- date_on_sale_from / to 清空時，對應 meta 存為空字串

    Example: 同時清空 date_on_sale_from 與 date_on_sale_to
      Given 課程 109 的 date_on_sale_from 為 "2026-01-01 00:00:00"
      And 課程 109 的 date_on_sale_to 為 "2026-12-31 23:59:59"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | date_on_sale_from | date_on_sale_to |
        |                   |                 |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_from" 的 meta_value 應為 ""
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_to" 的 meta_value 應為 ""

  Rule: 後置（狀態）- date_on_sale 單側為空但另一側有值時，視為兩側都清空

    Example: 只送 date_on_sale_from 為空但 to 有值
      Given 課程 109 的 date_on_sale_from 為 "2026-01-01 00:00:00"
      And 課程 109 的 date_on_sale_to 為 "2026-12-31 23:59:59"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | date_on_sale_from | date_on_sale_to     |
        |                   | 2026-06-30 23:59:59 |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_from" 的 meta_value 應為 ""
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_to" 的 meta_value 應為 ""

    Example: 只送 date_on_sale_to 為空但 from 有值
      Given 課程 109 的 date_on_sale_from 為 "2026-01-01 00:00:00"
      And 課程 109 的 date_on_sale_to 為 "2026-12-31 23:59:59"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | date_on_sale_from   | date_on_sale_to |
        | 2026-06-01 00:00:00 |                 |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_from" 的 meta_value 應為 ""
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_to" 的 meta_value 應為 ""

  Rule: 後置（狀態）- Q4 清單其他欄位清空時，DB 存空字串

    Example: 清空 short_description
      When 管理員 "Admin" 更新課程 109，參數如下：
        | short_description |
        |                   |
      Then 操作成功
      And 課程 109 的 short_description 應為 ""

    Example: 清空 purchase_note
      When 管理員 "Admin" 更新課程 109，參數如下：
        | purchase_note |
        |               |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "_purchase_note" 的 meta_value 應為 ""

    Example: 清空 sku
      Given 課程 109 的 sku 為 "PHP-109"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | sku |
        |     |
      Then 操作成功
      And 課程 109 的 sku 應為 ""

    Example: 清空 limit_value 且 limit_type 改為 unlimited
      Given 課程 109 的 limit_type 為 "fixed"
      And 課程 109 的 limit_value 為 "30"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | limit_type | limit_value | limit_unit |
        | unlimited  |             |            |
      Then 操作成功
      And 課程 109 的 limit_type 應為 "unlimited"
      And 課程 109 的 wp_postmeta 中 "limit_value" 的 meta_value 應為 ""
      And 課程 109 的 wp_postmeta 中 "limit_unit" 的 meta_value 應為 ""

    Example: 清空 course_schedule（開課時間）
      Given 課程 109 的 course_schedule 為 "1735689600"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | course_schedule |
        |                 |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "course_schedule" 的 meta_value 應為 ""

    Example: 清空 feature_video
      When 管理員 "Admin" 更新課程 109，參數如下：
        | feature_video |
        |               |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "feature_video" 的 meta_value 應為 ""

    Example: 清空 trial_video
      When 管理員 "Admin" 更新課程 109，參數如下：
        | trial_video |
        |             |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 應為 ""

  Rule: 後置（狀態）- slug 清空時由 WordPress 自動依 post_title 重建

    Example: 清空 slug
      Given 課程 109 的 slug 為 "custom-slug"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | slug |
        |      |
      Then 操作成功
      And 課程 109 的 post_name 應為非空字串
      # WP 原生行為：post_name 為空時會由 sanitize_title(post_title) 重建

  Rule: 後置（狀態）- 外部課程 button_text 清空時 fallback 為預設文字

    Example: 外部課程清空 button_text
      Given 課程 109 的 type 為 "external"
      And 課程 109 的 button_text 為 "立即購買"
      When 管理員 "Admin" 更新課程 109，參數如下：
        | button_text |
        |             |
      Then 操作成功
      And 課程 109 的 button_text 應為 "Visit course"
      # 對齊 `handle_save_course_meta_data` L625 的既有 fallback 行為

  # ========== 後置（回應）==========

  Rule: 後置（回應）- GET 回傳空值時使用 null，而非 0 或空字串

    Example: sale_date_range 在 DB 為空時，GET 回 null
      Given 課程 109 的 wp_postmeta 中 "_sale_price_dates_from" 為 ""
      And 課程 109 的 wp_postmeta 中 "_sale_price_dates_to" 為 ""
      When 管理員 "Admin" 取得課程 109 詳情
      Then 操作成功
      And 回應中 "date_on_sale_from" 應為 null
      And 回應中 "date_on_sale_to" 應為 null
      And 回應中 "sale_date_range" 應為 null

    Example: sale_price 在 DB 為空時，GET 回空字串（對齊 WC_Product::get_sale_price）
      Given 課程 109 的 wp_postmeta 中 "_sale_price" 為 ""
      When 管理員 "Admin" 取得課程 109 詳情
      Then 操作成功
      And 回應中 "sale_price" 應為 ""
      And 回應中 "on_sale" 應為 false

  # ========== 前端 graceful 處理（UI 層不爆炸契約）==========

  Rule: 前端 - InputNumber 收到空字串 / null / undefined 不噴 React warning

    Example: 開啟課程 109 編輯頁，sale_price 在 DB 為空
      Given 課程 109 的 sale_price 為 ""
      When 管理員 "Admin" 開啟 "/wp-admin/admin.php?page=power-course#/courses/edit/109"
      Then 頁面成功載入
      And 控制台應無 React warning 或 runtime error
      And sale_price 欄位應顯示為空（placeholder 狀態）

  Rule: 前端 - RangePicker 收到 [0,0] / [null,null] / null 時視為 undefined，不顯示 1970-01-01

    Example: 開啟課程 109 編輯頁，sale_date_range 在 DB 為空
      Given 課程 109 的 date_on_sale_from 為空
      And 課程 109 的 date_on_sale_to 為空
      When 管理員 "Admin" 開啟 "/wp-admin/admin.php?page=power-course#/courses/edit/109"
      Then 頁面成功載入
      And 控制台應無 React warning 或 runtime error
      And sale_date_range 欄位應顯示為空（placeholder 狀態），不應顯示 "1970-01-01"

  # ========== E2E 回歸循環（對應 Issue #203 原始驗收標準）==========

  Rule: E2E - 清空後儲存，invalidate 後欄位仍為空值

    Example: 使用者清空 sale_price 與 sale_date_range 後儲存
      Given 管理員 "Admin" 開啟課程 109 編輯頁
      And 課程 109 的 sale_price 顯示為 "888"
      And 課程 109 的 sale_date_range 顯示區間為 "2026-01-01 ~ 2026-12-31"
      When 管理員清空 sale_price 欄位
      And 管理員清空 sale_date_range 欄位
      And 管理員點擊「儲存」按鈕
      Then 儲存成功提示出現
      And 頁面觸發 API invalidate 並重新取得課程 109 詳情
      And sale_price 欄位應顯示為空
      And sale_date_range 欄位應顯示為空
      And 控制台應無 React warning 或 runtime error
