@command
Feature: 排序作業

  管理員以拖拉方式調整同一章節內作業的顯示順序。

  業務依據：zenbu-site-2.0 `course-assignments.service.ts::reorder` + `ReorderAssignmentsDto`
  （ids ≤ 200；全部 id 必須存在且屬於指定範圍；sort_order 以 (index + 1) × 10 寫入；空陣列直接回傳列表）

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
      | assignmentId | chapterId | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
      | 400          | 201       | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |
      | 401          | 201       | 練習二：上傳截圖 | yes        | yes        | no            | yes           | 2         | 0      | 0          | publish     |
      | 410          | 200       | 第一章總複習     | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員排序作業時操作失敗
      When 用戶 "Alice" 呼叫 POST /assignments/sort
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- ids 中每一個 id 都必須對應到存在的作業且不可重複

    Scenario Outline: ids 為 <ids> 時操作失敗
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 <ids>
      Then 操作失敗，錯誤為「部分作業不存在」

      Examples:
        | ids         |
        | [400, 9999] |
        | [400, 400]  |

  Rule: 前置（狀態）- ids 中每一份作業都必須屬於指定的章節

    Example: ids 混入其他章節的作業時操作失敗
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 [400, 410]
      Then 操作失敗，錯誤為「作業不屬於指定的章節」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- chapter_id 為必填

    Example: 未指定章節時操作失敗
      When 用戶 "Admin" 呼叫 POST /assignments/sort
      Then 操作失敗，錯誤為「chapter_id 為必填」

  Rule: 前置（參數）- ids 最多 200 筆

    Example: ids 超過 200 筆時操作失敗
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 {201 個作業 id}
      Then 操作失敗，錯誤為「ids 最多 200 筆」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 依 ids 先後順序以 10 為間隔寫入 menu_order

    Example: 將練習二排到練習一之前
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 [401, 400]
      Then 操作成功
      And 作業 401 應為：
        | menu_order |
        | 10         |
      And 作業 400 應為：
        | menu_order |
        | 20         |

  Rule: 後置（狀態）- ids 為空陣列時不變更任何排序

    Example: 空陣列不改變排序
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 []
      Then 操作成功
      And 作業 400 應為：
        | menu_order |
        | 0          |
      And 作業 401 應為：
        | menu_order |
        | 0          |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳該章節排序後的作業列表

    Example: 排序後回傳新的順序
      When 管理員 "Admin" 排序章節 201 的作業，ids 為 [401, 400]
      Then 操作成功
      And 查詢結果應為：
        | assignmentId | title            | menu_order |
        | 401          | 練習二：上傳截圖 | 10         |
        | 400          | 練習一：變數宣告 | 20         |
