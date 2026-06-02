# 實作計劃：免費課程三種異常情境修復 (Issue #231)

## 概述

Issue #231 回報免費課程在特定定價組合下的三個異常，clarifier 已收斂為四個工作項：

1. **Bug #1（PHP，根因已確認）**：免費課程 + 隱藏單堂課 + 有銷售方案時，免費卡片仍顯示 → 隱藏失效。根因為卡片路由 `single-product.php` 在 `is_free=yes` 時載入 `single-product-free.php` 並 `return`，而 `hide_single_course` 的隱藏判斷只寫在 `single-product-sale.php`，免費卡片從未檢查此開關。
2. **Bug #2（PHP，根因待 spike 確認）**：手動 0 元主課程 + 0 元銷售方案時無法下單。需先以「失敗測試」重現，確認根因後再修，目標讓此組合可正常結帳並自動授權。
3. **Q3（React）**：站長手動把課程價格設為 0（`is_free` 未開）時，後台顯示非阻擋提示，建議改用免費課程開關。
4. **Q5（React）**：在「隱藏單堂課購買」toggle 旁加說明文字「此功能對免費課程同樣生效（會隱藏免費卡片）」。

完整 Q&A 與修訂後驗收標準見 `specs/clarify/2026-05-28-issue231-free-course-bugs.md`。

## 範圍模式：HOLD SCOPE

本任務為 bug 修復，範圍已由 clarifier 明確收斂。**預估影響 7~8 個檔案**（PHP 生產檔 1~2 + React 生產檔 1 + 新測試檔 2~3 + i18n 4 檔由 pipeline 產生）。專注於防彈架構與邊界情況，不擴張範圍。

> ⚠️ Bug #2 的修復檔案數依 spike 結果可能 +1（新增 `FrontEnd/Purchasable.php` 與 Bootstrap 註冊）。若 spike 發現根因落在預期外的位置而導致影響 > 15 檔，須回報並重新走範圍模式判定。

## 需求重述（修訂後驗收標準）

- [ ] AC1：免費課程 + 隱藏單堂課 + **有**已發佈銷售方案 → 前台**不顯示**免費卡片，只顯示方案卡片
- [ ] AC2：免費課程 + 隱藏單堂課 + **無**已發佈銷售方案 → 前台**也不顯示**任何卡片（Q7 A：admin 刻意決策，不干涉）
- [ ] AC3：免費課程 + **未開**隱藏單堂課 → 免費卡片正常顯示（行為不變）
- [ ] AC4：手動 0 元主課程 + 0 元方案 → 學員可正常完成下單結帳
- [ ] AC5：0 元方案訂單完成後 → 自動授予課程授權（與付費方案一致，沿用既有 hook）
- [ ] AC6：站長手動把課程價格設為 0（`is_free` 未開）→ 後台跳出建議改用免費課程開關的提示（不強制、可忽略）
- [ ] AC7：後台「隱藏單堂課購買」toggle 旁顯示說明「此功能對免費課程同樣生效（會隱藏免費卡片）」
- [ ] AC8：現有路徑「主課程非 0 元 + 方案 0 元 + 隱藏單堂課」行為不受影響（回歸保護）
- [ ] AC9：付費課程「隱藏單堂課 + 無方案 = 無購買卡片」行為維持現狀（Q6 B，不在本次改動範圍）

## 程式碼研究結論（根因定位）

| 觀察項目 | 內容 | 來源 |
| --- | --- | --- |
| 卡片路由 | `single-product.php` L38-48：`is_free=yes` → 載入 `single-product-free` 並 return；否則 `single-product-sale` | 已讀 |
| 免費卡片缺檢查 | `single-product-free.php`：L22 product 檢查後直接 `printf`，**無 `hide_single_course` 判斷** ← Bug #1 根因 | 已讀 |
| 付費卡片範本 | `single-product-sale.php` L26-29：`$hide = get_meta('hide_single_course') ?: 'no'; if('yes'===$hide) return;` ← 可直接比照 | 已讀 |
| Sidebar 組裝 | `sider.php` L36 載入 single-product；L41-55 foreach `Helper::get_bundle_products()` 僅渲染 `publish` bundle | 已讀 |
| 訂單授權 | `Resources/Order.php`：`woocommerce_new_order` 加 bundled items（subtotal/total=0）；`woocommerce_order_status_{course_access_trigger}` 觸發授權；trigger 預設 `completed` | 已讀 |
| 0 價 purchasable | 全 codebase **無** `woocommerce_is_purchasable` filter；WC 原生 `is_purchasable()` 對 `get_price()===''` 回 false、`'0'` 回 true | grep 確認 |
| 卡片 enroll 按鈕 | 三張卡片皆以 `$product->is_purchasable() && $product->is_in_stock()` 決定按鈕 disabled | 已讀 |
| 後台定價 UI | `CoursePrice/index.tsx`：`is_free`/`hide_single_course` 兩個 `FiSwitch`（L88-99）；勾 `is_free` 時 `useEffect` 自動把 `regular_price`/`sale_price` 設 0（L33-41） | 已讀 |
| 價格欄位元件 | `ProductPriceFields/Simple.tsx`：`regular_price`/`sale_price` 用 InputNumber `min={0}` | 已讀 |
| FrontEnd 載入點 | `Bootstrap.php` L40-41：`FrontEnd\MyAccount::instance(); FrontEnd\CheckoutRedirect::instance();` ← 新 class 註冊處 | 已讀 |

## 資料流分析

### Bug #1 卡片渲染流（修復後）

```
銷售頁 sider.php
  └─ load_template('card/single-product')           ← $GLOBALS['course']
       ├─ is_external? → single-product-external，return
       ├─ is_free=yes? → single-product-free
       │     └─【新增】hide_single_course=yes? → return（不渲染免費卡片）  ★Bug#1 修復點
       │     └─ 否 → printf 免費卡片
       └─ 否 → single-product-sale（既有 hide 檢查 L26-29）
  └─ foreach publish bundle → card/bundle-product（不受 hide 影響，照常顯示）
```

### Bug #2 結帳授權流（目標行為）

```
前台點 bundle「立即報名」(?add-to-cart=BUNDLE_ID)
  └─ WC add_to_cart_action（需 bundle is_purchasable() = true）   ← Bug#2 疑似閘門
  └─ 結帳 → woocommerce_new_order
       └─ Order::add_course_item_meta → 加 bundled 課程 item（total=0）
  └─ 0 元訂單 → WC 自動完成 → woocommerce_order_status_completed
       └─ Order::add_meta_to_avl_course → AddStudent → 授予課程授權  ★AC5
```

## Bug #2 根因 Spike（紅燈先行，務必照辦）

> **重要**：Bug #2 根因尚未確認。clarify 文件明訂「根因待 tdd-coordinator 深入」。第一個交付物是**能重現回報行為的失敗測試**，確認實際失敗點後再決定修復位置。**禁止**在未重現前直接套用下方任一假設。

### 重現步驟（整合測試）

建立 `tests/Integration/BundleProduct/FreeBundleCheckoutTest.php`，精確重現回報組合：

1. 建立課程商品：`_is_course=yes`、`_regular_price='0'`、`_price='0'`、`is_free` **未設**（維持 `no`）、`publish`、虛擬商品
2. 建立 bundle 商品：`bundle_type='bundle'`、`link_course_ids=課程`、`_regular_price='0'`、`_price='0'`、`pbp_product_ids=[課程]`、`publish`
3. 斷言重現失敗點（逐一驗證以縮小根因）：
   - `wc_get_product($bundle_id)->is_purchasable()` 是否為 `false`？（驗證 H1）
   - `wc_get_product($course_id)->is_purchasable()` 是否為 `false`？
   - 模擬 `$order = wc_create_order()`；`$order->add_product($bundle, 1)`；觸發 `woocommerce_new_order`；`$order->update_status('completed')` → 斷言課程授權是否授予（驗證 AC5 是否獨立壞掉）
4. 對照組：把課程 `_regular_price='999'`、`_price='999'`（其餘不變）重跑，確認「主課程非 0」確實通過 → 鎖定差異來源

### 根因假設（依可能性排序，供 spike 縮小範圍）

| # | 假設 | 驗證方式 | 若成立的修法 |
| --- | --- | --- | --- |
| H1（主） | 0/空價商品 `is_purchasable()` 回 false，導致 enroll 按鈕 disabled / add-to-cart 被擋 | spike 斷言 `is_purchasable()` | 新增 `woocommerce_is_purchasable` filter（見下） |
| H2 | bundle 或課程價格儲存為空字串 `''`（而非 `'0'`），WC 視為未定價→不可購買 | spike 印出 `get_price()` 原始值 | 同 H1 filter 兜底；或修儲存端確保 0 存為 `'0'` |
| H3 | 0 元訂單未達 `course_access_trigger`（virtual 判定 → processing vs completed）導致 AC5 不授權 | spike 走完整 order status 流程斷言授權 | 確保含課程/方案的 0 元訂單到達 trigger 狀態 |
| H4 | 主課程 0 元時被其他邏輯視為「已擁有」而阻擋方案購買 | spike 檢查是否有 add-to-cart 阻擋（grep 已知無，列為低可能） | 視發現再評估 |

### 推測的落地修法（H1/H2 成立時，待 spike 確認後採用）

新增 `inc/classes/FrontEnd/Purchasable.php`（`SingletonTrait`），於建構式註冊：

```php
\add_filter( 'woocommerce_is_purchasable', [ $this, 'force_free_course_purchasable' ], 10, 2 );
```

`force_free_course_purchasable( bool $purchasable, \WC_Product $product ): bool`：
- 僅當 `$product` 為**課程商品**（`CourseUtils::is_course_product()`）或 **bundle 商品**（`Helper::instance($product)?->is_bundle_product`）
- 且狀態為 `publish`
- 且原因僅為「價格為 0 或空」時 → 強制回 `true`
- 其他商品一律回原值 `$purchasable`（嚴格 scope，避免汙染站內其他商品）

並於 `Bootstrap.php` L41 後新增 `FrontEnd\Purchasable::instance();`。

> AC5 授權：spike 若確認 0 元訂單已能到達 `completed` 並授權（對照組旅程 3 已成立），則無需額外動授權邏輯；若 H3 成立才補。

## 架構變更

### 後端（PHP）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/templates/components/card/single-product-free.php` | 修改 | L22 product 檢查後、`printf` 之前，比照 `single-product-sale.php` L26-29 加入 `$hide_single_course = $product->get_meta('hide_single_course') ?: 'no'; if ('yes' === $hide_single_course) { return; }`（Q1 A / Q7 A：一律隱藏，不因 0 方案 fallback）★Bug#1 |
| `inc/classes/FrontEnd/Purchasable.php` | **新增（H1/H2 成立時）** | `woocommerce_is_purchasable` filter，scope 限課程商品 + bundle 商品 + publish + 僅 0/空價放行 ★Bug#2 |
| `inc/classes/Bootstrap.php` | 修改（隨上） | L41 後新增 `FrontEnd\Purchasable::instance();` |

### 前端（React / TypeScript）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx` | 修改 | **Q5**：`hide_single_course` 的 `FiSwitch` 補 `formItemProps.tooltip`（或下方說明文字）「此功能對免費課程同樣生效（會隱藏免費卡片）」。<br>**Q3**：`Form.useWatch(['regular_price'])` + `Form.useWatch(['sale_price'])`，當 `regular_price`(或 sale)為 0 且 `watchIsFree===false` 時，於價格區塊下方顯示 Ant Design 非阻擋提示（`Alert type="info" showIcon` 或 `Typography.Text type="secondary"`），文案「Detected a price of 0. Consider enabling 'This is a free course' instead.」。提示僅顯示、不阻擋儲存、不自動改值 |

> Q3/Q5 皆在同一檔內完成，不新增元件檔。提示元件用既有 antd（`Alert`），不引入新依賴。
> ⚠️ React Hooks 規則：新增的 `useWatch` 必須無條件呼叫（置於既有 `watchIsFree` 旁），不可放進 `isExternal` 條件分支內。

### i18n（新字串）

| 步驟 | 內容 |
| --- | --- |
| 新 msgid（英文，依 `i18n.rule.md`） | 1. `This feature also applies to free courses (hides the free course card)`（Q5 說明）<br>2. `Detected a price of 0. Consider enabling 'This is a free course' instead.`（Q3 提示） |
| 繁中翻譯 | 加到 `scripts/i18n-translations/manual.json`（含 `msgstr_zh_TW` 與 `msgstr_ja`，比照既有條目格式）<br>Q5→「此功能對免費課程同樣生效（會隱藏免費卡片）」<br>Q3→「偵測到價格為 0，建議改用「這是免費課程」開關。」 |
| 重建 | 跑 `pnpm run i18n:build`（pot→merge→mo→json），一併 commit `languages/` 四檔 |

> 術語表確認：`This is a free course` / `Hide single course purchase` 已存在於 UI；新字串為完整句子，無術語衝突。**禁止手改 `.po`**。

### 規格 / 文件

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `specs/clarify/2026-05-28-issue231-free-course-bugs.md` | 已存在 | 無需改動 |
| 本計劃 `specs/plans/issue-231-free-course-bugs.md` | 新增 | 即本檔 |

## 錯誤處理 / 邊界情況登記表

| 情境 | 預期行為 | 對應 AC |
| --- | --- | --- |
| 免費課程 + hide + 有 publish 方案 | 免費卡片 return 不渲染，方案卡片照常 | AC1 |
| 免費課程 + hide + 0 方案 | 免費卡片 return 不渲染，前台無任何卡片（刻意） | AC2 |
| 免費課程 + 未開 hide | 免費卡片正常渲染 | AC3 |
| 免費課程 + hide + 只有 draft/trash 方案 | 免費卡片 return；draft 方案本就不渲染（sider.php L44 過濾）→ 無卡片 | AC2（一致） |
| 付費課程 + hide + 0 方案 | 維持現狀（sale 卡片 return，無卡片） | AC9 不改 |
| bundle 價格為空字串 `''` | filter 強制 purchasable（scope 內） | AC4 |
| 非課程/非 bundle 的 0 元商品 | filter 不介入，維持 WC 原生行為 | 防汙染 |
| 0 元訂單授權 | 沿用既有 `course_access_trigger` hook | AC5 |
| Q3 提示在 `is_free` 開啟後 | `watchIsFree===true` 時不顯示提示（避免重複引導） | AC6 |
| Q3 提示於外部課程 | `isExternal` 時整個價格區塊已隱藏，提示自然不出現 | 無衝突 |

## 失敗模式登記表（回歸風險）

| 風險 | 影響 | 緩解 |
| --- | --- | --- |
| `single-product-free.php` 加 return 後，免費課程「未開 hide」也被誤隱藏 | AC3 破壞 | 嚴格比照 sale 卡片條件 `'yes' === $hide`；測試覆蓋「未開 hide 仍顯示」 |
| `woocommerce_is_purchasable` filter scope 過寬，放行站內其他 0 元商品 | 非預期商品變可購買 | filter 內嚴格判斷「課程商品 OR bundle 商品 + publish」，其餘回原值；測試覆蓋「一般 0 元商品不受影響」 |
| filter 導致缺貨（out of stock）商品也被放行 | 缺貨仍可下單 | filter 只處理「價格」面向，不碰庫存；`is_in_stock()` 仍獨立判斷 |
| Q3 `useWatch` 放進條件分支 | React Hooks error | 無條件呼叫，置於元件頂層 |
| i18n 只跑 `i18n:pot` 沒跑 `i18n:build` | 繁中 fallback 顯示英文 | 依 rule 跑完整 `i18n:build`，commit 四檔 |
| Bug #2 實際根因與 H1 不同（spike 推翻） | 修錯位置 | 強制 spike 重現後才決定修法；本計劃已列 H1-H4 與對應修法 |
| 修 Bug #2 後旅程 3（非 0 主課程 + 0 方案）回歸 | AC8 破壞 | 整合測試保留對照組；filter scope 與 0 價無關時回原值 |

## 實作順序（考慮依賴）

依 TDD Red→Green→Refactor，**每個工作項獨立循環**：

1. **Bug #1（最高信心，先做）**
   - Red：整合測試 `tests/Integration/Course/FreeCardHideTest.php`——render `card/single-product`（用 `Plugin::load_template(..., false)` 捕捉輸出），斷言：
     - 免費 + hide=yes → 輸出為空（不含 `Buy now` / `Free course`）
     - 免費 + hide=no → 輸出非空（含 `Free course`）
   - Green：`single-product-free.php` 加 hide 檢查
   - 跑 `pnpm run lint:php`

2. **Bug #2（spike 先行）**
   - Red：`tests/Integration/BundleProduct/FreeBundleCheckoutTest.php`——重現「0 主課程 + 0 方案」失敗，含對照組「999 主課程 + 0 方案」通過
   - 確認根因（H1-H4）→ Green：依發現實作（推測 `FrontEnd/Purchasable.php` + Bootstrap 註冊）
   - 補 AC5 授權斷言（0 元方案訂單 completed → 授權）
   - 跑 `pnpm run lint:php` + `composer run test`

3. **Q5 後台說明（React，獨立）**
   - 在 `CoursePrice/index.tsx` 的 hide toggle 加 tooltip/說明
   - 新增 i18n msgid

4. **Q3 後台提示（React，獨立）**
   - `useWatch` 價格 + 非阻擋 `Alert`
   - 新增 i18n msgid

5. **i18n 收尾**
   - `manual.json` 補兩條繁中/日文翻譯
   - `pnpm run i18n:build`，commit `languages/` 四檔

6. **前端建置驗證**：`pnpm run lint:ts` + `pnpm run build`

## 測試策略

### PHP Integration Test（PHPUnit，主力）

| 測試檔 | 覆蓋 | 重點案例 |
| --- | --- | --- |
| `tests/Integration/Course/FreeCardHideTest.php`（新） | Bug #1 / AC1-3 | 免費+hide=yes→無輸出；免費+hide=no→有輸出；免費+hide=yes+0方案→無輸出（AC2） |
| `tests/Integration/BundleProduct/FreeBundleCheckoutTest.php`（新） | Bug #2 / AC4-5、AC8 | 0主+0方案 is_purchasable=true；0元訂單 completed→授權；對照組 999主+0方案通過；一般 0 元商品不受 filter 影響（防汙染） |

> 範本參考：`tests/Integration/Order/OrderAutoGrantCourseTest.php`（授權斷言 `assert_user_has_course_access`）、`tests/Integration/BundleProduct/BundleOrderQuantityTest.php`（bundle/課程/商品建立與 meta 設定模式）。沿用 `Tests\Integration\TestCase` 的 `create_course` / `enroll_user_to_course` / `assert_user_has_course_access` helper。

### E2E Test（Playwright，端到端驗證）

| 測試檔 | 覆蓋 | 重點 |
| --- | --- | --- |
| `tests/e2e/02-frontend/0XX-free-course-hide-single.spec.ts`（新） | Bug #1 前台 | 免費+hide+有方案的銷售頁：斷言免費卡片不存在、bundle 卡片存在 |
| 擴充 `tests/e2e/02-frontend/006-add-to-cart.spec.ts` 或新 spec | Bug #2 前台 | 0 主課程 + 0 方案：點方案「立即報名」→ 可進結帳/完成下單 |

> E2E 範本參考既有 `006-add-to-cart.spec.ts`、`002-course-product-pricing.spec.ts`（`loadFrontendTestData` + `SELECTORS`）。Q3/Q5 後台 UI 無前端 unit test，依 `pnpm run build` 編譯驗證 + 必要時 playwright-cli 人工截圖（本地站 `https://local-turbo.powerhouse.tw/wp-admin`）。

### 品質閘門

- `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）
- `composer run test`（PHPUnit 全綠）
- `pnpm run lint:ts` + `pnpm run build`（TS 編譯）
- `pnpm run i18n:build` 後確認 `languages/` 四檔 diff 含新字串、無新增空 msgstr

## 風險評估與注意事項

1. **Bug #2 根因未定**：最大不確定性。已用 spike-first 流程化解——先寫失敗測試重現，禁止盲修。H1（`woocommerce_is_purchasable`）為最可能落點，但須以對照組確認「主課程價格」才是真正差異來源（旅程 2 vs 3）。
2. **filter scope 必須嚴格**：`woocommerce_is_purchasable` 是全站 filter，務必只對課程/bundle + publish + 0/空價放行，否則汙染站內其他商品。測試需含「一般 0 元商品不受影響」反向案例。
3. **模板測試可行性**：`single-product-free.php` 用 `printf` 直接輸出，`Plugin::load_template(..., false)` 以 ob 捕捉回傳字串，加 `return` 後回空字串 → 可斷言。若子模板（button/price）在測試環境缺 WC context 報錯，改為斷言「不含 Buy now 連結 class `pc-add-to-cart-link`」較穩健。
4. **i18n 紀律**：msgid 一律英文、禁止手改 `.po`、跑完整 `i18n:build`、commit 四檔（見 `i18n.rule.md` 驗收標準 11-13）。
5. **React Hooks**：新 `useWatch` 無條件呼叫，置於頂層。
6. **不改範圍**：付費課程卡片邏輯（Q6 B）、手動 0 元與免費開關底層合併（Q3 僅提示）一律不動。
7. **commit 訊息**：繁體中文 Conventional Commits（如 `fix(course): 免費課程隱藏單堂課失效修復`）。

## 交接

本計劃交 `@zenbu-powers:tdd-coordinator` 執行，依「實作順序」逐項跑 Red→Green→Refactor。Bug #2 務必先完成 spike 重現再進 Green。
