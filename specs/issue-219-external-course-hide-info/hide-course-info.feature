@ignore @ui
Feature: 外部課程隱藏課程資訊區塊（Issue #219）

  # 背景：外部課程（WC_Product_External）沒有站內章節、學員、觀看期限與開課時間，
  # 過去 body.php 對每個統計項無條件塞「-」，導致前台一定出現一整排橫線，
  # 看起來像「資料沒填好」，拉低銷售頁專業度。
  #
  # 站長決策（Issue #219 留言）：「不用做成開關，如果是外部課程，就整個區域隱藏。」
  # → 不新增任何後台開關，外部課程直接整塊（含「課程資訊」標題）隱藏。
  # → 站內課程維持現有逐項 show_* 開關，完全不受影響。
  # → 既有外部課程升級後無需任何資料 migration，前台立即變乾淨。
  #
  # 實作位置：inc/templates/pages/course-product/body.php
  # 課程資訊區塊（標題 typography/title + course-product/info 模板）以 $is_external 判斷整塊跳過。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role   |
      | 10     | Teacher | teacher@test.com | editor |
    And 系統中有以下外部課程：
      | courseId | name            | _is_course | type     | status  | product_url                    | button_text     |
      | 200      | Python 資料科學 | yes        | external | publish | https://hahow.in/courses/12345 | 前往 Hahow 上課 |
    And 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  |
      | 100      | PHP 基礎課 | yes        | simple | publish |

  Rule: 外部課程銷售頁完全不渲染「課程資訊」整塊區域

    Example: 全新外部課程預設不出現課程資訊區塊
      When 訪客進入外部課程 200 的銷售頁
      Then 不應看到「課程資訊」區塊標題
      And 不應看到「開課時間」「課程時長」「章節數量」「觀看時間」「學員人數」任何統計項
      And 頁面不應出現任何 "-" 佔位符

    Example: 既有外部課程升級後前台立即變乾淨（無 migration）
      Given 外部課程 200 殘留站內預設的 show_* meta（部分為 "yes"）
      When 訪客進入外部課程 200 的銷售頁
      Then 不應看到「課程資訊」區塊標題
      And 不應看到任何課程資訊統計項

  Rule: 站內課程「課程資訊」區塊維持原行為，不受影響

    Example: 站內課程仍顯示課程資訊並遵循逐項 show_* 開關
      When 訪客進入站內課程 100 的銷售頁
      Then 應看到「課程資訊」區塊標題
      And 應依各統計項的 show_* 設定顯示對應項目（如「開課時間」「章節數量」）

    Example: 站內課程關閉某統計項時僅隱藏該項，區塊仍在
      Given 站內課程 100 的 show_course_schedule 設為 "no"
      And 站內課程 100 的 show_course_chapters 設為 "yes"
      When 訪客進入站內課程 100 的銷售頁
      Then 應看到「課程資訊」區塊標題
      And 不應看到「開課時間」統計項
      And 應看到「章節數量」統計項

  Rule: 不新增任何後台設定入口

    Example: 外部課程編輯頁的「其他設定」不出現課程資訊顯示開關
      When 管理員進入外部課程 200 的編輯頁「其他設定」頁籤
      Then 不應出現「是否顯示課程資訊區塊」的設定開關
      And 不應出現課程資訊逐項顯示開關
