@ignore @event-driven @subscription
Feature: 訂閱整合課程授權

  當課程的 limit_type 為 "follow_subscription" 時，學員的課程存取權與 WooCommerce Subscription 的生命週期綁定。
  訂閱的狀態變更（active、on-hold、cancelled、expired、pending-cancel）會直接影響學員是否仍可觀看課程。

  **核心機制（來自 inc/classes/Resources/Course/Limit.php、ExpireDate.php、Resources/Order.php、Utils/Subscription.php）:**
  - `Limit::calc_expire_date()` 會回傳字串 `subscription_{id}`（而非 timestamp），存入 `pc_avl_coursemeta.expire_date`
  - `ExpireDate` 讀取該值後，以 `wcs_get_subscription($subscription_id)` 動態查詢訂閱狀態
  - `is_expired = !$subscription->has_status(['active', 'pending-cancel'])`
    → 僅 active 與 pending-cancel 視為有效，其他所有狀態（on-hold、cancelled、expired、pending、trash）皆視為過期
  - 訂閱相關訂單由 `woocommerce_subscription_payment_complete` hook 處理（而非 `woocommerce_new_order`），且只有 parent order 會觸發（續訂不重新授權）

  Background:
    Given WC_Subscription 類別存在（已安裝 WooCommerce Subscriptions）
    And 系統中有以下用戶：
      | userId | name  | role     |
      | 10     | Alice | customer |
    And 系統中有以下課程：
      | courseId | name        | _is_course | type         | limit_type          |
      | 100      | 年訂閱課程  | yes        | subscription | follow_subscription |

  # ========== Happy Path：訂閱啟用 ==========

  Rule: 訂閱首次付款完成後，開通課程權限並將 expire_date 設為 "subscription_{id}"

    Example: 學員購買訂閱並完成付款
      Given Alice 下了訂閱訂單 7001，parent_order_id = 8001
      And 訂閱 7001 購買了課程 100
      When WooCommerce 觸發 "woocommerce_subscription_payment_complete" action，參數為 subscription 7001
      And 該 subscription 的 parent order 為 8001，且 related_orders 只有 8001 一筆
      Then 系統呼叫 _handle_add_course_item_meta_by_order(8001)
      And 當訂單狀態變成開課觸發條件（settings.course_access_trigger）時，執行 add_meta_to_avl_course
      And Limit::calc_expire_date 回傳 "subscription_7001"
      And pc_avl_coursemeta 對 (course_id=100, user_id=10) 寫入 expire_date = "subscription_7001"
      And 觸發 CourseLifeCycle::ADD_STUDENT_TO_COURSE_ACTION
      And 寫入 StudentLog（log_type = order_created，content 指向訂單 8001）

  # ========== 續訂不重複開通 ==========

  Rule: 訂閱續訂訂單不重新開通課程權限

    Example: 訂閱續訂產生新訂單但不再觸發開通
      Given Alice 的訂閱 7001 已經成立並開通課程 100
      And 訂閱 7001 產生續訂訂單 8002
      When WooCommerce 觸發 "woocommerce_subscription_payment_complete"，參數為 subscription 7001
      Then 系統讀取 subscription 的 related_orders，發現有兩筆 (parent + renewal)
      And 因 count(related_orders) !== 1，直接 return 不處理
      And pc_avl_coursemeta 的 expire_date 保持為 "subscription_7001"（未被覆寫）

  # ========== Happy Path：訂閱 active 期間 ==========

  Rule: 訂閱狀態為 active 時，學員可正常觀看課程

    Example: 學員進入教室頁面
      Given Alice 的 pc_avl_coursemeta(course_id=100, user_id=10).expire_date = "subscription_7001"
      And 訂閱 7001 的狀態為 "active"
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_subscription 為 true
      And ExpireDate.subscription_id 為 7001
      And ExpireDate.expire_date_label 為 "跟隨訂閱 #7001"
      And ExpireDate.is_expired 為 false
      And Alice 可正常存取課程 100

  Rule: 訂閱狀態為 pending-cancel 時，學員仍可觀看課程（直到期末）

    Example: 學員取消續訂但當期未到
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 的狀態為 "pending-cancel"（取消但未到期）
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_expired 為 false
      And Alice 仍可存取課程 100

  # ========== 訂閱失效情境 ==========

  Rule: 訂閱狀態為 on-hold 時，視為過期

    Example: 續訂付款失敗使訂閱暫停
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 的狀態為 "on-hold"
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_expired 為 true
      And Alice 無法存取課程 100

  Rule: 訂閱狀態為 cancelled 時，視為過期

    Example: 管理員或學員主動取消訂閱
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 的狀態為 "cancelled"
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_expired 為 true
      And Alice 無法存取課程 100

  Rule: 訂閱狀態為 expired 時，視為過期

    Example: 訂閱自然到期
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 的狀態為 "expired"
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_expired 為 true
      And Alice 無法存取課程 100

  Rule: 訂閱不存在時，視為過期

    Example: 訂閱已被刪除
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 已被刪除（wcs_get_subscription 回傳 null）
      When 系統建立 ExpireDate 實例
      Then ExpireDate.is_expired 為 true
      And Alice 無法存取課程 100

  # ========== 重新啟用 ==========

  Rule: 訂閱從 cancelled 重新變回 active，學員即時恢復存取

    Example: 訂閱復活
      Given Alice 的 expire_date = "subscription_7001"
      And 訂閱 7001 的狀態曾為 "cancelled"（無法存取）
      When 管理員將訂閱 7001 的狀態改回 "active"
      Then pc_avl_coursemeta.expire_date 仍為 "subscription_7001"（不需要更新）
      And 下次 Alice 進入課程時，ExpireDate 動態查詢發現 has_status(['active', 'pending-cancel']) = true
      And ExpireDate.is_expired 為 false
      And Alice 恢復存取課程 100

  # ========== Subscriptions 外掛未啟用 ==========

  Rule: WC_Subscription 類別不存在時，expire_date 回傳 0（無限期 fallback）

    Example: 未安裝 WooCommerce Subscriptions
      Given WC_Subscription 類別不存在
      And 課程 100 的 limit_type 為 "follow_subscription"
      When 系統計算 expire_date
      Then Limit::calc_expire_date 回傳 0
      And 系統寫入 WC Logger 記錄失敗原因
      And 學員的 expire_date 存為 0（無限期）

  # ========== 訂閱購物 vs 一般訂單 ==========

  Rule: 訂閱訂單與一般訂單走不同 hook，避免重複開通

    Example: 一般訂單走 woocommerce_new_order
      Given Alice 下了一般訂單 8100（不含訂閱商品）
      When WooCommerce 觸發 "woocommerce_new_order"
      Then 系統呼叫 add_course_item_meta
      And wcs_order_contains_subscription(8100, ...) 為 false
      And 執行 _handle_add_course_item_meta_by_order

    Example: 訂閱訂單跳過 woocommerce_new_order
      Given Alice 下了訂閱訂單 8101
      When WooCommerce 觸發 "woocommerce_new_order"
      Then 系統呼叫 add_course_item_meta
      And wcs_order_contains_subscription(8101, ['parent','resubscribe','switch','renewal']) 為 true
      And 系統直接 return 不處理
      And 改由 "woocommerce_subscription_payment_complete" hook 處理
