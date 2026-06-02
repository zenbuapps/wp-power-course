@ignore @ui
Feature: 課程類型輔助說明文字（避免「外部課程」誤解為「外訓」）
  # Issue #235：內外部商品切換、名稱備註
  #
  # 背景：部分管理員看到「外部課程」會誤以為是「外訓」（公司外部的教育訓練），
  # 而非「其他平台的課程（如 Hahow、Udemy）」。
  #
  # **澄清決策（Issue #235）：**
  # - Q5=B：將「外部課程」字面改為「外部平台課程」
  # - Q6=D：下拉選單用 description（永遠可見）、編輯頁用 Tooltip（節省版面）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |

  # ========================================================================
  # 全域文案：「外部課程」→「外部平台課程」
  # ========================================================================

  Rule: UI 上所有出現「外部課程」字樣的位置均改為「外部平台課程」

    Example: 課程列表頁的新增下拉選單文案
      When 管理員 "Admin" 進入後台課程列表頁
      And 點擊「新增課程」下拉選單
      Then 下拉選項應顯示「站內課程」
      And 下拉選項應顯示「外部平台課程」
      And 不應出現舊文案「外部課程」（單獨出現的字樣）

    Example: 編輯頁 Segmented 的選項文案
      When 管理員 "Admin" 進入任一課程編輯頁
      Then 頁面標題列旁的 Segmented 應顯示選項「站內課程」與「外部平台課程」
      And 不應出現舊文案「外部課程」（單獨出現的字樣）

    Example: 課程列表表頭與篩選器
      When 管理員 "Admin" 在後台課程列表頁開啟類型篩選器
      Then 篩選選項應顯示「站內課程」與「外部平台課程」

  # ========================================================================
  # 場景一：新增課程下拉選單 — 使用 description（永遠可見）
  # ========================================================================

  Rule: 新增課程下拉選單的每個選項在標籤下方顯示一行灰色 description

    Example: 下拉選單顯示兩個選項與描述
      When 管理員 "Admin" 在後台課程列表頁點擊「新增課程」下拉選單
      Then 下拉選單應顯示兩個選項
      And 選項「站內課程」下方應顯示 description：「在本站直接觀看的課程，包含章節、影片與學員管理」
      And 選項「外部平台課程」下方應顯示 description：「導向其他平台的課程（如 Hahow、Udemy），僅在本站展示銷售頁」
      And description 文字應使用灰色小字樣式（不影響主選項可讀性）

    Example: 新手管理員可從 description 一眼判斷選哪個
      Given 管理員 "Admin" 為首次使用本站後台的新手
      When 管理員 "Admin" 在後台點擊「新增課程」下拉選單
      Then 管理員無需查詢文件即可從 description 理解兩種類型的差異
      And description 文字明確排除「外訓」聯想（提及「其他平台」與「站內觀看」）

  # ========================================================================
  # 場景二：課程編輯頁 Segmented — 使用 Tooltip（節省版面）
  # ========================================================================

  Rule: 編輯頁 Segmented 旁顯示資訊圖示，滑鼠 hover 時彈出 Tooltip 說明

    Example: Segmented 旁有 InfoCircle 圖示
      When 管理員 "Admin" 進入任一課程編輯頁
      Then 頁面標題列旁的 Segmented 右側應顯示一個 InfoCircle 圖示
      And 圖示在標題列維持可見（不會被頁籤切換隱藏）

    Example: hover InfoCircle 顯示 Tooltip 說明兩種類型差異
      Given 管理員 "Admin" 已進入任一課程編輯頁
      When 管理員 "Admin" 將滑鼠移到 InfoCircle 圖示上
      Then 應彈出 Tooltip
      And Tooltip 內容包含：「站內課程：在本站直接觀看的課程，包含章節、影片與學員管理」
      And Tooltip 內容包含：「外部平台課程：導向其他平台的課程（如 Hahow、Udemy），僅在本站展示銷售頁」
      And Tooltip 不包含「外訓」字樣（明確排除誤解）

    Example: 滑鼠移開 InfoCircle 後 Tooltip 自動消失
      Given 管理員 "Admin" 已 hover InfoCircle 並顯示 Tooltip
      When 管理員 "Admin" 將滑鼠移離 InfoCircle
      Then Tooltip 自動消失
      And 不影響頁面其他操作

  # ========================================================================
  # 場景三：對話框與系統提示的文案一致性
  # ========================================================================

  Rule: 所有切換對話框、系統提示、錯誤訊息均使用「外部平台課程」字樣

    Example: 切換確認對話框的文案
      When 管理員 "Admin" 觸發切換確認對話框（站內 → 外部）
      Then 對話框標題應使用「切換為外部平台課程」（不可使用「切換為外部課程」）

    Example: 切換成功提示文案
      Given 管理員 "Admin" 已成功將站內課程切換為外部平台課程
      Then 系統提示應顯示「已切換為外部平台課程」

  # ========================================================================
  # 場景四：i18n 對應
  # ========================================================================

  Rule: 文案變更需同步更新 .pot 翻譯來源

    Example: 新文案進入 power-course.pot
      When 開發者執行 `pnpm run i18n:build`
      Then `languages/power-course.pot` 應包含字串 "External platform course"（對應「外部平台課程」）
      And `languages/power-course.pot` 應包含字串 "In-site course"（對應「站內課程」）
      And 繁中翻譯 `.po` 應顯示對應的「外部平台課程」與「站內課程」
      And `scripts/i18n-translations/manual.json` 應包含對應翻譯對照
