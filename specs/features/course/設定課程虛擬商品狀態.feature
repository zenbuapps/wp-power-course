@ignore @command
Feature: 設定課程虛擬商品狀態

  課程編輯頁 (Courses Edit Admin SPA) 允許管理員透過 Switch 控制
  課程商品的 WooCommerce `virtual` 屬性，並由後端 API 持久化。
  對應 Issue #237。

  關鍵變更：
  1. 課程儲存時，**不再**強制將課程商品設為虛擬商品（移除 `$data['virtual'] = true` 硬寫）
  2. 課程編輯頁新增 Switch 元件「Virtual product」，預設為 true
  3. 切換後立即同步 form value，儲存時隨 update payload 一同送到後端
  4. 既有課程（升級後）DB meta 維持 `yes`，不執行 migration

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | limit_type | price | virtual |
      | 100      | PHP 基礎課 | yes        | publish | unlimited  | 1200  | yes     |
      | 101      | 實體研習營 | yes        | publish | unlimited  | 3000  | no      |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在且 _is_course 為 yes

    Example: 對不存在的課程更新 virtual 失敗
      When 管理員 "Admin" 更新課程 9999，參數如下：
        | virtual |
        | false   |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- virtual 必須為 boolean

    Scenario Outline: virtual 接受 boolean 與其等價值
      When 管理員 "Admin" 更新課程 100，參數如下：
        | virtual         |
        | <virtual_value> |
      Then 操作成功
      And 課程 100 的 virtual 應為 <expected_db>

      Examples:
        | 說明                | virtual_value | expected_db |
        | 布林 true            | true          | "yes"       |
        | 布林 false           | false         | "no"        |
        | 字串 "true"          | "true"        | "yes"       |
        | 字串 "false"         | "false"       | "no"        |
        | 字串 "yes"           | "yes"         | "yes"       |
        | 字串 "no"            | "no"          | "no"        |

  Rule: 前置（參數）- virtual 未出現在 request body 時，視為「保持原狀」（向下相容既有合約）

    Example: 既有虛擬課程更新 name 但未送 virtual，virtual 保留為 yes
      Given 課程 100 的 virtual 為 "yes"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | name         |
        | PHP 進階課程 |
      Then 操作成功
      And 課程 100 的 virtual 應為 "yes"

    Example: 既有實體課程更新 name 但未送 virtual，virtual 保留為 no
      Given 課程 101 的 virtual 為 "no"
      When 管理員 "Admin" 更新課程 101，參數如下：
        | name           |
        | 實體研習營進階 |
      Then 操作成功
      And 課程 101 的 virtual 應為 "no"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 課程儲存時不再強制覆寫 virtual（移除 hardcode）

    Example: 將虛擬課程改為實體課程，儲存後 DB 確實為 no
      Given 課程 100 的 virtual 為 "yes"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | virtual |
        | false   |
      Then 操作成功
      And 課程 100 的 virtual 應為 "no"
      And 課程 100 不應觸發任何 virtual=true 的強制覆寫

    Example: 將實體課程改為虛擬課程，儲存後 DB 確實為 yes
      Given 課程 101 的 virtual 為 "no"
      When 管理員 "Admin" 更新課程 101，參數如下：
        | virtual |
        | true    |
      Then 操作成功
      And 課程 101 的 virtual 應為 "yes"

  Rule: 後置（狀態）- virtual 切換為 false 時，僅異動 `_virtual` meta，不聯動 `_downloadable` / `_manage_stock` / shipping 相關欄位

    Example: virtual=false 不影響 downloadable
      Given 課程 100 的 virtual 為 "yes"
      And 課程 100 的 downloadable 為 "no"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | virtual |
        | false   |
      Then 操作成功
      And 課程 100 的 virtual 應為 "no"
      And 課程 100 的 downloadable 應為 "no"

    Example: virtual=false 不影響 manage_stock
      Given 課程 100 的 virtual 為 "yes"
      And 課程 100 的 manage_stock 為 "no"
      When 管理員 "Admin" 更新課程 100，參數如下：
        | virtual |
        | false   |
      Then 操作成功
      And 課程 100 的 virtual 應為 "no"
      And 課程 100 的 manage_stock 應為 "no"

  Rule: 後置（狀態）- GET 課程詳情回應內含 virtual 欄位（既有合約不變）

    Example: 既有虛擬課程 GET 回應 virtual 為 true
      When 管理員 "Admin" 取得課程 100 詳情
      Then 操作成功
      And 回應資料的 virtual 欄位應為 "yes"

    Example: 實體課程 GET 回應 virtual 為 false
      When 管理員 "Admin" 取得課程 101 詳情
      Then 操作成功
      And 回應資料的 virtual 欄位應為 "no"

  # ========== UI - Switch 元件行為 ==========

  Rule: UI - 「Virtual product」Switch 顯示於 CoursePrice tab，Label/Tooltip 沿用 WC 原生措辭

    Example: CoursePrice tab 顯示 Virtual product Switch
      Given 管理員 "Admin" 已開啟課程 100 的編輯頁
      When 管理員 "Admin" 切換至 "Price" tab
      Then 頁面顯示 Switch 元件，name 為 "virtual"
      And Switch 的 label 為 "Virtual product"
      And Switch 旁顯示 Tooltip，內容為 "Virtual products are intangible and do not require shipping."

    Example: 既有虛擬課程編輯頁，Switch 初始狀態為 ON
      Given 課程 100 的 virtual 為 "yes"
      When 管理員 "Admin" 開啟課程 100 的編輯頁並切換至 "Price" tab
      Then Switch "virtual" 的狀態應為 ON

    Example: 既有實體課程編輯頁，Switch 初始狀態為 OFF
      Given 課程 101 的 virtual 為 "no"
      When 管理員 "Admin" 開啟課程 101 的編輯頁並切換至 "Price" tab
      Then Switch "virtual" 的狀態應為 OFF

  Rule: UI - 建立新課程時，Switch 預設為 ON（虛擬商品）

    Example: 新建課程 Switch 預設 ON
      When 管理員 "Admin" 開啟「建立新課程」表單
      And 切換至 "Price" tab
      Then Switch "virtual" 的初始狀態應為 ON

  Rule: UI - 切換 Switch 後，儲存時隨 update payload 一併送出

    Example: 將 Switch 從 ON 切到 OFF 並儲存
      Given 管理員 "Admin" 已開啟課程 100 的編輯頁，Switch "virtual" 為 ON
      When 管理員 "Admin" 將 Switch "virtual" 切換為 OFF
      And 點擊「儲存」按鈕
      Then 後端收到 update 請求，request body 包含 virtual=false
      And 課程 100 的 virtual 應為 "no"
      And 頁面 invalidate 後 Switch "virtual" 顯示為 OFF

    Example: 將 Switch 從 OFF 切到 ON 並儲存
      Given 管理員 "Admin" 已開啟課程 101 的編輯頁，Switch "virtual" 為 OFF
      When 管理員 "Admin" 將 Switch "virtual" 切換為 ON
      And 點擊「儲存」按鈕
      Then 後端收到 update 請求，request body 包含 virtual=true
      And 課程 101 的 virtual 應為 "yes"

  # ========== 向下相容 ==========

  Rule: 向下相容 - 既有課程升級後不執行 migration，DB 中 virtual=yes 維持不變

    Example: 升級後既有課程 DB 維持 yes
      Given 升級前課程 100 的 virtual 為 "yes"
      When 系統升級至 Issue #237 修復後版本
      Then 課程 100 的 virtual 應仍為 "yes"
      And 系統未執行任何 migration 腳本
