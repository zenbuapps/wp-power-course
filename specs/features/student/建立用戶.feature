@ignore @command
Feature: 建立用戶

  管理員可透過 /users 端點直接建立 WordPress 用戶（不一定是學員），
  並同時寫入 user_meta。此端點用於後台「學員管理」頁面手動新增學員前先建立 WP 用戶。

  **Code source:** `inc/classes/Api/User.php::post_users_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |

  # ========== Happy Path ==========

  Rule: 成功建立用戶並分離 core fields 與 user_meta

    Example: 建立新學員
      When 管理員 "Admin" 呼叫 POST /users（form-data），參數如下：
        | user_login | user_email       | user_pass  | display_name | custom_field |
        | alice      | alice@test.com   | Secret1234 | Alice Chen   | VIP          |
      Then 操作成功
      And 回應 code 為 "post_user_success"
      And 回應 data.id 為新用戶的 userId 字串
      And WordPress 新增一筆 user，user_login=alice、user_email=alice@test.com
      And user_meta 寫入 custom_field=VIP

  # ========== 失敗 ==========

  Rule: wp_insert_user 回傳 WP_Error 時回報失敗

    Example: user_login 已存在
      Given 系統已有用戶 user_login="alice"
      When 管理員 "Admin" 呼叫 POST /users，參數如下：
        | user_login | user_email        |
        | alice      | alice2@test.com   |
      Then 操作失敗
      And 回應 code 為 "create_user_error"
      And 回應 HTTP 狀態為 400
      And 回應 message 為 WP_Error 的錯誤訊息
