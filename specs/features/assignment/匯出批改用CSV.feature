@query
Feature: 匯出批改用CSV

  管理員匯出含 submission_id 的「可往返」CSV，修改「狀態」「講師回饋」欄後以「匯入批改結果CSV」回寫。（實作第一波）

  業務依據：zenbu-site-2.0 `GET admin/course-assignments/submissions/export.csv` + `course-submission-csv.service.ts::serializeRoundTripCsv`
  （欄位：submission_id / 學員 Email / 學員姓名 / 作業 / 繳交時間 / 目前狀態 / 狀態(可改 pending/pass/fail) / 講師回饋(可改)；
    篩選課程與狀態，狀態非法值視同 all；檔名 submissions-grades-{UTC 時間戳}.csv）
  修正：參考實作傳入上限 5000 筆，但列表服務內部把上限夾到 200；本規格依原意以 5000 筆為上限
  架構對應：課程以 course_id 篩選（參考實作為 courseSlug）；垃圾桶中作業的繳交不匯出

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
      | 3      | Bob   | bob@test.com   | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 入門課  | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 201       | 1-1 變數   | 100         |
    And 課程 101 有以下章節：
      | chapterId | post_title | post_parent |
      | 300       | JS 第一章  | 101         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0      | 10         | trash       |
      | 420          | 300       | JS 練習          | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
    And 系統中有以下作業繳交：
      | submissionId | assignmentId | userId | text_body    | attachment_ids | status  | feedback | submitted_at        | graded_by | graded_at           |
      | 500          | 400          | 2      | let x = 1;   | []             | pending |          | 2026-09-16 09:00:00 |           |                     |
      | 501          | 400          | 3      | const y = 2; | []             | pass    | 寫得很好 | 2026-09-16 15:30:00 | 1         | 2026-09-17 09:30:00 |
      | 503          | 420          | 2      | let z = 3;   | []             | fail    | 再補充   | 2026-09-17 09:30:00 | 1         | 2026-09-17 09:45:00 |
      | 504          | 402          | 3      | 舊作業       | []             | pending |          | 2026-09-17 09:00:00 |           |                     |
    And 現在時間為 "2026-09-17 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員匯出時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignment-submissions/export
      Then 操作失敗，錯誤為「權限不足」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- status 不是 all、pending、pass、fail 時視同 all

    Example: status 為非法值時匯出全部
      When 管理員 "Admin" 匯出批改用 CSV，參數如下：
        | status |
        | graded |
      Then 操作成功
      And CSV 的 submission_id 依序應為 [503, 501, 500]

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 未篩選時依繳交時間由新到舊匯出全部繳交，不含垃圾桶中作業的繳交

    Example: 匯出全部繳交
      When 管理員 "Admin" 匯出批改用 CSV，參數如下：
        | course_id | status |
        |           | all    |
      Then 操作成功
      And CSV 內容應為：
        | submission_id | 學員 Email     | 學員姓名 | 作業             | 繳交時間                 | 目前狀態 | 狀態(可改 pending/pass/fail) | 講師回饋(可改) |
        | 503           | alice@test.com | Alice    | JS 練習          | 2026-09-17T01:30:00.000Z | 未通過   | fail                         | 再補充         |
        | 501           | bob@test.com   | Bob      | 練習一：變數宣告 | 2026-09-16T07:30:00.000Z | 通過     | pass                         | 寫得很好       |
        | 500           | alice@test.com | Alice    | 練習一：變數宣告 | 2026-09-16T01:00:00.000Z | 待批改   | pending                      |                |
      And CSV 應以 UTF-8 BOM 開頭且換行為 CRLF

  Rule: 後置（回應）- 依課程與狀態篩選

    Scenario Outline: course_id 為 <course_id>、status 為 <status>
      When 管理員 "Admin" 匯出批改用 CSV，參數如下：
        | course_id   | status   |
        | <course_id> | <status> |
      Then 操作成功
      And CSV 的 submission_id 依序應為 <ids>

      Examples:
        | course_id | status  | ids        |
        | 100       | all     | [501, 500] |
        |           | pending | [500]      |
        | 100       | pass    | [501]      |

  Rule: 後置（回應）- 下載檔名為 submissions-grades-{UTC 時間戳}.csv

    Example: 匯出檔名
      When 管理員 "Admin" 匯出批改用 CSV，參數如下：
        | status |
        | all    |
      Then 操作成功
      And 下載檔名應為 "submissions-grades-2026-09-17-02-00-00.csv"

  Rule: 後置（回應）- 最多匯出 5000 筆

    Example: 有 5001 筆繳交時只匯出 5000 筆
      Given 作業 400 有 5001 筆繳交
      When 管理員 "Admin" 匯出批改用 CSV，參數如下：
        | status |
        | all    |
      Then 操作成功
      And CSV 資料列數應為 5000
