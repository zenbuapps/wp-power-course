@ignore @command
Feature: 更新章節

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | chapter_video_type | chapter_length |
      | 200       | 第一章     | 100         | bunny              | 600            |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 章節必須存在且 post_type 為 pc_chapter

    Example: 不存在的章節更新失敗
      When 管理員 "Admin" 更新章節 9999，參數如下：
        | post_title |
        | 新名稱     |
      Then 操作失敗，錯誤為「章節不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 不可為空

    Example: 未提供章節 ID 時更新失敗
      When 管理員 "Admin" 更新章節 ""，參數如下：
        | post_title |
        | 新名稱     |
      Then 操作失敗，錯誤訊息包含 "id"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新對應章節的 post 及 meta 資料

    Example: 成功更新章節標題和影片
      When 管理員 "Admin" 更新章節 200，參數如下：
        | post_title   | chapter_video_type | chapter_length | enable_comment |
        | 第一章（更新）| youtube            | 900            | no             |
      Then 操作成功
      And 章節 200 的 post_title 應為 "第一章（更新）"
      And 章節 200 的 chapter_video_type 應為 "youtube"
      And 章節 200 的 chapter_length 應為 900
