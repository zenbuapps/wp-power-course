# Issue #200: 結帳頁 add-to-cart URL 參數導致重複加入購物車

## 問題描述

使用者從課程銷售頁點擊「立即報名」按鈕後，會被導向 `checkout?add-to-cart=XXX`。如果在結帳頁按下重整，WooCommerce 會再次處理 `add-to-cart` GET 參數，導致商品被重複加入購物車。

## 根本原因

4 個模板檔案都使用 `wc_get_checkout_url() + ?add-to-cart={product_id}` 產生直連結帳頁的 URL：

1. `inc/templates/components/card/single-product-sale.php` — 付費課程卡片
2. `inc/templates/components/card/single-product-free.php` — 免費課程卡片
3. `inc/templates/components/card/bundle-product.php` — 銷售方案卡片
4. `inc/templates/pages/course-product/body.php` — 手機端固定 CTA（第 219 行）

此外，`inc/assets/src/events/cart.ts` 中 `.pc-add-to-cart-link` 的 click handler 也是直接 `window.location.href = href`，href 包含 `?add-to-cart`。

WooCommerce 在每次 page load 時會檢查 `$_GET['add-to-cart']` 並執行加入購物車，因此只要 URL 中有此參數，每次重整都會再加一次。

## 確認的需求決策

| # | 問題 | 決定 |
|---|------|------|
| Q1 | 課程是否限制購買數量 | **不限制**，允許多份購買，不啟用 `sold_individually` |
| Q2 | 技術方案 | **PHP server-side redirect**：`template_redirect` hook，WC 處理完 add-to-cart 後 302 到乾淨 URL |
| Q3 | 是否清空購物車 | **不改**，聚焦本次問題 |
| Q4 | 修改範圍 | **全部商品類型**，統一在 PHP redirect 層攔截 |
| Q5 | 已購學員防護 | **維持現有 Modal**，僅為提醒，不阻擋購買 |
| Q6 | E2E 測試 | **需更新**，補「重整後購物車數量不變」迴歸測試 |
| Q7 | sold_individually 矛盾 | **不啟用**，Modal 僅為提醒，使用者可買多份 |

## 技術方案

### 新增檔案

- `inc/classes/FrontEnd/CheckoutRedirect.php` — 新建 class，處理結帳頁 redirect

### 實作邏輯

```
hook: template_redirect (priority: 20, 在 WC 預設處理之後)

if (is_checkout() && isset($_GET['add-to-cart'])):
    // WC 已在更早的 hook（wp_loaded, priority 20）處理完 add-to-cart
    // 移除 add-to-cart 和 quantity 參數，保留其他參數
    $clean_url = remove_query_arg(['add-to-cart', 'quantity'])
    wp_safe_redirect($clean_url, 302)
    exit
```

### 不修改的檔案

- 4 個模板檔案的 URL 產生邏輯不需修改（仍用 `?add-to-cart=XXX`，因為 redirect 會在伺服器端清理）
- `cart.ts` 前端 JS 不需修改
- 已購學員 Modal 邏輯不需修改

### 修改的檔案

1. `inc/classes/FrontEnd/CheckoutRedirect.php` — **新建**
2. `inc/classes/Bootstrap.php` — 在 constructor 中初始化 `CheckoutRedirect::instance()`
3. `tests/e2e/02-frontend/006-add-to-cart.spec.ts` — 補迴歸測試

## E2E 測試計畫

在 `006-add-to-cart.spec.ts` 新增測試案例：

1. **重整後購物車數量不變**：點擊 CTA → 到結帳頁 → 驗證 URL 不含 `add-to-cart` → 重整 → 驗證購物車數量不變
2. **URL 清理驗證**：直接訪問 `checkout?add-to-cart=XXX` → 驗證被 redirect 到乾淨 URL

## 規格檔案

- Feature spec: `specs/features/checkout/結帳頁清除add-to-cart參數.feature`
