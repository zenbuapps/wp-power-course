@ignore @command
Feature: 零元方案結帳

  延伸自 Issue #231 Bug #2：當課程主商品「手動設為 0 元」（非勾選免費課程開關，
  is_free=no 但 price=0）且該課程的銷售方案（bundle）也是 0 元時，學員透過 0 元方案
  無法加入購物車或無法完成結帳。

  **設計決策（已確認 Q2 A / Q4 A）：**
  - Q2 A：修復結帳流程，讓「手動 0 元主課程 + 0 元方案」也能正常下單，不加任何阻擋
  - Q4 A：0 元方案訂單完成後自動授予課程授權，與付費方案完全一致
    （0 元訂單 WooCommerce 會自動完成 → 沿用 `Resources/Order.php` 既有授權 hook）

  **既有可行路徑（旅程 3）：** 主課程非 0 元 + 方案 0 元 + 隱藏單堂課，可正常下單，
  本 feature 須確保此路徑行為不受影響。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role       |
      | 50     | Learner | learner@test.com  | subscriber |
    And 系統當下時間為 "2026-05-28 10:00:00"

  # ========== Bug #2：手動 0 元主課程 + 0 元方案可下單 ==========

  Rule: 後置（結帳）- 手動 0 元主課程搭配 0 元方案時，學員可順利完成下單

    Example: 0 元方案可加入購物車
      Given 系統中有以下課程：
        | courseId | name    | _is_course | status  | type   | is_free | regular_price |
        | 100      | 手動0元課 | yes        | publish | simple | no      | 0             |
      And 課程 100 有以下已發佈銷售方案：
        | bundleId | name      | regular_price | status  |
        | 200      | 零元方案  | 0             | publish |
      When 學員 Learner 將銷售方案 200 加入購物車
      Then 購物車應包含銷售方案 200

    Example: 0 元方案可完成結帳並建立訂單
      Given 系統中有以下課程：
        | courseId | name    | _is_course | status  | type   | is_free | regular_price |
        | 101      | 手動0元課B | yes      | publish | simple | no      | 0             |
      And 課程 101 有以下已發佈銷售方案：
        | bundleId | name      | regular_price | status  |
        | 201      | 零元方案B | 0             | publish |
      And 學員 Learner 已將銷售方案 201 加入購物車
      When 學員 Learner 完成結帳
      Then 應建立一筆訂單
      And 該訂單應自動完成（0 元免付款）

  Rule: 後置（授權）- 0 元方案訂單完成後自動授予課程授權（Q4 A）

    Example: 0 元方案訂單完成 → 學員取得課程授權
      Given 系統中有以下課程：
        | courseId | name      | _is_course | status  | type   | is_free | regular_price |
        | 102      | 手動0元課C | yes        | publish | simple | no      | 0             |
      And 課程 102 有以下已發佈銷售方案：
        | bundleId | name      | regular_price | status  | link_course_ids |
        | 202      | 零元方案C | 0             | publish | 102             |
      When 學員 Learner 透過銷售方案 202 完成 0 元訂單
      Then 學員 Learner 應取得課程 102 的觀看授權
      And 授權行為應與付費方案訂單一致

  # ========== 既有可行路徑不受影響（旅程 3）==========

  Rule: 後置（結帳）- 主課程非 0 元 + 方案 0 元 + 隱藏單堂課的既有路徑維持可下單

    Example: 主課程 999 元 + 0 元方案 + 隱藏單堂課 → 0 元方案仍可完成下單與授權
      Given 系統中有以下課程：
        | courseId | name    | _is_course | status  | type   | is_free | hide_single_course | regular_price |
        | 103      | 付費課A | yes        | publish | simple | no      | yes                | 999           |
      And 課程 103 有以下已發佈銷售方案：
        | bundleId | name      | regular_price | status  | link_course_ids |
        | 203      | 零元方案D | 0             | publish | 103             |
      When 學員 Learner 透過銷售方案 203 完成 0 元訂單
      Then 應建立一筆訂單
      And 學員 Learner 應取得課程 103 的觀看授權
