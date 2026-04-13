@ignore @command @log
Feature: 學員活動日誌類型

  Power Course 透過 `pc_student_logs` 自訂資料表記錄學員的所有關鍵事件。
  每筆日誌有一個 `log_type` 欄位，使用的 slug **與 Email Trigger 的 slug 重疊但不完全對應**：
  部分 slug 同時用於 Email 自動觸發與 Log 記錄，部分 slug 只用於 Log 記錄。

  **Code source:**
  - `inc/classes/PowerEmail/Resources/Email/Trigger/AtHelper.php`（定義所有 slug 常數）
  - `inc/classes/PowerEmail/Resources/Email/Trigger/At.php`（只註冊 5 個有 email 的 trigger）
  - `inc/classes/Resources/Course/LifeCycle.php`（寫入 order_created、course_removed、update_student logs）
  - `inc/classes/Resources/Chapter/Core/LifeCycle.php`（寫入 chapter_enter、chapter_finish、chapter_unfinished logs）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role     |
      | 10     | Alice | customer |

  # ========== Log type 與 Email Trigger 的對應矩陣 ==========

  Rule: AtHelper 定義 9 個 slug，但只有 5 個同時是 Email Trigger

    Example: Slug 用途矩陣
      Then 系統中存在以下 log_type / email_trigger 對應：
        | slug               | 是 Email Trigger | 寫入 StudentLog | label（AtHelper::set_label）| 用途 |
        | course_granted     | 是               | 是              | 開通課程權限後              | 學員獲得課程存取權 |
        | course_finish      | 是               | 是              | 課程完成時                  | 學員完成整個課程 |
        | course_launch      | 是               | 是              | 課程開課時                  | 課程排程開課時觸發 |
        | chapter_enter      | 是               | 是              | 進入單元時                  | 學員首次進入章節 |
        | chapter_finish     | 是               | 是              | 完成單元時                  | 學員完成章節 |
        | order_created      | **否**           | 是              | 訂單成立時                  | 僅用於 log，由 Course/LifeCycle::add_order_created_log 寫入 |
        | chapter_unfinished | **否**           | 是              | 單元未完成時                | 僅用於 log，由 Chapter/LifeCycle::add_chapter_unfinished_log 寫入 |
        | course_removed     | **否**           | 是              | 管理員手動移除課程權限時    | 僅用於 log，由 Course/LifeCycle 內的移除流程寫入 |
        | update_student     | **否**           | 是              | 更新學員觀看課程期限時      | 僅用於 log，由 Course/LifeCycle::save_meta_update_student 寫入 |

  # ========== order_created ==========

  Rule: 學員購買含課程權限的商品時，log 記錄 order_created

    Example: 購買 PHP 基礎課
      Given 商品 100 為課程商品
      When Alice 下訂單 8001 購買商品 100
      And 訂單狀態變更為 course_access_trigger 設定的狀態
      Then pc_student_logs 新增一筆：
        | user_id | course_id | title                                    | log_type       |
        | 10      | 100       | 購買包含課程 #100 權限的商品 訂單 #8001  | order_created  |
      And 不觸發 Email 發送（order_created 無對應 Email Trigger）

  # ========== course_removed ==========

  Rule: 管理員手動移除學員課程權限時，log 記錄 course_removed

    Example: 移除 Alice 的課程權限
      Given Alice 已被加入課程 100
      When 管理員呼叫 POST /courses/remove-students 移除 Alice
      Then pc_student_logs 新增一筆 log_type=course_removed
      And power_course_after_remove_student_from_course action 觸發
      And 該 action 的 handler 會更新已寄信過的 Email 標記 mark_as_sent 為 0（讓未來重新購買時可再次收到信）

  # ========== update_student ==========

  Rule: 管理員更新學員到期日時，log 記錄 update_student

    Example: 延長 Alice 的到期日
      Given Alice 的 pc_avl_coursemeta(course_id=100, user_id=10).expire_date 為 1714118340
      When 管理員呼叫 PUT /students/expire-date 將 Alice 的到期日改為 1745654340
      Then power_course_after_update_student_from_course action 觸發
      And pc_student_logs 新增一筆 log_type=update_student

  # ========== chapter_unfinished ==========

  Rule: 學員手動將章節從已完成改回未完成時，log 記錄 chapter_unfinished

    Example: 取消完成
      Given Alice 在章節 200 已有 finished_at
      When Alice 呼叫 POST /toggle-finish-chapters/200
      Then pc_avl_chaptermeta 的 finished_at 被刪除
      And power_course_chapter_unfinished action 觸發
      And pc_student_logs 新增一筆 log_type=chapter_unfinished

  # ========== 前端查詢 ==========

  Rule: 前端查詢學員活動紀錄時會看到所有 log_type（無篩選）

    Example: 查詢 Alice 的活動紀錄
      When 管理員呼叫 GET /student-logs?user_id=10
      Then 回應可能包含 order_created / course_granted / chapter_enter / chapter_finish / chapter_unfinished / update_student / course_removed 各種 log_type
      And 前端以 AtHelper 的 label 顯示人類可讀的事件名稱

  # ========== 歷史技術債記錄 ==========

  Rule: 4 個非 Email Trigger 的 slug 目前僅作為 log_type，未來若要擴充為 Email Trigger，需在 At.php 的 constructor 新增對應的 add_action

    Example: 歷史決策
      Given 本 feature 所列 4 個 log-only slug（order_created、chapter_unfinished、course_removed、update_student）
      When 審視 At.php 的 constructor
      Then 其只註冊 5 個有 email 的 trigger：COURSE_GRANTED、COURSE_FINISHED、COURSE_LAUNCHED、CHAPTER_ENTERED、CHAPTER_FINISHED
      And AtHelper 註解明確標示其他 4 個為 "目前 email 沒有這個 trigger"
      And 此為**有意的設計**，並非 dead code：slug 本身供 log_type 使用
