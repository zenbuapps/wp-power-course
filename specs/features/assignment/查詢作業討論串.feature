@query
Feature: 查詢作業討論串

  學員查看自己繳交的討論串；管理員查看任一繳交的討論串。查詢時把「對方發送、尚未讀取」的訊息標為已讀。
  （實作第二波）

  業務依據：zenbu-site-2.0 `course-assignment-thread.service.ts::listForStudent / listForTeacher`
  （學員驗 ownership，不驗報名；依 createdAt ASC；回傳 id / senderType / body / readAt / createdAt / mine；軟刪除訊息排除）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
      | 500          | 400          | 2      | let x = 1;   | []             | pending |          | 2026-09-16 09:00:00 |           |           |
      | 501          | 400          | 3      | const y = 2; | []             | pending |          | 2026-09-16 10:00:00 |           |           |
    And 系統中有以下作業討論訊息：
      | messageId | submissionId | sender_type | sender_id | body            | read_at | created_at          | deleted_at          |
      | 600       | 500          | student     | 2         | 老師請問型別    |         | 2026-09-16 09:10:00 |                     |
      | 601       | 500          | teacher     | 1         | 參考 1-1 的範例 |         | 2026-09-16 10:00:00 |                     |
      | 602       | 500          | student     | 2         | 打錯字請忽略    |         | 2026-09-16 10:10:00 | 2026-09-16 10:15:00 |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客查詢討論串時操作失敗
      When 未登入訪客查詢作業繳交 500 的討論串
      Then 操作失敗，錯誤為「必須登入」

  Rule: 前置（狀態）- 管理員端操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員呼叫管理員端討論串時操作失敗
      When 用戶 "Bob" 呼叫 GET /assignment-submissions/500/messages
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 繳交紀錄必須存在

    Example: 學員查詢不存在的繳交討論串時操作失敗
      When 用戶 "Alice" 查詢作業繳交 9999 的討論串
      Then 操作失敗，錯誤為「繳交紀錄不存在」

    Example: 管理員查詢不存在的繳交討論串時操作失敗
      When 管理員 "Admin" 查詢作業繳交 9999 的討論串
      Then 操作失敗，錯誤為「繳交紀錄不存在」

  Rule: 前置（狀態）- 學員只能查詢自己繳交的討論串

    Example: Alice 查詢 Bob 繳交的討論串時操作失敗
      When 用戶 "Alice" 查詢作業繳交 501 的討論串
      Then 操作失敗，錯誤為「無權存取此繳交紀錄」

  Rule: 前置（狀態）- 學員查詢討論串不檢查課程觀看權

    Example: 已被移出課程的 Alice 仍可查詢自己的討論串
      Given 用戶 "Alice" 未被加入課程 100
      When 用戶 "Alice" 查詢作業繳交 500 的討論串
      Then 操作成功

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 依建立時間由舊到新回傳未刪除的訊息，並以 mine 標示是否為查詢者這一方發送

    Example: 學員端查詢討論串
      When 用戶 "Alice" 查詢作業繳交 500 的討論串
      Then 操作成功
      And 查詢結果應為：
        | id  | sender_type | body            | mine  | created_at          |
        | 600 | student     | 老師請問型別    | true  | 2026-09-16 09:10:00 |
        | 601 | teacher     | 參考 1-1 的範例 | false | 2026-09-16 10:00:00 |

    Example: 管理員端查詢討論串
      When 管理員 "Admin" 查詢作業繳交 500 的討論串
      Then 操作成功
      And 查詢結果應為：
        | id  | sender_type | body            | mine  |
        | 600 | student     | 老師請問型別    | false |
        | 601 | teacher     | 參考 1-1 的範例 | true  |

  Rule: 後置（回應）- 訊息只回傳 id、sender_type、body、read_at、created_at 與 mine，不回傳發送者 id 與 email

    Example: 學員端回應不含老師的 email
      When 用戶 "Alice" 查詢作業繳交 500 的討論串
      Then 操作成功
      And 回應不應包含 sender_email 欄位
      And 回應不應包含 sender_id 欄位

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 學員查詢後，老師發送且未讀的訊息標為已讀

    Example: Alice 讀取後老師訊息已讀
      When 用戶 "Alice" 查詢作業繳交 500 的討論串
      Then 操作成功
      And 作業討論訊息 601 的 read_at 應為 "2026-09-17 10:00:00"
      And 作業討論訊息 600 的 read_at 應為空

  Rule: 後置（狀態）- 管理員查詢後，學員發送且未讀的訊息標為已讀

    Example: 管理員讀取後學員訊息已讀
      When 管理員 "Admin" 查詢作業繳交 500 的討論串
      Then 操作成功
      And 作業討論訊息 600 的 read_at 應為 "2026-09-17 10:00:00"
      And 作業討論訊息 601 的 read_at 應為空
