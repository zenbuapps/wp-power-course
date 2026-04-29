@ignore @command
Feature: 建立公告

  管理員可在課程編輯頁建立課程公告，支援立即發佈與預約發佈，可選結束時間與可見性。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email              | role          |
      | 1      | Admin   | admin@test.com     | administrator |
      | 10     | Teacher | teacher@test.com   | editor        |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | type     |
      | 100      | PHP 基礎課 | yes        | publish | simple   |
      | 101      | 外部課程   | yes        | publish | external |
      | 999      | 非課程商品 | no         | publish | simple   |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- parent_course_id 對應的課程必須存在且 _is_course 為 yes

    Example: 指向不存在課程時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title       | parent_course_id |
        | 雙十一限時優惠   | 9999             |
      Then 操作失敗

    Example: 指向非課程商品時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title       | parent_course_id |
        | 雙十一限時優惠   | 999              |
      Then 操作失敗

    Example: 外部課程也可建立公告
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title       | parent_course_id | post_status |
        | 合作推廣公告     | 101              | publish     |
      Then 操作成功

  # ========== 前置（參數）==========

  Rule: 前置（參數）- post_title 不可為空

    Example: 未提供公告標題時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | parent_course_id |
        |            | 100              |
      Then 操作失敗，錯誤訊息包含 "post_title"

  Rule: 前置（參數）- parent_course_id 不可為空

    Example: 未提供根課程 ID 時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title       | parent_course_id |
        | 雙十一限時優惠   |                  |
      Then 操作失敗，錯誤訊息包含 "parent_course_id"

  Rule: 前置（參數）- visibility 必須為 public 或 enrolled

    Example: 非法 visibility 值時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | parent_course_id | visibility |
        | 公告       | 100              | invalid    |
      Then 操作失敗，錯誤訊息包含 "visibility"

  Rule: 前置（參數）- end_at 若有設定須為 10 位 Unix timestamp

    Scenario Outline: end_at 不合法時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | parent_course_id | end_at   |
        | 公告       | 100              | <end_at> |
      Then 操作失敗

      Examples:
        | 說明             | end_at      |
        | 位數不足         | 12345       |
        | 負數             | -1762876800 |
        | 非數字字串       | abc1234567  |

  Rule: 前置（參數）- end_at 若有設定須晚於 post_date

    Example: end_at 早於 post_date 時建立失敗
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | parent_course_id | post_date           | end_at     |
        | 公告       | 100              | 2026-12-01 00:00:00 | 1762876800 |
      Then 操作失敗，錯誤訊息包含 "end_at"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 建立 post_type 為 pc_announcement 的文章

    Example: 成功建立立即發佈公告（不設結束時間）
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title         | post_content        | parent_course_id | post_status | visibility |
        | 第五章全新上線！   | <p>內容詳情</p>     | 100              | publish     | public     |
      Then 操作成功
      And 新建公告的 post_type 應為 "pc_announcement"
      And 新建公告的 post_status 應為 "publish"
      And 新建公告的 post_parent 應為 100
      And 新建公告的 parent_course_id meta 應為 100
      And 新建公告的 visibility meta 應為 "public"
      And 新建公告的 end_at meta 應為空

    Example: 成功建立有結束時間的公告
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title           | parent_course_id | post_status | visibility | end_at     |
        | 雙十一限時五折優惠   | 100              | publish     | public     | 1762876800 |
      Then 操作成功
      And 新建公告的 end_at meta 應為 "1762876800"

    Example: 成功建立預約發佈公告
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title       | parent_course_id | post_status | post_date           |
        | 下週一發佈通知   | 100              | future      | 2026-11-03 09:00:00 |
      Then 操作成功
      And 新建公告的 post_status 應為 "future"
      And 新建公告的 post_date 應為 "2026-11-03 09:00:00"

    Example: 成功建立僅學員可見的公告
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title         | parent_course_id | post_status | visibility |
        | 內部更新通知       | 100              | publish     | enrolled   |
      Then 操作成功
      And 新建公告的 visibility meta 應為 "enrolled"

  Rule: 後置（狀態）- post_date 不指定時使用當下時間

    Example: 未指定 post_date
      Given 系統當下時間為 "2026-04-29 10:00:00"
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | parent_course_id | post_status |
        | 即時公告   | 100              | publish     |
      Then 操作成功
      And 新建公告的 post_date 應為 "2026-04-29 10:00:00"
