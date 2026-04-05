@ignore @command
Feature: 複製課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | price | limit_type |
      | 100      | PHP 基礎課 | yes        | publish | 1200  | unlimited  |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | menu_order |
      | 200       | 第一章     | 100         | 1          |
      | 201       | 第二章     | 100         | 2          |
      | 210       | 1-1 小節   | 200         | 1          |
    And 課程 100 有以下銷售方案：
      | productId | name     | bundle_type   | price |
      | 300       | 月費方案 | single_course | 399   |
    And 課程 100 的 bind_courses_data 商品綁定如下：
      | productId | course_id | limit_type | limit_value | limit_unit |
      | 500       | 100       | unlimited  |             |            |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 來源課程必須存在

    Example: 來源課程不存在時操作失敗
      When 管理員 "Admin" 複製課程 9999
      Then 操作失敗，錯誤為「課程不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必要參數必須提供

    Example: 未提供課程 ID 時操作失敗
      When 管理員 "Admin" 複製課程 ""
      Then 操作失敗，錯誤訊息包含 "id"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 新課程狀態固定為 draft

    Example: 成功複製課程，狀態為 draft
      When 管理員 "Admin" 複製課程 100
      Then 操作成功
      And 新課程的 name 應包含 "PHP 基礎課"
      And 新課程的 status 應為 "draft"
      And 新課程的 _is_course meta 應為 "yes"
      And 新課程的 price 應為 "1200"
      And 新課程的 limit_type 應為 "unlimited"

  Rule: 後置（狀態）- 遞迴複製所有巢狀章節並維持結構與排序

    Example: 複製後章節的巢狀結構正確
      When 管理員 "Admin" 複製課程 100
      Then 操作成功
      And 新課程應有 3 個章節（含子章節）
      And 新課程的頂層章節數為 2
      And 新課程的第一章應有 1 個子章節
      And 所有新章節的 parent_course_id 應指向新課程 ID
      And 新章節的 menu_order 應與原章節一致

  Rule: 後置（狀態）- 複製銷售方案和商品綁定

    Example: 複製後銷售方案和綁定也被複製
      When 管理員 "Admin" 複製課程 100
      Then 操作成功
      And 新課程應有 1 個銷售方案
      And 新銷售方案的 link_course_ids 應指向新課程 ID
      And 新銷售方案的 status 應為 "draft"

  Rule: 後置（狀態）- 學員資料不複製

    Example: 複製後新課程無學員
      Given 用戶 "Alice" 已被加入課程 100
      When 管理員 "Admin" 複製課程 100
      Then 操作成功
      And 新課程的學員數應為 0
