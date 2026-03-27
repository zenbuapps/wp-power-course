@ignore @command
Feature: 建立章節

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- parent_course_id 對應的課程必須存在且 _is_course 為 yes

    Example: 指向不存在課程時建立失敗
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id |
        | 第一章     | 9999        | 9999             |
      Then 操作失敗

  Rule: 前置（狀態）- 若 post_parent 為章節 ID，該章節必須存在

    Example: 父章節不存在時建立失敗
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id |
        | 1-1 小節   | 8888        | 100              |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- post_title 不可為空

    Example: 未提供章節名稱時建立失敗
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id |
        |            | 100         | 100              |
      Then 操作失敗，錯誤訊息包含 "post_title"

  Rule: 前置（參數）- parent_course_id 不可為空

    Example: 未提供根課程 ID 時建立失敗
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id |
        | 第一章     | 100         |                  |
      Then 操作失敗，錯誤訊息包含 "parent_course_id"

  Rule: 前置（參數）- chapter_video.type 必須為合法值

    Example: 非法影片類型時建立失敗
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id | chapter_video_type |
        | 第一章     | 100         | 100              | invalid            |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 建立 post_type 為 pc_chapter 的文章

    Example: 成功建立頂層章節
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id | chapter_video_type | chapter_length | enable_comment |
        | 第一章     | 100         | 100              | bunny              | 600            | yes            |
      Then 操作成功
      And 新建章節的 post_type 應為 "pc_chapter"
      And 新建章節的 post_parent 應為 100
      And 新建章節的 parent_course_id meta 應為 100

  Rule: 後置（狀態）- 支援建立子章節（巢狀結構）

    Example: 成功建立子章節
      Given 課程 100 有以下章節：
        | chapterId | post_title | post_parent |
        | 200       | 第一章     | 100         |
      When 管理員 "Admin" 建立章節，參數如下：
        | post_title | post_parent | parent_course_id |
        | 1-1 小節   | 200         | 100              |
      Then 操作成功
      And 新建章節的 post_parent 應為 200
      And 新建章節的 parent_course_id meta 應為 100
