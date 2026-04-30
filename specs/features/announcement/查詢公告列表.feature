@ignore @query
Feature: 查詢公告列表

  後台管理員可查詢全部公告（含排程中、已過期）；前台訪客只能取得目前生效中的公告。

  Background:
    Given 系統中有以下用戶：
      | userId | name      | email                | role          |
      | 1      | Admin     | admin@test.com       | administrator |
      | 10     | EnrolledA | enrolledA@test.com   | subscriber    |
      | 11     | EnrolledB | enrolledB@test.com   | subscriber    |
      | 99     | Guest     | guest@test.com       | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 學員 EnrolledA 已被加入課程 100
    And 學員 EnrolledB 已被加入課程 100
    And 系統當下時間為 "2026-04-29 10:00:00"
    And 課程 100 有以下公告：
      | announcementId | post_title       | post_status | post_date           | end_at     | visibility |
      | 300            | 永久公開公告     | publish     | 2026-04-01 10:00:00 |            | public     |
      | 301            | 限時公開公告     | publish     | 2026-04-15 10:00:00 | 1762876800 | public     |
      | 302            | 已過期公告       | publish     | 2026-03-01 10:00:00 | 1700000000 | public     |
      | 303            | 排程中公告       | future      | 2026-11-03 09:00:00 |            | public     |
      | 304            | 僅學員可見公告   | publish     | 2026-04-20 10:00:00 |            | enrolled   |
      | 305            | 已刪除公告       | trash       | 2026-04-10 10:00:00 |            | public     |

  # ========== 後台管理列表 ==========

  Rule: 後置（回應）- 管理員查詢可看到所有狀態的公告（含 trash）

    Example: 管理員查詢全部公告
      When 管理員 "Admin" 查詢公告列表，參數如下：
        | parent_course_id | post_status                |
        | 100              | publish,future,trash       |
      Then 操作成功
      And 回應公告數量為 6
      And 回應應包含公告 [300, 301, 302, 303, 304, 305]

  Rule: 後置（回應）- 管理員查詢預設不含 trash

    Example: 預設查詢
      When 管理員 "Admin" 查詢公告列表，參數如下：
        | parent_course_id |
        | 100              |
      Then 操作成功
      And 回應公告數量為 5
      And 回應不應包含公告 305

  Rule: 後置（回應）- 公告依 post_date 由新到舊排序

    Example: 排序
      When 管理員 "Admin" 查詢公告列表，參數如下：
        | parent_course_id |
        | 100              |
      Then 回應順序為 [303, 304, 301, 300, 302]

  Rule: 後置（回應）- 每則公告附帶狀態標籤（active / scheduled / expired）

    Scenario Outline: 狀態標籤
      When 管理員 "Admin" 查詢公告列表，參數如下：
        | parent_course_id |
        | 100              |
      Then 公告 <id> 的 status_label 應為 "<label>"

      Examples:
        | id  | label     | 說明                                |
        | 300 | active    | publish 且未到 end_at 或無 end_at   |
        | 301 | active    | publish 且未到 end_at               |
        | 302 | expired   | publish 但已過 end_at               |
        | 303 | scheduled | post_status=future                  |
        | 304 | active    | publish 且未到 end_at               |

  # ========== 前台公開列表 ==========

  Rule: 後置（回應）- 訪客（未登入或未購）只能取得 visibility=public 且生效中的公告

    Example: 未登入訪客查詢
      When 訪客 "Guest"（未登入）查詢課程 100 的公開公告
      Then 操作成功
      And 回應公告數量為 2
      And 回應應包含公告 [300, 301]
      And 回應不應包含公告 302（已過期）
      And 回應不應包含公告 303（排程中）
      And 回應不應包含公告 304（僅學員可見）
      And 回應不應包含公告 305（已刪除）

    Example: 未購學員查詢
      When 學員 "Guest" 查詢課程 100 的公開公告
      Then 回應公告數量為 2
      And 回應應包含公告 [300, 301]
      And 回應不應包含公告 304

  Rule: 後置（回應）- 已購學員可看到 visibility=enrolled 的公告

    Example: 已購學員查詢
      When 學員 "EnrolledA" 查詢課程 100 的公開公告
      Then 操作成功
      And 回應公告數量為 3
      And 回應應包含公告 [300, 301, 304]

  Rule: 後置（回應）- 公開列表依 post_date 由新到舊排序

    Example: 已購學員看到的順序
      When 學員 "EnrolledA" 查詢課程 100 的公開公告
      Then 回應順序為 [304, 301, 300]

  Rule: 後置（回應）- 公開列表只回傳目前生效中的公告（post_status=publish 且未過 end_at）

    Example: 排程中公告不對外公開
      When 訪客 "Guest" 查詢課程 100 的公開公告
      Then 回應不應包含公告 303

    Example: 已過期公告不對外公開
      When 訪客 "Guest" 查詢課程 100 的公開公告
      Then 回應不應包含公告 302

  # ========== 取得單一公告 ==========

  Rule: 後置（回應）- GET /announcements/{id} 回傳單一公告詳情

    Example: 取得單一公告
      When 管理員 "Admin" 查詢公告 300
      Then 操作成功
      And 回應的 announcement.id 應為 300
      And 回應應包含 post_title、post_content、post_date、end_at、visibility、status_label

    Example: 取得不存在公告
      When 管理員 "Admin" 查詢公告 9999
      Then 操作失敗，HTTP status 為 404
