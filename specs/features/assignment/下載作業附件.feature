@query
Feature: 下載作業附件

  學員下載自己上傳的附件；管理員下載出現在學員繳交中的附件（批改用）。檔案由伺服器驗權後串流。

  業務依據：zenbu-site-2.0 `GET admin/course-assignments/attachments/:mediaId/url`
  （管理員：附件須出現在任一繳交，否則「找不到對應的作業附件」；媒體不存在「附件不存在」）
  與 `GET me/course-assignments/attachments/:mediaId/url`（學員：防 IDOR，否則「無權存取此附件」）
  偏離（Q4 使用者決策）：參考實作回傳公開 URL；Power Course 驗權後由伺服器串流，學員以「上傳者本人」判定歸屬

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
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 0          | publish     |
    And 系統中有以下作業附件：
      | attachmentId | userId | original_name | mime_type                                                               | file_size |
      | 700          | 2      | 作業截圖.png  | image/png                                                               | 20480     |
      | 701          | 3      | Bob 報告.pdf  | application/pdf                                                         | 40960     |
      | 702          | 2      | 草稿.docx     | application/vnd.openxmlformats-officedocument.wordprocessingml.document | 8192      |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
      | 500          | 401          | 2      | 如附件    | [700]          | pending |          | 2026-09-16 09:00:00 |           |           |
      | 501          | 401          | 3      | 如附件    | [701]          | pending |          | 2026-09-16 10:00:00 |           |           |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客下載附件時操作失敗
      When 未登入訪客下載作業附件 700
      Then 操作失敗，錯誤為「必須登入」

  Rule: 前置（狀態）- 附件紀錄與實體檔案都必須存在

    Example: 附件紀錄不存在時操作失敗
      When 用戶 "Alice" 下載作業附件 9999
      Then 操作失敗，錯誤為「附件不存在」

    Example: 實體檔案已不存在時操作失敗
      Given 作業附件 700 的實體檔案已不存在
      When 用戶 "Alice" 下載作業附件 700
      Then 操作失敗，錯誤為「附件不存在」

  Rule: 前置（狀態）- 學員只能下載自己上傳的附件

    Example: Alice 下載 Bob 的附件時操作失敗
      When 用戶 "Alice" 下載作業附件 701
      Then 操作失敗，錯誤為「無權存取此附件」

  Rule: 前置（狀態）- 管理員只能下載已出現在任一學員繳交中的附件

    Example: 管理員下載尚未被繳交使用的附件時操作失敗
      When 管理員 "Admin" 下載作業附件 702
      Then 操作失敗，錯誤為「找不到對應的作業附件」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 驗權通過後由伺服器串流檔案，以原始檔名下載

    Example: Alice 下載自己已繳交的附件
      When 用戶 "Alice" 下載作業附件 700
      Then 操作成功
      And 回應應為檔案串流：
        | file_name    | mime_type |
        | 作業截圖.png | image/png |

    Example: Alice 下載自己尚未繳交使用的附件
      When 用戶 "Alice" 下載作業附件 702
      Then 操作成功
      And 回應應為檔案串流：
        | file_name | mime_type                                                               |
        | 草稿.docx | application/vnd.openxmlformats-officedocument.wordprocessingml.document |

    Example: 管理員下載 Bob 繳交中的附件
      When 管理員 "Admin" 下載作業附件 701
      Then 操作成功
      And 回應應為檔案串流：
        | file_name    | mime_type       |
        | Bob 報告.pdf | application/pdf |

  Rule: 後置（回應）- 回應帶禁止快取標頭

    Example: 下載回應禁止快取
      When 用戶 "Alice" 下載作業附件 700
      Then 操作成功
      And 回應標頭 Cache-Control 應包含 "no-cache"
