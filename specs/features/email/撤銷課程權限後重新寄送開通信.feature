@ignore @command
Feature: 撤銷課程權限後重新寄送課程開通信

  修復 Issue #232：管理員撤銷學員課程權限後，同一學員重新購買同一課程時，
  課程開通通知信（trigger_at = course_granted）不再寄出的問題。

  三個協同修法：
  1. 排程去重只看 pending / in-progress（`At::schedule_email()`）—— complete 歷史不再永久卡住排程。
  2. 寄送放行依 `allow_repeat_send` 開關（`At::trigger_condition()`）。
  3. 管理員撤銷學員時，連帶清掉對應的 Action Scheduler group 並重置 mark_as_sent（方案 A，僅 course_granted）。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 10     | Alice | alice@test.com | customer      |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at     | allow_repeat_send | post_status |
      | 500     | 課程開通通知 | course_granted | yes               | publish     |
    And 觸發 course_granted 的 Action Scheduler hook 為 "power_email_send_course_granted"

  # ========== 後置（狀態）：修法 1 — 排程去重只看 pending / in-progress ==========

  Rule: 後置（狀態）- complete 的歷史 action 不再阻擋重新排程

    Example: 已有 complete 的歷史 action 時仍可重新排程
      Given Action Scheduler 已有 1 筆 status 為 "complete" 的 action：
        | hook                            | group（identifier）                                          |
        | power_email_send_course_granted | email_id:500\|user_id:10\|ids:100\|trigger_at:course_granted\| |
      When action "power_course_add_student_to_course" 觸發，參數 (10, 100)
      Then Action Scheduler 應新增 1 筆 pending action，group 為相同 identifier
      And 不應記錄 "already scheduled or sent, skip duplicate schedule" warning

  Rule: 後置（狀態）- 已有 pending / in-progress action 時跳過重複排程（防單一事件重入爆信）

    Example: 同一 group 已有 pending action 時不重排
      Given Action Scheduler 已有 1 筆 status 為 "pending" 的 action：
        | hook                            | group（identifier）                                          |
        | power_email_send_course_granted | email_id:500\|user_id:10\|ids:100\|trigger_at:course_granted\| |
      When action "power_course_add_student_to_course" 觸發，參數 (10, 100)
      Then Action Scheduler 不應新增重複的 action
      And 應記錄 "already scheduled or sent, skip duplicate schedule" warning

  # ========== 後置（狀態）：修法 2 — 寄送放行依 allow_repeat_send 開關 ==========

  Rule: 後置（狀態）- allow_repeat_send=true 時無視 mark_as_sent 照觸發就寄

    Example: 開關為 true 且已有寄送紀錄時仍會寄
      Given 郵件模板 500 的 allow_repeat_send 為 true
      And pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 500      | 10      | 100     | course_granted | 1            |
      When 系統判斷郵件模板 500 是否寄給用戶 10（課程 100）
      Then 應允許寄送

  Rule: 後置（狀態）- allow_repeat_send=false 時依 mark_as_sent 判斷

    Example: 開關為 false 且 mark_as_sent=1 時不寄
      Given 郵件模板 500 的 allow_repeat_send 為 false
      And pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 500      | 10      | 100     | course_granted | 1            |
      When 系統判斷郵件模板 500 是否寄給用戶 10（課程 100）
      Then 不應寄送

    Example: 開關為 false 且 mark_as_sent=0 時可寄
      Given 郵件模板 500 的 allow_repeat_send 為 false
      And pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 500      | 10      | 100     | course_granted | 0            |
      When 系統判斷郵件模板 500 是否寄給用戶 10（課程 100）
      Then 應允許寄送

  # ========== 後置（狀態）：修法 3 — 撤銷學員時清理（方案 A） ==========

  Rule: 後置（狀態）- 管理員撤銷時重置 course_granted 的 mark_as_sent 為 0（不論開關）

    Example: 撤銷後 course_granted 的寄送紀錄被重置
      Given pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 500      | 10      | 100     | course_granted | 1            |
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      Then pc_email_records 中該筆記錄的 mark_as_sent 應為 0

  Rule: 後置（狀態）- 管理員撤銷時清掉對應的 Action Scheduler group（含 complete）

    Example: 撤銷後 course_granted 的歷史 action 被清除
      Given Action Scheduler 已有以下 action：
        | hook                            | status   | group（identifier）                                          |
        | power_email_send_course_granted | complete | email_id:500\|user_id:10\|ids:100\|trigger_at:course_granted\| |
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      Then 該 group 下的所有 course_granted action 應被清除

    Example: 撤銷時清掉尚未觸發的 send-later 延遲 action（避免撤銷後仍寄出）
      Given 郵件模板 500 設定為「達成條件 3 天後寄送」
      And Action Scheduler 已有 1 筆 status 為 "pending" 的延遲 action，group 對應 (email 500, user 10, course 100)
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      Then 該 pending 延遲 action 應被清除
      And 不應在 3 天後寄出該開通信

  Rule: 後置（狀態）- 撤銷時只清 course_granted，其他 trigger_at 不受影響

    Example: 撤銷不影響 chapter_finish 的寄送紀錄與排程
      Given 系統中有以下郵件模板：
        | emailId | post_title | trigger_at     | post_status |
        | 600     | 章節完成   | chapter_finish | publish     |
      And pc_email_records 中已有以下記錄：
        | email_id | user_id | post_id | trigger_at     | mark_as_sent |
        | 600      | 10      | 100     | chapter_finish | 1            |
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      Then pc_email_records 中 email_id 600 的記錄 mark_as_sent 仍為 1

  # ========== 後置（狀態）：端到端 — 撤銷 → 重購 → 再次收到開通信 ==========

  Rule: 後置（狀態）- 端到端：撤銷後重新購買能再次收到開通信

    Example: allow_repeat_send=true（預設）撤銷後重購再次寄出
      Given 用戶 "Alice" 已購買課程 100 並已收到開通信（mark_as_sent=1、AS 有 complete action）
      And 郵件模板 500 的 allow_repeat_send 為 true
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      And 用戶 "Alice" 重新購買課程 100，action "power_course_add_student_to_course" 觸發，參數 (10, 100)
      Then Action Scheduler 應新增 1 筆 pending action
      And 用戶 "alice@test.com" 應再次收到課程開通通知信

    Example: allow_repeat_send=false 撤銷後重購也再次寄出（因 mark_as_sent 已被重置為 0）
      Given 用戶 "Alice" 已購買課程 100 並已收到開通信（mark_as_sent=1、AS 有 complete action）
      And 郵件模板 500 的 allow_repeat_send 為 false
      When action "power_course_after_remove_student_from_course" 觸發，參數 (10, 100)
      And 用戶 "Alice" 重新購買課程 100，action "power_course_add_student_to_course" 觸發，參數 (10, 100)
      Then 用戶 "alice@test.com" 應再次收到課程開通通知信

  Rule: 後置（狀態）- 未撤銷時 allow_repeat_send=false 不重複寄

    Example: 同一報名期間（未撤銷）開關為 false 重複觸發不再寄
      Given 用戶 "Alice" 已購買課程 100 並已收到開通信（mark_as_sent=1）
      And 郵件模板 500 的 allow_repeat_send 為 false
      And 期間未發生任何撤銷
      When action "power_course_add_student_to_course" 再次觸發，參數 (10, 100)
      Then 不應再次寄出課程開通通知信
