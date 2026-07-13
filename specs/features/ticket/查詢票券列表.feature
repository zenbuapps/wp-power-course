@ignore @query
Feature: 查詢票券列表

  # 端點：GET /power-course/tickets
  # 購買者查自己（我的票券頁）；管理員查全部（後台分票管理頁），支援 status / course / purchaser 篩選。
  # 權限隔離：非管理員僅能看到自己 purchaser_id 的票券，看不到別人的。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | React 課程 | yes        | publish |
    And 系統中有以下票券：
      | code            | course_ids | purchaser_id | redeemer_id | status   |
      | PC-ALICE0000001 | [100]      | 2            |             | pending  |
      | PC-ALICE0000002 | [100]      | 2            | 3           | redeemed |
      | PC-ALICE0000003 | [101]      | 2            |             | voided   |
      | PC-BOB000000001 | [100]      | 3            |             | pending  |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 未登入用戶不可查詢票券

    Example: 訪客查詢票券被拒
      Given 使用者未登入
      When 查詢票券列表
      Then 操作失敗，錯誤為「必須登入」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 一般用戶僅能查到自己購買的票券

    Example: Alice 查詢只看到自己的票券
      Given 用戶 "Alice" 已登入
      When 用戶 "Alice" 查詢票券列表
      Then 回應應包含票券 "PC-ALICE0000001"
      And 回應應包含票券 "PC-ALICE0000002"
      And 回應應包含票券 "PC-ALICE0000003"
      And 回應不應包含票券 "PC-BOB000000001"

  Rule: 後置（回應）- 管理員可查到全部票券

    Example: 管理員查詢看到所有票券
      Given 管理員 "Admin" 已登入
      When 管理員查詢票券列表
      Then 回應應包含 4 張票券

  Rule: 後置（回應）- 管理員可依狀態篩選票券

    Example: 篩選 pending 狀態
      Given 管理員 "Admin" 已登入
      When 管理員查詢票券列表並以 status "pending" 篩選
      Then 回應應包含票券 "PC-ALICE0000001"
      And 回應應包含票券 "PC-BOB000000001"
      And 回應不應包含票券 "PC-ALICE0000002"

  Rule: 後置（回應）- 管理員可依課程篩選票券

    Example: 篩選課程 101 的票券
      Given 管理員 "Admin" 已登入
      When 管理員查詢票券列表並以 course_id "101" 篩選
      Then 回應應包含票券 "PC-ALICE0000003"
      And 回應不應包含票券 "PC-ALICE0000001"

  Rule: 後置（回應）- 管理員可依購買者篩選票券

    Example: 篩選購買者 Bob 的票券
      Given 管理員 "Admin" 已登入
      When 管理員查詢票券列表並以 purchaser_id "3" 篩選
      Then 回應應包含票券 "PC-BOB000000001"
      And 回應不應包含票券 "PC-ALICE0000001"

  Rule: 後置（回應）- 每筆票券回應包含狀態、購買者、兌換者與時間戳

    Example: 票券回應欄位完整
      Given 用戶 "Alice" 已登入
      When 用戶 "Alice" 查詢票券列表
      Then 票券 "PC-ALICE0000002" 的回應應包含 status、purchaser_id、redeemer_id、created_at、redeemed_at 欄位
