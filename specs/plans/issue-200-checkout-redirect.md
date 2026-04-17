# 實作計劃：結帳頁清除 add-to-cart URL 參數 (Issue #200)

## 概述

修正使用者在結帳頁按重整後，因 URL 中的 `?add-to-cart=XXX` 參數持續存在，導致 WooCommerce 重複將商品加入購物車的問題。在 PHP 端使用 `template_redirect` hook，偵測結帳頁含有 `add-to-cart` GET 參數時，WC 處理完加入購物車後 302 redirect 到乾淨 URL。

## 範圍模式：HOLD SCOPE

Bug 修復，範圍已定。預估影響 3 個檔案（1 新建 + 1 修改 + 1 E2E 補測試），專注於防彈邊界情況。

## 需求

- 結帳頁含 `add-to-cart` 參數時，WC 處理完後 302 redirect 到乾淨 URL
- 重整結帳頁不會導致商品重複加入購物車
- 購物車中已有其他商品時，新加入的商品不影響既有商品
- 不限制購買數量（不啟用 `sold_individually`）
- 非結帳頁的 `add-to-cart` 行為不受影響
- redirect 時保留非 `add-to-cart` 的 query 參數（如 `coupon`）
- redirect 時清除 `quantity` 參數
- 已購學員 Modal 確認流程不受影響
- 補 E2E 迴歸測試

## 架構變更

| 檔案 | 變更類型 | 說明 |
|------|----------|------|
| `inc/classes/FrontEnd/CheckoutRedirect.php` | **新建** | 結帳頁 redirect 邏輯，使用 `template_redirect` hook |
| `inc/classes/Bootstrap.php` | 修改 | 在 constructor 中初始化 `FrontEnd\CheckoutRedirect::instance()` |
| `tests/e2e/02-frontend/006-add-to-cart.spec.ts` | 修改 | 補迴歸測試：重整後購物車數量不變、URL 清理驗證 |

### 不修改的檔案

- 4 個模板檔案（`single-product-sale.php`、`single-product-free.php`、`bundle-product.php`、`body.php`）的 URL 產生邏輯不需修改（仍用 `?add-to-cart=XXX`，redirect 會在伺服器端清理）
- `inc/assets/src/events/cart.ts` 前端 JS 不需修改
- `subscription-product.php` 使用 `button/add-to-cart.php` 的 AJAX 模式，不走 URL redirect，不受影響
- 已購學員 Modal 邏輯不需修改

## 資料流分析

### 使用者點擊「立即報名」→ 結帳頁

```
使用者點擊 CTA ──▶ 瀏覽器請求 /checkout/?add-to-cart=101
                        │
                        ▼
              WC wp_loaded (priority 20)
              WC_Form_Handler::add_to_cart_action()
              → 解析 $_GET['add-to-cart']
              → 加入購物車 ✅
                        │
                        ▼
              template_redirect (priority 20)
              CheckoutRedirect::maybe_redirect()
              → is_checkout() ✓
              → isset($_GET['add-to-cart']) ✓
              → remove_query_arg(['add-to-cart', 'quantity'])
              → wp_safe_redirect(乾淨 URL, 302)
              → exit
                        │
                        ▼
              瀏覽器 302 → /checkout/ (乾淨 URL)
              → 重整 → 無 add-to-cart 參數 → 不重複加入 ✅
```

### Shadow Paths

```
INPUT: /checkout/?add-to-cart=XXX
  │
  ├── [nil path] add-to-cart 參數不存在 → 不觸發 redirect，正常載入結帳頁
  ├── [invalid path] add-to-cart=0 或非數字 → WC 自行處理錯誤，redirect 仍移除參數
  ├── [non-checkout] 非結帳頁（如 /cart/?add-to-cart=XXX）→ 不觸發 redirect
  ├── [coupon preserved] /checkout/?add-to-cart=101&coupon=SAVE10 → redirect 到 /checkout/?coupon=SAVE10
  └── [quantity cleaned] /checkout/?add-to-cart=101&quantity=3 → WC 處理數量 3，redirect 到 /checkout/
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `maybe_redirect()` | `is_checkout()` 回傳 false（結帳頁設定錯誤） | 邏輯 | 不觸發 redirect，WC 正常處理 | 否 |
| `maybe_redirect()` | `wp_safe_redirect()` 失敗（headers already sent） | Runtime | PHP warning 但不中斷頁面 | 否（降級為不 redirect） |
| WC add-to-cart | 商品不存在/下架/缺貨 | 業務 | WC 自身處理，加入購物車失敗但 redirect 仍執行清理 URL | 是（WC notice） |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| CheckoutRedirect | headers already sent | 是（降級） | 否 | 否 | 頁面正常載入，但 URL 仍有參數 |
| CheckoutRedirect | WC 未安裝/is_checkout 不存在 | 是（函式存在檢查） | 否 | 否 | 不執行 redirect |
| E2E 測試 | 購物車清空失敗 | 是 | 是 | N/A | 測試 beforeEach 清空 |

## 實作步驟

### 第一階段：PHP 後端 — 結帳頁 redirect

1. **新建 `CheckoutRedirect` class**（檔案：`inc/classes/FrontEnd/CheckoutRedirect.php`）
   - 行動：建立 `J7\PowerCourse\FrontEnd\CheckoutRedirect` class
     - 使用 `SingletonTrait`（與專案其他 FrontEnd class 一致）
     - 在 `__construct()` 中註冊 `template_redirect` hook（priority 20，確保在 WC 的 `wp_loaded` priority 20 處理 add-to-cart 之後）
     - 實作 `maybe_redirect()` 方法：
       1. 檢查 `function_exists('is_checkout')` 防止 WC 未安裝
       2. 檢查 `is_checkout()` — 僅結帳頁觸發
       3. 檢查 `isset($_GET['add-to-cart'])` — 有 add-to-cart 參數才觸發
       4. 使用 `remove_query_arg(['add-to-cart', 'quantity'])` 取得乾淨 URL
       5. 呼叫 `wp_safe_redirect($clean_url, 302)` + `exit`
   - 原因：WooCommerce 在 `wp_loaded` (priority 20) 的 `WC_Form_Handler::add_to_cart_action()` 已處理完 `$_GET['add-to-cart']`，`template_redirect` 在其之後執行，此時購物車已更新，redirect 可安全清除 URL 參數
   - 依賴：無
   - 風險：低 — 僅影響結帳頁的 GET 請求流程，不涉及資料庫操作

2. **在 Bootstrap 中初始化 CheckoutRedirect**（檔案：`inc/classes/Bootstrap.php`）
   - 行動：在 `Bootstrap::__construct()` 中，`FrontEnd\MyAccount::instance()` 之後加入 `FrontEnd\CheckoutRedirect::instance()`
   - 原因：遵循專案慣例，FrontEnd 相關 class 集中在 Bootstrap 的 FrontEnd 區塊初始化
   - 依賴：步驟 1
   - 風險：低

### 第二階段：E2E 迴歸測試

3. **更新 E2E 測試**（檔案：`tests/e2e/02-frontend/006-add-to-cart.spec.ts`）
   - 行動：在現有 `describe('加入購物車')` 內新增以下測試案例：
     - **`結帳頁 redirect 應清除 add-to-cart URL 參數`**：
       1. 使用 `page.goto('/checkout/?add-to-cart={productId}')` 直接訪問結帳頁
       2. 等待頁面載入完成
       3. 驗證最終 URL 不含 `add-to-cart` 參數（`expect(page.url()).not.toContain('add-to-cart')`)
     - **`重整結帳頁後購物車數量應維持不變`**：
       1. 先清空購物車（`emptyCart` helper）
       2. 訪問 `/checkout/?add-to-cart={productId}`，等待 redirect
       3. 驗證 URL 是乾淨的結帳頁 URL
       4. 重整頁面（`page.reload()`）
       5. 透過 WC Store API 或頁面 DOM 驗證購物車中該商品數量為 1
     - **`redirect 應保留非 add-to-cart 的 query 參數`**：
       1. 訪問 `/checkout/?add-to-cart={productId}&test_param=hello`
       2. 驗證 redirect 後 URL 保留 `test_param=hello`
       3. 驗證 URL 不含 `add-to-cart`
   - 原因：Issue #200 是已知 bug，補迴歸測試防止未來回歸
   - 依賴：步驟 1、2（測試目標功能必須先實作）
   - 風險：低

## 測試策略

> 此 section 供 tdd-coordinator 交給 test-creator 執行。

### E2E 測試（主要）

由於此功能涉及瀏覽器 URL 重導向行為，E2E 測試是最適合的驗證方式：

- **測試檔案**：`tests/e2e/02-frontend/006-add-to-cart.spec.ts`
- **測試執行指令**：`pnpm run test:e2e:frontend`
- **待測試流程**：
  1. 直接訪問 `checkout/?add-to-cart=XXX` → 驗證 302 redirect 到乾淨 URL
  2. 重整結帳頁 → 驗證購物車數量不變
  3. 帶其他 query 參數 → 驗證保留非 add-to-cart 參數
- **關鍵邊界情況**：
  - 購物車為空時加入商品
  - 購物車已有商品時再加入新商品
  - 多次連續重整

### PHP Integration Test（選擇性）

此功能的核心邏輯很薄（3 個 if 判斷 + redirect），且強依賴 WordPress 環境函式（`is_checkout()`、`wp_safe_redirect()`），PHP unit test 的價值有限，E2E 測試已足夠覆蓋。

### 品質檢查

- `pnpm run lint:php` — 確保新 PHP 檔案通過 PHPCS + PHPStan level 9
- 新檔案需要 `declare(strict_types=1)` 與正確的 namespace

## 風險與緩解措施

- **風險**：`template_redirect` 的 priority 與其他 plugin 的 hook 衝突
  - 緩解措施：使用 priority 20（與 WC 預設一致），且邏輯中有 `is_checkout()` 和 `isset($_GET['add-to-cart'])` 雙重條件守衛，不會誤觸發
  
- **風險**：某些頁面建構器（如 Elementor）覆寫結帳頁，導致 `is_checkout()` 回傳 false
  - 緩解措施：WooCommerce 的 `is_checkout()` 已處理大多數建構器兼容；即使 `is_checkout()` 回傳 false，降級行為只是不 redirect（退回原本有 bug 的行為），不會造成新問題

- **風險**：headers already sent（其他 plugin 在 `template_redirect` 之前輸出內容）
  - 緩解措施：`wp_safe_redirect()` 內部已處理此情況；最壞情況是 redirect 不生效，退回原本行為

## 成功標準

- [ ] 訪問 `/checkout/?add-to-cart=XXX` 後被 302 redirect 到 `/checkout/`（不含 add-to-cart 參數）
- [ ] 重整結帳頁後，購物車中商品數量維持不變
- [ ] 帶其他 query 參數（如 `coupon=SAVE10`）時，redirect 後保留該參數
- [ ] 非結帳頁的 `add-to-cart` 行為不受影響
- [ ] 已購學員 Modal 確認流程正常運作
- [ ] `pnpm run lint:php` 通過
- [ ] E2E 測試通過：`pnpm run test:e2e:frontend`

## 附錄：WooCommerce add-to-cart 處理時序

```
Request: GET /checkout/?add-to-cart=101

1. wp_loaded (priority 20) — WC_Form_Handler::add_to_cart_action()
   → 讀取 $_GET['add-to-cart'] = 101
   → 呼叫 WC()->cart->add_to_cart(101, qty)
   → 購物車已更新 ✅

2. template_redirect (priority 20) — CheckoutRedirect::maybe_redirect()
   → is_checkout() = true
   → isset($_GET['add-to-cart']) = true
   → wp_safe_redirect('/checkout/', 302)
   → exit

3. 瀏覽器收到 302 → GET /checkout/（乾淨 URL）
   → 正常載入結帳頁
   → 購物車已有商品 ✅
   → 重整不會重複加入 ✅
```
