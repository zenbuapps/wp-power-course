@query
Feature: 查詢章節作業列表

  管理員查詢章節或整門課程的作業設定（課程編輯頁作業管理、管理員教室預覽使用），或查詢單一作業。

  業務依據：zenbu-site-2.0 `GET admin/course-assignments/by-lesson/:id`、`by-chapter/:id`、`by-course/:slug`、`:id`
  （排序 sortOrder ASC、createdAt ASC）
  架構對應：Power Course 只有 pc_chapter，依課程查詢時以章節攤平順序排列；垃圾桶中的作業視為不存在

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
      | 2      | Alice | alice@test.com | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 200       | 第一章     | 100         |
      | 201       | 1-1 變數   | 200         |
    And 系統中有以下作業：
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0          | 10         | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 1789747140 | 0          | publish     |
      | 402          | 201       | 已刪除練習       | yes        | no         | no            | no            | 1         | 0          | 5          | trash       |
      | 410          | 200       | 第一章總複習     | yes        | no         | no            | no            | 1         | 0          | 0          | publish     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員查詢作業列表時操作失敗
      When 用戶 "Alice" 呼叫 GET /assignments
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 查詢單一作業時作業必須存在，在垃圾桶中的作業視為不存在

    Scenario Outline: 查詢<情境>的作業時操作失敗
      When 管理員 "Admin" 查詢作業 <assignmentId>
      Then 操作失敗，錯誤為「作業不存在」

      Examples:
        | 情境       | assignmentId |
        | 不存在     | 9999         |
        | 已在垃圾桶 | 402          |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 查詢列表時 chapter_id 與 course_id 至少需指定一個

    Example: 未指定章節或課程時操作失敗
      When 管理員 "Admin" 查詢作業列表，參數如下：
        | chapter_id | course_id |
        |            |           |
      Then 操作失敗，錯誤為「chapter_id 或 course_id 至少需指定一個」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 以 chapter_id 查詢時回傳該章節作業，依 menu_order ASC 排序且不含垃圾桶中的作業

    Example: 查詢章節 201 的作業
      When 管理員 "Admin" 查詢作業列表，參數如下：
        | chapter_id |
        | 201        |
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | chapter_id | title            | menu_order |
        | 401          | 201        | 練習二：上傳截圖 | 0          |
        | 400          | 201        | 練習一：變數宣告 | 10         |

  Rule: 後置（回應）- 以 course_id 查詢時回傳整門課程作業，先依章節攤平順序再依 menu_order 排序

    Example: 查詢課程 100 的全部作業
      When 管理員 "Admin" 查詢作業列表，參數如下：
        | course_id |
        | 100       |
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | chapter_id | title            |
        | 410          | 200        | 第一章總複習     |
        | 401          | 201        | 練習二：上傳截圖 |
        | 400          | 201        | 練習一：變數宣告 |

  Rule: 後置（回應）- 以作業 id 查詢時回傳該作業完整設定

    Example: 查詢作業 401
      When 管理員 "Admin" 查詢作業 401
      Then 操作成功
      And 回應應包含：
        | id  | chapter_id | course_id | title            | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order |
        | 401 | 201        | 100       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 1789747140 | 0          |
