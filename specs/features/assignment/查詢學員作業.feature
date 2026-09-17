@query
Feature: 查詢學員作業

  學員在教室查看某章節的作業、整門課程的作業總覽，或單一作業；每份作業附帶學員自己的繳交（無則為 null）。
  逾期標記、總覽統計（作業總數、已通過數、通過百分比）由前端依回傳資料計算，不在 API 回應中。

  業務依據：zenbu-site-2.0 `GET me/course-assignments/by-lesson/:id`、`by-chapter/:id`、`by-course/:slug`、`:id`
  （入口一律先驗報名；解析不到課程一律回「尚未報名此課程」，不洩漏作業是否存在；回傳 { assignment, mySubmission }）
  架構對應：報名判定 hasActiveAccess → CourseUtils::is_avl（含課程權限包）且未到期且課程已開課

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
      | 200       | 第一章     | 100         |
      | 201       | 1-1 變數   | 200         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0          | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 1789055940 | 10         | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0          | 20         | trash       |
      | 410          | 200       | 第一章總複習     | yes        | no         | no            | no            | 1         | 0          | 0          | publish     |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body  | attachment_ids | status | feedback | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1; | []             | pass   | 寫得很好 | 2026-09-16 09:00:00 | 1         | 2026-09-16 10:00:00 |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客查詢作業時操作失敗
      When 未登入訪客查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作失敗，錯誤為「必須登入」

  Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權（已購或持有涵蓋此課程的有效權限包、未到期、課程已開課）

    Example: 未加入課程的學員查詢時操作失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 課程觀看權已到期時操作失敗
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 1609459200
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 課程尚未開課時操作失敗
      Given 課程 100 的 course_schedule 為 1789869600
      And 現在時間為 "2026-09-17 10:00:00"
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作失敗，錯誤為「尚未報名此課程」

    Example: 持有全站課程權限包的學員可查詢
      Given 用戶 "Bob" 持有以下有效權限包：
        | passId | scope_type | limit_type |
        | 300    | all        | unlimited  |
      When 用戶 "Bob" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作成功

  Rule: 前置（狀態）- 查詢單一作業時，作業不存在或在垃圾桶一律回應「尚未報名此課程」，不洩漏作業是否存在

    Example: 查詢不存在的作業時回應尚未報名此課程
      When 用戶 "Alice" 查詢我的作業 9999
      Then 操作失敗，錯誤為「尚未報名此課程」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 查詢列表時 chapter_id 與 course_id 至少需指定一個

    Example: 未指定章節或課程時操作失敗
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | chapter_id | course_id |
        |            |           |
      Then 操作失敗，錯誤為「chapter_id 或 course_id 至少需指定一個」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 以 chapter_id 查詢時回傳該章節作業（依 menu_order 排序、不含垃圾桶中的作業）與學員自己的繳交

    Example: Alice 查詢章節 201 的作業
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | title            | due_at     | my_submission_status | my_submission_feedback |
        | 400          | 練習一：變數宣告 | 0          | pass                 | 寫得很好               |
        | 401          | 練習二：上傳截圖 | 1789055940 |                      |                        |

  Rule: 後置（回應）- 以 course_id 查詢時回傳整門課程作業，先依章節攤平順序再依 menu_order 排序

    Example: Alice 查詢課程 100 的作業總覽
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | course_id |
        | 100       |
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | title            | my_submission_status |
        | 410          | 第一章總複習     |                      |
        | 400          | 練習一：變數宣告 | pass                 |
        | 401          | 練習二：上傳截圖 |                      |

  Rule: 後置（回應）- 只回傳學員自己的繳交，不包含其他學員的繳交

    Example: Bob 已繳交練習二時 Alice 查詢結果仍顯示未繳交
      Given 用戶 "Bob" 已被加入課程 100，expire_date 0
      And 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 501          | 401          | 3      | Bob 的答案 | []            | pending |          | 2026-09-16 11:00:00 |           |           |
      When 用戶 "Alice" 查詢我的作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | my_submission_status |
        | 400          | pass                 |
        | 401          |                      |

  Rule: 後置（回應）- 查詢單一作業時回傳作業設定與學員自己的繳交，繳交附件只含 attachment_id 與 file_name

    Example: Alice 查詢已繳交附件的作業 401
      Given 系統中有以下作業附件：
        | attachmentId | userId | original_name | mime_type | file_size |
        | 700          | 2      | 作業截圖.png  | image/png | 20480     |
      And 系統中有以下作業繳交：
        | submissionId | assignmentId | userId | text_body | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at |
        | 502          | 401          | 2      | 如附件    | [700]          | pending |          | 2026-09-16 12:00:00 |           |           |
      When 用戶 "Alice" 查詢我的作業 401
      Then 操作成功
      And 回應應包含：
        | assignment.id | assignment.max_files | my_submission.id | my_submission.status | my_submission.attachments       |
        | 401           | 2                    | 502              | pending              | [{700, 作業截圖.png}]           |
