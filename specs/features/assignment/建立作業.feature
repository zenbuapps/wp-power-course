@command
Feature: 建立作業

  管理員在課程的章節下建立作業。作業為 CPT `pc_assignment`，`post_parent` 指向所屬章節（`pc_chapter`，任意層級），
  一個章節可有多份作業。

  業務依據：zenbu-site-2.0 `course-assignments.service.ts::create` + `dto/assignment.dto.ts::CreateAssignmentDto`
  架構對應：參考實作以 lesson_id XOR chapter_id 掛父實體，Power Course 只有 pc_chapter；dueAt ISO 8601 → due_at 10 位 timestamp

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

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）

    Example: 非管理員建立作業時操作失敗
      When 用戶 "Alice" 呼叫 POST /assignments
      Then 操作失敗，錯誤為「權限不足」

  Rule: 前置（狀態）- 章節必須存在

    Example: 章節不存在時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            |
        | 9999       | 練習一：變數宣告 |
      Then 操作失敗，錯誤為「章節不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- chapter_id 為必填

    Example: 未提供 chapter_id 時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | title            |
        | 練習一：變數宣告 |
      Then 操作失敗，錯誤為「chapter_id 為必填」

  Rule: 前置（參數）- title 長度必須為 1 至 200 字

    Scenario Outline: title 為 <情境> 時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title   |
        | 201        | <title> |
      Then 操作失敗，錯誤為「title 長度必須為 1 至 200 字」

      Examples:
        | 情境       | title           |
        | 空字串     |                 |
        | 超過 200 字 | {字元 x 201}    |

  Rule: 前置（參數）- instructions 不可超過 20000 字

    Example: 作業說明超過 20000 字時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | instructions   |
        | 201        | 練習一：變數宣告 | {字元 x 20001} |
      Then 操作失敗，錯誤為「instructions 不可超過 20000 字」

  Rule: 前置（參數）- allow_text 與 allow_file 至少一個為 yes

    Example: 文字與檔案繳交皆關閉時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | allow_text | allow_file |
        | 201        | 練習一：變數宣告 | no         | no         |
      Then 操作失敗，錯誤為「至少需允許文字或檔案其中一種繳交方式」

  Rule: 前置（參數）- max_files 必須為 1 至 20 的整數

    Scenario Outline: max_files 為 <max_files> 時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | allow_file | max_files   |
        | 201        | 練習二：上傳截圖 | yes        | <max_files> |
      Then 操作失敗，錯誤為「max_files 必須為 1 至 20 的整數」

      Examples:
        | max_files |
        | 0         |
        | 21        |

  Rule: 前置（參數）- due_at 必須為 0 或 10 位 Unix timestamp

    Example: due_at 不是 10 位 timestamp 時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | due_at |
        | 201        | 練習一：變數宣告 | 12345  |
      Then 操作失敗，錯誤為「due_at 必須為 0 或 10 位 Unix timestamp」

  Rule: 前置（參數）- menu_order 必須為大於等於 0 的整數

    Example: menu_order 為負數時建立失敗
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | menu_order |
        | 201        | 練習一：變數宣告 | -1         |
      Then 操作失敗，錯誤為「menu_order 必須為大於等於 0 的整數」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 只提供必填欄位時建立 publish 作業並套用預設值

    Example: 只給章節與標題時套用預設題型設定
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            |
        | 201        | 練習一：變數宣告 |
      Then 操作成功
      And 新建作業應為：
        | post_parent | parent_course_id | title            | allow_text | allow_file | required_text | required_file | max_files | due_at | menu_order | post_status |
        | 201         | 100              | 練習一：變數宣告 | yes        | no         | no            | no            | 1         | 0      | 0          | publish     |

  Rule: 後置（狀態）- 提供的說明、題型設定、截止時間與排序照實寫入

    Example: 建立需上傳檔案且有截止時間的作業
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            | instructions         | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order |
        | 201        | 練習二：上傳截圖 | <p>請上傳執行結果</p> | yes        | yes        | no            | yes           | 2         | 1789747140 | 10         |
      Then 操作成功
      And 新建作業應為：
        | post_parent | parent_course_id | title            | instructions         | allow_text | allow_file | required_text | required_file | max_files | due_at     | menu_order | post_status |
        | 201         | 100              | 練習二：上傳截圖 | <p>請上傳執行結果</p> | yes        | yes        | no            | yes           | 2         | 1789747140 | 10         | publish     |

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳新建作業的完整設定

    Example: 建立成功後回應含作業 id 與所屬課程
      When 管理員 "Admin" 建立作業，參數如下：
        | chapter_id | title            |
        | 201        | 練習一：變數宣告 |
      Then 操作成功
      And 回應應包含：
        | chapter_id | course_id | title            | allow_text | allow_file | max_files | due_at | menu_order |
        | 201        | 100       | 練習一：變數宣告 | yes        | no         | 1         | 0      | 0          |
