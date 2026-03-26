@ignore @query
Feature: 查詢學員列表

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           | role          |
      | 1      | Admin | admin@test.com  | administrator |
      | 2      | Alice | alice@test.com  | subscriber    |
      | 3      | Bob   | bob@test.com    | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 1893456000

  # ========== 前置（參數）==========

  Rule: 前置（參數）- meta_value（course_id）必須提供

    Example: 未提供 meta_value 時操作失敗
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | posts_per_page | paged |
        | 20             | 1     |
      Then 操作失敗，錯誤為「meta_value 為必填參數」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應回傳特定課程的學員列表（含進度資訊）

    Example: 查詢課程 100 的學員列表
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | meta_value | posts_per_page | paged |
        | 100        | 20             | 1     |
      Then 操作成功
      And 回應學員數量為 2
      And 回應中應包含以下學員資訊：
        | userId | display_name | expire_date |
        | 2      | Alice        | 0           |
        | 3      | Bob          | 1893456000  |
      And 回應包含 pagination（total, total_pages）

  Rule: 後置（回應）- 課程無學員時回傳空列表

    Example: 查詢無學員的課程
      Given 系統中有以下課程：
        | courseId | name       | _is_course | status  |
        | 200      | 空白課程   | yes        | publish |
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | meta_value |
        | 200        |
      Then 操作成功
      And 回應學員數量為 0

  Rule: 後置（回應）- 支援依 email/name/id 搜尋

    Example: 依 email 搜尋學員
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | meta_value | search       | search_field |
        | 100        | alice        | email        |
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

  Rule: 後置（回應）- 支援反向查詢（不在特定課程中的用戶）

    Example: 查詢不在課程 100 中的用戶
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | meta_value |
        | !100       |
      Then 操作成功
      And 回應中應不包含用戶 "Alice"
      And 回應中應不包含用戶 "Bob"
