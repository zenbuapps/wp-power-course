# 實作計畫：Issue #225 — 銷售方案卡片庫存顯示

> Branch: `issue/225`
> Spec: `specs/open-issue/225-pricing-card-stock-display.md`
> Feature: `specs/features/frontend/銷售方案卡片庫存顯示.feature`
> User answers: A A A B B A A
> Scope mode: **HOLD SCOPE**（既有 bug 修復，範圍受 Q6=A 約束於 `card/pricing.php` + 必要的 stock 文案調整）

---

## 概述

`card/pricing.php`（`[pc_courses]` 短代碼使用的銷售方案卡片）漏呼叫 `Plugin::load_template('stock', ...)`，導致前台不顯示庫存。本計畫補上庫存區塊、實作售完狀態（連結停用 + 視覺灰階 + 「已售完」文字），並補 Playwright E2E 防止再次回歸。

> ⚠️ 重要校正：spec 內提到 `[pc_pricing_table]` 短代碼，但實際短代碼名稱為 **`[pc_courses]`**（在 `Shortcodes/General.php::pc_courses_callback()` → `list/pricing` → `card/pricing`）。實作以實際短代碼為準，不需新增 `pc_pricing_table`。

---

## 需求重述

讓 `card/pricing.php` 在三種狀態下都能正確呈現庫存：

1. **充足庫存**：價格下方顯示綠色 badge「剩餘 N 個」
2. **低庫存**（`stock_quantity ≤ low_stock_amount`）：badge 切紅色
3. **售完**（`!is_in_stock()`）：badge 切灰色，文字改「已售完」；整張卡片連結停用、視覺灰階

未啟用庫存管理或 `show_rest_stock=no` 時：**不渲染**庫存區塊（沿用 `stock/index.php` 既有 early return 邏輯）。

---

## 已知風險（來自研究）

| # | 風險 | 緩解 |
|---|------|------|
| R1 | **改動 `stock/index.php` 的「Sold out」文字影響全站** — `single-product-sale.php` / `bundle-product.php` 共用同一個 stock 模板 | 在 stock 模板用 `$stock_quantity <= 0` 條件判斷時切換 msgid 為 `Sold out`，其他卡片仍能正常顯示且文案更清楚（spec Q4 已允許） |
| R2 | **i18n pipeline 漏跑** — 新增 `Sold out` msgid 後若忘記跑 `pnpm run i18n:build`，繁中介面會 fallback 顯示英文 | 計畫明確列出 `pnpm run i18n:build` 步驟，並要求 commit `.pot`/`.po`/`.mo`/`.json` 四檔 |
| R3 | **卡片整體變 `<a>` 標籤的售完停用語義** — 既有 pricing.php 兩個 `<a href="permalink">` 包裹圖片與標題；改 `<span>` 會破壞 markup，加 `aria-disabled` 較保險 | 使用 conditional：售完時改輸出 `<a aria-disabled="true" tabindex="-1" class="pointer-events-none">`（保留結構穩定性，CSS 屏蔽點擊） |
| R4 | **`stock_status` 與 `manage_stock` 邊界** — 商品可能 `manage_stock=no` 但 `stock_status=outofstock`（手動標記） | 售完判定一律用 `$product->is_in_stock()`（WC 標準 API，已自動處理兩種模式） |
| R5 | **CSS 灰階用 Tailwind 而非新 class** — 既有 `pc-course-card` 沒有獨立 CSS（純 Tailwind utility），新增 `pc-course-card--sold-out` BEM modifier 需另寫 CSS 檔，違反專案慣例 | 直接在 PHP 模板用 Tailwind utility（`opacity-60 grayscale [&_img]:grayscale`）+ 結合 `pc-course-card--sold-out` 作為 hook class 供未來覆寫，不另寫 CSS |
| R6 | **PHPStan level 9 嚴格** — 新增變數需有型別、`is_in_stock()` 回傳 `bool` 可信任 | 沿用既有 pattern（其他兩張卡片已通過 level 9） |
| R7 | **E2E 測試資料準備** — 既有 `frontend-setup.ts` 不會建立帶 `manage_stock=yes` 的商品 | spec 內測試自行透過 WC REST API（`wcPost('products/{id}', { manage_stock: true, stock_quantity, low_stock_amount, manage_stock })`）動態調整 fixture 課程的庫存設定 |

未發現額外已知風險。

---

## 架構變更

| # | 檔案 | 變更類型 | 摘要 |
|---|------|---------|------|
| 1 | `inc/templates/components/card/pricing.php` | **修改** | (a) printf template 在「價格 `%6$s`」下方插入庫存區塊 placeholder；(b) 新增 `$is_in_stock` 判定變數；(c) 售完時：外層 div 加 `pc-course-card--sold-out opacity-60 [&_img]:grayscale` class，兩處 `<a href>` 改為 `<a aria-disabled="true" tabindex="-1" class="pointer-events-none">`；(d) 呼叫 `Plugin::load_template('stock', [...], false)` |
| 2 | `inc/templates/components/stock/index.php` | **修改** | 售完（`$stock_quantity <= 0`）時，文字從 `%s left in stock`（顯示「0 left in stock」）改為 msgid `Sold out`（顯示「已售完」），其他狀態文字不變 |
| 3 | `scripts/i18n-translations/manual.json` | **新增** | 新增條目 `{ "msgid": "Sold out", "msgstr_zh_TW": "已售完", "msgstr_ja": "売り切れ", "context": "inc/templates/components/stock/index.php" }` |
| 4 | `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json` | **pipeline 自動產出** | 跑 `pnpm run i18n:build` 同步 |
| 5 | `tests/e2e/02-frontend/051-pricing-card-stock.spec.ts` | **新增** | 5 個 scenarios 對應 spec 的測試覆蓋 |

> 註：spec 文件提到的 E2E 測試路徑 `tests/e2e/frontend/...` 不準確，實際慣例為 `tests/e2e/02-frontend/{NNN}-{kebab-name}.spec.ts`（編號 050 已被佔用，新檔取 **051**）。

---

## 資料流分析

### 銷售方案卡片渲染流程

```
[pc_courses] shortcode
        │
        ▼
General::pc_courses_callback()
        │
        ▼
wc_get_products({...})
        │
        ▼
Plugin::load_template( 'list/pricing', { products, columns } )
        │
        ▼
foreach $products as $product:
   Plugin::load_template( 'card/pricing', { product } )
        │
        ▼
card/pricing.php
   ├── 取得 $product 屬性（名稱、圖、講師、價格...）
   ├── ★ 新增：$is_in_stock = $product->is_in_stock();
   ├── ★ 新增：$stock_html = Plugin::load_template( 'stock', [ 'product' => $product ], false );
   │           ↓
   │     stock/index.php
   │      ├── if (!$managing_stock) return '';            ← shadow: nil
   │      ├── if (!$show_rest_stock) return '';           ← shadow: hidden by setting
   │      ├── $stock_quantity = get_stock_quantity();     ← may be null/0
   │      ├── color_class = green | red | gray
   │      └── label = "Sold out" | "%s left in stock"     ← ★ 新增 sold out 分支
   │
   └── printf(template) with conditional:
         ★ 外層 div class: $is_in_stock ? '' : 'pc-course-card--sold-out opacity-60 [&_img]:grayscale'
         ★ <a href>: $is_in_stock ? real-link : aria-disabled='true' tabindex='-1' pointer-events-none

OUTPUT ──▶ HTML rendered in shortcode area
```

### Shadow paths

| 階段 | nil path | empty path | invalid path |
|------|----------|------------|--------------|
| `$product->is_in_stock()` | 回傳 `bool`（WC 保證） | — | — |
| `$product->managing_stock()` | 回傳 `bool`（WC 保證） | — | — |
| `$product->get_stock_quantity()` | 可能 `null`（未啟用管理時） | `0`（售完） | 負數理論不可能（WC clamp） |
| `$product->get_low_stock_amount()` | 可能 `''`（沒個別設定） | — | 沿用既有 stock 模板的全站 fallback |
| `Plugin::load_template('stock', ...)` | 回傳 `''`（unmanaged / hidden） | — | — |

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|--------------|----------|----------|-------------|
| `card/pricing.php` 整段 | `$product` 非 `\WC_Product` | 型別錯誤 | 既有 early return（line 26-28） | 否（卡片不渲染） |
| `stock/index.php` early return（未管理） | `$product->managing_stock() === false` | 業務邏輯分支 | 回傳空字串 | 否（庫存區塊不出現） |
| `stock/index.php` early return（隱藏） | `show_rest_stock meta = no` | 業務邏輯分支 | 回傳空字串 | 否 |
| `is_in_stock()` 與 `stock_quantity` 不一致 | 站長手動設 `stock_status=outofstock` 但 `stock_quantity > 0` | 資料異常 | 以 `is_in_stock()` 為準（更貼近 WC 語意），庫存 badge 走「Sold out」分支 | 是（卡片顯示已售完） |
| Tailwind class 名輸出未 escape | 使用者輸入未介入此處 | XSS | 全為硬編碼字串，無風險 | — |

> ✅ 無 critical gap（不存在「處理方式=無」+「使用者可見=靜默」的組合）

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|----------|---------|---------|-------------|----------|
| 售完卡片仍可點擊跳商品頁 | `<a href>` 沒停用 | 計畫設計：`aria-disabled` + `pointer-events-none` | E2E `Scenario: 售完` | 是 | 透過 CSS `pointer-events:none` 攔截 |
| 低庫存沒切紅 | `notify_low_stock_amount` fallback 錯誤 | 沿用 `stock/index.php` 既有邏輯（已上線） | E2E `Scenario: 低庫存` | 是 | 既有測試 + 新增 E2E 覆蓋 |
| `Sold out` msgid 沒翻譯 | 漏跑 `pnpm run i18n:build` | 計畫步驟強制 | 視覺驗收（DoD） | 是（顯示英文） | 跑 i18n:build 後 commit |
| 影響其他卡片（single-product-sale / bundle-product） | 因 stock 模板共用導致售完文字統一變「已售完」 | spec Q4 + Q6 已允許（接受影響） | 手動驗收 `single-product-sale` 與 `bundle-product` 卡片售完顯示 | 是（其他卡片售完文案也變） | 若反悔可在 stock 模板加 `$context` 參數區分 |

---

## 實作步驟（給 tdd-coordinator 照辦）

### 第一階段：i18n 翻譯先行（避免下游忘做）

1. **新增 manual.json 條目**（檔案：`scripts/i18n-translations/manual.json`）
   - 行動：在現有「stock 區塊」附近（搜尋 `%s left in stock` 上下文）新增：
     ```json
     {
       "msgid": "Sold out",
       "msgstr_zh_TW": "已售完",
       "msgstr_ja": "売り切れ",
       "context": "inc/templates/components/stock/index.php"
     }
     ```
   - 原因：建立翻譯來源，後續才能透過 pipeline 同步到 `.po/.mo/.json`
   - 依賴：無
   - 風險：低

### 第二階段：紅燈測試（TDD Red）

2. **建立 Playwright E2E spec**（檔案：`tests/e2e/02-frontend/051-pricing-card-stock.spec.ts`）
   - 行動：
     - 建立帶 5 個情境的 `test.describe('銷售方案卡片庫存顯示 [Issue #225]', ...)`
     - 透過 `api.wcPost('products/{id}', { manage_stock, stock_quantity, low_stock_amount, stock_status })` 動態調整 fixture 課程的庫存
     - 建立一個 `[pc_courses]` 短代碼的測試頁（或直接用既有頁面，視 fixtures 安排）
     - 5 個 scenarios：
       1. **充足庫存**：`stock_quantity=50`、`low_stock_amount=5` → 期待 `text=剩餘 50 個` + class `bg-green-100`
       2. **低庫存**：`stock_quantity=3`、`low_stock_amount=5` → 期待 `text=剩餘 3 個` + class `bg-red-100`
       3. **售完**：`stock_quantity=0`、`stock_status=outofstock` → 期待 `text=已售完` + class `bg-gray-100` + 卡片有 class `pc-course-card--sold-out` + `<a aria-disabled="true">`
       4. **未啟用庫存管理**：`manage_stock=no` → 期待 `.pc-course-card` 內**找不到**任何 stock badge（`bg-green-100 / bg-red-100 / bg-gray-100`）
       5. **show_rest_stock=no**：`manage_stock=yes`、product meta `show_rest_stock=no` → 同上不顯示
   - 原因：紅燈先確認測試可重現 issue（pricing.php 修改前所有 scenario 都 fail）
   - 依賴：步驟 1
   - 風險：中（涉及測試資料準備，需熟悉 `ApiClient` 與 wc-rest 邏輯）

   - **執行驗證**：
     ```bash
     pnpm run test:e2e:frontend -- 051-pricing-card-stock.spec.ts
     # 預期：紅燈 — 至少 4 個 scenario fail（充足、低、售完、售完卡片 disabled）；
     # scenario 4/5（不顯示庫存）因為 pricing.php 本來就不渲染，可能直接 pass — 屬於既有行為意外通過，可在第三階段實作後重新確認
     ```

### 第三階段：綠燈實作（TDD Green）

3. **修改 `stock/index.php` 支援售完文字**（檔案：`inc/templates/components/stock/index.php`）
   - 行動：在 line 52 `$stock_label = sprintf(...)` 前加入分支：
     ```php
     if ($stock_quantity <= 0) {
         $stock_label = esc_html__( 'Sold out', 'power-course' );
     } else {
         $stock_label = sprintf(
             /* translators: %s: 庫存數量 */
             esc_html__( '%s left in stock', 'power-course' ),
             esc_html( (string) $stock_quantity )
         );
     }
     ```
   - 原因：售完時改顯示明確文字（spec Q4 要求）
   - 依賴：步驟 1（i18n 翻譯就位）
   - 風險：低（純文案邏輯切換，既有 color_class 邏輯不動）

4. **修改 `card/pricing.php` 補上庫存渲染與售完狀態**（檔案：`inc/templates/components/card/pricing.php`）
   - 行動：
     - 在 line 30（`$chapter_ids` 之後）新增：
       ```php
       $is_in_stock = $product->is_in_stock();
       $card_state_class = $is_in_stock ? '' : 'pc-course-card--sold-out opacity-60 [&_img]:grayscale';
       $card_link_href   = $is_in_stock ? $product->get_permalink() : '#';
       $card_link_attrs  = $is_in_stock
           ? ''
           : 'aria-disabled="true" tabindex="-1" class="pointer-events-none"';
       $stock_html       = Plugin::load_template(
           'stock',
           [ 'product' => $product ],
           false
       );
       ```
     - printf template 修改（重點變動）：
       - 外層 `<div class="pc-course-card">` → `<div class="pc-course-card %10$s">`（接 `$card_state_class`）
       - 兩處 `<a href="%1$s">` 統一改為 `<a href="%1$s" %11$s>`（接 `$card_link_attrs`）
       - 在 `<div class="pc-course-card__price ...">%6$s</div>` 下方插入新行：`%12$s`（接 `$stock_html`）
       - printf 參數補齊到第 12 個
   - 原因：補渲染庫存 + 售完視覺差異化 + 連結停用
   - 依賴：步驟 2、3
   - 風險：中（printf placeholder 編號重排需確保不破壞既有畫面）

   - **執行驗證**：
     ```bash
     pnpm run lint:php
     # 確認 PHPCS / PHPStan level 9 通過
     ```

5. **跑 i18n pipeline 同步 4 個檔**（一次性指令）
   - 行動：執行 `pnpm run i18n:build`
   - 原因：產出 `.pot`/`.po`/`.mo`/`.json`，確保 `Sold out` 在 runtime 能取得繁中翻譯
   - 依賴：步驟 1、3
   - 風險：低
   - **驗證**：
     - `git diff languages/` 應出現 `Sold out` 與 `已售完`
     - console 輸出 `[build-zhtw-po] 未翻譯（空 msgstr）` 數量不上升

6. **再跑一次 E2E 確認綠燈**
   - 行動：`pnpm run test:e2e:frontend -- 051-pricing-card-stock.spec.ts`
   - 預期：全部 5 個 scenarios 通過
   - 依賴：步驟 4、5

### 第四階段：回歸驗收

7. **檢查 stock 模板影響範圍 — 其他卡片售完文案**（手動驗收）
   - 行動：用本地站建立兩個庫存為 0 的 single-product 與 bundle-product，瀏覽到顯示這兩張卡片的頁面，確認 stock badge 顯示「已售完」而非「0 left in stock」
   - 原因：spec Q6 雖然只修 `card/pricing.php`，但 stock 模板共用，文案變更會擴散；確認不影響可讀性
   - 依賴：步驟 3、5
   - 風險：低（文案更明確而非變差）

8. **跑完整品質檢查**
   - 行動：
     ```bash
     pnpm run lint:php       # phpcbf + phpcs + phpstan level 9
     pnpm run lint:ts        # ESLint
     pnpm run test:e2e:frontend  # 全部前台 E2E（確保沒回歸）
     ```
   - 依賴：步驟 6、7

---

## 測試策略

### Playwright E2E（核心）

| 檔案 | `tests/e2e/02-frontend/051-pricing-card-stock.spec.ts` |
|------|---|
| 執行指令 | `pnpm run test:e2e:frontend -- 051-pricing-card-stock.spec.ts` |
| 測試前置 | `frontend-setup.ts` 提供基礎課程；本 spec 在 `beforeAll`/`beforeEach` 透過 `ApiClient.wcPost('products/{id}', { manage_stock, stock_quantity, ... })` 動態切換庫存狀態 |
| 測試後置 | `afterAll` 還原商品 `manage_stock=no` 避免污染後續 spec |
| 5 個 scenarios | 充足庫存綠 / 低庫存紅 / 售完灰 + 卡片 disabled / `manage_stock=no` 不顯示 / `show_rest_stock=no` 不顯示 |

### 關鍵 selectors（給測試實作者參考）

| Locator | 意義 |
|---------|------|
| `.pc-course-card` | 卡片本體 |
| `.pc-course-card.pc-course-card--sold-out` | 售完卡片 |
| `.pc-course-card .bg-green-100` | 充足庫存 badge |
| `.pc-course-card .bg-red-100` | 低庫存 badge |
| `.pc-course-card .bg-gray-100` | 售完 badge |
| `.pc-course-card a[aria-disabled="true"]` | 售完時的禁用連結 |
| `.pc-course-card:has-text('剩餘 50 個')` | 文案驗證（含繁中） |
| `.pc-course-card:has-text('已售完')` | 售完文案驗證 |

### PHPUnit（不在本 issue 範圍）

`stock/index.php` 是純 PHP 模板，沒有可單元測試的純函式 — 行為驗收交給 E2E。spec Q7=A 也明示「補 Playwright E2E」即可。

### 手動驗收（DoD 一部分）

- [ ] 開 `[pc_courses]` 顯示頁，三種庫存狀態都正確
- [ ] 開 `single-product-sale` / `bundle-product` 售完商品，確認文字改顯示「已售完」可接受
- [ ] 用 playwright-cli skill 截圖三種狀態存證附 PR

---

## 依賴項目

無新增 PHP / npm 依賴。沿用：
- `Plugin::load_template()`
- `\WC_Product::is_in_stock()` / `managing_stock()` / `get_stock_quantity()` / `get_low_stock_amount()`
- `@wordpress/i18n` runtime（前台已透過 `inc/classes/Templates/Ajax.php::wp_enqueue_scripts()` 接好）

---

## 風險與緩解措施

| 等級 | 風險 | 緩解 |
|------|------|------|
| **中** | i18n pipeline 漏跑導致繁中介面 fallback 英文 | 步驟 5 強制 `pnpm run i18n:build`，PR review 必看 `languages/` 四檔 diff |
| **中** | `stock/index.php` 文案改動影響全站 3 張卡片 | spec Q4 & Q6 已釐清允許；步驟 7 手動驗收兩張既有卡片 |
| **低** | Tailwind `[&_img]:grayscale` 在舊瀏覽器不支援 attribute selector | 本專案最低支援 Chrome 90+/Safari 14+，arbitrary variant 已可用 |
| **低** | PHPStan level 9 對 `$product->get_low_stock_amount()` 回傳型別嚴格 | 沿用 stock 模板既有的 `(int) $product_low_stock_amount` 型別轉換 |
| **低** | `[pc_courses]` 顯示的商品大多沒設庫存（站長未啟用 manage_stock）→ 大部分卡片不顯示庫存 | 屬預期行為（spec Q3=A），不視為缺陷 |

---

## 錯誤處理策略

- **Early return 優先**：`stock/index.php` 既有兩道 early return（`!managing_stock` / `!show_rest_stock`）保留不動；不啟用庫存的卡片就單純不渲染庫存區塊。
- **WC 原生 API 優先**：售完判定一律走 `is_in_stock()`，避免自寫 `stock_status === 'outofstock'` 漏判邊界。
- **i18n fallback 明確**：所有新增字串走 `esc_html__` + `power-course` text domain，msgid 一律英文，符合 `.claude/rules/i18n.rule.md` 第 1 條最高原則。

---

## 限制條件（本計畫**不會**做的事）

- ❌ 不重構 `single-product-sale.php` / `bundle-product.php`（spec Q6=A，留給未來 refactor issue）
- ❌ 不抽取共用 partial（spec Q6=A）
- ❌ 不新增獨立 CSS 檔（pc-course-card 既有慣例就是純 Tailwind utility，繼續沿用）
- ❌ 不新增 `pc_pricing_table` 短代碼（spec 提到此名稱屬筆誤，實際短代碼是 `pc_courses`）
- ❌ 不改變 stock badge 配色邏輯（沿用 `stock/index.php` 既有 color_class 三段判斷）
- ❌ 不處理「售完」時的「自動隱藏整張卡片」需求（spec Q4 明確選 B，不選 C）
- ❌ 不寫前端 unit test（專案無前端 unit test 慣例）

---

## 成功標準

- [ ] `card/pricing.php` 在 `[pc_courses]` 短代碼下三種庫存狀態都正確渲染（綠/紅/灰）
- [ ] 售完卡片：（a）顯示「已售完」灰色 badge、（b）卡片整體灰階透明度降低、（c）`<a>` 加上 `aria-disabled="true"` + `pointer-events-none`、（d）點擊卡片不導向商品頁
- [ ] 低庫存（`stock_quantity ≤ low_stock_amount`）切換紅色 badge
- [ ] `manage_stock=no` 或 `show_rest_stock=no` 時，卡片完全不渲染庫存區塊
- [ ] `single-product-sale.php` / `bundle-product.php` 在售完時也顯示「已售完」（共用 stock 模板帶來的副作用，可接受）
- [ ] `pnpm run lint:php` 通過（PHPCS + PHPStan level 9）
- [ ] `pnpm run test:e2e:frontend -- 051-pricing-card-stock.spec.ts` 5 個 scenarios 全綠
- [ ] `pnpm run i18n:build` 跑過，`languages/power-course.pot`、`power-course-zh_TW.po`、`.mo`、`.json` 四檔 diff 一起 commit
- [ ] `[build-zhtw-po] 未翻譯（空 msgstr）` 數量沒上升
- [ ] PR 附 playwright-cli 截圖三種狀態

---

## 預估複雜度：**低**

- 既有 stock 模板 80% 邏輯可直接重用
- 只動 2 個 PHP 檔 + 1 個 i18n json + 1 個新測試檔
- 不涉及資料庫、API、Refine 前端
- 風險集中在 i18n 同步與 stock 文案共用影響範圍（已在 spec 釐清允許）

預估工時：**0.5 ~ 1 工程日**（含測試 + lint + 手動驗收）

---

## 交接給 tdd-coordinator

- 此計畫已存於 `specs/plans/225-pricing-card-stock-display.plan.md`，commit 到分支 `issue/225`
- tdd-coordinator 依「實作步驟」順序執行 Red → Green → Refactor → Doc Sync
- 提醒：
  - 步驟 1（i18n 條目）先於步驟 2（測試）執行，避免測試 spec 內斷言中文文字時對不上
  - 步驟 5（`pnpm run i18n:build`）**絕對不能省**，否則繁中介面 fallback 英文
  - 步驟 7（手動驗收其他兩張卡片）是 spec Q6 的副作用驗證關鍵
