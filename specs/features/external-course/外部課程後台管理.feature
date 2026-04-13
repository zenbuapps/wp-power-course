@ignore @ui
Feature: 外部課程後台管理

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | product_url                    | button_text |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 | 前往課程    |
    And 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  |
      | 100      | PHP 基礎課 | yes        | simple | publish |

  # ========== 新增外部課程 ==========

  Rule: 新增課程表單頂部有「站內課程 / 外部課程」Radio 切換

    Example: 新增課程時可選擇課程類型
      When 管理員 "Admin" 進入新增課程頁面
      Then 表單頂部應顯示課程類型 Radio：「站內課程」與「外部課程」
      And 預設選中「站內課程」

  Rule: 選擇「外部課程」後動態隱藏不適用頁籤

    Example: 選擇外部課程時隱藏不適用頁籤
      When 管理員 "Admin" 在新增課程頁面選擇「外部課程」
      Then 應顯示以下頁籤：「課程描述」、「課程訂價」、「QA設定」、「其他設定」
      And 不應顯示以下頁籤：「銷售方案」、「章節管理」、「學員管理」、「分析」

  Rule: 選擇「外部課程」後在「課程描述」tab 顯示外部連結欄位

    Example: 外部課程描述 tab 包含外部連結欄位
      When 管理員 "Admin" 在新增課程頁面選擇「外部課程」
      Then 「課程描述」tab 應顯示「外部連結 URL」輸入欄位
      And 「課程描述」tab 應顯示「CTA 按鈕文字」輸入欄位
      And 「CTA 按鈕文字」欄位的 placeholder 應為 "前往課程"

  # ========== 編輯外部課程 ==========

  Rule: 編輯外部課程時類型 Radio 應鎖定且不可切換

    Example: 外部課程編輯頁的類型不可變更
      When 管理員 "Admin" 進入外部課程 200 的編輯頁面
      Then 課程類型 Radio 應顯示「外部課程」已選中
      And 課程類型 Radio 應為不可操作（disabled）

  Rule: 編輯站內課程時類型 Radio 也應鎖定

    Example: 站內課程編輯頁的類型不可變更
      When 管理員 "Admin" 進入站內課程 100 的編輯頁面
      Then 課程類型 Radio 應顯示「站內課程」已選中
      And 課程類型 Radio 應為不可操作（disabled）

  Rule: 外部課程編輯頁只顯示適用的頁籤

    Example: 外部課程編輯頁隱藏不適用頁籤
      When 管理員 "Admin" 進入外部課程 200 的編輯頁面
      Then 應顯示以下頁籤：「課程描述」、「課程訂價」、「QA設定」、「其他設定」
      And 不應顯示以下頁籤：「銷售方案」、「章節管理」、「學員管理」、「分析」

  Rule: 外部課程編輯頁的「課程描述」tab 顯示外部連結欄位（含既有值）

    Example: 編輯頁的外部連結欄位帶入既有值
      When 管理員 "Admin" 進入外部課程 200 的編輯頁面
      Then 「外部連結 URL」欄位的值應為 "https://hahow.in/courses/12345"
      And 「CTA 按鈕文字」欄位的值應為 "前往課程"

  # ========== 後台課程列表 ==========

  Rule: 後台課程列表正常顯示外部課程

    Example: 後台列表包含站內與外部課程
      When 管理員 "Admin" 瀏覽後台課程列表
      Then 列表應包含課程 "PHP 基礎課"
      And 列表應包含課程 "Python 資料科學"

  Rule: 外部課程在列表中的產品類型顯示為 WC 原生「外部/加盟商品」

    Example: 列表中的產品類型欄識別外部課程
      When 管理員 "Admin" 瀏覽後台課程列表
      Then 課程 "Python 資料科學" 的產品類型應顯示為「外部/加盟商品」或「external」
      And 課程 "PHP 基礎課" 的產品類型應顯示為「簡單商品」或「simple」

  # ========== 課程訂價 Tab ==========

  Rule: 外部課程的訂價 tab 不顯示訂閱相關欄位

    Example: 外部課程訂價 tab 無訂閱選項
      When 管理員 "Admin" 進入外部課程 200 的編輯頁面並切換到「課程訂價」tab
      Then 不應顯示產品類型選擇（簡單商品/定期定額）
      And 不應顯示訂閱相關欄位（訂閱週期、免費試用等）
      And 應顯示「原價」與「特價」欄位（作為展示用途）
