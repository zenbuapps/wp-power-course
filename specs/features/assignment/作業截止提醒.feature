@command
Feature: 作業截止提醒

  Action Scheduler 每日 09:00 掃描即將截止的作業，對仍有有效觀看權且尚未繳交的學員觸發 PowerEmail 觸發時機 assignment_due_soon。（實作第一波）

  業務依據：zenbu-site-2.0 `course-assignments-cron.service.ts`
  （每日 09:00；截止時間落在 [現在, 現在 + 48 小時]；收件人 = 目前仍有存取權的學員扣除已繳交者）
  架構對應：寄信改走 PowerEmail 模板（需有 assignment_due_soon 模板才寄）；參考實作以程序記憶體去重（多台機器會重寄），
            改以 pc_email_records.identifier（含作業 id 與 due_at）去重；有效觀看權含課程權限包持有者與課程已開課

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 2      | Alice | alice@test.com | subscriber |
      | 3      | Bob   | bob@test.com   | subscriber |
      | 4      | Carol | carol@test.com | subscriber |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 1789747140 | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 1789869600 | 10         | publish     |
      | 402          | 201       | 練習三：心得     | yes        | no         | no            | no            | 1         | 0          | 20         | publish     |
      | 403          | 201       | 練習四：只收檔案 | no         | yes        | no            | no            | 1         | 1789055940 | 30         | publish     |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 0
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at          | course_id | post_status |
      | 810     | 作業即將截止 | assignment_due_soon |           | publish     |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 只處理截止時間落在現在到 48 小時後（含兩端）的作業

    Example: 只寄出 48 小時內截止的練習一
      When ActionScheduler 執行作業截止提醒掃描
      Then 應寄出以下作業截止提醒：
        | email          | assignment_title |
        | alice@test.com | 練習一：變數宣告 |
        | bob@test.com   | 練習一：變數宣告 |

    Scenario Outline: 截止時間為 <due_at>（<說明>）時寄送提醒
      Given 系統中有以下作業：
        | assignmentId | chapterId | title    | allow_text | allow_file | required_text | required_file | max_files | due_at   | menu_order | post_status |
        | 405          | 201       | 邊界練習 | yes        | no         | no            | no            | 1         | <due_at> | 40         | publish     |
      When ActionScheduler 執行作業截止提醒掃描
      Then 用戶 "Alice" 應收到作業 405 的截止提醒

      Examples:
        | due_at     | 說明              |
        | 1789610400 | 等於現在          |
        | 1789783200 | 等於現在 + 48 小時 |

    Scenario Outline: 截止時間為 <due_at>（<說明>）時不寄送提醒
      Given 系統中有以下作業：
        | assignmentId | chapterId | title    | allow_text | allow_file | required_text | required_file | max_files | due_at   | menu_order | post_status |
        | 405          | 201       | 邊界練習 | yes        | no         | no            | no            | 1         | <due_at> | 40         | publish     |
      When ActionScheduler 執行作業截止提醒掃描
      Then 用戶 "Alice" 不應收到作業 405 的截止提醒

      Examples:
        | due_at     | 說明                     |
        | 1789610399 | 早於現在 1 秒            |
        | 1789783201 | 晚於現在 + 48 小時 1 秒 |

  Rule: 前置（狀態）- 已繳交該作業的學員不寄送（不論批改狀態）

    Example: Alice 已繳交練習一
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body  | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 500          | 400          | 2      | let x = 1; | []             | pending |          | 2026-09-16 09:00:00 |           |           |
      When ActionScheduler 執行作業截止提醒掃描
      Then 應寄出以下作業截止提醒：
        | email        | assignment_title |
        | bob@test.com | 練習一：變數宣告 |

  Rule: 前置（狀態）- 只寄給對課程仍有有效觀看權的學員（含持有涵蓋此課程之有效權限包者）

    Example: Bob 已到期不寄送
      Given 用戶 "Bob" 在課程 100 的 expire_date 為 1609459200
      When ActionScheduler 執行作業截止提醒掃描
      Then 應寄出以下作業截止提醒：
        | email          | assignment_title |
        | alice@test.com | 練習一：變數宣告 |

    Example: 持有全站權限包的 Carol 也會收到
      Given 用戶 "Carol" 持有以下有效權限包：
        | passId | scope_type | limit_type |
        | 300    | all        | unlimited  |
      When ActionScheduler 執行作業截止提醒掃描
      Then 應寄出以下作業截止提醒：
        | email          | assignment_title |
        | alice@test.com | 練習一：變數宣告 |
        | bob@test.com   | 練習一：變數宣告 |
        | carol@test.com | 練習一：變數宣告 |

  Rule: 前置（狀態）- 課程尚未開課時不寄送

    Example: 課程排程開課時間在未來
      Given 課程 100 的 course_schedule 為 1789869600
      When ActionScheduler 執行作業截止提醒掃描
      Then 不應發送任何郵件

  Rule: 前置（狀態）- 垃圾桶中的作業不寄送

    Example: 練習一已移至垃圾桶
      Given 系統中有以下作業：
        | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
        | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 1789747140 | 0          | trash       |
      When ActionScheduler 執行作業截止提醒掃描
      Then 不應發送任何郵件

  Rule: 前置（狀態）- 沒有已發佈的 assignment_due_soon 郵件模板時不寄送

    Example: 模板為草稿
      Given 系統中有以下郵件模板：
        | emailId | post_title   | trigger_at          | course_id | post_status |
        | 810     | 作業即將截止 | assignment_due_soon |           | draft       |
      When ActionScheduler 執行作業截止提醒掃描
      Then 不應發送任何郵件

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 外掛初始化時確保每日 09:00 的掃描排程存在

    Example: 註冊每日排程
      When 外掛初始化
      Then Action Scheduler 應存在每日 09:00（站台時區）執行的 "pc_assignment_due_soon_scan" 排程

  Rule: 後置（狀態）- 同一模板對同一學員、同一作業、同一截止時間只寄一次

    Example: 當天再次掃描不重複寄送
      Given 郵件模板 810 已針對作業 400 截止時間 1789747140 寄送提醒給用戶 "Alice"
      And 郵件模板 810 已針對作業 400 截止時間 1789747140 寄送提醒給用戶 "Bob"
      When ActionScheduler 執行作業截止提醒掃描
      Then 不應發送任何郵件

  Rule: 後置（狀態）- 截止時間變更後依新的截止時間再次提醒

    Example: 練習一延後截止後再次提醒
      Given 郵件模板 810 已針對作業 400 截止時間 1789700000 寄送提醒給用戶 "Alice"
      When ActionScheduler 執行作業截止提醒掃描
      Then 用戶 "Alice" 應收到作業 400 的截止提醒

  Rule: 後置（狀態）- 寄出後寫入郵件紀錄

    Example: 寄給 Alice 後寫入 pc_email_records
      When ActionScheduler 執行作業截止提醒掃描
      Then pc_email_records 應新增一筆記錄：
        | email_id | user_id | post_id | trigger_at          | mark_as_sent |
        | 810      | 2       | 100     | assignment_due_soon | true         |
