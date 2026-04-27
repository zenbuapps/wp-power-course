@ignore @command
Feature: 線性觀看章節存取控制

  課程開啟線性觀看後，鎖定的章節有雙層存取控制：
  1. 教室頁面層：PHP redirect 到當前應觀看的章節 + toast 提示
  2. toggle-finish API 層：鎖定的章節無法被標記為完成（403）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | enable_linear_viewing |
      | 100      | PHP 基礎課 | yes        | publish | yes                   |
    And 課程 100 有以下章節（按 menu_order 平攤順序）：
      | chapterId | post_title   | menu_order | post_parent |
      | 300       | 第一單元     | 0          | 0           |
      | 301       | 1-1 PHP 簡介 | 0          | 300         |
      | 302       | 1-2 變數型別 | 1          | 300         |
      | 303       | 1-3 流程控制 | 2          | 300         |
      | 400       | 第二單元     | 1          | 0           |
      | 401       | 2-1 物件導向 | 0          | 400         |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Alice" 在課程 100 無任何章節完成紀錄

  # ========== 教室頁面層：URL 直接存取 ==========

  Rule: 學員透過 URL 直接存取鎖定章節時，redirect 到當前應觀看的章節

    Example: 直接存取被鎖定的章節 401，導向到第一個章節 300
      Given 用戶 "Alice" 在課程 100 無任何章節完成紀錄
      When 用戶 "Alice" 透過 URL 直接存取章節 401
      Then 系統 302 redirect 到章節 300 的 URL，附加 query 參數 ?linear_locked=1
      And 不顯示章節 401 的內容

    Example: 已完成到 301，直接存取 303，導向到 302（當前應觀看的章節）
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
      When 用戶 "Alice" 透過 URL 直接存取章節 303
      Then 系統 302 redirect 到章節 302 的 URL，附加 query 參數 ?linear_locked=1

    Example: 存取已解鎖的章節，正常顯示
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
      When 用戶 "Alice" 透過 URL 直接存取章節 301
      Then 正常顯示章節 301 的內容
      And 不發生 redirect

  # ========== 教室頁面層：Toast 提示 ==========

  Rule: 被 redirect 的目標頁面偵測 query 參數並顯示 toast 提示

    Example: redirect 後顯示 toast 並清除 URL 參數
      Given 用戶 "Alice" 被 redirect 到章節 300，URL 包含 ?linear_locked=1
      When 頁面載入完成
      Then 頁面頂部顯示 toast 訊息：「請先完成前面的章節才能觀看該內容」
      And 使用 history.replaceState 移除 URL 中的 linear_locked 參數

  # ========== toggle-finish API 層：鎖定章節的完成驗證 ==========

  Rule: 鎖定的章節無法透過 API 標記為完成（防繞過）

    Example: 嘗試完成被鎖定的章節 302，API 回傳 403
      Given 用戶 "Alice" 在課程 100 無任何章節完成紀錄
      When 用戶 "Alice" 呼叫 POST /power-course/toggle-finish-chapters/302，body 包含 course_id: 100
      Then API 回傳 HTTP 403
      And 回應 message 為「此章節尚未解鎖，請先完成前面的章節」
      And 章節 302 的 finished_at 仍為空

    Example: 已解鎖的章節可正常標記完成
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
      When 用戶 "Alice" 呼叫 POST /power-course/toggle-finish-chapters/301，body 包含 course_id: 100
      Then API 回傳 HTTP 200
      And 章節 301 標記為完成

    Example: 取消已完成章節仍然允許（不受鎖定限制）
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
      When 用戶 "Alice" 呼叫 POST /power-course/toggle-finish-chapters/301（取消完成）
      Then API 回傳 HTTP 200
      And 章節 301 的 finished_at 被清除

  # ========== API 層：非線性觀看課程不驗證 ==========

  Rule: 未啟用線性觀看的課程，toggle API 不做鎖定驗證

    Example: 未啟用線性觀看，任意章節可完成
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | enable_linear_viewing |
        | 101      | JS 進階課 | yes        | publish | no                    |
      And 課程 101 有以下章節：
        | chapterId | post_title |
        | 500       | Ch1        |
        | 501       | Ch2        |
      And 用戶 "Alice" 已被加入課程 101，expire_date 0
      And 用戶 "Alice" 在課程 101 無任何章節完成紀錄
      When 用戶 "Alice" 呼叫 POST /power-course/toggle-finish-chapters/501，body 包含 course_id: 101
      Then API 回傳 HTTP 200
      And 章節 501 標記為完成

  # ========== 管理員預覽不受限 ==========

  Rule: 管理員透過 URL 存取鎖定章節時不 redirect

    Example: 管理員直接存取被鎖定的章節
      Given 用戶 "Admin" 具有 manage_woocommerce 權限
      When 用戶 "Admin" 透過 URL 直接存取章節 401
      Then 正常顯示章節 401 的內容
      And 不發生 redirect

  # ========== 自動完成觸發解鎖後 API 驗證 ==========

  Rule: 影片自動完成後的章節可立即被完成（時序正確）

    Example: 影片自動完成 300 後，立即呼叫 toggle 完成 301
      Given 用戶 "Alice" 在課程 100 無任何章節完成紀錄
      When 用戶 "Alice" 切換章節 300 的完成狀態（自動完成）
      Then 章節 300 標記為完成，301 解鎖
      When 用戶 "Alice" 呼叫 POST /power-course/toggle-finish-chapters/301，body 包含 course_id: 100
      Then API 回傳 HTTP 200
      And 章節 301 標記為完成
