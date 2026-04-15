@ignore @command
Feature: 外部課程連結與 CTA 欄位

  外部課程（type=external，WC External/Affiliate Product）不在站內售課，而是導流到外部平台
  （如 Hahow、Udemy、YouTube 會員頻道等）。核心欄位是 `product_url`（外部 URL）與 `button_text`（CTA 按鈕文字）。

  **Code source:** `inc/classes/Api/Course.php`（共用 courses 端點），以及 WooCommerce 原生 External Product 的 `_product_url` 與 `_button_text` meta。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |

  # ========== 建立外部課程 ==========

  Rule: 建立外部課程時必須指定 product_url，button_text 可留空（預設為「前往課程」）

    Example: 建立 Hahow 外部課程
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name              | type     | product_url                       | button_text     |
        | PHP 名師課（Hahow） | external | https://hahow.in/courses/12345    | 前往 Hahow 上課 |
      Then 操作成功
      And 商品 post_meta _product_url 為 "https://hahow.in/courses/12345"
      And 商品 post_meta _button_text 為 "前往 Hahow 上課"

    Example: 未提供 button_text 時使用預設值
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name         | type     | product_url                    |
        | 外部課程範例 | external | https://example.com/course/1   |
      Then 操作成功
      And 商品 post_meta _button_text 為 "前往課程"（預設值）

  # ========== 前置（參數）==========

  Rule: product_url 必須為 http:// 或 https:// 開頭

    Example: 非法 URL
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name   | type     | product_url              |
        | 測試   | external | ftp://example.com/course |
      Then 操作失敗
      And 錯誤為 "product_url 必須為 http/https 開頭"

  Rule: 外部課程不可設定 limit_type / limit_value / course_schedule

    Example: 嘗試為外部課程設定觀看期限
      When 管理員建立外部課程並傳入 limit_type=fixed
      Then 後端忽略 limit 相關欄位（外部課程不走站內觀看流程）
      And 商品 post_meta 不寫入 limit_type / limit_value / limit_unit

  # ========== 前台展示 ==========

  Rule: 外部課程銷售頁顯示 CTA 按鈕並導向 product_url

    Example: Alice 訪問外部課程商品頁
      Given 商品 100 為外部課程，_product_url="https://hahow.in/courses/12345"、_button_text="前往 Hahow 上課"
      When Alice 訪問 /product/{slug}/
      Then 頁面顯示 "前往 Hahow 上課" 按鈕
      And 按鈕 href 指向 "https://hahow.in/courses/12345"
      And 按鈕使用 target="_blank" 在新視窗開啟（依 WP 預設 External Product 行為）

  Rule: 外部課程的按鈕在列表短代碼 [pc_courses] 中同樣顯示 CTA 而非「加入購物車」

    Example: 課程列表渲染
      When 頁面顯示 "[pc_courses]"
      And 列表中有外部課程
      Then 外部課程卡片顯示 _button_text 的文字
      And 卡片上不顯示購物車 / 加入購物車按鈕

  # ========== 更新 ==========

  Rule: 管理員可隨時更新 product_url 與 button_text

    Example: 變更外部連結
      Given 商品 100 為外部課程，_product_url="https://hahow.in/old-url"
      When 管理員更新課程 100，傳入：
        | product_url                    |
        | https://hahow.in/new-url       |
      Then 操作成功
      And 商品 post_meta _product_url 為新值

  # ========== 購物車阻擋（與既有 feature 交互） ==========

  Rule: 外部課程不可被加入購物車（見 features/external-course/外部課程購物車阻擋.feature）

    Example: 防止誤加入購物車
      Given 商品 100 為外部課程
      When 前端或 API 嘗試將商品 100 加入購物車
      Then WooCommerce 拒絕加入（External Product 原生行為）

  # ========== 與 limit_type 的關係 ==========

  Rule: erm.dbml 中 courses 表的 limit_type 對外部課程沒有意義

    Example: 資料表記錄
      Given 商品 100 為外部課程
      Then courses 表中該列的 limit_type 為預設 "unlimited"
      And 此欄位被 `Resources/Course/Limit.php` 讀取時不會影響任何業務流程
      And 前台/後台都不暴露此欄位給外部課程
