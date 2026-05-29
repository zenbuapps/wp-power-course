@ignore
Feature: 學員快速編輯
  As a 站長 / 管理員
  I want 在 Power Course 後台的學員清單直接查看與修改學員的基本資料、WooCommerce 訂單資料與密碼
  So that 我不再需要切換到 WordPress 原生用戶頁面就能完成日常學員管理

  # ────────────────────────────────────────────────────────────
  # 背景：學員清單存在兩個入口（全域學員管理、課程編輯頁學員 Tab），
  #       兩處共用同一個編輯 Drawer。
  # 權限決策：Q7=C — 具備 edit_users 能力的角色才能開啟與儲存。
  # ────────────────────────────────────────────────────────────
  Background:
    Given 我是具備 "edit_users" 能力的後台使用者
    And 系統中存在一位學員「王小明」

  # ════════════════════════════════════════════════════════════
  Rule: 透過點擊學員開啟共用的編輯 Drawer

    Scenario: 從全域學員管理點擊學員開啟 Drawer
      Given 我在全域學員管理頁面 "/admin/students"
      When 我點擊學員「王小明」的名字
      Then 頁面右側滑出學員編輯 Drawer
      And Drawer 載入「王小明」的完整資料

    Scenario: 從課程編輯頁學員 Tab 點擊學員開啟相同的 Drawer
      Given 我在某課程編輯頁的「課程學員」Tab
      When 我點擊學員「王小明」的名字
      Then 頁面右側滑出與全域學員管理相同的學員編輯 Drawer
      And Drawer 載入「王小明」的完整資料

    Scenario: Drawer 載入完整的學員資料分區
      Given 我已開啟「王小明」的編輯 Drawer
      Then 我看到「基本資料」區塊
      And 我看到「WooCommerce 帳單資料」區塊
      And 我看到可收合的「WooCommerce 收件資料」區塊
      And 我看到「訂單摘要」區塊

  # ════════════════════════════════════════════════════════════
  Rule: 唯讀欄位不可編輯

    Scenario: 用戶 ID 與帳號為唯讀
      Given 我已開啟「王小明」的編輯 Drawer
      Then 「用戶 ID」欄位為唯讀
      And 「帳號（user_login）」欄位為唯讀

  # ════════════════════════════════════════════════════════════
  Rule: 修改基本資料並儲存

    Scenario: 管理員修改顯示名稱成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我將「顯示名稱」改為「王大明」
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And Drawer 維持開啟且顯示更新後的資料
      And 學員列表中該學員的顯示名稱同步更新為「王大明」

    Scenario: 管理員修改登入 Email 成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我將「Email」改為一組有效的新 Email
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 系統提示「學員的登入 Email 已更新」

    Scenario: 管理員修改姓與名成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我修改「姓（last_name）」與「名（first_name）」
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 學員的姓名 meta 已更新

    Scenario: 登入 Email 與帳單 Email 為獨立欄位互不連動
      Given 我已開啟「王小明」的編輯 Drawer
      When 我只修改「Email（登入）」並儲存
      Then 「帳單 Email（billing_email）」維持原值不變

  # ════════════════════════════════════════════════════════════
  Rule: 修改 WooCommerce 帳單與收件資料

    Scenario: 管理員修改帳單電話與地址成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我修改「帳單電話」「帳單地址第一行」「帳單城市」「帳單郵遞區號」
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 對應的 billing_* user meta 已更新

    Scenario: 帳單國家與州省以 WooCommerce 下拉選單選取
      Given 我已開啟「王小明」的編輯 Drawer
      When 我從「帳單國家」下拉選單選取一個國家
      And 我從「帳單州/省」下拉選單選取對應的州省
      And 我點擊「儲存」
      Then billing_country 與 billing_state 以 WooCommerce 代碼格式儲存

    Scenario: 管理員展開並修改收件資料成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我展開「收件資料」區塊
      And 我修改收件姓名與收件地址
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 對應的 shipping_* user meta 已更新

    Scenario: 在 Drawer 修改的帳單資料在 WooCommerce 我的帳號頁正確反映
      Given 我已將「王小明」的帳單電話改為新號碼並儲存成功
      When 「王小明」開啟 WooCommerce 我的帳號 > 地址頁面
      Then 帳單電話顯示為新號碼

  # ════════════════════════════════════════════════════════════
  Rule: 設定新密碼

    Scenario: 管理員設定新密碼成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我在「新密碼」欄位輸入新密碼
      And 我在「確認新密碼」欄位輸入相同的密碼
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 學員的密碼已更新
      And 頁面不顯示任何密碼明文

    Scenario: 新密碼留空時不修改密碼
      Given 我已開啟「王小明」的編輯 Drawer
      When 我將「新密碼」欄位留空
      And 我修改其他欄位並點擊「儲存」
      Then 學員的密碼維持不變

    Scenario: 兩次密碼輸入不一致時阻擋儲存
      Given 我已開啟「王小明」的編輯 Drawer
      When 我在「新密碼」與「確認新密碼」輸入不同的值
      And 我點擊「儲存」
      Then 系統顯示「兩次輸入的密碼不一致」錯誤
      And 表單不送出

  # ════════════════════════════════════════════════════════════
  Rule: 發送 WordPress 原生密碼重設信

    Scenario: 管理員發送密碼重設信成功
      Given 我已開啟「王小明」的編輯 Drawer
      When 我點擊「發送密碼重設信」
      Then 系統寄出 WordPress 原生密碼重設信給該學員的登入 Email
      And 系統顯示「已發送密碼重設信」提示

  # ════════════════════════════════════════════════════════════
  Rule: 表單驗證與錯誤處理

    Scenario: Email 格式錯誤時顯示錯誤且不送出
      Given 我已開啟「王小明」的編輯 Drawer
      When 我將「Email」改為格式不正確的字串
      And 我點擊「儲存」
      Then 系統在 Email 欄位即時顯示「Email 格式不正確」
      And 表單不送出

    Scenario: 後端儲存失敗時顯示明確錯誤
      Given 我已開啟「王小明」的編輯 Drawer
      And 後端在儲存時回傳錯誤
      When 我點擊「儲存」
      Then 系統顯示後端回傳的錯誤訊息
      And Drawer 維持開啟且不清空已輸入的內容

  # ════════════════════════════════════════════════════════════
  Rule: 學員列表顯示電話欄位

    Scenario: 全域學員列表顯示電話欄位
      Given 學員「王小明」的 billing_phone 有值
      When 我開啟全域學員管理頁面
      Then 列表表格顯示「電話」欄位
      And 該欄位顯示「王小明」的 billing_phone

    Scenario: 學員沒有電話時欄位顯示空白
      Given 學員「王小明」沒有 billing_phone
      When 我開啟全域學員管理頁面
      Then 該學員的「電話」欄位顯示空白

  # ════════════════════════════════════════════════════════════
  Rule: Drawer 內嵌訂單摘要

    Scenario: 顯示學員訂單筆數與最近訂單摘要
      Given 學員「王小明」有多筆 WooCommerce 訂單
      When 我開啟「王小明」的編輯 Drawer
      Then 「訂單摘要」區塊顯示訂單總筆數
      And 顯示最近數筆訂單的金額、日期與狀態
      And 顯示「查看全部訂單」連結指向 WooCommerce 訂單列表並以該學員篩選

    Scenario: 無訂單學員顯示空狀態
      Given 學員「王小明」沒有任何 WooCommerce 訂單
      When 我開啟「王小明」的編輯 Drawer
      Then 「訂單摘要」區塊顯示「尚無訂單」空狀態

  # ════════════════════════════════════════════════════════════
  Rule: 權限控管

    Scenario: 不具 edit_users 能力者無法儲存
      Given 我是不具備 "edit_users" 能力的後台使用者
      When 我嘗試呼叫更新學員資料的 API
      Then 系統回傳權限不足錯誤
      And 學員資料維持不變
