@ignore @ui @query
Feature: 免費課程隱藏單堂課卡片

  延伸自 Issue #231 Bug #1：當課程為「免費課程」（is_free=yes）且開啟「隱藏單堂課購買」
  （hide_single_course=yes）時，前台課程銷售頁應隱藏免費課程的購買卡片，
  只保留銷售方案（bundle）卡片。

  **根因：** 卡片路由 `single-product.php` 在 is_free=yes 時直接載入
  `single-product-free.php` 並 return，而 hide_single_course 的隱藏判斷只寫在
  `single-product-sale.php`（付費卡片），免費卡片從未檢查此開關 → 隱藏失效。

  **Issue #231 設計決策（已確認 Q1 A / Q6 B / Q7 A）：**
  - Q1 A / Q7 A：只要 hide_single_course=yes 就一律隱藏免費卡片，
    即使完全沒有已發佈銷售方案也不顯示（admin 刻意為之，不 fallback）
  - Q6 B：付費課程維持現狀（hide_single_course=yes 一律隱藏），不在本次額外改動
  - 修法：`single-product-free.php` 比照 `single-product-sale.php` 加入
    `if ( 'yes' === hide_single_course ) { return; }`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 99     | Guest | guest@test.com | subscriber |
    And 系統當下時間為 "2026-05-28 10:00:00"

  # ========== 免費課程 + 隱藏單堂課 ==========

  Rule: 後置（顯示）- 免費課程開啟隱藏單堂課時，一律隱藏免費購買卡片（不因有無方案而改變）

    Example: 免費課程 + 隱藏單堂課 + 有已發佈方案 → 只顯示方案卡片
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course |
        | 100      | 免費課A   | yes        | publish | simple | yes     | yes                |
      And 課程 100 有以下已發佈銷售方案：
        | bundleId | name        | regular_price | status  |
        | 200      | 教材禮包方案 | 499           | publish |
      When 訪客瀏覽課程 100 的銷售頁
      Then 前台不應顯示免費課程的購買卡片
      And 前台應顯示銷售方案 200 的卡片

    Example: 免費課程 + 隱藏單堂課 + 無任何已發佈方案 → 不顯示任何購買卡片（admin 刻意為之）
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course |
        | 101      | 免費課B   | yes        | publish | simple | yes     | yes                |
      And 課程 101 沒有任何已發佈銷售方案
      When 訪客瀏覽課程 101 的銷售頁
      Then 前台不應顯示免費課程的購買卡片
      And 前台不應顯示任何銷售方案卡片

    Example: 免費課程 + 隱藏單堂課 + 僅有草稿方案 → 不顯示任何購買卡片
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course |
        | 102      | 免費課C   | yes        | publish | simple | yes     | yes                |
      And 課程 102 有以下銷售方案：
        | bundleId | name      | regular_price | status |
        | 201      | 草稿方案  | 499           | draft  |
      When 訪客瀏覽課程 102 的銷售頁
      Then 前台不應顯示免費課程的購買卡片
      And 前台不應顯示銷售方案 201 的卡片

  # ========== 免費課程未開隱藏單堂課（行為不變）==========

  Rule: 後置（顯示）- 免費課程未開啟隱藏單堂課時，免費卡片正常顯示

    Example: 免費課程 + 未開隱藏單堂課 → 顯示免費卡片
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course |
        | 103      | 免費課D   | yes        | publish | simple | yes     | no                 |
      When 訪客瀏覽課程 103 的銷售頁
      Then 前台應顯示免費課程的購買卡片

    Example: 免費課程 + 未開隱藏單堂課 + 有方案 → 免費卡片與方案卡片並存
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course |
        | 104      | 免費課E   | yes        | publish | simple | yes     | no                 |
      And 課程 104 有以下已發佈銷售方案：
        | bundleId | name        | regular_price | status  |
        | 202      | 教材禮包方案 | 499           | publish |
      When 訪客瀏覽課程 104 的銷售頁
      Then 前台應顯示免費課程的購買卡片
      And 前台應顯示銷售方案 202 的卡片

  # ========== 付費課程行為維持現狀（Q6 B）==========

  Rule: 後置（顯示）- 付費課程隱藏單堂課的既有行為維持不變

    Example: 付費課程 + 隱藏單堂課 + 有方案 → 只顯示方案卡片（現狀）
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | hide_single_course | regular_price |
        | 105      | 付費課A   | yes        | publish | simple | no      | yes                | 999           |
      And 課程 105 有以下已發佈銷售方案：
        | bundleId | name        | regular_price | status  |
        | 203      | 進階方案    | 1299          | publish |
      When 訪客瀏覽課程 105 的銷售頁
      Then 前台不應顯示單堂課的購買卡片
      And 前台應顯示銷售方案 203 的卡片
