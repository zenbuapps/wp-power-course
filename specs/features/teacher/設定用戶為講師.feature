@ignore @command
Feature: 設定用戶為講師

  管理員可將既有 WordPress 用戶批次升級為講師身分，供後續在課程編輯頁指派為該課程的講師。
  講師身分以 user_meta `is_teacher = yes` 標記。

  **Code source:** `inc/classes/Api/User.php::post_users_add_teachers_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name     | role          | is_teacher |
      | 1      | Admin    | administrator |            |
      | 20     | Carol    | customer      |            |
      | 21     | David    | customer      |            |
      | 22     | Eve      | customer      | yes        |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- user_ids 為必填

    Example: 未提供 user_ids 時失敗
      When 管理員 "Admin" 呼叫 POST /users/add-teachers，參數為空
      Then 後端 user_ids 為空陣列，foreach 不執行
      And 回應 code 為 "update_users_to_teachers_success"（代碼當前不驗必填）
      And 回應 data.user_ids 為空字串

  # ========== Happy Path ==========

  Rule: 批次將用戶升級為講師

    Example: 將 Carol 與 David 設為講師
      When 管理員 "Admin" 呼叫 POST /users/add-teachers，參數如下：
        | user_ids  |
        | [20, 21]  |
      Then 操作成功
      And 回應 code 為 "update_users_to_teachers_success"
      And 回應 message 為 "批次將用戶轉為講師成功"
      And Carol (userId=20) 的 user_meta is_teacher 為 "yes"
      And David (userId=21) 的 user_meta is_teacher 為 "yes"

  Rule: 對既有講師重複設定不影響狀態

    Example: Eve 已經是講師
      When 管理員 "Admin" 呼叫 POST /users/add-teachers，參數如下：
        | user_ids |
        | [22]     |
      Then 操作成功
      And Eve 的 user_meta is_teacher 仍為 "yes"

  # ========== 後置效果 ==========

  Rule: 升級為講師後，用戶可被指派到課程

    Example: 指派 Carol 為課程 100 的講師
      Given Carol 已被設為講師（is_teacher=yes）
      When 管理員將 Carol 指派到課程 100 的 teacher_ids
      Then 課程 100 的 teacher_ids 包含 20
      And Carol 會出現在講師列表（features/teacher/指派講師到課程.feature）
