@command
Feature: 發送作業討論訊息

  學員在自己繳交的討論串發送訊息；管理員以老師身分在任一繳交的討論串發送訊息。
  評語（feedback）是批改結果；討論串是圍繞同一筆繳交的往返互動。（實作第二波）

  業務依據：zenbu-site-2.0 `course-assignment-thread.service.ts::postAsStudent / postAsTeacher` + `PostAssignmentMessageDto`
  （body 1-10000 字、不 trim；學員驗 ownership 不驗報名；老師訊息記錄 sender_email 快照；新訊息 read_at 為 null）

  UI 行為（不屬於本 Feature 的 API 行為，Example 不變）：後台批改頁與前台教室的輸入列都在送出前 trim，
  純空白不送出；後端 API 則不 trim、只有空白的 body 長度仍在 1-10000 內，視為合法並原樣保存。
  見 `specs/ui/作業批改頁.md`、`specs/ui/教室作業區塊.md`。

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
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status | feedback       | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1;   | []             | fail   | 請補充型別說明 | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      | 501          | 400          | 3      | const y = 2; | []             | pending |               | 2026-09-16 10:00:00 |           |                     |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客發送訊息時操作失敗
      When 未登入訪客在作業繳交 500 的討論串發送訊息：
        | body |
        | 你好 |
      Then 操作失敗，錯誤為「必須登入」

  Rule: 前置（狀態）- 管理員端操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員呼叫管理員端發送訊息時操作失敗
      When 用戶 "Bob" 呼叫 POST /assignment-submissions/500/messages
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 繳交紀錄必須存在

    Example: 學員在不存在的繳交發送訊息時操作失敗
      When 用戶 "Alice" 在作業繳交 9999 的討論串發送訊息：
        | body |
        | 你好 |
      Then 操作失敗，錯誤為「繳交紀錄不存在」

    Example: 管理員在不存在的繳交發送訊息時操作失敗
      When 管理員 "Admin" 在作業繳交 9999 的討論串發送訊息：
        | body |
        | 你好 |
      Then 操作失敗，錯誤為「繳交紀錄不存在」

  Rule: 前置（狀態）- 學員只能在自己繳交的討論串發送訊息

    Example: Alice 在 Bob 的繳交發送訊息時操作失敗
      When 用戶 "Alice" 在作業繳交 501 的討論串發送訊息：
        | body |
        | 你好 |
      Then 操作失敗，錯誤為「無權存取此繳交紀錄」

  Rule: 前置（狀態）- 學員發送訊息不檢查課程觀看權

    Example: 已被移出課程的 Alice 仍可在自己的討論串發送訊息
      Given 用戶 "Alice" 未被加入課程 100
      When 用戶 "Alice" 在作業繳交 500 的討論串發送訊息：
        | body           |
        | 請問哪裡要補充 |
      Then 操作成功

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 訊息內容長度必須為 1 至 10000 字

    Scenario Outline: 訊息內容為<情境>時操作失敗
      When 用戶 "Alice" 在作業繳交 500 的討論串發送訊息：
        | body   |
        | <body> |
      Then 操作失敗，錯誤為「訊息內容長度必須為 1 至 10000 字」

      Examples:
        | 情境          | body           |
        | 空字串        |                |
        | 超過 10000 字 | {字元 x 10001} |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 學員發送的訊息記錄為 student，接收方未讀

    Example: Alice 發送訊息
      When 用戶 "Alice" 在作業繳交 500 的討論串發送訊息：
        | body           |
        | 請問哪裡要補充 |
      Then 操作成功
      And 作業繳交 500 的討論串最後一則訊息應為：
        | sender_type | sender_id | sender_email | body           | read_at | created_at          |
        | student     | 2         |              | 請問哪裡要補充 |         | 2026-09-17 10:00:00 |

  Rule: 後置（狀態）- 管理員發送的訊息記錄為 teacher 並保存管理員 email 快照，接收方未讀

    Example: 管理員發送訊息
      When 管理員 "Admin" 在作業繳交 500 的討論串發送訊息：
        | body                   |
        | 請說明為什麼用 let     |
      Then 操作成功
      And 作業繳交 500 的討論串最後一則訊息應為：
        | sender_type | sender_id | sender_email   | body               | read_at | created_at          |
        | teacher     | 1         | admin@test.com | 請說明為什麼用 let |         | 2026-09-17 10:00:00 |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳新訊息並標示為查詢者自己發送

    Example: 發送成功後回應新訊息
      When 用戶 "Alice" 在作業繳交 500 的討論串發送訊息：
        | body           |
        | 請問哪裡要補充 |
      Then 操作成功
      And 回應應包含：
        | sender_type | body           | read_at | mine |
        | student     | 請問哪裡要補充 |         | true |
