@ignore @command
Feature: 建立外部課程

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email              | role          |
      | 1      | Admin   | admin@test.com     | administrator |
      | 10     | Teacher | teacher@test.com   | editor        |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- name 不可為空

    Example: 未提供課程名稱時建立失敗
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name | status  | product_url                    | button_text |
        |      | publish | https://hahow.in/courses/12345 | 前往課程    |
      Then 操作失敗，錯誤訊息包含 "name"

  Rule: 前置（參數）- product_url 不可為空

    Example: 未提供外部連結時建立失敗
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url | button_text |
        | Python 資料科學  | publish |             | 前往課程    |
      Then 操作失敗，錯誤訊息包含 "product_url"

  Rule: 前置（參數）- product_url 必須為合法的 http/https URL

    Scenario Outline: 非法 URL 格式時建立失敗
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url   | button_text |
        | Python 資料科學  | publish | <product_url> | 前往課程    |
      Then 操作失敗，錯誤訊息包含 "product_url"

      Examples:
        | 說明               | product_url           |
        | 缺少協定           | hahow.in/courses/123  |
        | 使用 ftp 協定      | ftp://example.com     |
        | 僅有協定           | https://              |
        | JavaScript 注入    | javascript:alert(1)   |

  Rule: 前置（參數）- button_text 未提供時使用預設值「前往課程」

    Example: 未提供 button_text 時使用預設值
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url                    |
        | Python 資料科學  | publish | https://hahow.in/courses/12345 |
      Then 操作成功
      And 新建課程的 button_text 應為 "前往課程"

  Rule: 前置（參數）- price 若有設定須為非負數

    Example: 設定負數 price 時建立失敗
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | price | product_url                    |
        | Python 資料科學  | publish | -100  | https://hahow.in/courses/12345 |
      Then 操作失敗，錯誤訊息包含 "price"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功建立 WC External 商品並設 _is_course 為 yes

    Example: 成功建立基本外部課程
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url                    | button_text    | description               |
        | Python 資料科學  | publish | https://hahow.in/courses/12345 | 前往 Hahow 上課 | 適合初學者的 Python 課程 |
      Then 操作成功
      And 新建課程的 _is_course meta 應為 "yes"
      And 新建課程的 product type 應為 "external"
      And 新建課程的 product_url 應為 "https://hahow.in/courses/12345"
      And 新建課程的 button_text 應為 "前往 Hahow 上課"
      And 新建課程的 status 應為 "publish"
      And 新建課程的 is_purchasable 應為 false
      And 回應中包含新建課程的 id（正整數）

  Rule: 後置（狀態）- 支援設定展示用價格

    Example: 建立外部課程並設定展示價格
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | regular_price | sale_price | product_url                    |
        | Python 資料科學  | publish | 2400          | 1990       | https://hahow.in/courses/12345 |
      Then 操作成功
      And 新建課程的 regular_price 應為 "2400"
      And 新建課程的 sale_price 應為 "1990"

  Rule: 後置（狀態）- 支援指派講師

    Example: 建立外部課程並指派講師
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url                    | teacher_ids |
        | Python 資料科學  | publish | https://hahow.in/courses/12345 | 10          |
      Then 操作成功
      And 新建課程應有 1 筆 teacher_ids meta rows
      And 新建課程的 teacher_ids meta 應包含 userId 10

  Rule: 後置（狀態）- 支援設定特色影片

    Example: 建立外部課程並設定 YouTube 特色影片
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url                    | feature_video_type | feature_video_id |
        | Python 資料科學  | publish | https://hahow.in/courses/12345 | youtube            | dQw4w9WgXcQ      |
      Then 操作成功
      And 新建課程的 feature_video type 應為 "youtube"

  Rule: 後置（狀態）- 外部課程產品類型固定為 external，不可設為 simple 或 subscription

    Example: 建立外部課程時 type 固定為 external
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | product_url                    |
        | Python 資料科學  | publish | https://hahow.in/courses/12345 |
      Then 操作成功
      And 新建課程的 product type 應為 "external"

  Rule: 後置（狀態）- 回傳新建外部課程的完整資料

    Example: 建立外部課程後回應包含完整資料
      When 管理員 "Admin" 建立外部課程，參數如下：
        | name             | status  | regular_price | product_url                    | button_text    | is_popular | is_featured |
        | Python 資料科學  | publish | 2400          | https://hahow.in/courses/12345 | 前往 Hahow 上課 | yes        | no          |
      Then 操作成功
      And 回應資料應包含以下欄位：
        | 欄位            | 期望值             |
        | name            | Python 資料科學    |
        | status          | publish            |
        | type            | external           |
        | product_url     | https://hahow.in/courses/12345 |
        | button_text     | 前往 Hahow 上課    |
        | regular_price   | 2400               |
        | is_popular      | yes                |
        | is_featured     | no                 |
        | _is_course      | yes                |
