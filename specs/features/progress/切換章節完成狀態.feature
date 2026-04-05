@ignore @command
Feature: 切換章節完成狀態

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 200       | 第一章     | 100         |
      | 201       | 第二章     | 100         |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須已有該章節所屬課程的存取權

    Example: 無課程存取權時操作失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 切換章節 200 的完成狀態
      Then 操作失敗，錯誤為「無此課程存取權」

  Rule: 前置（狀態）- 課程存取未到期

    Example: 課程已到期時操作失敗
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 1609459200
      When 用戶 "Alice" 切換章節 200 的完成狀態
      Then 操作失敗，錯誤為「課程存取已到期」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- chapter_id 必須存在且 post_type 為 pc_chapter

    Example: 章節不存在時操作失敗
      When 用戶 "Alice" 切換章節 9999 的完成狀態
      Then 操作失敗，錯誤為「章節不存在」

  Rule: 前置（參數）- user_id 必須已登入

    Example: 未登入訪客操作失敗
      When 未登入訪客切換章節 200 的完成狀態
      Then 操作失敗，錯誤為「必須登入」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 標記完成時新增 finished_at 並觸發 chapter_finished action

    Example: 成功標記章節為完成
      Given 用戶 "Alice" 在章節 200 無 finished_at
      When 用戶 "Alice" 切換章節 200 的完成狀態
      Then 操作成功
      And 章節 200 對用戶 "Alice" 的 chaptermeta finished_at 應不為空
      And action "power_course_chapter_finished" 應被觸發

  Rule: 後置（狀態）- 取消完成時刪除 finished_at 並觸發 chapter_unfinished action

    Example: 成功取消章節完成
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"
      When 用戶 "Alice" 切換章節 200 的完成狀態
      Then 操作成功
      And 章節 200 對用戶 "Alice" 的 chaptermeta finished_at 應為空
      And action "power_course_chapter_unfinished" 應被觸發

  Rule: 後置（狀態）- 切換後重新計算課程進度

    Example: 完成所有章節後課程進度達 100%
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"
      And 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 切換章節 201 的完成狀態
      Then 操作成功
      And 用戶 "Alice" 在課程 100 的進度應為 100
      And action "power_course_course_finished" 應以參數 (100, 2) 被觸發
      And 課程 100 對用戶 "Alice" 的 coursemeta finished_at 應不為空
