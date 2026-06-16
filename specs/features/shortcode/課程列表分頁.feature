@ignore @frontend
Feature: 課程列表分頁

  為既有 `[pc_courses]` 短代碼（`Shortcodes/General.php::pc_courses_callback`，
  前台模板 `inc/templates/components/list/pricing.php`）新增「純 AJAX 傳統頁碼分頁」。

  **本次範圍（Issue #236）只做分頁，不做前台分類篩選列。**

  **關鍵決策：**

  | 項目 | 決策 |
  |------|------|
  | `limit` 語意 | 改為「每頁顯示幾門」（預設 12）；可翻頁看完全部課程 |
  | 載入方式 | 純 AJAX 無刷新、**不更動 URL**（一頁可能有多個 shortcode，URL 無法記錄所有狀態） |
  | 分頁樣式 | 傳統頁碼導航 `‹ 1 2 3 4 ›`，點數字跳頁 |
  | refresh 行為 | 回到第 1 頁（可接受） |
  | 向下相容 | `category` / `include` / `exclude` / `tag` / `orderby` / `order` / `columns` / `exclude_avl_courses` 行為不變 |

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
      | 10     | Alice | customer      |
    And 系統中有 15 門 publish 狀態的課程（courseId 100~114，_is_course=yes）

  Rule: [pc_courses] 預設每頁顯示 12 門課程（依 wc_get_products 查 publish + visible 課程）

    Example: 不帶參數時第 1 頁顯示 12 門課程
      When 頁面內容包含 "[pc_courses]"
      And 訪客瀏覽該頁面
      Then 第 1 頁渲染 12 筆課程卡片
      And 使用 list/pricing 模板

  Rule: limit 參數控制「每頁」顯示幾門課程，超出的課程可翻頁瀏覽

    Example: limit=6 時每頁 6 門、共 3 頁
      When 頁面內容包含 "[pc_courses limit=6]"
      And 訪客瀏覽該頁面
      Then 第 1 頁渲染 6 筆課程卡片
      And 總頁數為 3

  Rule: 課程總數超過每頁數量時，列表底部顯示傳統頁碼導航；只有 1 頁時不顯示

    Example: 15 門課程、每頁 12 門時顯示 2 頁的頁碼導航
      When 頁面內容包含 "[pc_courses limit=12]"
      And 訪客瀏覽該頁面
      Then 列表底部顯示傳統頁碼導航
      And 頁碼導航包含第 1 頁與第 2 頁
      And 第 1 頁標記為 current

    Example: 課程總數未超過每頁數量時不顯示頁碼導航
      Given 系統中僅有 5 門 publish 狀態的課程
      When 頁面內容包含 "[pc_courses limit=12]"
      And 訪客瀏覽該頁面
      Then 第 1 頁渲染 5 筆課程卡片
      And 列表底部不顯示頁碼導航

  Rule: 點擊頁碼以 AJAX 載入對應頁課程，不整頁刷新、不更動 URL，並顯示 loading 狀態

    Example: 在第 1 頁點擊「2」後以 AJAX 載入第 2 頁課程
      Given 頁面內容包含 "[pc_courses limit=12]"
      And 訪客瀏覽該頁面並停在第 1 頁
      When 訪客點擊頁碼「2」
      Then 載入期間顯示 loading 狀態
      And 課程卡片區塊抽換為第 2 頁的 3 筆課程（courseId 112~114）
      And 頁面未整頁重新載入
      And 網址列 URL 未改變

  Rule: AJAX 分頁端點沿用 shortcode 既有查詢參數（category / exclude_avl_courses / orderby 等）再加上 page

    Example: 帶 category 的列表翻頁後仍只回傳該分類課程
      Given 分類「程式」下有 8 門 publish 課程
      And 頁面內容包含 "[pc_courses limit=6 category=程式]"
      And 訪客瀏覽該頁面並停在第 1 頁
      When 訪客點擊頁碼「2」
      Then 課程卡片區塊抽換為「程式」分類的第 2 頁 2 筆課程
      And 回傳結果不包含其他分類的課程

    Example: 登入用戶在排除已購課程的列表翻頁後仍排除已擁有課程
      Given Alice 已被加入課程 100
      And Alice 登入狀態
      And 頁面內容包含 "[pc_courses limit=6 exclude_avl_courses=true]"
      And Alice 瀏覽該頁面並停在第 1 頁
      When Alice 點擊頁碼「2」
      Then 抽換後的課程卡片不包含課程 100

  Rule: 同一頁面多個 [pc_courses] 各自維護獨立的目前頁，互不干擾

    Example: 翻動列表 A 不影響列表 B 的目前頁
      Given 頁面內容同時包含列表 A "[pc_courses limit=6]" 與列表 B "[pc_courses limit=6]"
      And 訪客瀏覽該頁面，A 與 B 皆停在第 1 頁
      When 訪客點擊列表 A 的頁碼「2」
      Then 列表 A 抽換為第 2 頁課程
      And 列表 B 仍停在第 1 頁

  Rule: 查詢結果為空時顯示友善提示，且不顯示頁碼導航

    Example: 指定無課程的分類時顯示空狀態提示
      Given 分類「無課程分類」下沒有任何 publish 課程
      When 頁面內容包含 "[pc_courses category=無課程分類]"
      And 訪客瀏覽該頁面
      Then 顯示提示訊息 "No courses match the criteria"
      And 不顯示頁碼導航

  Rule: 未加新參數的 [pc_courses] 維持向下相容（差別僅 limit 語意改為每頁數量且會顯示分頁）

    Example: 既有寫法在課程數未超過每頁數量時呈現與改版前一致
      Given 系統中僅有 3 門 publish 狀態的課程
      When 頁面內容包含 "[pc_courses]"
      And 訪客瀏覽該頁面
      Then 第 1 頁渲染 3 筆課程卡片
      And 列表底部不顯示頁碼導航
      And 渲染結果與改版前一致
