@ignore @command
Feature: 更新公告

  管理員可編輯已建立公告的標題、內容、發佈時間、結束時間、可見性。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下公告：
      | announcementId | post_title         | post_status | visibility | end_at     |
      | 300            | 雙十一限時優惠     | publish     | public     | 1762876800 |
      | 301            | 預約發佈通知       | future      | public     |            |
      | 302            | 已過期公告         | publish     | public     | 1700000000 |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 公告必須存在且 post_type 為 pc_announcement

    Example: 不存在的公告更新失敗
      When 管理員 "Admin" 更新公告 9999，參數如下：
        | post_title |
        | 新標題     |
      Then 操作失敗，錯誤為「公告不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 不可為空

    Example: 未提供公告 ID 時更新失敗
      When 管理員 "Admin" 更新公告 ""，參數如下：
        | post_title |
        | 新標題     |
      Then 操作失敗，錯誤訊息包含 "id"

  Rule: 前置（參數）- visibility 若有提供須為 public 或 enrolled

    Example: 非法 visibility 時更新失敗
      When 管理員 "Admin" 更新公告 300，參數如下：
        | visibility |
        | invalid    |
      Then 操作失敗，錯誤訊息包含 "visibility"

  Rule: 前置（參數）- end_at 若有提供須晚於 post_date

    Example: end_at 早於 post_date 時更新失敗
      When 管理員 "Admin" 更新公告 300，參數如下：
        | post_date           | end_at     |
        | 2026-12-01 00:00:00 | 1762876800 |
      Then 操作失敗，錯誤訊息包含 "end_at"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新對應公告的 post 及 meta 資料

    Example: 成功更新公告標題與內容
      When 管理員 "Admin" 更新公告 300，參數如下：
        | post_title         | post_content                |
        | 雙十一限時五折優惠 | <p>使用折扣碼 SAVE50</p>    |
      Then 操作成功
      And 公告 300 的 post_title 應為 "雙十一限時五折優惠"
      And 公告 300 的 post_content 應為 "<p>使用折扣碼 SAVE50</p>"

    Example: 成功延長結束時間
      When 管理員 "Admin" 更新公告 300，參數如下：
        | end_at     |
        | 1764691200 |
      Then 操作成功
      And 公告 300 的 end_at meta 應為 "1764691200"

    Example: 成功清除結束時間（轉為永久顯示）
      When 管理員 "Admin" 更新公告 300，參數如下：
        | end_at |
        |        |
      Then 操作成功
      And 公告 300 的 end_at meta 應為空

    Example: 成功修改可見性
      When 管理員 "Admin" 更新公告 300，參數如下：
        | visibility |
        | enrolled   |
      Then 操作成功
      And 公告 300 的 visibility meta 應為 "enrolled"

  Rule: 後置（狀態）- 已過期公告可透過更新 end_at 重新生效

    Example: 已過期公告恢復生效
      When 管理員 "Admin" 更新公告 302，參數如下：
        | end_at     |
        | 1893456000 |
      Then 操作成功
      And 公告 302 的 end_at meta 應為 "1893456000"
      And 公告 302 應重新出現在課程 100 的銷售頁公告區塊

  Rule: 後置（狀態）- 排程公告可改為立即發佈

    Example: 從 future 改為 publish
      When 管理員 "Admin" 更新公告 301，參數如下：
        | post_status |
        | publish     |
      Then 操作成功
      And 公告 301 的 post_status 應為 "publish"
