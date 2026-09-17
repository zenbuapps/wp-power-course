@command
Feature: 更新作業

  管理員修改作業的標題、說明、題型設定、截止時間、排序，或把作業改掛到其他章節（可跨課程）。
  部分更新：只變更有提供的欄位，合併後的題型設定仍須允許至少一種繳交方式。

  業務依據：zenbu-site-2.0 `course-assignments.service.ts::update`（PartialType DTO、合併後 assertFormFlags、可改 lessonId / chapterId）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 200       | 第一章     | 100         |
      | 201       | 1-1 變數   | 200         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員更新作業時操作失敗
      When 用戶 "Bob" 呼叫 PUT /assignments/400
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在

    Example: 作業不存在時更新失敗
      When 管理員 "Admin" 更新作業 9999，參數如下：
        | title  |
        | 新標題 |
      Then 操作失敗，錯誤為「作業不存在」

    Example: 作業在垃圾桶中時更新失敗
      Given 系統中有以下作業：
        | assignmentId | chapterId | title      | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
        | 402          | 201       | 已刪除練習 | yes        | no         | no            | no            | 1         | 0      | 0          | trash       |
      When 管理員 "Admin" 更新作業 402，參數如下：
        | title  |
        | 新標題 |
      Then 操作失敗，錯誤為「作業不存在」

  Rule: 前置（狀態）- 提供 chapter_id 時目標章節必須存在

    Example: 改掛到不存在的章節時更新失敗
      When 管理員 "Admin" 更新作業 400，參數如下：
        | chapter_id |
        | 9999       |
      Then 操作失敗，錯誤為「章節不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 合併既有設定後 allow_text 與 allow_file 至少一個為 yes

    Example: 關閉唯一開啟的文字繳交時更新失敗
      When 管理員 "Admin" 更新作業 400，參數如下：
        | allow_text |
        | no         |
      Then 操作失敗，錯誤為「至少需允許文字或檔案其中一種繳交方式」

  Rule: 前置（參數）- 提供的欄位必須符合建立作業的格式規則

    Scenario Outline: <欄位> 格式不合法時更新失敗
      When 管理員 "Admin" 更新作業 400，參數如下：
        | <欄位> |
        | <值>   |
      Then 操作失敗，錯誤為「<錯誤訊息>」

      Examples:
        | 欄位      | 值  | 錯誤訊息                                |
        | title     |     | title 長度必須為 1 至 200 字            |
        | max_files | 21  | max_files 必須為 1 至 20 的整數         |
        | due_at    | 123 | due_at 必須為 0 或 10 位 Unix timestamp |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 只更新有提供的欄位，其餘維持原值

    Example: 只修改標題時題型設定不變
      When 管理員 "Admin" 更新作業 400，參數如下：
        | title                |
        | 練習一：變數與常數   |
      Then 操作成功
      And 作業 400 應為：
        | post_parent | parent_course_id | title              | allow_text | allow_file | max_files | due_at | menu_order | post_status |
        | 201         | 100              | 練習一：變數與常數 | yes        | no         | 1         | 0      | 0          | publish     |

  Rule: 後置（狀態）- 改掛到其他課程的章節時，parent_course_id 更新為新章節的根課程

    Example: 將作業移到另一門課程的章節
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  |
        | 101      | JS 入門課 | yes        | publish |
      And 課程 101 有以下章節：
        | chapterId | post_title | post_parent |
        | 300       | JS 第一章  | 101         |
      When 管理員 "Admin" 更新作業 400，參數如下：
        | chapter_id |
        | 300        |
      Then 操作成功
      And 作業 400 應為：
        | post_parent | parent_course_id |
        | 300         | 101              |

  Rule: 後置（狀態）- 更新作業不影響既有的繳交紀錄

    Example: 開啟檔案繳交後既有通過的繳交維持通過
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body  | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
        | 500          | 400          | 3      | let x = 1; | []             | pass   | 寫得很好 | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      When 管理員 "Admin" 更新作業 400，參數如下：
        | allow_file | required_file |
        | yes        | yes           |
      Then 操作成功
      And 作業繳交 500 應為：
        | text_body  | status | feedback | graded_at           |
        | let x = 1; | pass   | 寫得很好 | 2026-09-16 10:00:00 |
