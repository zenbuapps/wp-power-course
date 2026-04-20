@ignore @command
Feature: 線性觀看解鎖邏輯

  課程開啟「線性觀看」後，學員必須依 menu_order 平攤一維順序完成章節。
  解鎖邏輯採「最遠進度模式」：找到已完成章節中位置最後面的那一個，
  從第一章到該位置的下一個章節，全部解鎖。
  第一個章節永遠是解鎖狀態。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
      | 3      | Bob   | bob@test.com   |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | enable_linear_viewing |
      | 100      | PHP 基礎課 | yes        | publish | yes                   |
      | 101      | JS 進階課  | yes        | publish | no                    |
    And 課程 100 有以下章節（按 menu_order 平攤順序）：
      | chapterId | post_title   | menu_order | post_parent | has_children |
      | 300       | 第一單元     | 0          | 0           | yes          |
      | 301       | 1-1 PHP 簡介 | 0          | 300         | no           |
      | 302       | 1-2 變數型別 | 1          | 300         | no           |
      | 303       | 1-3 流程控制 | 2          | 300         | no           |
      | 400       | 第二單元     | 1          | 0           | yes          |
      | 401       | 2-1 物件導向 | 0          | 400         | no           |
    And 平攤一維順序為 [300, 301, 302, 303, 400, 401]
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 0

  # ========== 核心：最遠進度模式 ==========

  Rule: 無完成紀錄時，僅第一個章節解鎖

    Example: 學員初次進入課程，只有第一章解鎖
      Given 用戶 "Alice" 在課程 100 無任何章節完成紀錄
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300]
      And 鎖定的章節為 [301, 302, 303, 400, 401]

  Rule: 完成第一章後，解鎖到第二章

    Example: 完成第一單元（父章節），解鎖 1-1
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301]
      And 鎖定的章節為 [302, 303, 400, 401]

  Rule: 依序完成，逐步解鎖

    Example: 完成到 1-2，解鎖到 1-3
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
        | 302       |
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301, 302, 303]
      And 鎖定的章節為 [400, 401]

  Rule: 全部完成後，所有章節解鎖

    Example: 所有章節完成
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
        | 302       |
        | 303       |
        | 400       |
        | 401       |
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301, 302, 303, 400, 401]
      And 鎖定的章節為 []

  # ========== 最遠進度模式：跳躍完成 ==========

  Rule: 跳躍完成時，以最遠已完成章節為基準，忽略中間缺口

    Example: 自由模式遺留 — 完成 300 和 302（跳過 301），最遠到 302
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 302       |
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301, 302, 303]
      And 鎖定的章節為 [400, 401]
      And 章節 301 雖未完成但在最遠進度之前，仍為解鎖狀態

    Example: 自由模式遺留 — 只完成最後一章 401，全部解鎖
      Given 用戶 "Bob" 在以下章節已完成：
        | chapterId |
        | 401       |
      When 系統計算用戶 "Bob" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301, 302, 303, 400, 401]
      And 鎖定的章節為 []

    Example: 自由模式遺留 — 完成 300 和 303（跳過 301、302），最遠到 303
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 303       |
      When 系統計算用戶 "Alice" 在課程 100 的章節解鎖狀態
      Then 解鎖的章節為 [300, 301, 302, 303, 400]
      And 鎖定的章節為 [401]

  # ========== 取消完成後重新鎖定 ==========

  Rule: 取消完成章節後，基於新的最遠進度重算解鎖範圍

    Example: 取消 302 完成後，最遠進度回到 301
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
        | 302       |
      When 用戶 "Alice" 取消章節 302 的完成狀態
      Then 用戶 "Alice" 在章節 302 的 finished_at 應為空
      And 系統重新計算解鎖狀態
      And 解鎖的章節為 [300, 301, 302]
      And 鎖定的章節為 [303, 400, 401]
      And 章節 302 雖取消完成但為最遠進度的下一章，仍為解鎖狀態

    Example: 取消 301 完成，最遠進度回到 300
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
      When 用戶 "Alice" 取消章節 301 的完成狀態
      Then 系統重新計算解鎖狀態
      And 解鎖的章節為 [300, 301]
      And 鎖定的章節為 [302, 303, 400, 401]

  # ========== 未啟用線性觀看 ==========

  Rule: 未啟用線性觀看的課程，所有章節均為解鎖

    Example: JS 進階課未啟用線性觀看
      Given 課程 101 有以下章節：
        | chapterId | post_title |
        | 500       | Ch1        |
        | 501       | Ch2        |
        | 502       | Ch3        |
      And 用戶 "Alice" 已被加入課程 101，expire_date 0
      And 用戶 "Alice" 在課程 101 無任何章節完成紀錄
      When 系統計算用戶 "Alice" 在課程 101 的章節解鎖狀態
      Then 解鎖的章節為 [500, 501, 502]
      And 鎖定的章節為 []
      And 行為與現有系統完全一致

  # ========== 管理員預覽 ==========

  Rule: 管理員預覽模式不受線性觀看限制

    Example: 管理員可自由瀏覽所有章節
      Given 用戶 "Admin" 具有 manage_woocommerce 權限
      And 課程 100 已啟用線性觀看
      And 用戶 "Admin" 在課程 100 無任何章節完成紀錄
      When 管理員 "Admin" 存取章節 401
      Then 存取成功，不受線性觀看限制
      And 所有章節在側邊欄均顯示為可存取（無鎖定圖示）

  # ========== 切換完成後 API 回應 ==========

  Rule: toggle-finish API 在線性觀看模式下回傳 unlocked_chapter_ids

    Example: 完成章節 300 後，API 回傳新解鎖的章節 ID
      Given 用戶 "Alice" 在課程 100 無任何章節完成紀錄
      When 用戶 "Alice" 切換章節 300 的完成狀態
      Then 操作成功
      And API 回應的 data 包含 unlocked_chapter_ids: [301]
      And API 回應的 data 包含 locked_chapter_ids: [302, 303, 400, 401]

    Example: 取消完成章節 302 後，API 回傳需重新鎖定的章節
      Given 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 300       |
        | 301       |
        | 302       |
      When 用戶 "Alice" 切換章節 302 的完成狀態（取消完成）
      Then 操作成功
      And API 回應的 data 包含 unlocked_chapter_ids: [300, 301, 302]
      And API 回應的 data 包含 locked_chapter_ids: [303, 400, 401]
