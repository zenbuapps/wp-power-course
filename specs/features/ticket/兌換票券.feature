@ignore @command
Feature: 兌換票券

  # 任何已登入用戶輸入票券代碼即可兌換，兌換 = 依 grant_payload 快照「重放」既有訂單授權路徑，收件人換成兌換者。
  # 端點：POST /power-course/tickets/redeem
  #
  # 流程（架構定案 五）—— 整段包 transaction：
  #   1. SELECT ... FOR UPDATE 鎖列（坑 3：兩人同時貼同一組碼會雙兌換）
  #   2. 驗 status = pending 且未過期（v1 expires_at 恆為 null）
  #   3. 驗兌換者未持有 payload 中的課程 / pass → 已持有則拒絕、票不消耗
  #   4. 依 payload 重放授權：
  #        course_ids       → LifeCycle::add_student_to_course()，expire_date 用 Limit::calc_expire_date()，以兌換時刻為起點
  #        access_pass_id   → AccessPass\Service\Grant::grant()
  #   5. 更新票 status=redeemed / redeemer_id / redeemed_at
  #   6. 寫 StudentLog（log_type=TICKET_REDEEMED）+ Gate::flush_cache(user_id)
  #
  # 代碼比對不區分大小寫（統一轉大寫）。兌換 API 有 rate limit：同 user/IP 每小時 10 次失敗即鎖。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role       |
      | 2      | Alice   | alice@test.com   | subscriber |
      | 3      | Bob     | bob@test.com     | subscriber |
      | 4      | Charlie | charlie@test.com | subscriber |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 系統中有以下票券：
      | code            | course_ids | purchaser_id | redeemer_id | status   | limit_type | limit_value | limit_unit |
      | PC-A1B2C3D4E5F6 | [100]      | 2            |             | pending  | fixed      | 30          | day        |
      | PC-USEDTICKET01 | [100]      | 2            | 3           | redeemed | fixed      | 30          | day        |
      | PC-VOIDTICKET01 | [100]      | 2            |             | voided   | fixed      | 30          | day        |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 未登入用戶不可兌換，導向登入並保留已輸入代碼

    Example: 訪客兌換時被導向登入頁並帶回代碼
      Given 使用者未登入
      When 訪客提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 系統要求先登入
      And 登入後兌換頁應預填代碼 "PC-A1B2C3D4E5F6"

  Rule: 前置（狀態）- 代碼不存在或格式錯誤時提示無效

    Example: 輸入系統中不存在的代碼
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "INVALID-CODE"
      Then 操作失敗，錯誤為「兌換碼無效，請確認輸入是否正確」

  Rule: 前置（狀態）- 已被兌換的代碼再次輸入時提示已使用，票不再消耗

    Example: 輸入已被 Bob 兌換過的代碼
      Given 用戶 "Charlie" 已登入
      When 用戶 "Charlie" 提交兌換代碼 "PC-USEDTICKET01"
      Then 操作失敗，錯誤為「此兌換碼已被使用」
      And 用戶 "Charlie" 的 avl_course_ids 應不包含課程 100

  Rule: 前置（狀態）- 已作廢的代碼無法兌換

    Example: 輸入已作廢的代碼
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "PC-VOIDTICKET01"
      Then 操作失敗，錯誤為「此兌換碼已失效」

  Rule: 前置（狀態）- 兌換者已擁有票券對應課程時拒絕兌換且票不消耗

    Example: Bob 已擁有課程 100 時兌換對應票券
      Given 用戶 "Bob" 已登入
      And 用戶 "Bob" 已擁有課程 100 的存取權
      When 用戶 "Bob" 提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 操作失敗，錯誤為「您已擁有此課程的存取權」
      And 票券 "PC-A1B2C3D4E5F6" 的 status 應維持 "pending"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 兌換成功後兌換者取得課程存取權，票券標記為已兌換

    Example: Bob 成功兌換 PC-A1B2C3D4E5F6
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 操作成功
      And 用戶 "Bob" 的 avl_course_ids 應包含課程 100
      And 票券 "PC-A1B2C3D4E5F6" 的 status 應為 "redeemed"
      And 票券 "PC-A1B2C3D4E5F6" 的 redeemer_id 應為 3
      And 票券 "PC-A1B2C3D4E5F6" 應記錄 redeemed_at 時間

  Rule: 後置（狀態）- 代碼比對不區分大小寫

    Example: 以小寫輸入代碼仍可兌換
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "pc-a1b2c3d4e5f6"
      Then 操作成功
      And 票券 "PC-A1B2C3D4E5F6" 的 status 應為 "redeemed"

  Rule: 後置（狀態）- 課程到期日以兌換時刻為起點計算（對兌換者更公平）

    Example: fixed 30 天票券以兌換時間起算到期
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 課程 100 對用戶 "Bob" 的 coursemeta expire_date 應為兌換後 30 天的 timestamp

  Rule: 後置（狀態）- 兌換成功後寫入 TICKET_REDEEMED 學員活動日誌

    Example: 兌換後產生活動日誌
      Given 用戶 "Bob" 已登入
      When 用戶 "Bob" 提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 用戶 "Bob" 應有一筆 log_type 為 "ticket_redeemed" 的學員活動日誌

  Rule: 後置（狀態）- 併發兌換同一代碼時僅一方成功（坑 3：FOR UPDATE 鎖列）

    Example: Bob 與 Charlie 同時兌換同一代碼
      Given 用戶 "Bob" 與用戶 "Charlie" 皆已登入
      When 用戶 "Bob" 與用戶 "Charlie" 同時提交兌換代碼 "PC-A1B2C3D4E5F6"
      Then 票券 "PC-A1B2C3D4E5F6" 僅被兌換一次
      And 僅有一位用戶取得課程 100 的存取權

  Rule: 後置（狀態）- 兌換 API 具頻率限制，同 user/IP 每小時 10 次失敗即鎖

    Example: 短時間內連續輸入錯誤代碼觸發鎖定
      Given 用戶 "Bob" 已登入
      And 用戶 "Bob" 於一小時內已有 10 次兌換失敗
      When 用戶 "Bob" 再次提交任一兌換代碼
      Then 操作失敗，錯誤為「嘗試次數過多，請稍後再試」
