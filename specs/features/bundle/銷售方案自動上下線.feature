@ignore @command
Feature: 銷售方案到點自動上下線

  # Issue #247：到達設定時間時，由 ActionScheduler 背景輪詢自動切換銷售方案狀態。
  # - 觸發者：ActionScheduler（每 10 分鐘輪詢一次，與「排程開課」course_schedule 同機制，Q6）
  # - 自動下線：now >= bundle_schedule_offline 且 status=publish → 轉 draft
  # - 自動上線：now >= bundle_schedule_online  且 status=draft   → 轉 publish
  # - 誤差：因 10 分鐘輪詢，實際執行可能比設定時間晚最多約 10 分鐘
  # - 下線 = 轉草稿（保留資料、設定、銷售紀錄），非刪除，可日後重新發佈
  # - 前台 sider.php 僅顯示 publish 方案，draft 自動從課程銷售頁消失

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 站台時區設定為 "Asia/Taipei"

  # ========== 自動下線 ==========

  Rule: 到達下線時間且方案為已發佈時，ActionScheduler 自動將方案轉為草稿

    Example: 下線時間已到，方案自動轉草稿
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 400      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 400 的 status 應變為 "draft"
      And 課程銷售頁不再顯示方案 400

    Example: 下線時間未到，方案維持發佈
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 401      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-14 12:00"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 401 的 status 應維持 "publish"

  Rule: 自動下線僅切換狀態為草稿，保留方案所有資料與銷售紀錄

    Example: 下線後資料保留，可日後重新發佈
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 402      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 402 的 status 應變為 "draft"
      And 方案 402 的名稱、價格、綁定課程、商品設定均保留不變

  # ========== 自動上線 ==========

  Rule: 到達上線時間且方案為草稿時，ActionScheduler 自動將方案轉為已發佈

    Example: 上線時間已到，草稿方案自動發佈
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type   | status | bundle_schedule_online |
        | 403      | 早鳥方案 | 100            | single_course | draft  | 2026-11-12 00:00       |
      And 目前時間為 "2026-11-12 00:08"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 403 的 status 應變為 "publish"
      And 課程銷售頁顯示方案 403

  Rule: 上線與下線時間獨立判斷，依方案「當下狀態」各自觸發

    Example: 同一檔促銷先自動上線、後自動下線
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | bundle_type   | status | bundle_schedule_online | bundle_schedule_offline |
        | 404      | 早鳥方案 | 100            | single_course | draft  | 2026-11-12 00:00       | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-12 00:08"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 404 的 status 應變為 "publish"
      Given 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 404 的 status 應變為 "draft"

  # ========== 購物車 / 進行中訂單處理（Q2=B：最嚴格） ==========

  Rule: 自動下線那一刻，方案完全無法購買；購物車內未結帳的該方案項目一併失效

    Example: 下線後購物車內的該方案項目失效，無法結帳
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 405      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 顧客 "小宇" 的購物車已加入方案 405
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 405 的 status 應變為 "draft"
      And 顧客 "小宇" 購物車中的方案 405 項目應失效
      And 顧客 "小宇" 無法以方案 405 完成結帳

    Example: 下線後新顧客無法將方案加入購物車
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 406      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      And 顧客 "小新" 嘗試將方案 406 加入購物車
      Then 加入購物車失敗

  Rule: 自動下線不影響「已成立」的訂單，既有訂單照常存在

    Example: 下線前已成立的訂單不受影響
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 407      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 顧客 "小宇" 已於 "2026-11-14 22:00" 以方案 407 成立訂單 9001
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 407 的 status 應變為 "draft"
      And 訂單 9001 不受影響，照常存在

  # ========== 與手動操作 / 刪除的交互 ==========

  Rule: 方案若已被手動改為草稿，下線排程輪詢不重複動作、不報錯

    Example: 已是草稿的方案，下線輪詢略過
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status | bundle_schedule_offline |
        | 408      | 雙11限時方案 | 100            | single_course | draft  | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 408 的 status 應維持 "draft"
      And 輪詢不產生錯誤

  Rule: 方案被刪除後，其排程自動失效，輪詢不報錯

    Example: 已刪除方案的排程輪詢安全略過
      Given 銷售方案 409 原訂於 "2026-11-14 23:59" 自動下線
      And 方案 409 已被刪除
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 輪詢不產生錯誤

  # ========== 自動執行後的後台可感知性（Q4=A、Q5=A） ==========

  Rule: 後置（狀態）- 自動上下線執行後，後台列表與編輯頁顯示「已於 X 時間自動下線/上線」

    Example: 下線後列表顯示已自動下線狀態
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | bundle_type   | status  | bundle_schedule_offline |
        | 410      | 雙11限時方案 | 100            | single_course | publish | 2026-11-14 23:59        |
      And 目前時間為 "2026-11-15 00:05"（站台時區）
      When ActionScheduler 執行銷售方案排程輪詢
      Then 方案 410 的 status 應變為 "draft"
      And 後台銷售方案列表標記方案 410「已於 2026-11-14 23:59 自動下線」
      And 方案 410 編輯頁顯示已自動下線的狀態
