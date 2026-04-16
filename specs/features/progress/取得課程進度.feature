@ignore @query
Feature: 取得課程進度

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent |
      | 200       | 第一章     | 100         |
      | 201       | 第二章     | 100         |
      | 202       | 第三章     | 100         |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 學員必須已有該課程的存取權

    Example: 無課程存取權時查詢失敗
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 查詢課程 100 的進度
      Then 操作失敗，錯誤為「無此課程存取權」

  Rule: 前置（狀態）- 課程存取未到期

    Example: 課程已到期時回傳 expired 標記
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 1609459200
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 expired 應為 true

    Example: 訂閱為 pending-cancel 時仍可查詢進度
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 "subscription_789"
      And 訂閱 789 的狀態為 "pending-cancel"
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 expired 應為 false

    Example: 訂閱為 cancelled 時回傳 expired 標記
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 "subscription_789"
      And 訂閱 789 的狀態為 "cancelled"
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 expired 應為 true

  # ========== 前置（參數）==========

  Rule: 前置（參數）- course_id 必須提供且為正整數

    Example: 課程不存在時查詢失敗
      When 用戶 "Alice" 查詢課程 9999 的進度
      Then 操作失敗，錯誤為「課程不存在」

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 應回傳進度百分比和已完成章節列表

    Example: 查詢部分完成的課程進度
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應資料應包含：
        | 欄位              | 期望值 |
        | progress          | 33.33  |
        | total_chapters    | 3      |
      And 回應中 finished_chapters 應包含 chapterId 200

  Rule: 後置（回應）- 應回傳最後造訪資訊

    Example: 查詢含最後造訪紀錄的進度
      Given 用戶 "Alice" 在課程 100 的 last_visit_info 為 chapter_id 200，last_visit_at "2025-06-01 10:30:00"
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 last_visit_info.chapter_id 應為 200

  # ========== 續播秒數（Issue #146）==========

  Rule: 後置（回應）- 每個章節進度物件應包含 last_position_seconds

    Example: 章節有續播秒數時回應包含整數秒
      Given 用戶 "Alice" 在章節 200 的 last_position_seconds 為 120
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 chapters[chapterId=200].last_position_seconds 應為 120

    Example: 章節無續播紀錄時回應為 0
      Given 用戶 "Alice" 在章節 201 無 last_position_seconds
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 chapters[chapterId=201].last_position_seconds 應為 0

    Example: 章節已完成且保留續播秒數（Q12）
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 570
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 chapters[chapterId=200].finished_at 應為 "2025-06-01 10:00:00"
      And 回應中 chapters[chapterId=200].last_position_seconds 應為 570

  Rule: 後置（回應）- 應回傳到期時間

    Example: 查詢永久存取的課程進度
      When 用戶 "Alice" 查詢課程 100 的進度
      Then 操作成功
      And 回應中 expire_date 應為 "0"
