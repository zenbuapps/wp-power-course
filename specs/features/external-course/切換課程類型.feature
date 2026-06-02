@ignore @command
Feature: 切換課程類型（站內 ↔ 外部平台課程）
  # Issue #235：內外部商品切換、名稱備註
  #
  # 背景：原先「外部課程」建立後類型即鎖定無法切換，導致管理員選錯時只能刪除重建。
  # 此功能允許在課程編輯頁切換「站內課程 ↔ 外部平台課程」，並透過確認對話框
  # 列出資料影響範圍以降低誤觸風險。
  #
  # **澄清決策（Issue #235 第一輪）：**
  # - 控制項位置：頁面標題列旁的 Segmented（跨 Tab 全域可見）— Q1=C
  # - 切換影響：資料保留隱藏，對話框列出影響清單（學員數 / 章節數 / Bundle 數）— Q2=D
  # - product_url / button_text：切回站內時保留（僅隱藏，不刪除）— Q3=C
  # - API 行為：立刻送出 + 全頁 loading + 失敗回滾 UI — Q4=C
  # - 名稱：原「外部課程」改字面為「外部平台課程」— Q5=B
  # - 說明文字：新增下拉選單用 description（永遠可見）、編輯頁用 Tooltip — Q6=D
  # - API 端點：既有 POST /courses/{id} 擴充 + `confirm_type_change` 旗標 — Q7=C

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  |
      | 100      | PHP 基礎課 | yes        | simple | publish |
    And 課程 100 已有以下關聯資料：
      | 章節數 | 已授權學員數 | 綁定銷售方案數 |
      | 5      | 12           | 2              |
    And 系統中有以下外部平台課程：
      | courseId | name             | _is_course | type     | status  | product_url                    | button_text |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 | 前往課程    |

  # ========================================================================
  # 場景一：站內課程 → 外部平台課程
  # ========================================================================

  Rule: 站內課程編輯頁的標題列旁顯示 Segmented，可切換為「外部平台課程」

    Example: 站內課程編輯頁的 Segmented 預設選中「站內課程」
      When 管理員 "Admin" 進入課程 100 的編輯頁
      Then 頁面標題列旁應顯示 Segmented，包含選項「站內課程」與「外部平台課程」
      And Segmented 當前選中「站內課程」
      And Segmented 在所有 Tab 切換時均維持可見

  Rule: 點擊 Segmented 切換為「外部平台課程」時，必須先彈出確認對話框

    Example: 站內切外部時彈出確認對話框並列出資料影響清單
      When 管理員 "Admin" 在課程 100 編輯頁點擊 Segmented 的「外部平台課程」
      Then 應彈出確認對話框
      And 對話框標題應包含「切換為外部平台課程」
      And 對話框內容應顯示以下影響清單：
        | 項目         | 數量 |
        | 已授權學員   | 12   |
        | 章節數       | 5    |
        | 綁定銷售方案 | 2    |
      And 對話框應說明「切換後上述資料將被隱藏（不會刪除），可隨時切回站內課程恢復」
      And 對話框應有「取消」與「確認切換」兩個按鈕
      And 此時 Segmented 仍維持選中「站內課程」（尚未送出）

    Example: 站內切外部對話框列出 Bundle 影響時，提示前台展示風險
      When 管理員 "Admin" 在課程 100 編輯頁點擊 Segmented 的「外部平台課程」
      Then 對話框應額外提示「此課程被 2 個銷售方案綁定，切換為外部平台課程後，前台銷售方案展示行為可能不一致，建議檢查」

  Rule: 點擊「取消」時不執行切換，UI 維持原狀

    Example: 取消切換時 Segmented 維持站內
      Given 管理員 "Admin" 已在課程 100 編輯頁觸發切換對話框
      When 管理員 "Admin" 點擊對話框的「取消」按鈕
      Then 對話框關閉
      And Segmented 仍選中「站內課程」
      And 不發送任何 API 請求

  Rule: 點擊「確認切換」時立刻送出 API（含 confirm_type_change 旗標）並顯示全頁 loading

    Example: 確認切換時送出 API 並顯示 loading
      Given 管理員 "Admin" 已在課程 100 編輯頁觸發切換對話框
      When 管理員 "Admin" 點擊對話框的「確認切換」按鈕
      Then 應發送 POST /power-course/v2/courses/100，參數包含：
        | type     | confirm_type_change |
        | external | true                |
      And 頁面顯示全頁 loading 遮罩（操作期間使用者不可互動）

  Rule: 切換成功後 UI 即時更新（隱藏不適用 Tab、顯示外部連結欄位、Segmented 反映新狀態）

    Example: 站內切外部成功後頁面結構即時調整
      Given 管理員 "Admin" 已點擊「確認切換」且 API 回應成功
      Then 全頁 loading 消失
      And Segmented 選中「外部平台課程」
      And 顯示成功提示「已切換為外部平台課程」
      And 應顯示以下頁籤：「課程描述」、「課程訂價」、「QA設定」、「其他設定」
      And 不應顯示以下頁籤：「銷售方案」、「章節管理」、「學員管理」、「分析」
      And 「課程描述」Tab 應顯示「外部連結 URL」與「CTA 按鈕文字」欄位
      And 「外部連結 URL」欄位值為空（首次切換尚未填寫）
      And 「CTA 按鈕文字」欄位的 placeholder 應為 "前往課程"

  Rule: 切換失敗時自動回滾 UI 狀態並提示錯誤

    Example: API 回 500 時 UI 回滾為原類型
      Given 管理員 "Admin" 已點擊「確認切換」
      And API POST /power-course/v2/courses/100 回應 HTTP 500
      Then 全頁 loading 消失
      And Segmented 回滾為選中「站內課程」
      And 顯示錯誤提示「切換失敗，請稍後再試」
      And 頁籤顯示維持站內課程的原狀態

  # ========================================================================
  # 場景二：外部平台課程 → 站內課程
  # ========================================================================

  Rule: 外部平台課程編輯頁的 Segmented 預設選中「外部平台課程」

    Example: 外部平台課程編輯頁的 Segmented 反映當前類型
      When 管理員 "Admin" 進入課程 200 的編輯頁
      Then 頁面標題列旁應顯示 Segmented
      And Segmented 當前選中「外部平台課程」

  Rule: 外部切站內時，對話框需告知「外部連結設定將被隱藏但不刪除」

    Example: 外部切站內彈出確認對話框
      When 管理員 "Admin" 在課程 200 編輯頁點擊 Segmented 的「站內課程」
      Then 應彈出確認對話框
      And 對話框標題應包含「切換為站內課程」
      And 對話框內容應說明「外部連結 URL 與 CTA 按鈕文字將被隱藏但不刪除（切回外部平台課程時自動帶回原值）」
      And 對話框內容應提示「切換為站內課程後需自行設定章節、銷售方案等內容」
      And 對話框應有「取消」與「確認切換」兩個按鈕

  Rule: 外部切站內成功後保留 product_url 與 button_text（僅 UI 隱藏）

    Example: 外部切站內後 meta 資料保留
      Given 管理員 "Admin" 已點擊「確認切換」（外部→站內）且 API 成功
      Then 商品 post_meta `_product_url` 仍為 "https://hahow.in/courses/12345"
      And 商品 post_meta `_button_text` 仍為 "前往課程"
      And 編輯頁不應顯示「外部連結 URL」與「CTA 按鈕文字」欄位
      And 應顯示以下頁籤：「課程描述」、「課程訂價」、「QA設定」、「其他設定」、「銷售方案」、「章節管理」、「學員管理」、「分析」

    Example: 切回外部平台課程時自動帶回原 product_url 與 button_text
      Given 課程 200 已從外部切換為站內，且 meta 保留
      When 管理員 "Admin" 再次將課程 200 切換為「外部平台課程」並確認
      Then API 切換成功後，「外部連結 URL」欄位帶回 "https://hahow.in/courses/12345"
      And 「CTA 按鈕文字」欄位帶回 "前往課程"

  # ========================================================================
  # 場景三：資料保留語意（站內 → 外部切換）
  # ========================================================================

  Rule: 站內切外部後，原有章節 / 學員授權 / Bundle 關聯一律保留（資料不刪除）

    Example: 站內切外部後章節仍存在 DB
      Given 課程 100 原本有 5 個章節（post_type=chapter）
      When 管理員 "Admin" 確認將課程 100 切換為「外部平台課程」
      Then 該 5 個章節在 DB 中仍存在（不被刪除）
      And 章節在編輯頁的 UI 中不可見（章節管理 Tab 被隱藏）

    Example: 站內切外部後學員授權記錄仍存在
      Given 課程 100 原本有 12 筆學員授權記錄（pc_avl_coursemeta）
      When 管理員 "Admin" 確認將課程 100 切換為「外部平台課程」
      Then 該 12 筆授權記錄在 DB 中仍存在
      And 學員管理 Tab 被隱藏，且學員的 myaccount 課程列表不再顯示此外部平台課程

    Example: 切回站內後原資料完整恢復顯示
      Given 課程 100 已從站內切換為外部平台課程
      When 管理員 "Admin" 再次將課程 100 切換為「站內課程」並確認
      Then 「章節管理」Tab 恢復顯示，原 5 個章節結構完整呈現
      And 「學員管理」Tab 恢復顯示，原 12 筆授權記錄完整呈現
      And 「銷售方案」Tab 恢復顯示，原 2 個 Bundle 關聯保留

  # ========================================================================
  # 場景四：後端 confirm_type_change 旗標安全機制
  # ========================================================================

  Rule: 未帶 confirm_type_change 旗標時，API 忽略 type 變更（向下相容既有 update 行為）

    Example: 一般更新 type 欄位但未帶旗標 → 忽略 type
      When 管理員 "Admin" 更新課程 100，參數如下：
        | type     | name           |
        | external | PHP 基礎課改名 |
      Then 操作成功
      And 課程 100 的 product type 仍為 "simple"
      And 課程 100 的 name 已更新為 "PHP 基礎課改名"

  Rule: 帶 confirm_type_change=true 旗標時，API 執行類型切換並切換 WC_Product class

    Example: 站內切外部時 WC_Product class 從 Simple 變為 External
      When 管理員 "Admin" 更新課程 100，參數如下：
        | type     | confirm_type_change |
        | external | true                |
      Then 操作成功
      And 課程 100 的 wp_posts.post_type 為 "product"
      And 課程 100 的 product_type taxonomy 為 "external"
      And `wc_get_product(100)` 回傳 `WC_Product_External` 實例

    Example: 外部切站內時 WC_Product class 從 External 變為 Simple
      When 管理員 "Admin" 更新課程 200，參數如下：
        | type   | confirm_type_change |
        | simple | true                |
      Then 操作成功
      And 課程 200 的 product_type taxonomy 為 "simple"
      And `wc_get_product(200)` 回傳 `WC_Product_Simple` 實例

  Rule: 帶 confirm_type_change=true 但 type 值不變時，視為無效操作（不報錯，但不做任何切換）

    Example: 同類型切換為 no-op
      When 管理員 "Admin" 更新課程 100，參數如下：
        | type   | confirm_type_change |
        | simple | true                |
      Then 操作成功
      And 課程 100 的 product type 仍為 "simple"
      And API 回應 body 包含 `type_change_skipped: true`（提示未實際切換）

  Rule: 帶 confirm_type_change=true 但 type 非合法值時拒絕

    Example: type 非 simple/external 時 API 回 400
      When 管理員 "Admin" 更新課程 100，參數如下：
        | type     | confirm_type_change |
        | bundle   | true                |
      Then 操作失敗
      And API 回應 HTTP 400
      And 錯誤訊息為「切換目標類型僅支援 simple 或 external」
