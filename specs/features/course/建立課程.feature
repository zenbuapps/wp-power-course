@ignore @command
Feature: 建立課程

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email              | role          |
      | 1      | Admin   | admin@test.com     | administrator |
      | 10     | Teacher | teacher@test.com   | editor        |
      | 11     | Teacher2| teacher2@test.com  | editor        |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- name 不可為空

    Example: 未提供課程名稱時建立失敗
      When 管理員 "Admin" 建立課程，參數如下：
        | name | status  | price | limit_type |
        |      | publish | 1200  | unlimited  |
      Then 操作失敗，錯誤訊息包含 "name"

  Rule: 前置（參數）- limit_type 為 fixed 時 limit_value 與 limit_unit 不可為空

    Example: limit_type 為 fixed 但未提供 limit_value 時建立失敗
      When 管理員 "Admin" 建立課程，參數如下：
        | name       | status  | price | limit_type | limit_value | limit_unit |
        | PHP 進階課 | publish | 1800  | fixed      |             | day        |
      Then 操作失敗，錯誤訊息包含 "limit_value"

    Example: limit_type 為 fixed 但未提供 limit_unit 時建立失敗
      When 管理員 "Admin" 建立課程，參數如下：
        | name       | status  | price | limit_type | limit_value | limit_unit |
        | PHP 進階課 | publish | 1800  | fixed      | 30          |            |
      Then 操作失敗，錯誤訊息包含 "limit_unit"

  Rule: 前置（參數）- limit_type 為 assigned 時 limit_value 必須是 10 位 unix timestamp

    Scenario Outline: limit_type 為 assigned 但 limit_value 不合法時建立失敗
      When 管理員 "Admin" 建立課程，參數如下：
        | name       | status  | limit_type | limit_value   | limit_unit |
        | PHP 進階課 | publish | assigned   | <limit_value> | timestamp  |
      Then 操作失敗

      Examples:
        | 說明             | limit_value |
        | 位數不足         | 12345       |
        | 負數             | -1735689600 |
        | 非數字字串       | abc1234567  |

  Rule: 前置（參數）- price 若有設定須為非負數

    Example: 設定負數 price 時建立失敗
      When 管理員 "Admin" 建立課程，參數如下：
        | name      | status  | price | limit_type |
        | Node 課程 | publish | -100  | unlimited  |
      Then 操作失敗，錯誤訊息包含 "price"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功建立 WooCommerce 商品並設 _is_course 為 yes

    Example: 成功建立基本課程
      When 管理員 "Admin" 建立課程，參數如下：
        | name         | status  | price | regular_price | limit_type | description      |
        | PHP 基礎入門 | publish | 1200  | 1500          | unlimited  | 適合初學者的課程 |
      Then 操作成功
      And 新建課程的 _is_course meta 應為 "yes"
      And 新建課程的 status 應為 "publish"
      And 回應中包含新建課程的 id（正整數）

  Rule: 後置（狀態）- teacher_ids 以多筆 meta rows 分別儲存

    Example: 建立課程並指派多位講師
      When 管理員 "Admin" 建立課程，參數如下：
        | name         | status  | price | limit_type | teacher_ids |
        | React 實戰課 | publish | 2000  | unlimited  | 10,11       |
      Then 操作成功
      And 新建課程應有 2 筆 teacher_ids meta rows
      And 新建課程的 teacher_ids meta 應包含 userId 10
      And 新建課程的 teacher_ids meta 應包含 userId 11

  Rule: 後置（狀態）- 回傳新建課程的完整資料（含 ID）

    Example: 建立課程後回應包含完整課程資料
      When 管理員 "Admin" 建立課程，參數如下：
        | name            | status | price | limit_type | limit_value | limit_unit | course_schedule | is_popular | is_featured |
        | Vue 前端完整課程 | draft  | 3000  | fixed      | 365         | day        | 0               | yes        | no          |
      Then 操作成功
      And 回應資料應包含以下欄位：
        | 欄位            | 期望值           |
        | name            | Vue 前端完整課程 |
        | status          | draft            |
        | limit_type      | fixed            |
        | limit_value     | 365              |
        | limit_unit      | day              |
        | is_popular      | yes              |
        | is_featured     | no               |
        | _is_course      | yes              |
