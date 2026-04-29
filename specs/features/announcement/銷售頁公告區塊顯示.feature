@ignore @query @ui
Feature: 銷售頁公告區塊顯示

  課程銷售頁在「價格與購買按鈕」之後、「課程介紹 Tab 區」之前嵌入公告區塊。
  公告區塊不是 Tab，是直接顯示在頁面上的橫幅區。
  多則公告同時生效時：最新一則預設展開，其餘折疊（手風琴 / Accordion）。

  **Issue #6 設計決策：**
  - 不採用 Tab 形式（決議：公告直接顯示在銷售頁上）
  - 位置：價格區下方、Tab 上方
  - 排版：手風琴（最新展開、其餘折疊）
  - 排序：依 post_date 由新到舊
  - 無有效公告時整個區塊隱藏

  Background:
    Given 系統中有以下用戶：
      | userId | name      | email                | role          |
      | 10     | EnrolledA | enrolledA@test.com   | subscriber    |
      | 99     | Guest     | guest@test.com       | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | type     |
      | 100      | PHP 基礎課 | yes        | publish | simple   |
      | 101      | 外部課程   | yes        | publish | external |
    And 學員 EnrolledA 已被加入課程 100
    And 系統當下時間為 "2026-04-29 10:00:00"

  # ========== 區塊位置 ==========

  Rule: 公告區塊渲染於銷售頁「價格區下方、Tab 區上方」

    Example: DOM 結構順序
      Given 課程 100 有 1 則生效公告
      When 訪客瀏覽課程 100 的銷售頁
      Then 頁面 DOM 中公告區塊位於「價格與購買按鈕」之後
      And 頁面 DOM 中公告區塊位於「Tab 導覽列」之前

  # ========== 顯示條件 ==========

  Rule: 後置（顯示）- 課程沒有任何生效公告時，公告區塊整個隱藏

    Example: 無公告時
      Given 課程 100 沒有任何公告
      When 訪客瀏覽課程 100 的銷售頁
      Then 頁面不應渲染公告區塊
      And 頁面 DOM 中不應出現 #pc-announcement-section 元素

    Example: 所有公告都已過期時
      Given 課程 100 有以下公告：
        | announcementId | post_status | post_date           | end_at     |
        | 300            | publish     | 2026-03-01 10:00:00 | 1700000000 |
      When 訪客瀏覽課程 100 的銷售頁
      Then 頁面不應渲染公告區塊

    Example: 所有公告都未到發佈時間
      Given 課程 100 有以下公告：
        | announcementId | post_status | post_date           |
        | 303            | future      | 2026-11-03 09:00:00 |
      When 訪客瀏覽課程 100 的銷售頁
      Then 頁面不應渲染公告區塊

  Rule: 後置（顯示）- 對訪客而言，僅學員可見公告不算入「是否有生效公告」的判斷

    Example: 課程只有「僅學員可見」公告，未登入訪客視為無公告
      Given 課程 100 有以下公告：
        | announcementId | post_status | visibility |
        | 304            | publish     | enrolled   |
      When 訪客 "Guest"（未登入）瀏覽課程 100 的銷售頁
      Then 頁面不應渲染公告區塊

    Example: 課程只有「僅學員可見」公告，已購學員可看到區塊
      Given 課程 100 有以下公告：
        | announcementId | post_title         | post_status | visibility |
        | 304            | 僅學員可見的更新   | publish     | enrolled   |
      When 學員 "EnrolledA" 瀏覽課程 100 的銷售頁
      Then 頁面應渲染公告區塊
      And 公告區塊應包含「僅學員可見的更新」

  # ========== 排版（手風琴） ==========

  Rule: 後置（顯示）- 多則公告依 post_date 由新到舊排列

    Example: 三則公告排序
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_date           | post_status | visibility |
        | 300            | 公告 A     | 2026-04-01 10:00:00 | publish     | public     |
        | 301            | 公告 B     | 2026-04-15 10:00:00 | publish     | public     |
        | 302            | 公告 C     | 2026-04-20 10:00:00 | publish     | public     |
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告區塊內順序應為 [302, 301, 300]

  Rule: 後置（顯示）- 預設只有最新一則展開，其餘折疊

    Example: 三則公告的初始展開狀態
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_date           | post_status |
        | 300            | 公告 A     | 2026-04-01 10:00:00 | publish     |
        | 301            | 公告 B     | 2026-04-15 10:00:00 | publish     |
        | 302            | 公告 C     | 2026-04-20 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 302（最新）的內容應展開（aria-expanded="true"）
      And 公告 301 的內容應折疊（aria-expanded="false"）
      And 公告 300 的內容應折疊（aria-expanded="false"）

  Rule: 後置（顯示）- 折疊項點擊後可展開，再次點擊收合（每項獨立切換）

    Example: 點擊舊公告展開
      Given 課程 100 有 3 則公告，最新為 302
      When 訪客點擊公告 301 的標題
      Then 公告 301 的內容應展開
      And 公告 302 的展開狀態維持為展開（多項可同時展開）

  Rule: 後置（顯示）- 只有單一公告時直接展開（無需點擊）

    Example: 單則公告
      Given 課程 100 有 1 則公告 300
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 300 的內容應展開
      And 公告 300 應仍可被點擊以收合（基本手風琴互動）

  # ========== 公告卡片內容 ==========

  Rule: 後置（顯示）- 每則公告顯示標題、發佈日期、Power Editor 渲染後的內文

    Example: 公告卡片元素
      Given 課程 100 有以下公告：
        | announcementId | post_title         | post_content              | post_date           |
        | 300            | 第五章全新上線！   | <p>歡迎來學習新內容</p>   | 2026-04-20 10:00:00 |
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 300 的卡片應顯示標題 "第五章全新上線！"
      And 公告 300 的卡片應顯示日期 "2026-04-20"
      And 公告 300 的卡片應渲染 HTML 內容 "<p>歡迎來學習新內容</p>"

  # ========== 外部課程 ==========

  Rule: 後置（顯示）- 外部課程（External Course）也支援公告區塊

    Example: 外部課程顯示公告
      Given 課程 101 有以下公告：
        | announcementId | post_title       | post_status | visibility |
        | 400            | 合作推廣公告     | publish     | public     |
      When 訪客瀏覽課程 101 的銷售頁
      Then 頁面應渲染公告區塊
      And 公告區塊應包含「合作推廣公告」

  # ========== 響應式 ==========

  Rule: 後置（顯示）- 公告區塊在手機端正常顯示（與既有版面共用響應式佈局）

    Example: 手機端
      Given 課程 100 有 2 則生效公告
      When 訪客在手機端（width=375px）瀏覽課程 100 的銷售頁
      Then 公告區塊應佔滿可用寬度
      And 折疊項標題與內容應垂直堆疊（不溢出）

  # ========== 時區 ==========

  Rule: 後置（顯示）- 公告生效判斷使用站台時區（WordPress timezone_string）

    Example: 站台時區為 Asia/Taipei
      Given WordPress timezone_string 為 "Asia/Taipei"
      And 系統當下時間（UTC）為 "2026-11-01 00:00:00"
      And 課程 100 有以下公告：
        | announcementId | post_status | post_date           |
        | 303            | future      | 2026-11-01 09:00:00 |
      Then 公告 303 在台北時間 11/01 09:00 後才生效
      And 在 11/01 08:59（台北時間）瀏覽銷售頁時公告 303 不應顯示
