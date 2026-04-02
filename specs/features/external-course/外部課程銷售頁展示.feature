@ignore @ui
Feature: 外部課程銷售頁展示

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email              | role          |
      | 10     | Teacher | teacher@test.com   | editor        |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | regular_price | sale_price | product_url                    | button_text      | teacher_ids | is_popular |
      | 200      | Python 資料科學  | yes        | external | publish | 2400          | 1990       | https://hahow.in/courses/12345 | 前往 Hahow 上課  | 10          | yes        |
    And 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  | regular_price |
      | 100      | PHP 基礎課 | yes        | simple | publish | 1200          |

  # ========== 課程列表頁 ==========

  Rule: 課程列表頁 - 外部課程卡片外觀與站內課程一致

    Example: 外部課程卡片在列表中正常顯示
      When 訪客瀏覽課程列表頁
      Then 應看到課程卡片 "Python 資料科學"
      And 應看到課程卡片 "PHP 基礎課"
      And 課程卡片 "Python 資料科學" 應顯示封面圖
      And 課程卡片 "Python 資料科學" 應顯示標題 "Python 資料科學"
      And 課程卡片 "Python 資料科學" 應顯示價格

  Rule: 課程列表頁 - 外部課程卡片右上角顯示外部連結圖示

    Example: 外部課程卡片有細微的外部標記
      When 訪客瀏覽課程列表頁
      Then 課程卡片 "Python 資料科學" 應顯示外部連結圖示 "↗"
      And 課程卡片 "PHP 基礎課" 不應顯示外部連結圖示

  Rule: 課程列表頁 - 點擊外部課程卡片進入站內銷售頁

    Example: 點擊外部課程卡片不直接跳轉外部
      When 訪客點擊課程卡片 "Python 資料科學"
      Then 應進入課程 200 的站內銷售頁
      And 不應開啟新視窗跳轉外部

  # ========== 銷售頁 Header ==========

  Rule: 銷售頁 Header - 顯示課程標題、講師、封面圖/特色影片

    Example: 銷售頁 Header 正常顯示外部課程資訊
      When 訪客進入外部課程 200 的銷售頁
      Then 應看到課程標題 "Python 資料科學"
      And 應看到講師 "Teacher" 的頭像與名稱
      And 應看到「熱門」徽章（因 is_popular = yes）

  Rule: 銷售頁 Header - 支援特色影片（若有設定）

    Example: 外部課程設定了特色影片時正常播放
      Given 外部課程 200 設定了 YouTube 特色影片 "dQw4w9WgXcQ"
      When 訪客進入外部課程 200 的銷售頁
      Then Header 應顯示 YouTube 特色影片播放器

  # ========== 銷售頁 Body ==========

  Rule: 銷售頁 Body - 課程資訊統計項顯示「-」

    Example: 外部課程的課程資訊統計項為佔位符
      When 訪客進入外部課程 200 的銷售頁
      Then 課程資訊的「章節數量」應顯示 "-"
      And 課程資訊的「課程時長」應顯示 "-"
      And 課程資訊的「課程學員」應顯示 "-"
      And 課程資訊的「觀看時間」應顯示 "-"

  Rule: 銷售頁 Body - 保留課程介紹 Tab

    Example: 外部課程銷售頁顯示課程介紹
      When 訪客進入外部課程 200 的銷售頁
      Then 應看到「課程介紹」分頁
      And 課程介紹分頁應顯示課程的 description 內容

  Rule: 銷售頁 Body - 隱藏章節列表 Tab

    Example: 外部課程銷售頁不顯示章節列表
      When 訪客進入外部課程 200 的銷售頁
      Then 不應看到「章節列表」分頁

  # ========== 銷售頁 Sidebar ==========

  Rule: 銷售頁 Sidebar - 顯示「前往課程」CTA 按鈕取代購買按鈕

    Example: Sidebar 顯示 CTA 按鈕而非加入購物車
      When 訪客進入外部課程 200 的銷售頁
      Then Sidebar 應顯示 CTA 按鈕，文字為 "前往 Hahow 上課"
      And Sidebar 不應顯示「加入購物車」按鈕
      And Sidebar 不應顯示「立即報名」按鈕

  Rule: 銷售頁 Sidebar - CTA 按鈕以新視窗開啟外部連結

    Example: 點擊 CTA 按鈕開啟外部連結
      When 訪客點擊外部課程 200 銷售頁的 CTA 按鈕
      Then 應以新視窗（target="_blank"）開啟 "https://hahow.in/courses/12345"
      And 連結應包含 rel="noopener noreferrer" 屬性

  Rule: 銷售頁 Sidebar - 顯示展示用價格

    Example: Sidebar 顯示 WC 格式的價格
      When 訪客進入外部課程 200 的銷售頁
      Then Sidebar 價格區塊應顯示原價 "NT$2,400"
      And Sidebar 價格區塊應顯示特價 "NT$1,990"

  Rule: 銷售頁 Sidebar - 不顯示銷售方案卡片

    Example: 外部課程銷售頁無銷售方案區塊
      When 訪客進入外部課程 200 的銷售頁
      Then Sidebar 不應顯示銷售方案卡片

  # ========== 銷售頁 Mobile Fixed CTA ==========

  Rule: 銷售頁 Mobile - Fixed CTA 也替換為外部連結按鈕

    Example: 手機版固定 CTA 顯示外部連結按鈕
      Given 外部課程 200 啟用了 enable_mobile_fixed_cta
      When 訪客以手機瀏覽外部課程 200 的銷售頁
      Then 底部固定 CTA 按鈕文字應為 "前往 Hahow 上課"
      And 點擊底部固定 CTA 應以新視窗開啟外部連結

  # ========== 銷售頁 Footer ==========

  Rule: 銷售頁 Footer - 正常顯示講師資訊區塊

    Example: 外部課程銷售頁底部顯示講師介紹
      When 訪客進入外部課程 200 的銷售頁
      Then Footer 應顯示講師 "Teacher" 的完整介紹
