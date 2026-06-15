@ignore @command
Feature: 設定銷售方案排程上下線時間

  # Issue #247：站長可為銷售方案（Bundle 商品）設定「自動上線時間」與「自動下線時間」。
  # - 自動上線時間：到點且方案為草稿 → 自動發佈
  # - 自動下線時間：到點且方案已發佈 → 自動轉草稿（保留資料，非刪除）
  # - 兩者皆為「選填」，不填時方案維持現有手動上下架行為，既有方案完全不受影響
  # - 排程時間以站台 WordPress 設定的時區為準（Q7）
  # 對應 meta：
  #   bundle_schedule_online  — Unix timestamp，0 = 無自動上線排程
  #   bundle_schedule_offline — Unix timestamp，0 = 無自動下線排程
  # 實際的到點執行行為見 banshou：features/bundle/銷售方案自動上下線.feature

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 站台時區設定為 "Asia/Taipei"
    And 目前時間為 "2026-11-10 12:00"（站台時區）

  # ========== 設定自動下線時間（核心痛點） ==========

  Rule: 管理員可為銷售方案設定「自動下線時間」（選填）

    Example: 設定未來的自動下線時間
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | price | bundle_type   | status  |
        | 300      | 雙11限時方案 | 100            | 399   | single_course | publish |
      When 管理員 "Admin" 更新銷售方案 300，參數如下：
        | bundle_schedule_offline |
        | 2026-11-14 23:59        |
      Then 操作成功
      And 方案 300 的 bundle_schedule_offline 應為 "2026-11-14 23:59"（站台時區）
      And 方案 300 的 status 應維持 "publish"

  Rule: 管理員可為銷售方案設定「自動上線時間」（選填）

    Example: 為草稿方案設定未來的自動上線時間
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | price | bundle_type   | status |
        | 301      | 預排促銷方案 | 100            | 399   | single_course | draft  |
      When 管理員 "Admin" 更新銷售方案 301，參數如下：
        | bundle_schedule_online |
        | 2026-11-12 00:00       |
      Then 操作成功
      And 方案 301 的 bundle_schedule_online 應為 "2026-11-12 00:00"（站台時區）
      And 方案 301 的 status 應維持 "draft"

  Rule: 管理員可同時設定上線與下線時間，排成一檔完整促銷檔期

    Example: 一次排好「自動開、自動關」
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | price | bundle_type   | status |
        | 302      | 早鳥方案 | 100            | 299   | single_course | draft  |
      When 管理員 "Admin" 更新銷售方案 302，參數如下：
        | bundle_schedule_online | bundle_schedule_offline |
        | 2026-11-12 00:00       | 2026-11-14 23:59        |
      Then 操作成功
      And 方案 302 的 bundle_schedule_online 應為 "2026-11-12 00:00"（站台時區）
      And 方案 302 的 bundle_schedule_offline 應為 "2026-11-14 23:59"（站台時區）

  # ========== 選填語義：不影響既有方案 ==========

  Rule: 後置（狀態）- 排程時間為選填，不填時方案維持手動上下架行為

    Example: 既有方案未設定任何排程，行為不變
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | price | bundle_type   | status  |
        | 303      | 常駐方案 | 100            | 599   | single_course | publish |
      When 管理員 "Admin" 更新銷售方案 303，參數如下：
        | name       |
        | 常駐方案v2 |
      Then 操作成功
      And 方案 303 的 bundle_schedule_online 應為 0
      And 方案 303 的 bundle_schedule_offline 應為 0
      And 方案 303 不會被自動上下線

  # ========== 修改 / 清除排程 ==========

  Rule: 管理員可隨時修改已設定的排程時間，系統依新時間重新安排

    Example: 促銷延長，下線時間往後改
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | price | bundle_type   | status  | bundle_schedule_offline |
        | 304      | 雙11限時方案 | 100            | 399   | single_course | publish | 2026-11-14 23:59        |
      When 管理員 "Admin" 更新銷售方案 304，參數如下：
        | bundle_schedule_offline |
        | 2026-11-16 23:59        |
      Then 操作成功
      And 方案 304 的 bundle_schedule_offline 應為 "2026-11-16 23:59"（站台時區）

  Rule: 管理員可清除已設定的排程時間，方案回到「需手動上下架」狀態

    Example: 決定改為永久上架，清除下線時間
      Given 系統中有以下銷售方案：
        | bundleId | name         | link_course_id | price | bundle_type   | status  | bundle_schedule_offline |
        | 305      | 雙11限時方案 | 100            | 399   | single_course | publish | 2026-11-14 23:59        |
      When 管理員 "Admin" 更新銷售方案 305，清除 bundle_schedule_offline
      Then 操作成功
      And 方案 305 的 bundle_schedule_offline 應為 0
      And 方案 305 不會被自動下線

  # ========== 設定「過去的時間」→ 立即執行並提示（Q3=B） ==========

  Rule: 設定一個「已過去」的下線時間時，允許儲存並立即下線，同時回傳明確提示

    Example: 已發佈方案被設定過去的下線時間 → 立即轉草稿並提示
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | price | bundle_type   | status  |
        | 306      | 促銷方案 | 100            | 399   | single_course | publish |
      When 管理員 "Admin" 更新銷售方案 306，參數如下：
        | bundle_schedule_offline |
        | 2026-11-09 23:59        |
      Then 操作成功
      And 方案 306 的 status 應變為 "draft"
      And 回應應包含立即下線的提示訊息（告知方案已立即下線）

    Example: 草稿方案被設定過去的上線時間 → 立即發佈並提示
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | price | bundle_type   | status |
        | 307      | 早鳥方案 | 100            | 299   | single_course | draft  |
      When 管理員 "Admin" 更新銷售方案 307，參數如下：
        | bundle_schedule_online |
        | 2026-11-09 00:00       |
      Then 操作成功
      And 方案 307 的 status 應變為 "publish"
      And 回應應包含立即上線的提示訊息（告知方案已立即上線）

  # ========== 時區基準（Q7=A） ==========

  Rule: 後置（狀態）- 排程時間一律以站台 WordPress 設定的時區解讀並儲存為對應的 Unix timestamp

    Example: 站台為 Asia/Taipei，輸入 23:59 對應該時區的時間點
      Given 系統中有以下銷售方案：
        | bundleId | name     | link_course_id | price | bundle_type   | status  |
        | 308      | 促銷方案 | 100            | 399   | single_course | publish |
      And 站台時區設定為 "Asia/Taipei"
      When 管理員 "Admin" 更新銷售方案 308，參數如下：
        | bundle_schedule_offline |
        | 2026-11-14 23:59        |
      Then 操作成功
      And 方案 308 的 bundle_schedule_offline 對應的 Unix timestamp 應以 "Asia/Taipei" 解讀
