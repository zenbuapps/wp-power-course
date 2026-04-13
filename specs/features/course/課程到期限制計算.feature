@ignore @command
Feature: 課程到期限制計算

  每個課程商品都有 `limit_type` meta 決定學員購買後的觀看期限。
  Power Course 支援四種模式，透過 `Resources/Course/Limit.php` 的 `calc_expire_date()` 計算實際 expire_date，
  最終存入 `pc_avl_coursemeta.expire_date`（型別為 int timestamp 或字串 `"subscription_{id}"`）。

  | limit_type         | 說明 | 計算結果 |
  |--------------------|------|---------|
  | unlimited          | 無期限（預設） | 0（無限期） |
  | fixed              | 固定時間（從開通當下起算 N 天/月/年）| 當日 15:59 的 timestamp |
  | assigned           | 指定日期（固定到某個 Unix timestamp）| `limit_value` 原值 |
  | follow_subscription| 跟隨 WC Subscription 的 active 狀態 | 字串 `"subscription_{id}"` |

  **重要邊界（來自 code）:**
  - fixed 模式計算時使用 `strtotime("+{limit_value} {limit_unit}")`，單位限定為 day / month / year
  - fixed 模式的到期時間固定截至**當天 15:59:00**（非保留原時分秒）
  - assigned 模式 `limit_value` 為 10 位 Unix timestamp
  - follow_subscription 需要 WC_Subscription 類別存在，否則 fallback 為 0

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role     |
      | 10     | Alice | customer |
    And 目前時間為 "2026-04-13 10:30:00"

  # ========== unlimited ==========

  Rule: limit_type=unlimited 時，expire_date 為 0（無期限）

    Example: 無期限課程
      Given 課程 100 的 limit_type=unlimited
      When Alice 購買課程 100 並完成訂單
      Then pc_avl_coursemeta(course_id=100, user_id=10).expire_date 為 0
      And ExpireDate.is_expired 為 false
      And ExpireDate.expire_date_label 為 "無期限"

  # ========== fixed ==========

  Rule: limit_type=fixed 時，expire_date 為「開通日 + N 單位」當天的 15:59:00

    Example: 固定 30 天
      Given 課程 100 的 limit_type=fixed、limit_value=30、limit_unit=day
      When Alice 於 2026-04-13 10:30:00 購買並完成訂單
      Then expire_date 為 2026-05-13 15:59:00 對應的 Unix timestamp
      And ExpireDate.expire_date_label 為 "2026-05-13 15:59:00"

    Example: 固定 6 個月
      Given 課程 100 的 limit_type=fixed、limit_value=6、limit_unit=month
      When Alice 於 2026-04-13 10:30:00 購買並完成訂單
      Then expire_date 為 2026-10-13 15:59:00 對應的 Unix timestamp

    Example: 固定 1 年
      Given 課程 100 的 limit_type=fixed、limit_value=1、limit_unit=year
      When Alice 於 2026-04-13 10:30:00 購買並完成訂單
      Then expire_date 為 2027-04-13 15:59:00 對應的 Unix timestamp

  Rule: fixed 模式的 limit_value 或 limit_unit 必填，否則計算結果不保證正確

    Example: limit_value 為 0 時視為未設定
      Given 課程 100 的 limit_type=fixed、limit_value=0
      When 呼叫 Limit::__construct
      Then Limit.limit_value 為 null（內部 set_limit_value 對 !$limit_value 設 null）

  # ========== assigned ==========

  Rule: limit_type=assigned 時，expire_date 為 limit_value（10 位 Unix timestamp）

    Example: 指定到期日
      Given 課程 100 的 limit_type=assigned、limit_value=1767225599（2026-12-31 15:59:59 UTC）
      When Alice 於任何時間購買並完成訂單
      Then expire_date 為 1767225599
      And ExpireDate.expire_date_label 為 "2026-12-31 23:59:59"（依 WP 時區）

  Rule: assigned 模式下所有學員到期日相同（由站長固定設置）

    Example: 10 位學員同時購買同一課程
      Given 課程 100 的 limit_type=assigned、limit_value=1767225599
      When 10 位學員分別在不同時間購買並完成訂單
      Then 10 筆 pc_avl_coursemeta 的 expire_date 皆為 1767225599

  # ========== follow_subscription ==========

  Rule: limit_type=follow_subscription 時，expire_date 為 "subscription_{id}"

    Example: 訂閱商品的課程授權
      Given 課程 100 的 limit_type=follow_subscription、type=subscription
      And WC_Subscription 類別存在
      And Alice 購買的訂閱訂單 8001 對應唯一的 subscription 7001
      When 系統計算 expire_date
      Then Limit::calc_expire_date 透過 wcs_get_subscriptions_for_order 取得 subscription 7001
      And expire_date 為字串 "subscription_7001"

  Rule: 一張訂單對應到多個 subscription 時，follow_subscription 回傳 0

    Example: 多訂閱訂單
      Given 課程 100 的 limit_type=follow_subscription
      And Alice 購買訂單 8001 對應 2 個不同的 subscriptions
      When 系統計算 expire_date
      Then Limit::calc_expire_date 回傳 0（因 count(subscriptions) !== 1）

  Rule: WC_Subscription 類別不存在時，follow_subscription 降級為 0

    Example: 未安裝 Subscriptions 外掛
      Given WC_Subscription 類別不存在
      And 課程 100 的 limit_type=follow_subscription
      When 系統計算 expire_date
      Then Limit::calc_expire_date 回傳 0
      And WC Logger 寫入 "訂單 {id} 的 expire_date 計算失敗，因為 WC_Subscription 不存在"

  # ========== limit_type 切換行為（課程設定變更） ==========

  Rule: 課程變更 limit_type 不會追溯已開通的學員

    Example: 管理員把固定 30 天改成無限期
      Given 課程 100 原本 limit_type=fixed、limit_value=30、limit_unit=day
      And Alice 於 2026-04-13 已開通課程 100，expire_date 為 2026-05-13 15:59:00 的 timestamp
      When 管理員將課程 100 改為 limit_type=unlimited
      Then Alice 的 pc_avl_coursemeta.expire_date 保持為 2026-05-13 15:59:00 的 timestamp
      And 新開通的學員才會套用 unlimited 規則（expire_date=0）

  Rule: 後台直接透過「更新學員到期日」API 可覆寫單筆 expire_date

    Example: 管理員手動延長 Alice 的到期日
      Given Alice 的 expire_date 為 2026-05-13 15:59:00 的 timestamp
      When 管理員呼叫 PUT /students/expire-date（features/student/更新學員到期日.feature）
      And 傳入新的 expire_date 2027-05-13 15:59:00 的 timestamp
      Then Alice 的 pc_avl_coursemeta.expire_date 更新為新值
      And 觸發 AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION

  # ========== 驗證 ==========

  Rule: 非法 limit_type 字串會被 fallback 為 "unlimited"

    Example: 傳入未知的 limit_type
      When 呼叫 new Limit("unknown_type", null, null)
      Then Limit.limit_type 為 "unlimited"

  Rule: 非法 limit_unit 不會拋錯，僅寫入 WC Logger

    Example: 傳入 "hour"
      When 呼叫 new Limit("fixed", 5, "hour")
      Then WC Logger 寫入 "set_limit_unit Invalid limit unit"
      And Limit.limit_unit 仍保留為 "hour"（不 fallback）
