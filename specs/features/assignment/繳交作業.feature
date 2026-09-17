@command
Feature: 繳交作業

  學員繳交作業（文字與 / 或附件）。每位學員每份作業只有一筆繳交，重交時覆寫內容、狀態重設為待批改並清空批改結果。
  截止時間不阻擋繳交。學員教室介面在已通過時隱藏重交按鈕，但 API 本身不擋。

  業務依據：zenbu-site-2.0 `course-assignment-submissions.service.ts::submit`
  （先驗報名 → 作業存在 → assertSubmissionShape → UPSERT on (assignment_id, customer_id)）
  偏離（Q4 衍生）：帶入的附件必須為本人上傳（參考實作未檢查，受保護下載需確保歸屬）
  偏離（使用者決策，PC 專屬功能）：課程啟用線性觀看時，不可繳交鎖定章節的作業；沿用 LinearViewing 既有解鎖邏輯
  （參考實作沒有線性觀看；若不擋，學員可經 API 繳交鎖定章節作業，批改通過後章節被標為完成並依最遠進度模式解鎖前面所有章節）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 2      | Alice | alice@test.com | subscriber |
      | 3      | Bob   | bob@test.com   | subscriber |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
      | 202       | 1-2 型別   | 100         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0          | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 1789747140 | 10         | publish     |
      | 402          | 201       | 練習三：心得     | yes        | yes        | yes           | no            | 1         | 0          | 20         | publish     |
      | 403          | 201       | 練習四：只收檔案 | no         | yes        | no            | no            | 1         | 0          | 30         | publish     |
      | 405          | 202       | 練習五：型別轉換 | yes        | no         | no            | no            | 1         | 0          | 0          | publish     |
    And 系統中有以下作業附件：
      | attachmentId | userId | original_name | mime_type       | file_size |
      | 700          | 2      | 作業截圖.png  | image/png       | 20480     |
      | 701          | 2      | 執行結果.pdf  | application/pdf | 40960     |
      | 702          | 2      | 程式碼.zip    | application/zip | 1024      |
      | 710          | 3      | Bob 截圖.png  | image/png       | 20480     |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客繳交時操作失敗
      When 未登入訪客繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「必須登入」

  Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權（已購或持有涵蓋此課程的有效權限包、未到期、課程已開課）

    Example: 未加入課程的學員繳交時操作失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 課程觀看權已到期時繳交失敗
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 1609459200
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 課程尚未開課時繳交失敗
      Given 課程 100 的 course_schedule 為 1789869600
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「尚未報名此課程」

  Rule: 前置（狀態）- 作業不存在或在垃圾桶時一律回應「尚未報名此課程」，不洩漏作業是否存在

    Example: 繳交不存在的作業時操作失敗
      When 用戶 "Alice" 繳交作業 9999，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 繳交已移至垃圾桶的作業時操作失敗
      Given 系統中有以下作業：
        | assignmentId | chapterId | title      | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
        | 404          | 201       | 已刪除練習 | yes        | no         | no            | no            | 1         | 0      | 40         | trash       |
      When 用戶 "Alice" 繳交作業 404，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作失敗，錯誤為「尚未報名此課程」

  Rule: 前置（狀態）- 課程啟用線性觀看時，作業所屬章節必須已對學員解鎖（沿用 LinearViewing::is_chapter_locked 的最遠進度解鎖邏輯，具 manage_woocommerce 能力者豁免）

    Example: 啟用線性觀看且作業所屬章節鎖定時繳交失敗
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      And 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 繳交作業 405，內容如下：
        | text_body    | attachment_ids |
        | 型別轉換心得 | []             |
      Then 操作失敗，錯誤為「此章節尚未解鎖，請先完成前面的章節」
      And 用戶 "Alice" 在作業 405 的繳交筆數應為 0

    Example: 啟用線性觀看且完成前一章節後可繳交下一章節的作業
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      And 用戶 "Alice" 在章節 201 的 finished_at 為 "2026-09-16 10:00:00"
      When 用戶 "Alice" 繳交作業 405，內容如下：
        | text_body    | attachment_ids |
        | 型別轉換心得 | []             |
      Then 操作成功
      And 用戶 "Alice" 在作業 405 的繳交應為：
        | status  |
        | pending |

    Example: 啟用線性觀看時第一個章節永遠解鎖，無完成紀錄也可繳交
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      And 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作成功

    Example: 未啟用線性觀看時不檢查章節鎖定
      Given 課程 100 的 enable_linear_viewing 為 'no'
      And 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 繳交作業 405，內容如下：
        | text_body    | attachment_ids |
        | 型別轉換心得 | []             |
      Then 操作成功

    Example: 具 manage_woocommerce 能力者不受線性觀看鎖定限制
      Given 系統中有以下用戶：
        | userId | name    | email            | role         |
        | 5      | Manager | manager@test.com | shop_manager |
      And 用戶 "Manager" 已被加入課程 100，expire_date 0
      And 課程 100 的 enable_linear_viewing 為 'yes'
      And 用戶 "Manager" 在章節 201 無 finished_at
      When 用戶 "Manager" 繳交作業 405，內容如下：
        | text_body    | attachment_ids |
        | 型別轉換心得 | []             |
      Then 操作成功

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 文字內容（去除前後空白後）與附件至少提供一項

    Scenario Outline: 文字為<情境>且無附件時繳交失敗
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body   | attachment_ids |
        | <text_body> | []             |
      Then 操作失敗，錯誤為「請至少填寫文字或附件」

      Examples:
        | 情境     | text_body |
        | 空字串   |           |
        | 只有空白 | "   "     |

  Rule: 前置（參數）- 提供的內容必須符合作業的題型設定與附件上限

    Scenario Outline: <情境>時繳交失敗
      When 用戶 "Alice" 繳交作業 <assignmentId>，內容如下：
        | text_body   | attachment_ids   |
        | <text_body> | <attachment_ids> |
      Then 操作失敗，錯誤為「<錯誤訊息>」

      Examples:
        | 情境                     | assignmentId | text_body  | attachment_ids  | 錯誤訊息                     |
        | 只收檔案的作業帶文字     | 403          | 我的心得   | [700]           | 此作業不允許文字繳交         |
        | 只收文字的作業帶附件     | 400          | let x = 1; | [700]           | 此作業不允許檔案繳交         |
        | 必填文字的作業只帶附件   | 402          |            | [700]           | 此作業需填寫文字內容         |
        | 必填檔案的作業只帶文字   | 401          | 如附件     | []              | 此作業需上傳檔案             |
        | 附件數超過 max_files     | 401          | 如附件     | [700, 701, 702] | 附件數量超過上限（最多 2 個） |

  Rule: 前置（參數）- 文字內容不可超過 50000 字

    Example: 文字內容超過 50000 字時繳交失敗
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body      | attachment_ids |
        | {字元 x 50001} | []             |
      Then 操作失敗，錯誤為「文字內容不可超過 50000 字」

  Rule: 前置（參數）- 每個附件都必須存在且為學員本人上傳

    Scenario Outline: 帶入<情境>的附件時繳交失敗
      When 用戶 "Alice" 繳交作業 401，內容如下：
        | text_body | attachment_ids   |
        | 如附件    | <attachment_ids> |
      Then 操作失敗，錯誤為「附件不存在或無權使用」

      Examples:
        | 情境       | attachment_ids |
        | 不存在     | [9999]         |
        | 他人上傳   | [710]          |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 首次繳交建立待批改的繳交紀錄

    Example: Alice 首次繳交練習二
      When 用戶 "Alice" 繳交作業 401，內容如下：
        | text_body | attachment_ids |
        | 如附件    | [700, 701]     |
      Then 操作成功
      And 用戶 "Alice" 在作業 401 的繳交應為：
        | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 如附件    | [700, 701]     | pending |          | 2026-09-17 10:00:00 |           |           |

  Rule: 後置（狀態）- 已有繳交時（不論狀態）覆寫同一筆，狀態重設為待批改並清空評語、批改者與批改時間

    Scenario Outline: 前一次狀態為 <前次狀態> 時重交
      Given 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status     | feedback   | submitted_at        | graded_by | graded_at           |
        | 500          | 400          | 2      | 舊答案    | []             | <前次狀態> | <前次評語> | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body | attachment_ids |
        | 新答案    | []             |
      Then 操作成功
      And 作業繳交 500 應為：
        | text_body | status  | feedback | submitted_at        | graded_by | graded_at |
        | 新答案    | pending |          | 2026-09-17 10:00:00 |           |           |
      And 用戶 "Alice" 在作業 400 的繳交筆數應為 1

      Examples:
        | 前次狀態 | 前次評語       |
        | fail     | 請補充型別說明 |
        | pass     | 寫得很好       |
        | pending  |                |

  Rule: 後置（狀態）- 超過截止時間仍可繳交

    Example: 截止後繳交練習二仍成功
      Given 現在時間為 "2026-09-20 10:00:00"
      When 用戶 "Alice" 繳交作業 401，內容如下：
        | text_body | attachment_ids |
        | 補交      | [700]          |
      Then 操作成功
      And 用戶 "Alice" 在作業 401 的繳交應為：
        | status  | submitted_at        |
        | pending | 2026-09-20 10:00:00 |

  Rule: 後置（狀態）- 繳交不改變章節完成狀態

    Example: 繳交後章節仍未完成
      Given 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作成功
      And 用戶 "Alice" 在章節 201 的 finished_at 應為空

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳繳交紀錄

    Example: 繳交成功後回應繳交資料
      When 用戶 "Alice" 繳交作業 400，內容如下：
        | text_body  | attachment_ids |
        | let x = 1; | []             |
      Then 操作成功
      And 回應應包含：
        | assignment_id | user_id | text_body  | status  | submitted_at        |
        | 400           | 2       | let x = 1; | pending | 2026-09-17 10:00:00 |
