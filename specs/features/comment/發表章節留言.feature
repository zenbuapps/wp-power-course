@ignore @command
Feature: 發表章節留言

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | enable_comment |
      | 200       | 第一章     | yes            |
      | 201       | 第二章     | no             |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須有該章節所屬課程的存取權

    Example: 無課程權限時留言失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 在章節 200 發表留言 "很棒的內容"
      Then 操作失敗，錯誤為「無此課程存取權」

  Rule: 前置（狀態）- 章節必須啟用留言功能（enable_comment = yes）

    Example: 章節未啟用留言時操作失敗
      When 用戶 "Alice" 在章節 201 發表留言 "想問個問題"
      Then 操作失敗，錯誤為「此章節未啟用留言功能」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 留言內容不可為空

    Example: 空白留言時操作失敗
      When 用戶 "Alice" 在章節 200 發表留言 ""
      Then 操作失敗，錯誤為「留言內容不可為空」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 建立 WordPress 留言（comment）

    Example: 成功發表留言
      When 用戶 "Alice" 在章節 200 發表留言 "很棒的內容，謝謝老師"
      Then 操作成功
      And 章節 200 應有 1 筆新留言
      And 留言的 comment_author 應為 "Alice"
      And 留言的 comment_content 應為 "很棒的內容，謝謝老師"
