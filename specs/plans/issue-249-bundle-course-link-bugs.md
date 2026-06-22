# 實作計劃：銷售方案課程連結兩大 BUG 修復（Issue #249）

## 概述

銷售方案（bundle product）有兩套各自獨立、從不同步的課程連結 meta——`link_course_ids`（後台「歸屬」顯示用）與 `bind_courses_data`（購買「實際授權」用）。這個雙軌設計衍生兩個高信心已確認的 bug：

- **BUG 1**：複製課程時，`Duplicate.php::duplicate_product()` 只改寫 `link_course_ids` 與 `pbp_product_ids`，**完全沒碰 `bind_courses_data` / `bind_course_ids`**，導致複製出的方案購買時授權到「舊課程」，且後台殘留的舊綁定無移除入口、刪不掉。
- **BUG 2**：`get_product_ids_with_compat()` 在 `exclude_main_course !== 'yes'` 時無條件把 `link_course_id` 補回商品列表，而儲存流程每次都 `delete_post_meta(exclude_main_course)`、前端又無任何 UI 能寫入 `exclude_main_course='yes'`，形成「移除課程 → 讀回被補回 → 前端 unshift 回畫面」的死循環。連購買端（`Order.php:113`）也走 compat，使授權無法真正去除。

本計劃以一個「**持久化、不被儲存流程清除**的 explicit 旗標」取代廢棄的 `exclude_main_course`，讓讀取 / 格式化 / 購買三處一致尊重「使用者已明確編輯過此方案」的意圖；並補齊複製流程對 `bind_courses_data` / `bind_course_ids` 的 ID 替換；前端提供站長「移除課程 / 不含主課程」的明確控制，以及在課程編輯頁逐筆清除殘留錯誤綁定（重用既有 `products/unbind-courses` 端點）。

## 範圍模式：HOLD SCOPE

**預估影響**：生產檔案約 13 個（PHP 後端 5 + React 前端 4 + i18n 對照表 1 + 自動產生的 languages 4 檔算 1 批），測試與 spec 約 5 個（修改 2 + 新增 2 + spec 2）。範圍由使用者 prompt 明確收斂（兩 bug、根因已定、UI 必做）。本計劃專注於防彈架構與向下相容，不擴張需求。**未達 REDUCTION 門檻（>15 生產檔），亦未達 >30 需回報門檻。** 透過分階段、各階段可獨立合併控制節奏。

## 需求重述

正在建構什麼、服務對象、成功樣子：

1. **複製正確性（BUG 1 後端）**：複製課程 / 方案時，`duplicate_product()` 在改 `link_course_ids` 的同時，對新方案的 `bind_courses_data`（與 `bind_course_ids`）做 `old_course_id → new_parent` 替換（id / name 更新、limit 保留），對齊既有 `pbp_product_ids` 替換邏輯。複製鏈 A→B→C 每代正確指向當代課程。
2. **既有髒資料清理途徑**：站長現場已有殘留舊綁定的複製方案（如 post_id=271）。需提供「後台 UI 逐筆移除方案上錯誤 / 殘留綁定課程」的入口（既修新案也清舊案）；是否另做一次性 migration 由本計劃評估後決定（**結論見「向下相容 / Migration 策略」：採 UI-only，不做 DB migration**）。
3. **可去除課程（BUG 2 後端）**：以持久化且不被儲存清除的旗標取代 `exclude_main_course`，表達「此方案刻意不含歸屬課程」。`get_product_ids_with_compat()` / `format_product_details`（Product.php:590）/ `Order.php:113` 三處一致尊重它。`format_product_details` 對「使用者已明確編輯過」的方案回真實 `get_product_ids()` 而非無條件補課程。一併修正 axios `toFormData` 對空陣列 `[]` 整個略過欄位導致「清空所有商品時舊 meta 不更新」的次要 bug。
4. **去除課程 UI（BUG 2 前端）**：`BundleForm` 提供站長「不含主課程 / 去掉課程」的明確控制（switch + 既有移除按鈕的持久化），修正 `useEffect` 不再無條件把 course `unshift` 回 `selectedProducts`。
5. **殘留綁定移除 UI（BUG 1 前端）**：課程編輯頁「銷售方案」分頁（CourseBundles / ListItem）讓站長看到並移除方案上錯誤 / 殘留的綁定課程，連到既有 `products/unbind-courses` 流程。
6. **測試**：修正 / 補 PHPUnit 整合測試（複製後 `bind_courses_data` 指向新課程、去掉課程後不重置、購買授權正確），更新被固化錯誤行為的測試與 `.feature` spec。
7. **i18n**：新增 UI 字串遵守 `.claude/rules/i18n.rule.md`（英文 msgid、`power-course` domain、`manual.json`）。
8. **品質**：PHP 過 `pnpm run lint:php`（PHPCS + PHPStan level 9）；TS 過 `pnpm run lint:ts`。

## 關鍵事實確認（已重讀程式碼，行號為本 worktree 現況）

| 事實 | 位置 |
| --- | --- |
| 複製唯一入口；只改 `link_course_ids`（:176）+ 替換 `pbp_product_ids`（:189-200）/ `pbp_product_quantities`（:203-213），**未碰 bind_*** | `inc/classes/Utils/Duplicate.php::duplicate_product()` :158-224 |
| `WC_Admin_Duplicate_Product->product_duplicate()` 用 `clone` 原封複製所有 meta，專案無掛 `woocommerce_duplicate_product_exclude_meta` filter | `Duplicate.php:166`（grep 全專案無 exclude_meta filter） |
| 複製鏈透過此 hook 串接（`duplicate_bundle_product` :384-407 反查 `link_course_ids` 找原課程方案） | `Duplicate.php:27-29, 384-407` |
| compat 補課程的唯一條件 = `exclude_main_course === 'yes'`，否則 `array_unshift` link_course_id | `inc/classes/BundleProduct/Helper.php::get_product_ids_with_compat()` :319-337 |
| 讀取 / API response 一律回 compat 結果（含 `pbp_product_quantities` 計算亦走 compat :598） | `inc/classes/Api/Product.php:590-607` |
| 儲存時無條件 `delete_post_meta(exclude_main_course)` | `Product.php:1059`（`handle_special_fields`） |
| `bind_course_ids` 透過 `add_array_meta_keys` 每次儲存「**只增不刪**」add 回 `bind_courses_data`（:1073-1088）；`add_course_data` 只增不 reconcile（:95-104） | `Product.php:1073-1088`、`inc/classes/Resources/Course/BindCoursesData.php:95-104` |
| 購買端：付款時 `get_product_ids_with_compat()`（:113）加子商品；`_bind_courses_data` 快照（:191）→ 授權逐筆 `add_item(course_id)`（:265-273） | `inc/classes/Resources/Order.php:113, 191, 254-275` |
| 授權真相來源 = product 的 `bind_courses_data`，`link_course_ids` / `pbp_product_ids` 不直接授權 | `Order.php:188(快照), 238-273(授權)` |
| **已存在可重用端點**：`products/unbind-courses` 同時清 `bind_course_ids`（:428）與 `bind_courses_data`（remove_course_data + save :434-438） | `Product.php::post_products_unbind_courses_callback` :407-454 |
| 前端 `UnbindCourses` 元件已封裝呼叫該端點 | `js/src/components/product/UnbindCourses/index.tsx` |
| 前端建立方案時 `link_course_ids:[courseId]` + `pbp_product_ids:[courseId]` → link_course_id 永遠 >0，compat 必觸發 | `js/src/.../CourseBundles/index.tsx:97-104` |
| `BundleForm` useEffect 把 course `unshift` 回畫面（:149-158）；無 exclude UI | `js/src/.../CourseBundles/Edit/BundleForm.tsx:143-197` |
| **每次儲存 bundle 都送 hidden `bind_course_ids:[courseId]`（initialValue 永遠當前課程）** → 即使 unbind 後，下次存又被 add 回 | `js/src/.../CourseBundles/Edit/ProductPriceFields/index.tsx:37-42` |
| 殘留綁定的方案在 ListItem 用 `ProductBoundCourses` **唯讀顯示** `bind_courses_data`（無移除入口） | `js/src/.../CourseBundles/ListItem/index.tsx:144-150`、`js/src/components/product/ProductBoundCourses/index.tsx`（純顯示） |
| 被固化的錯誤測試（斷言 compat 自動補入課程） | `tests/Integration/BundleProduct/BundleProductQuantityTest.php:327-362` |
| 複製課程 spec 只驗 `link_course_ids`，無 `bind_courses_data` 檢查 | `specs/features/course/複製課程.feature:63-70` |
| 「移除排除當前課程」需求已被 spec 規格化為**正確行為**（Issue #185），但實作層 compat 仍補回，產生矛盾 | `specs/features/bundle/移除排除當前課程功能.feature`（全檔） |
| MCP 一致性點：兩個 tool 也 `delete_post_meta(exclude_main_course)` | `inc/classes/Api/Mcp/Tools/Bundle/BundleSetProductsTool.php:204`、`BundleDeleteProductsTool.php:174` |
| 前台卡片也走 compat | `inc/templates/components/card/bundle-product.php:32` |
| `WcProduct::update_meta_array` / `add_meta_array`：傳空陣列 = 只刪不增（行為可用） | 外部庫 `J7\WpUtils`，呼叫於 `Product.php:319,428,1067,1078` |
| `LifeCycle.php` 不參與複製（:308-328 是刪除課程清理），複製只走 `Duplicate.php` | 已確認 |

## 已知風險（來自研究）

| 風險 | 緩解措施 |
| --- | --- |
| **資料模型本質衝突**：`link_course_ids`（顯示 / 反查）與 `bind_courses_data`（授權）雙軌，本計劃**不重構合併**（規模過大、超出 bug 範圍），僅讓兩者在「複製」與「去除」兩條路徑同步 | HOLD SCOPE；在計劃「限制條件」明示不合併，留待後續架構重構 |
| 新旗標若沿用 `exclude_main_course` 鍵名，會被既有三處 `delete` 抹除（Product.php:1059、兩個 MCP tool）；若沿用又得移除這些 delete，破壞 Issue #185 既有「遷移清除」語義 | **採全新 meta key `bundle_edited_product_ids`（語義：使用者已明確設定過商品列表，true=尊重原值不補課程）**，與 `exclude_main_course` 完全脫鉤，不受既有 delete 影響。詳見「核心設計決策」 |
| compat 是「向下相容舊資料」的必要邏輯——舊方案（從未編輯過、無 explicit 旗標）仍需自動補課程，不能直接拿掉 compat，否則舊方案前台 / 購買會少課程 | 旗標預設 absent=舊行為（補課程）；只有「使用者編輯儲存過一次」才寫旗標 = 'yes'，之後尊重真實列表。新舊各得其所 |
| `toFormData` 對空陣列 `[]` 略過欄位 → 清空所有商品時 `pbp_product_ids` 舊 meta 不更新（次要 bug，與 Issue #203 同類） | 比照 Issue #203 解法：前端在 `handleOnFinish` 顯式把空 `pbp_product_ids` 標記為可辨識的空值（送 `'[]'` 字串），後端 `handle_special_fields` 辨識並清空 meta。詳見資料流 2 |
| 前端 `ProductPriceFields` hidden `bind_course_ids:[courseId]` 每次存都把當前課程 add 回 `bind_courses_data` → 與「去除課程」「移除殘留」直接衝突（unbind 後一存又回來） | 必須一併修正：`bind_course_ids` 改為由 `selectedProducts` 中「屬於課程的項目」動態推導，而非寫死 `[courseId]`。詳見前端階段 |
| 複製鏈深度（A→B→C）：`bind_courses_data` 的 `id` 替換必須以「**當代被複製課程的 old_course_id**」為基準，不能假設只有一層 | 沿用 `duplicate_product()` 既有的 `$old_course_id = link_course_ids(原方案)` 基準（每代 hook 重新計算），對 bind_* 套用同一基準 |
| `BindCourseData` 建構子 `course_id` 為 0 會 throw（:43-45），複製時若 new_parent 為 0 / 非數字 | 複製替換僅在 `is_numeric($new_parent) && $new_parent > 0` 分支內進行（已在 :174 條件內），與 link_course_ids 同分支 |
| PHPStan level 9：新 meta 讀取的型別、`array_filter` 後 `array_values` 重整 | 所有新程式碼補 PHPDoc、明確轉型；array meta 一律 `array_values()` |
| 移除 / 修改被固化測試（BundleProductQuantityTest:327-362）可能影響其他依賴 compat 的測試（BundleOrderQuantityTest:151 等） | 綠燈前先跑全 bundle group 測試確認連動；compat 對「無旗標舊方案」行為不變，故依賴舊行為的測試應仍綠 |
| 未發現額外第三方版本相容性風險（WC duplicate API、Refine、antd 皆既有使用） | 備註：未發現額外已知風險 |

## 核心設計決策

### 決策 A：新旗標 `bundle_edited_product_ids`（取代廢棄 `exclude_main_course`）

- **語義**：post meta，值 `'yes'` 表示「站長已明確編輯並儲存過此方案的商品列表」。`absent` / 非 `'yes'` = 舊方案（從未編輯），維持 compat 自動補課程。
- **為何不用布林「不含主課程」**：使用者要的是「能自由增減課程」。只要編輯儲存過一次，列表就是 single source of truth——無論列表含不含課程都尊重它。這比 `exclude_main_course`（只能表達二元「排除 / 包含」）更貼近 Issue #185 spec 的「當前課程視為普通商品」精神。
- **寫入時機**：`handle_special_fields` 處理 `pbp_product_ids` 時，只要該 key 有出現在 payload（含顯式空值），就 `update_post_meta(bundle_edited_product_ids, 'yes')`。
- **讀取尊重點**：`get_product_ids_with_compat()` 在 `bundle_edited_product_ids === 'yes'` 時直接回 `get_product_ids()`（不補課程）；同時保留 `exclude_main_course === 'yes'` 的舊判斷（向下相容已設過舊旗標的方案）。
- **不受既有 delete 影響**：三處 `delete_post_meta(exclude_main_course)` 完全不動（維持 Issue #185 遷移語義），新 key 與其正交。

### 決策 B：BUG 1 髒資料採 UI-only 清理，不做 DB migration

- **Trade-off 分析**：
  - DB migration 需自動判斷「哪些 `bind_courses_data` 是錯誤殘留」——但複製後新方案的 `bind_courses_data` 殘留舊 course_id，與「站長刻意綁多課程」在資料上無法可靠區分（多綁課程是合法功能）。自動清理有誤刪風險。
  - 站長現場髒資料量有限（手動複製產生），UI 逐筆移除可控、零誤刪、且同一入口長期可用（未來任何錯綁都能清）。
- **結論**：**不做一次性 migration**。提供 CourseBundles 分頁的逐筆移除 UI（重用 `products/unbind-courses`）。Fix 上線後，複製不再產生新髒資料；舊髒資料由站長按需清理。
- 在計劃「限制條件」明示此決策，交接 tdd-coordinator 時一併傳達。

## 架構變更

### 後端（PHP）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/classes/Utils/Duplicate.php` | 修改 | `duplicate_product()` :185-214 既有 `$old_course_id !== new_parent` 分支內，新增對 `bind_courses_data` 與 `bind_course_ids` 的 `old_course_id → new_parent` 替換：(1) `bind_course_ids` array 中 `(string)old → (string)new`；(2) `bind_courses_data` 逐筆 `id===old` 改為 `new`、`name` 以 `get_the_title(new_parent)` 更新、`limit_*` 保留。用 `update_post_meta` 寫回新方案。 |
| `inc/classes/BundleProduct/Helper.php` | 修改 | (1) 新增 `const EDITED_PRODUCT_IDS_META_KEY = 'bundle_edited_product_ids'`；(2) `get_product_ids_with_compat()` :319-337 在現有 `exclude_main_course==='yes'` 判斷後，新增 `bundle_edited_product_ids==='yes'` 一律回 `get_product_ids()`（不補課程）。 |
| `inc/classes/Api/Product.php` | 修改 | (1) `handle_special_fields` 處理 `pbp_product_ids`（在 `update_array_meta_keys` 迴圈，:1062-1070）時，若 payload 含此 key（含顯式空值 `'[]'`）→ 解析空陣列、`WcProduct::update_meta_array` 清空、並 `update_post_meta(EDITED_PRODUCT_IDS_META_KEY,'yes')`；(2) `add_array_meta_keys` 的 `bind_course_ids` reconcile：改為「以 payload 的 `bind_course_ids` 為準，先 remove 不在清單內的舊綁定，再 add 新的」——讓 unbind 後再儲存不會復活（呼叫 `remove_course_data`）；(3) `format_product_details`（:590, :598）改呼叫一個「尊重 edited 旗標」的取得邏輯（即 step (1) 修好的 `get_product_ids_with_compat` 已涵蓋，無需改 :590 本身，僅確認語義）。 |
| `inc/classes/Api/Mcp/Tools/Bundle/BundleSetProductsTool.php` | 修改 | :196-204 `set_bundled_ids` 後，比照 REST 寫入 `update_post_meta(EDITED_PRODUCT_IDS_META_KEY,'yes')`，使 MCP 設定商品也尊重 explicit 列表（一致性）。既有 `delete exclude_main_course` 保留。 |
| `inc/classes/Api/Mcp/Tools/Bundle/BundleDeleteProductsTool.php` | 修改 | :174 移除商品後同樣寫 `bundle_edited_product_ids='yes'`（一致性）。 |

> 購買端 `Order.php:113`、前台 `bundle-product.php:32` **無需改動**——兩者皆呼叫 `get_product_ids_with_compat()`，Helper 修好後自動尊重新旗標。這是「集中在 Helper 一處修正、多處受益」的設計優點。

### 前端（TypeScript / React）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `js/src/.../CourseBundles/Edit/BundleForm.tsx` | 修改 | (1) 初始化 useEffect（:143-164）：不再無條件 `unshift` 課程——僅當「方案從未編輯過（無 edited 旗標 / 後端回的列表確實含課程）」才顯示課程；改以後端回傳的 `pbp_product_ids` 為唯一真相，移除 :149-156 的強制補入。(2) 同步 useEffect（:166-197）保持以 `selectedProducts` 推導 `pbp_product_ids`，但移除「courseInSelected 時強制把 courseId 放第一位」對「已移除課程」的覆寫——尊重使用者移除。(3) 既有「Add current course」提示按鈕（:438-461）保留，作為「重新加入」入口（對應 spec「移除後可重新加入」）。 |
| `js/src/.../CourseBundles/Edit/ProductPriceFields/index.tsx` | 修改 | hidden `bind_course_ids`（:37-42）`initialValue` 不再寫死 `[courseId]`；改為由父層依「`selectedProducts` 中屬於課程的項目」動態 `setFieldValue`（課程在列表才綁、移除則不綁），消除「儲存又把課程 add 回 bind_courses_data」的死灰復燃。 |
| `js/src/.../CourseBundles/Edit/index.tsx` | 修改 | `handleOnFinish`（:64-109）：當 `selectedProducts` 為空 / 不含課程時，顯式把 `pbp_product_ids` 送為可辨識空值（`'[]'`，比照 Issue #203 空值處理），確保後端能清空 meta（解 toFormData 略過空陣列 bug）；同步 `bind_course_ids` 依列表推導。 |
| `js/src/.../CourseBundles/ListItem/index.tsx` | 修改 | 在 `ProductBoundCourses` 唯讀顯示（:144-150）旁，為每筆綁定課程加「移除」入口（重用 `UnbindCourses` 元件 / 直接呼叫 `products/unbind-courses`，帶 `product_ids:[bundleId]`、`course_ids:[該綁定 courseId]`），成功後 invalidate `bundle_products` 列表。這同時修新案殘留與清舊案。 |

> `js/src/components/product/ProductBoundCourses/index.tsx` 維持唯讀（共用元件，多處使用）；移除互動加在 CourseBundles 的 ListItem 容器層，不污染共用元件。

### i18n

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `scripts/i18n-translations/manual.json` | 修改 | 新增本次 UI 字串的繁中對照（英文 msgid → 繁中），如 `Remove this course from bundle`、`Are you sure to remove this course binding?` 等。 |
| `languages/power-course.pot` / `-zh_TW.po` / `.mo` / `.json` | 自動產生 | 跑 `pnpm run i18n:build` 後一併 commit（禁止手改 .po）。 |

### 測試 / Spec

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `tests/Integration/BundleProduct/BundleProductQuantityTest.php` | 修改 | :327-362 三個「固化錯誤行為」測試：保留「無旗標舊方案 → 仍補課程」（向下相容正確），**新增**「`bundle_edited_product_ids='yes'` → 不補課程」對照組；確保語義正確。 |
| `tests/Integration/Course/CourseDuplicateBundleBindTest.php` | 新增 | BUG 1：複製課程後新方案 `bind_courses_data` / `bind_course_ids` 指向新課程（非舊）、name 更新、limit 保留；A→B→C 複製鏈每代正確；購買新方案授權到新課程。 |
| `tests/Integration/BundleProduct/BundleRemoveCourseTest.php` | 新增 | BUG 2：編輯儲存（寫 edited 旗標）後移除課程 → 讀回不補課程、再存不復活；`pbp_product_ids` 清空（toFormData 空陣列）正確；購買不授權被移除課程。 |
| `specs/features/course/複製課程.feature` | 修改 | :63-70 規則補 `bind_courses_data` 指向新課程的 Example。 |
| `specs/features/bundle/移除排除當前課程功能.feature` | 修改 | 對齊新旗標語義（把 `exclude_main_course` 向下相容段補上 `bundle_edited_product_ids` 新方案路徑的 Example；複製段 :140-159 補 bind 同步）。 |

## 資料流分析

### 資料流 1：複製課程 → 方案 meta 替換（BUG 1，Write Path）

```
複製課程觸發           duplicate_product()            既有替換                      新增替換（本次）              寫回
─────────             ──────────────────             ────────                      ──────────────              ────
do_action     ──▶  WC clone 全 meta(含舊bind) ──▶  link_course_ids=new(:176) ──▶  bind_course_ids: old→new ──▶ update_post_meta
(:52)              new_product_id                    pbp_product_ids old→new        bind_courses_data:           (新方案)
                        │                            (:189-200)                       逐筆 id==old → new
                        ▼                            pbp_quantities old→new           name=get_the_title(new)
                  [old_course_id<=0?]→skip           (:203-213)                       limit_* 保留
                  [new_parent 非數字?]→skip                                          [bind 空陣列?]→只清不改
                  [bind_courses_data 非 array?]→skip                                 [id!=old?]→原樣保留(多綁課程)
```

Shadow paths：
- nil：`old_course_id <= 0`（原方案無 link）→ 不替換（既有 :185 條件涵蓋）。
- empty：`bind_courses_data` 為空 / 非陣列 → 無事可做，跳過（不可 throw）。
- partial：`bind_courses_data` 有多筆（站長綁多課程）→ 只替換 `id===old_course_id` 的那筆，其餘原樣保留。
- error：`BindCourseData` 對 course_id=0 throw → 替換前確保 new_parent 為正整數（在既有 numeric 分支內）。

### 資料流 2：儲存方案商品列表（BUG 2，Write Path，含空陣列）

```
BundleForm           handleOnFinish(前端)         toFormData            POST .../bundle_products/{id}     handle_special_fields(後端)
──────────           ──────────────────          ──────────            ────────────────────────────     ──────────────────────────
selectedProducts ─▶ pbp_product_ids = ids     ─▶ 一般陣列正常       ─▶ $meta_data['pbp_product_ids'] ─▶ update_meta_array(ids)
(可空/不含課程)       [空?]→送 '[]' 字串            [空[]?]→以前略過!      [存在?]                            + update_post_meta(
                     bind_course_ids =            (本次修為 '[]')                                              bundle_edited_product_ids='yes')
                       課程在列表?[courseId]:[]                                                              [payload 無此 key?]→保持原狀
                                                                                                            bind_course_ids reconcile:
                                                                                                              remove 不在清單者(unbind 不復活)
```

Shadow paths：
- empty：`selectedProducts` 清空 → 前端送 `'[]'` → 後端辨識 → `update_meta_array(product_id,'pbp_product_ids',[])` 清空 + 寫 edited 旗標。
- nil：payload 完全無 `pbp_product_ids` key → 保持原狀（向下相容既有合約，不可誤清）。
- conflict：使用者移除課程但 hidden `bind_course_ids` 仍寫死 courseId → 修為動態推導，移除即不綁。

### 資料流 3：讀取方案（Read Path，含 compat 決策）

```
GET bundle      format_product_details        get_product_ids_with_compat()             回傳前端
──────────      ──────────────────────        ─────────────────────────────             ────────
$helper    ──▶  pbp_product_ids =        ──▶  [exclude_main_course==='yes'?]→真實列表  ──▶ BundleForm 初始化
(:522)          get_product_ids_with_compat    [bundle_edited_product_ids==='yes'?]        以此為唯一真相
                (:590)                          → 真實 get_product_ids()(本次新增)         (不再前端 unshift)
                                                [else 舊方案]→補 link_course_id(向下相容)
```

Shadow paths：
- 新方案編輯過：旗標='yes' → 回真實列表（含 / 不含課程皆尊重）。
- 舊方案未編輯：無旗標 → 補課程（向下相容，前台 / 購買不少課）。
- empty：`get_product_ids()` 空 + 旗標 yes → 回空陣列（合法：純訂閱不含任何商品的方案）。

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| `Duplicate::duplicate_product` bind 替換 | `bind_courses_data` 結構非預期（缺 `id`） | 資料完整性 | `is_array($item) && isset($item['id'])` 守衛，跳過異常筆 | 否（靜默跳過，記錄複製仍成功） |
| 同上 | `new_parent` 非正整數 | 前置條件 | 僅在既有 `is_numeric && >0` 分支執行 | 否 |
| `handle_special_fields` 清空 pbp | `'[]'` 解析失敗 | 反序列化 | `json_decode` 失敗 fallback `[]`（既有模式） | 否 |
| `handle_special_fields` bind reconcile | `remove_course_data` 後 save 失敗 | DB 寫入 | `update_post_meta` 回 false → 既有 `$failed_ids` 累積回報 | 是（前端 message.error） |
| `products/unbind-courses`（ListItem 移除） | product_ids / course_ids 缺 | 參數驗證 | 既有 `WP::include_required_params`（:410）回 WP_Error 400 | 是（`Failed to unbind`） |
| 前端 ListItem 移除 mutation | 網路 / 後端 500 | 非同步 | `onError` message.error；列表不樂觀更新，失敗保留原狀 | 是 |
| `BundleForm` 讀取 `pbp_product_ids` | 後端回非陣列 | 型別 | `record?.[INCLUDED_PRODUCT_IDS_FIELD_NAME] \|\| []` 既有守衛 | 否 |
| `get_product_ids_with_compat` | meta 讀取 falsy | nil | 既有 `is_array` 守衛（Helper:156） | 否 |

> 無「處理方式=無 且 使用者可見=靜默」項目 → 無 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| 複製鏈 bind 替換 | 多綁課程其一誤改 | 是（只改 id===old） | 新增 IT | 否 | N/A（不會誤改） |
| 複製鏈 A→B→C | 第三代仍指向第一代 | 是（每代 hook 重算 old） | 新增 IT（複製鏈） | 否 | N/A |
| 去除課程 | 儲存後讀回又補課程 | 是（edited 旗標） | 新增 IT | 是（畫面重置消失） | 重新移除即可 |
| 去除課程 | bind_course_ids 死灰復燃 | 是（動態推導 + reconcile） | 新增 IT | 是 | N/A |
| 清空所有商品 | toFormData 略過 → 舊 meta 殘留 | 是（送 '[]'） | 新增 IT | 是 | N/A |
| ListItem 移除殘留 | 樂觀更新後後端失敗 | 是（不樂觀，onError 回滾） | E2E（建議，非阻塞） | 是 | 重試 |
| 舊方案（無旗標） | 修復後前台少課程 | 是（compat 保留） | 既有 IT（補對照組） | 是 | N/A（行為不變） |
| 購買被移除課程的方案 | 仍授權舊課程 | 是（Order 走 compat，尊重旗標） | 新增 IT | 是（學員多開課程） | N/A |

## 實作步驟

> 階段順序：先後端核心（可獨立測試與合併）→ 後端一致性（MCP）→ 前端 → i18n → 測試固化更新。各階段可獨立合併。TDD 由 tdd-coordinator 協調（Red→Green→Refactor），測試先行。

### 第一階段：後端核心修復（PHP，可獨立合併）— 派 wordpress-master

1. **新增 explicit 旗標常數與 compat 尊重**（檔案：`inc/classes/BundleProduct/Helper.php`）
   - 行動：新增 `const EDITED_PRODUCT_IDS_META_KEY = 'bundle_edited_product_ids';`；`get_product_ids_with_compat()`（:319-337）在 `exclude_main_course==='yes'` 判斷後，加 `if (get_post_meta(product_id, self::EDITED_PRODUCT_IDS_META_KEY, true) === 'yes') return $product_ids;`。
   - 原因：集中在 Helper 一處，讀取 / 購買 / 前台三路自動受益。
   - 依賴：無。風險：低。
2. **儲存時寫旗標 + 清空 pbp + bind reconcile**（檔案：`inc/classes/Api/Product.php`，`handle_special_fields`）
   - 行動：(a) `pbp_product_ids` 處理：payload 含此 key（含 `'[]'`）時解析、`update_meta_array` 寫入（空則清空）、`update_post_meta(EDITED_PRODUCT_IDS_META_KEY,'yes')`；(b) `bind_course_ids`（:1073-1088）改為 reconcile：先 `remove_course_data` 掉不在新清單內的舊綁定，再 add 新的，最後 save。
   - 原因：表達「使用者已明確編輯」、解空陣列 bug、阻止課程綁定復活。
   - 依賴：步驟 1（常數）。風險：中（動到 save 核心，需測試保護未送 key=保持原狀）。
3. **複製時同步 bind_courses_data / bind_course_ids**（檔案：`inc/classes/Utils/Duplicate.php`，`duplicate_product()` :185-214 分支內）
   - 行動：在既有 `pbp_*` 替換後，加 `bind_course_ids`（array old→new）與 `bind_courses_data`（逐筆 id===old → new、name 更新、limit 保留）的替換，`update_post_meta` 寫回新方案。守衛 `is_array` 與 `isset($item['id'])`。
   - 原因：BUG 1 根因修復。
   - 依賴：無（與步驟 1/2 正交，但同 PR 較佳）。風險：中。

### 第二階段：後端 MCP 一致性（PHP，可獨立合併）— 派 wordpress-master

4. **MCP 設定 / 刪除商品也寫旗標**（檔案：`BundleSetProductsTool.php:196-204`、`BundleDeleteProductsTool.php:174`）
   - 行動：在 `set_bundled_ids` / 刪除後 `update_post_meta(Helper::EDITED_PRODUCT_IDS_META_KEY,'yes')`。保留既有 `delete exclude_main_course`。
   - 原因：AI 操作 LMS 時與 REST 行為一致，避免 MCP 設定的列表被 compat 補回。
   - 依賴：步驟 1。風險：低。

### 第三階段：前端 UI 修復（React，可獨立合併）— 派 react-master

5. **BundleForm 尊重後端真實列表、不強制補課程**（檔案：`CourseBundles/Edit/BundleForm.tsx`）
   - 行動：移除初始化 useEffect（:149-156）的強制 `unshift` 課程；同步 useEffect（:166-197）尊重移除狀態；保留「Add current course」作為重新加入入口。
   - 依賴：第一階段（後端需先回正確列表）。風險：中（涉及 jotai atom 與 form 同步）。
6. **bind_course_ids 動態推導**（檔案：`CourseBundles/Edit/ProductPriceFields/index.tsx` + `index.tsx::handleOnFinish`）
   - 行動：hidden `bind_course_ids` 不再 `initialValue=[courseId]`；改由 `selectedProducts` 中課程項目推導；`handleOnFinish` 空 `pbp_product_ids` 送 `'[]'`。
   - 依賴：步驟 5。風險：中。
7. **CourseBundles ListItem 加殘留綁定移除入口**（檔案：`CourseBundles/ListItem/index.tsx`）
   - 行動：每筆 `bind_courses_data` 旁加移除按鈕（重用 `UnbindCourses` / 直呼 `products/unbind-courses`，`product_ids:[bundleId]`、`course_ids:[courseId]`），成功 invalidate `bundle_products`。
   - 原因：BUG 1 髒資料清理途徑（UI-only 決策）。
   - 依賴：無（後端端點已存在）。風險：低。

### 第四階段：i18n（可與第三階段同 PR）— 派 react-master / wordpress-master

8. **新增字串對照 + 跑 pipeline**（檔案：`scripts/i18n-translations/manual.json` → `pnpm run i18n:build`）
   - 行動：英文 msgid 寫程式 + 繁中加 manual.json + 跑 build + commit `.pot/.po/.mo/.json`。
   - 依賴：第三階段字串定稿。風險：低。

### 第五階段：測試固化更新 + 新測試 + spec — 派 test-creator（IT/E2E）+ wordpress-master（修固化測試）

9. 修 `BundleProductQuantityTest.php:327-362`（補 edited 旗標對照組）；新增 `CourseDuplicateBundleBindTest.php`、`BundleRemoveCourseTest.php`；更新兩個 `.feature`。
   - 依賴：第一～三階段實作完成。風險：中（需確認連動測試不破）。

## 測試策略

- **整合測試（PHPUnit，主力）**：
  - `CourseDuplicateBundleBindTest`：複製後新方案 `bind_courses_data[0]['id']` == 新課程、`name` == 新課程標題、`limit_*` 保留；`bind_course_ids` == [新課程]；A→B→C 鏈每代正確；建單付款後 `add_item` 授權到新課程（驗 `avl_coursemeta` 或 student）。
  - `BundleRemoveCourseTest`：模擬 `handle_special_fields` 送含課程 / 不含課程 / 空 `'[]'` 的 `pbp_product_ids` → 旗標='yes'、`get_product_ids_with_compat` 不補課程、再次儲存不復活；購買不授權被移除課程。
  - 修 `BundleProductQuantityTest`：保留無旗標補課程（向下相容），新增旗標='yes' 不補課程。
- **回歸**：跑 `tests/Integration/BundleProduct/*` + `tests/Integration/Product/{Unbind,UpdateBound}CoursesTest` + `tests/Integration/Course/CourseCRUDTest`，確認 compat 舊行為與 unbind 端點不破。
- **E2E（Playwright，建議非阻塞）**：CourseBundles 分頁移除殘留綁定 → 列表不再顯示該課程；BundleForm 移除課程 → 儲存 → 重開不重置。
- **測試執行指令**：
  - `composer run test`（PHPUnit 全部）
  - 針對性：`composer run test -- --group bundle` / `--filter CourseDuplicateBundleBindTest`
  - `pnpm run test:e2e:admin`（E2E admin）
- **關鍵邊界情況**：多綁課程複製只替換對應筆；舊方案無旗標前台不少課；清空所有商品（純訂閱方案）；複製鏈深度 ≥3；unbind 後再儲存不復活。

## 依賴項目

- 既有 REST 端點 `products/unbind-courses`（已存在，前端重用）。
- 既有前端元件 `UnbindCourses`、`ProductBoundCourses`、`PopconfirmDelete`。
- WC `WC_Admin_Duplicate_Product`（既有）、`J7\WpUtils\WcProduct::{update,add}_meta_array`（既有）。
- i18n pipeline `pnpm run i18n:build`。
- 無新增 npm / composer 依賴。

## 風險與緩解措施

- **高**：動到 `handle_special_fields` save 核心（步驟 2）可能影響所有 bundle 儲存 — 先寫測試鎖定「未送 key=保持原狀」「送空=清空」兩條合約，綠燈前跑全 bundle group 回歸。
- **中**：前端 useEffect / jotai 同步重構（步驟 5/6）易引入「移除後又跳回」殘留 — 以「後端列表為唯一真相」原則重寫，E2E 驗證重開不重置。
- **中**：複製 bind 替換多綁課程場景（步驟 3）— 測試覆蓋「只改 id===old，其餘保留」。
- **中**：修改固化測試（步驟 9）牽連其他依賴 compat 的測試 — compat 對無旗標方案行為不變，理論上不破；綠燈前全量跑確認。
- **低**：MCP 一致性（步驟 4）、ListItem 移除 UI（步驟 7）、i18n（步驟 8）皆為加法 / 重用既有，風險低。

## 錯誤處理策略

採「**靜默跳過異常資料 + 顯式回報操作失敗**」雙軌：複製時的資料完整性問題（缺 id、結構異常）靜默跳過單筆但不中斷複製（複製本身仍成功）；使用者主動操作（移除綁定、儲存方案）的失敗透過既有 `$failed_ids` / Refine `onError` message.error 明確回報。讀取路徑全程 nil-safe（既有 `is_array` / `?? []` 守衛），不向使用者拋讀取例外。

## 限制條件（此計劃不會做的事）

- **不重構 `link_course_ids` 與 `bind_courses_data` 雙軌資料模型**（規模過大、超出 bug 範圍）；僅讓兩者在「複製」與「去除」兩條路徑同步。雙軌合併留待後續架構重構。
- **不做一次性 DB migration 清理舊髒資料**（誤刪風險，見決策 B）；改提供 UI 逐筆移除。
- **不移除既有三處 `delete_post_meta(exclude_main_course)`**（維持 Issue #185 遷移語義）；新旗標與其正交。
- **不改購買端 `Order.php` 與前台 `bundle-product.php`**（修 Helper 一處即自動受益）。
- **不改共用元件 `ProductBoundCourses` 為可互動**（移除入口加在 CourseBundles 容器層）。
- 不處理「站長刻意多綁課程」與「複製殘留」在 DB 上的自動辨識（無法可靠區分，故 UI-only）。

## 成功標準

- [ ] 複製課程 / 方案後，新方案 `bind_courses_data` / `bind_course_ids` 指向新課程（id + name 更新、limit 保留）；A→B→C 鏈每代正確（PHPUnit 綠）。
- [ ] 購買複製出的方案，授權到新課程而非舊課程（PHPUnit 綠）。
- [ ] 編輯儲存過的方案移除課程後，讀回不補課程、再存不復活、購買不授權（PHPUnit 綠 + 手動 / E2E 驗證重開不重置）。
- [ ] 清空所有商品（純訂閱方案）儲存後，`pbp_product_ids` meta 確實清空（PHPUnit 綠）。
- [ ] 舊方案（無 edited 旗標）前台與購買仍含課程（向下相容回歸綠）。
- [ ] 課程編輯頁 CourseBundles 分頁可逐筆移除方案上的殘留 / 錯誤綁定課程，移除後列表更新（手動 / E2E）。
- [ ] BundleForm 提供移除課程的明確控制，移除可持久。
- [ ] MCP 設定 / 刪除商品後尊重 explicit 列表（不被 compat 補回）。
- [ ] 新 UI 字串符合 i18n rule（英文 msgid、power-course domain、manual.json、build 四檔 commit）。
- [ ] `pnpm run lint:php`（PHPCS + PHPStan level 9）與 `pnpm run lint:ts` 全綠。
- [ ] 被固化的錯誤測試已更新為正確語義，全 bundle group 回歸綠。

## 預估複雜度：中
