@ignore @command
Feature: 觸發自動郵件

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at     | course_id | post_content                               |
      | 400     | 課程開通通知 | course_granted | 100       | 恭喜 {display_name}，您已開通 {course_name} |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 對應觸發類型的郵件模板必須存在

    Example: 無對應模板時不發送郵件
      Given 課程 100 無 trigger_at 為 "course_finish" 的郵件模板
      When action "power_course_course_finished" 觸發，參數 (100, 2)
      Then 不應發送任何郵件

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 事件觸發時自動發送對應郵件

    Example: 學員加入課程後觸發開通通知
      When action "power_course_after_add_student_to_course" 觸發，參數 (2, 100)
      Then 應發送郵件給 "alice@test.com"
      And 郵件主旨應為 "課程開通通知"

  Rule: 後置（狀態）- 動態內容替換（固定變數集，透過 Replace 模組處理）

    Example: 郵件內容正確替換動態變數
      When action "power_course_after_add_student_to_course" 觸發，參數 (2, 100)
      Then 發送的郵件內容應包含 "Alice"
      And 發送的郵件內容應包含 "PHP 基礎課"

    Example: 支援的動態變數清單
      """
      User 變數：{display_name}, {user_email}, {ID}, {user_login}
      Course 變數：{course_name}, {course_id}, {course_regular_price}, {course_sale_price}, {course_slug}, {course_image_url}, {course_permalink}
      Chapter 變數：{chapter_title}
      """

  Rule: 後置（狀態）- 防止重複發送（identifier 唯一鍵）

    Example: 同一事件不重複發送郵件
      Given pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | identifier                   |
        | 400      | 2       | 100     | course_granted | 400_2_100_course_granted     |
      When action "power_course_after_add_student_to_course" 再次觸發，參數 (2, 100)
      Then 不應發送重複郵件

  Rule: 後置（狀態）- 發送後記錄到 pc_email_records

    Example: 成功發送後寫入記錄
      When action "power_course_after_add_student_to_course" 觸發，參數 (2, 100)
      Then pc_email_records 應新增一筆記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 400      | 2       | 100     | course_granted | true         |

  Rule: 後置（狀態）- 支援立即發送和排程發送

    Example: 手動觸發立即發送（透過 ActionScheduler 非同步佇列）
      When 管理員 "Admin" 對郵件模板 400 執行立即發送
      Then ActionScheduler 應排入非同步任務
      And 符合條件的學員應收到郵件

    Example: 手動觸發排程發送
      When 管理員 "Admin" 對郵件模板 400 排程發送於 "2026-04-01 09:00:00"
      Then ActionScheduler 應排入排程任務於指定時間
