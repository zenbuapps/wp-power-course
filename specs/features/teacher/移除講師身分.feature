@ignore @command
Feature: 移除講師身分

  管理員可將既有講師降回一般用戶，透過刪除 user_meta `is_teacher` 實現。

  **Code source:** `inc/classes/Api/User.php::post_users_remove_teachers_callback`

  **注意：** 目前實作採 early-break（任一筆失敗即中斷並回報失敗），這不是一般 batch API 的行為。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          | is_teacher |
      | 1      | Admin | administrator |            |
      | 20     | Carol | customer      | yes        |
      | 21     | David | customer      | yes        |

  # ========== Happy Path ==========

  Rule: 批次移除講師身分

    Example: 將 Carol 與 David 降回一般用戶
      When 管理員 "Admin" 呼叫 POST /users/remove-teachers，參數如下：
        | user_ids  |
        | [20, 21]  |
      Then 操作成功
      And 回應 code 為 "remove_teachers_success"
      And Carol 的 user_meta is_teacher 不存在
      And David 的 user_meta is_teacher 不存在

  # ========== Edge Case ==========

  Rule: 對非講師執行移除操作會回報失敗（因 delete_user_meta 回傳 false）

    Example: Eve 未曾為講師
      Given 用戶 Eve (userId=22) 從未設過 is_teacher meta
      When 管理員 "Admin" 呼叫 POST /users/remove-teachers，參數如下：
        | user_ids |
        | [22]     |
      Then delete_user_meta 回傳 false
      And 回應 code 為 "remove_teachers_failed"
      And 回應 HTTP 狀態為 400

  Rule: 批次中任一筆失敗會中斷後續處理（early-break）

    Example: David 未曾為講師，在 Carol 之後
      Given Carol (userId=20) 的 is_teacher 為 "yes"
      And David (userId=21) 從未設過 is_teacher
      When 管理員 "Admin" 呼叫 POST /users/remove-teachers，參數如下：
        | user_ids  |
        | [20, 21]  |
      Then Carol 的 is_teacher 被成功刪除（先處理）
      And David 處理時 delete_user_meta 回傳 false
      And 迴圈 break，未處理後續用戶
      And 整體回應 code 為 "remove_teachers_failed"
      And 回應 HTTP 狀態為 400
      And 此為已知行為，若未來要改為 partial-success batch，需同步更新此 feature

  # ========== 後置效果 ==========

  Rule: 移除講師身分後，已經綁定的課程 teacher_ids 不自動清除

    Example: 已被指派為課程講師的用戶被移除講師身分
      Given Carol 被指派為課程 100 的講師（teacher_ids 包含 20）
      When 管理員執行移除講師身分
      Then Carol 的 is_teacher meta 被刪除
      And 課程 100 的 teacher_ids 仍然包含 20
      And 需要管理員另外透過 PUT /courses/{id}/teachers 清理
