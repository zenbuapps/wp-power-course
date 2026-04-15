@ignore @command
Feature: 更新用戶資料

  管理員可透過 /users/{id} 端點修改既有 WordPress 用戶的 core fields 與 user_meta，
  並可附加檔案（如頭像）。表單以 form-data 送出。

  **Code source:** `inc/classes/Api/User.php::post_users_with_id_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          | user_email         |
      | 1      | Admin | administrator | admin@test.com     |
      | 10     | Alice | customer      | alice@test.com     |

  # ========== Happy Path ==========

  Rule: 成功更新用戶資料

    Example: 修改 Alice 的 display_name 與 email
      When 管理員 "Admin" 呼叫 POST /users/10，參數如下：
        | display_name | user_email              |
        | Alice Wang   | alice.wang@example.com  |
      Then 操作成功
      And 回應 code 為 "post_user_success"
      And 回應 message 為 "修改成功"
      And 回應 data.id 為 "10"
      And Alice 的 display_name 更新為 "Alice Wang"
      And Alice 的 user_email 更新為 "alice.wang@example.com"

  Rule: user_meta 會與 core fields 分離後分別儲存

    Example: 同時更新 user_meta
      When 管理員 "Admin" 呼叫 POST /users/10，參數如下：
        | display_name | vip_level | custom_note |
        | Alice Wang   | gold      | 客戶A       |
      Then Alice 的 user_meta vip_level 為 "gold"
      And Alice 的 user_meta custom_note 為 "客戶A"

  Rule: id 不會被寫入 user_meta

    Example: 防止污染 meta
      When 管理員 "Admin" 呼叫 POST /users/10，參數如下：
        | display_name | id |
        | Alice Wang   | 10 |
      Then Alice 的 user_meta 不包含 key="id"（因後端 unset 掉）

  # ========== 失敗 ==========

  Rule: wp_update_user 失敗時回應 HTTP 400

    Example: user_email 格式錯誤
      When 管理員 "Admin" 呼叫 POST /users/10，參數如下：
        | user_email |
        | not-an-email |
      Then 回應 code 為 "post_user_error"
      And 回應 HTTP 狀態為 400
