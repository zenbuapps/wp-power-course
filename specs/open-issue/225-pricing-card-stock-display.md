# Issue #225 — 銷售方案卡片庫存無法顯示

## 問題現象

站長在 WooCommerce 商品設定中啟用「管理庫存」並設定庫存量（例如 50 個），但前台透過 `[pc_pricing_table]` 短代碼渲染的銷售方案卡片上沒有顯示「剩餘 X 個」的庫存資訊。

## 根因分析

`inc/templates/components/card/pricing.php` 是 `[pc_pricing_table]` 短代碼使用的卡片模板，**沒有呼叫** `Plugin::load_template('stock', ...)`，所以即使後端商品有庫存設定也不會在卡片上渲染庫存區塊。

同類型銷售卡片的對照：

| 模板 | 用途 | 是否呼叫 stock 模板 |
|------|------|-----|
| `inc/templates/components/card/single-product-sale.php` | 商品頁右側單一銷售方案卡片 | ✅ 有（行 71-77） |
| `inc/templates/components/card/bundle-product.php` | 商品頁右側捆綁銷售方案卡片 | ✅ 有（行 111-116） |
| `inc/templates/components/card/pricing.php` | `[pc_pricing_table]` 短代碼列表卡片 | ❌ 沒有 |

後端 API 已正常回傳 `stock_quantity`、`stock_status`、`manage_stock`、`low_stock_amount`，純屬前台模板少渲染一段。

## 規格定義

完整 BDD 規格見：`specs/features/frontend/銷售方案卡片庫存顯示.feature`

## 釐清紀錄

| 編號 | 問題 | 答案 |
|------|------|------|
| Q1 | 庫存資訊顯示位置？ | A — 顯示在「價格」下方（與其他卡片一致） |
| Q2 | 庫存顯示文案？ | A — 直接重用既有 `stock` 模板 |
| Q3 | `manage_stock = false` 時的行為？ | A — 完全不顯示庫存區塊（沿用 `stock/index.php` 既有 early return） |
| Q4 | 售完時的處理？ | B — 「按鈕」停用 + 文字改為「已售完」（卡片無實體按鈕，映射為：卡片連結停用 + 顯示灰色「已售完」badge + 視覺灰階處理） |
| Q5 | 低庫存警示？ | B — 庫存數 ≤ `low_stock_amount` 時切紅色 + 警示文字（沿用 `stock/index.php` 既有邏輯） |
| Q6 | 修復範圍？ | A — 只修 `card/pricing.php`，不動其他既有卡片模板 |
| Q7 | E2E 測試？ | A — 補 Playwright E2E（`tests/e2e/frontend/pricing-card-stock.spec.ts`） |

## 預期變更檔案

- `inc/templates/components/card/pricing.php` — 在 printf template 的價格區塊下方插入 stock 模板呼叫，並處理 `stock_status = outofstock` 時的卡片狀態（連結停用、視覺灰階、`pc-course-card--sold-out` class）
- `inc/templates/components/stock/index.php` — **可能** 需要新增「Sold out」文字（當 `stock_quantity <= 0` 時，改顯示 `Sold out` 而非 `0 left in stock`），此調整為全站性，須同步確認 `single-product-sale.php` / `bundle-product.php` 可接受
- `tests/e2e/frontend/pricing-card-stock.spec.ts` — 新增 E2E 測試
- `scripts/i18n-translations/manual.json` — 補上「Sold out」→「已售完」對照
- `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json` — pipeline 產出，禁止手改

## 邊界與注意事項

1. **i18n**：所有新增字串遵循 `power-course` text domain，msgid 一律英文，繁中翻譯走 `manual.json`，跑 `pnpm run i18n:build` 同步全套。
2. **不擴大 scope**：本 issue 不重構三張卡片，也不抽 partial。
3. **`stock_status = outofstock` 判斷**：使用 `$product->is_in_stock()` 而非自行比對 meta。
4. **無 button 場景**：`pricing.php` 卡片本身就是連結（整張卡片可點），售完時的「停用」映射為「連結 noop + 視覺差異化」，而非真的有 button disabled。
