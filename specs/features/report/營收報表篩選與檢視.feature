@ignore @frontend @ui-only
Feature: 營收報表篩選與檢視

  管理員可在「分析」頁面設定日期範圍、商品篩選、時間間隔、檢視模式，查看 Power Course 擴展的營收報表。
  報表除了 WooCommerce Analytics 原生的營收欄位外，額外提供 Power Course 專屬的學員數與完成章節數統計。

  **Code source:**
  - 前端：`js/src/pages/admin/Analytics/`（index.tsx、Filter/、ViewType/、hooks/useRevenue.tsx）
  - 後端：`inc/classes/Api/Reports/Revenue/Api.php::get_reports_revenue_stats_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下課程與商品：
      | productId | name         | _is_course | type   |
      | 100       | PHP 基礎課   | yes        | simple |
      | 101       | Laravel 課程 | yes        | simple |
      | 300       | PHP 銷售方案 | no         | simple |

  # ========== 日期範圍篩選 ==========

  Rule: 預設日期範圍為最近 7 天

    Example: 首次進入 Analytics 頁面
      When 管理員 "Admin" 進入 /analytics
      Then 頁面載入後延遲 500ms 顯示內容（避免 FOUC）
      And Filter 表單 date_range 預設為 [今日-7天 00:00:00, 今日 23:59:59]

  Rule: 可使用 RANGE_PRESETS（今天、昨天、最近 7 天、最近 30 天等）快速切換

    Example: 切換到最近 30 天
      Given 管理員在 Analytics 頁面
      When 管理員在 RangePicker 選擇 preset "最近 30 天"
      Then Filter 表單 date_range 更新為 [今日-30天, 今日]

  Rule: 最大選取範圍為 1 年（透過 maxDateRange 限制）

    Example: 嘗試選擇超過 1 年的範圍
      When 管理員在 RangePicker 選擇 2024-01-01 到 2026-01-01
      Then 超過最大範圍的日期被 disable，無法選擇

  # ========== 商品篩選 ==========

  Rule: 可多選特定課程/商品篩選報表範圍

    Example: 只看 PHP 基礎課的營收
      When 管理員在 "查看特定課程/商品" 下拉中勾選 "PHP 基礎課"
      And 點擊 "查詢"
      Then 後端呼叫 GET /reports/revenue/stats?product_includes=100
      And 報表只計算 productId=100 的訂單

  Rule: 商品下拉支援關鍵字搜尋

    Example: 搜尋 "PHP"
      When 管理員在下拉輸入 "PHP"
      Then 下拉觸發 /products/select?s=PHP
      And 回應即時更新下拉選項

  Rule: 商品下拉顯示類型 Tag 與課程標示

    Example: 列表中有課程與一般商品
      When 下拉選項載入
      Then 每個選項顯示 "#{id} {name} <Tag>{productType.label}</Tag>"
      And 若商品 is_course=true 額外顯示 <Tag color="gold">課程</Tag>

  # ========== 時間間隔 ==========

  Rule: 支援 day / week / month / quarter 四種時間間隔

    Example: 切換到月
      When 管理員在 "時間間格" 下拉選擇 "依月"
      And 點擊 "查詢"
      Then 後端 SQL 以 DATE_FORMAT(meta_value, "%x-%m") 分組
      And 圖表的 x 軸依月份呈現

  # ========== 檢視模式 ==========

  Rule: 提供 "分開顯示"（DefaultView）與 "堆疊比較"（AreaView）兩種模式

    Example: 切換到堆疊比較
      Given 預設為 DefaultView
      When 管理員點擊 "堆疊比較" 圖示
      Then viewType state 切換為 EViewType.AREA
      And 頁面渲染 <AreaView /> 元件
      And LineChartOutlined 圖示變灰、AreaChartOutlined 變藍

    Example: 切換回分開顯示
      When 管理員點擊 "分開顯示" 圖示
      Then viewType 切換為 EViewType.DEFAULT
      And 頁面渲染 <DefaultView /> 元件

  # ========== 與去年同期比較 ==========

  Rule: 勾選 "與去年同期比較" 後，圖表同時顯示去年資料

    Example: 比較 2026-04 vs 2025-04
      Given date_range 為 2026-04-01 到 2026-04-30
      When 管理員勾選 "與去年同期比較"
      And 點擊 "查詢"
      Then 前端額外發起一次請求查詢 2025-04-01 到 2025-04-30 的資料
      And 圖表疊加顯示兩條線（今年、去年）

  # ========== 擴展欄位 ==========

  Rule: 報表除原生營收欄位外，包含 Power Course 擴展欄位

    Example: 檢查回應結構
      When 管理員查詢報表
      Then 回應 totals 包含以下欄位：
        | field                       |
        | net_revenue                 |
        | orders_count                |
        | num_items_sold              |
        | refunds                     |
        | gross_sales                 |
        | refunded_orders_count       |
        | non_refunded_orders_count   |
        | student_count               |
        | finished_chapters_count     |
      And student_count 來自 pc_avl_coursemeta 中 course_granted_at 的 DISTINCT user 計算
      And finished_chapters_count 來自 pc_avl_chaptermeta 中 finished_at 的 COUNT 計算

  Rule: 擴展欄位同時存在於 intervals 的 subtotals 中，與原生欄位同步分組

    Example: 每日統計
      When 管理員查詢日間隔報表
      Then intervals 陣列每筆 subtotals 也包含 student_count、finished_chapters_count

  # ========== 本地開發環境 ==========

  Rule: wp_get_environment_type 為 "local" 時禁用報表快取

    Example: 本地開發
      Given 環境變數 WP_ENVIRONMENT_TYPE = "local"
      When 管理員查詢報表
      Then disable_cache_in_local filter 使 woocommerce_analytics_report_should_use_cache 回傳 false
      And 每次查詢重新計算

  # ========== Detail 模式（單一課程頁嵌入） ==========

  Rule: Analytics 元件支援 context="detail" 模式，用於單一課程分析頁

    Example: 課程 100 的分析 tab
      Given 管理員在 /courses/edit/100 的 "分析" tab
      When 頁面渲染 <AnalyticsComponent context="detail" initialQuery={{...}} />
      Then Filter 自動鎖定 product_includes=[100]
      And 不顯示 "查看特定課程/商品" 下拉（hidden）
      And bundle_products 下拉查詢 link_course_ids=100 的銷售方案並顯示 Tags
