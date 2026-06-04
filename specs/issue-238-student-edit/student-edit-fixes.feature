@ignore
Feature: 學員編輯 Modal 修復與權限優化
  As a 站長 / 協作管理員
  I want 學員編輯 Modal 的互動 bug 被修復，且敏感欄位與危險操作依角色分層控管
  So that 我能安全且順暢地管理學員資料，避免非管理員誤改角色或外洩個資

  # ────────────────────────────────────────────────────────────
  # 對應 Issue #238。前置：StudentEditModal（Issue #229）已實作。
  # 權限基準（Q3=A）：IS_ADMIN = current_user_can('manage_options')。
  #   前端以 RoleGate / IS_ADMIN 隱藏；後端以 manage_options permission_callback 把關（雙保險）。
  # ────────────────────────────────────────────────────────────
  Background:
    Given 系統中存在一位學員「王小明」
    And 我已開啟「王小明」的學員編輯 Modal

  # ════════════════════════════════════════════════════════════
  # B1 — 使用者角色下拉選單可正常點選（修 z-index 遮擋）
  Rule: 角色下拉選單浮層顯示在最上層且可點選

    Scenario: 管理員展開角色下拉可選到選項
      Given 我是 Administrator
      And 我已進入編輯模式
      When 我點擊「使用者角色」下拉選單
      Then 下拉選單浮層展開並顯示在 Modal 之上不被遮擋
      And 我可以點選其中任一角色選項

    Scenario: 角色下拉列出全部已註冊的 WordPress 角色
      Given 我是 Administrator
      And 我已進入編輯模式
      When 我點擊「使用者角色」下拉選單
      Then 選單列出系統全部已註冊角色（含 Subscriber / Customer / Shop Manager / Administrator 等）

  # ════════════════════════════════════════════════════════════
  # F2 — 只有 Administrator 能更新角色；非 Admin 純文字唯讀（Q1=B）
  Rule: 使用者角色僅 Administrator 可修改

    Scenario: 管理員修改角色並儲存成功
      Given 我是 Administrator
      And 我已進入編輯模式
      When 我將「王小明」的角色從 "Subscriber" 改為 "Customer"
      And 我點擊「儲存」
      Then 系統顯示「修改成功」提示
      And 「王小明」的角色更新為 "Customer"

    Scenario: 非管理員看到角色為純文字唯讀
      Given 我是 Shop Manager（非 Administrator）
      And 我已進入編輯模式
      Then 「使用者角色」以純文字顯示目前角色（如「目前：Customer」）
      And 畫面不出現可編輯的角色下拉選單

    Scenario: 非管理員即使送出 role 參數後端也不套用
      Given 我是 Shop Manager（非 Administrator）
      When 我繞過 UI 對更新學員 API 帶入 role 參數
      Then 後端不變更該學員的角色
      And 其餘允許的欄位仍可正常更新

  # ════════════════════════════════════════════════════════════
  # B3 — 內部備註 Switch 可正常切換（Q4=A，前台呈現另開 Issue）
  Rule: 聯絡註記的「內部備註」開關可點選切換

    Scenario: 新增備註預設為內部備註
      When 我在「聯絡註記」輸入框輸入備註內容
      Then 「內部備註」開關預設為開啟（內部備註）

    Scenario: 切換開關改為客戶可見標記
      When 我點擊「內部備註」開關
      Then 開關可正常切換且不再是 disabled 狀態
      And 備註標記在「內部備註」與「客戶可見」之間切換
      And 對應的 badge 顏色隨標記改變

    Scenario: 新增內部備註成功
      Given 「內部備註」開關為開啟
      When 我輸入備註「學員反映影片卡頓，已聯繫技術團隊」
      And 我點擊「新增」
      Then 備註成功新增
      And Timeline 出現一張內部備註卡片

  # ════════════════════════════════════════════════════════════
  # B4 + F5 — 前往傳統用戶編輯介面（修跳轉 + 限 Admin）
  Rule: 前往傳統用戶選項編輯介面

    Scenario: 管理員點擊按鈕於新分頁開啟原生用戶編輯頁
      Given 我是 Administrator
      When 我點擊「前往傳統用戶選項編輯介面」按鈕
      Then 瀏覽器於新分頁開啟 WordPress 原生 "/wp-admin/user-edit.php?user_id=<王小明的 user_id>"
      And 我未被停留在 Power Course SPA 畫面

    Scenario: 非管理員看不到此按鈕
      Given 我是 Shop Manager（非 Administrator）
      Then 「前往傳統用戶選項編輯介面」按鈕不顯示

  # ════════════════════════════════════════════════════════════
  # F6 — 敏感欄位僅 Administrator 可見（Q2=A 保留基本資料核心欄位）
  Rule: 敏感資訊僅 Administrator 可見

    Scenario: 管理員看到完整 Modal
      Given 我是 Administrator
      Then 我看到「消費數據」區塊（總消費 / 總訂單數 / 平均訂單金額 / 最後活躍 / 最後訂單 / 註冊時間）
      And 我看到「自動填入」Tab（帳單 / 運送資訊）
      And 我看到「其他欄位」Tab（user_meta）
      And 我看到「購物車」區塊
      And 我看到「最近訂單」區塊

    Scenario: 非管理員看到精簡版 Modal
      Given 我是 Shop Manager（非 Administrator）
      Then 我看到「基本資料」Tab 核心欄位（姓名 / 顯示名稱 / Email / 生日 / 簡介）
      And 我看不到「消費數據」區塊
      And 我看不到「自動填入」Tab
      And 我看不到「其他欄位」Tab
      And 我看不到「購物車」區塊
      And 我看不到「最近訂單」區塊

  # ════════════════════════════════════════════════════════════
  # B7 + F8 + Q6 — 匯出學員 CSV（修復 + 限 Admin + 含 billing/shipping）
  Rule: 匯出學員 CSV 功能

    Scenario: 管理員成功匯出學員 CSV
      Given 我是 Administrator
      And 我在學員管理頁面
      When 我點擊「學員匯出 CSV」按鈕
      Then 確認 Modal 顯示預估匯出筆數
      When 我點擊「確認匯出」
      Then 瀏覽器成功下載 CSV 檔案

    Scenario: 匯出按鈕僅 Administrator 可見
      Given 我是 Shop Manager（非 Administrator）
      And 我在學員管理頁面
      Then 「學員匯出 CSV」按鈕不顯示

    Scenario: 非管理員直接呼叫匯出 API 被拒絕
      Given 我是 Shop Manager（非 Administrator）
      When 我直接呼叫 "students/export-all" 端點
      Then 後端回傳權限不足錯誤（需 manage_options）
      And 未產生任何 CSV 下載

    Scenario: 匯出 CSV 包含帳單與運送欄位
      Given 我是 Administrator
      When 我匯出學員 CSV
      Then CSV 保留既有欄位（user_id / last_name / first_name / display_name / user_email / user_registered / course_name / course_id / progress / expire_date_label / is_expired / subscription_id）
      And CSV 新增帳單欄位（billing_first_name / billing_last_name / billing_email / billing_phone / billing_company / billing_country / billing_state / billing_city / billing_postcode / billing_address_1 / billing_address_2）
      And CSV 新增運送欄位（shipping_first_name / shipping_last_name / shipping_company / shipping_country / shipping_state / shipping_city / shipping_postcode / shipping_address_1 / shipping_address_2）
      And 所有欄位標頭皆為已翻譯的文字

    Scenario: 篩選條件無匹配學員時的空狀態
      Given 我是 Administrator
      And 目前篩選條件下沒有任何學員
      When 我點擊「學員匯出 CSV」按鈕
      Then 確認 Modal 顯示「目前篩選條件下無學員資料」
      And 「確認匯出」按鈕為停用狀態

    Scenario: 匯出失敗時顯示明確錯誤
      Given 我是 Administrator
      And 匯出 API 呼叫失敗
      When 我點擊「確認匯出」
      Then 系統顯示「匯出失敗」錯誤訊息
