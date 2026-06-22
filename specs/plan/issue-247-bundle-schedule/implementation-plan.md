# 實作計劃：銷售方案自動上下線時間（Issue #247）

> 交接對象：`@zenbu-powers:tdd-coordinator`
> 範圍模式：**HOLD SCOPE**（功能範圍已由 clarifier 七問鎖定，預估影響約 12 檔）
> 已澄清決策：`Q1=B Q2=B Q3=B Q4=A Q5=A Q6=A Q7=A`

## 概述

讓站長能為「銷售方案」（WooCommerce Bundle 商品）設定**自動上線時間**與**自動下線時間**，到點由既有的 ActionScheduler 每 10 分鐘輪詢自動切換 `post_status`（`publish ↔ draft`），省去人工守時上下架。沿用「排程開課」（`course_schedule`）的成熟 pattern，最小化新架構。

## 需求重述

- 站長在「編輯銷售方案」可設定 `bundle_schedule_online`（自動上線）與 `bundle_schedule_offline`（自動下線），皆為**選填**（0 = 無排程）。
- 到點且方案狀態符合條件時，由 ActionScheduler 自動切換：到達上線時間且為 `draft` → `publish`；到達下線時間且為 `publish` → `draft`。
- 下線 = 轉草稿（**保留所有資料**，非刪除）。前台 `sider.php` 僅顯示 publish 方案，draft 自動隱藏。
- 可隨時修改 / 清除排程。設定**過去時間**時允許儲存並**立即執行**並回傳提示（Q3=B）。
- 自動下線那刻，方案完全無法購買，**購物車內未結帳的該方案項目一併失效**，但**已成立訂單不受影響**（Q2=B）。
- 列表 + 編輯頁皆顯示排程 / 已執行狀態（Q4=A、Q5=A）。所有時間以**站台 WordPress 時區**為準並於 UI 標示（Q7=A）。

## 已知風險（來自研究）

- **風險：購物車失效攔截點（Q2=B 最嚴格）** — WooCommerce 對 `draft` 商品於 `WC_Cart::check_cart_item_validity()` 已會自動移除，但須明確驗證並補強 `add_to_cart` 阻擋。緩解：複用既有 `FrontEnd/Purchasable.php` 的 `woocommerce_is_purchasable` filter 思路（draft 商品天生不可購買）+ 新增 `woocommerce_add_to_cart_validation` 阻擋 + 依賴 WC 原生 cart 驗證移除購物車項目，並用 IT 驗證。
- **風險：上線/下線獨立輪詢互相干擾** — 同一方案可同時設 online+offline。緩解：兩者依「方案當下狀態」各自獨立判斷（online 只作用於 draft、offline 只作用於 publish），SQL 各自查詢，互斥不會在同一輪迴圈衝突。
- **風險：時區誤判（站台先前有時區議題）** — 前端 DatePicker 須以站台時區解讀後存為 Unix timestamp。緩解：複用既有 `formItem/DatePicker` 元件（`normalize: value.unix()`），與 `course_schedule` 完全一致的時間處理；輪詢比對一律用 `time()`（UTC timestamp），不涉及時區轉換歧義。
- **風險：raw SQL 寫 wp_posts 後 object cache stale**（見 wordpress.rule.md）— 緩解：狀態切換一律走 `wp_update_post()`（內部自動 `clean_post_cache`），不使用 raw SQL 直寫。
- **風險：方案被刪除 / 已手動轉草稿，輪詢殘留報錯** — 緩解：SQL 已用 `post_status='publish'`（offline）/`'draft'`（online）+ `meta_value > 0` 過濾，已刪除方案不在 `product` post 結果內，天生安全略過。

## 架構變更

| # | 檔案 | 類型 | 摘要 |
|---|------|------|------|
| 1 | `inc/classes/BundleProduct/Helper.php` | modify | 新增 `SCHEDULE_ONLINE_META_KEY` / `SCHEDULE_OFFLINE_META_KEY` 常數與 `get_schedule_online()` / `get_schedule_offline()` getter |
| 2 | `inc/classes/BundleProduct/Service/Schedule.php` | **create** | ActionScheduler 輪詢處理器：掛 `Bootstrap::SCHEDULE_ACTION`，查詢到期方案並切換狀態；提供 `run_immediately()` 供 Q3=B 立即執行；記錄 `bundle_schedule_done_at` 供後台可感知 |
| 3 | `inc/classes/Resources/Loader.php` | modify | 註冊 `BundleProduct\Service\Schedule::instance()` |
| 4 | `inc/classes/Api/Product.php` | modify | `handle_special_fields`：解析/驗證 `bundle_schedule_online/offline`、Q3=B 過去時間立即執行並回傳 `schedule_notice`；`format_product_details`：回傳兩排程欄位 + 最近自動執行狀態 |
| 5 | `inc/classes/FrontEnd/Purchasable.php` | modify | 新增 `woocommerce_add_to_cart_validation` 阻擋已下線（draft）bundle 加入購物車（Q2=B）；draft 購物車項目失效依賴 WC 原生 cart 驗證 |
| 6 | `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/BundleForm.tsx` | modify | 新增兩個 `DatePicker` formItem（上線/下線時間）+ 站台時區提示文字 |
| 7 | `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/index.tsx` | modify | 儲存後處理 `schedule_notice` 回應（Q3=B 立即執行提示） |
| 8 | `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/ListItem/` | modify | 列表項目顯示「將於 X 自動上/下線」「已於 X 自動下線」狀態標記（Q5=A） |
| 9 | `js/src/components/product/ProductTable/types` | modify | `TBundleProductRecord` 新增 `bundle_schedule_online` / `bundle_schedule_offline` / `schedule_notice` 欄位型別 |
| 10 | `scripts/i18n-translations/manual.json` | modify | 新增本功能英文 msgid 的繁中翻譯 |
| 11 | `tests/Integration/BundleProduct/BundleScheduleTest.php` | **create** | IT：設定/修改/清除排程、過去時間立即執行、輪詢切換、邊界 |
| 12 | `tests/Integration/BundleProduct/BundleScheduleCartTest.php` | **create** | IT：下線後購物車失效、阻擋加入、已成立訂單不受影響（Q2=B） |
| 13 | `tests/e2e/01-admin/bundle-schedule.spec.ts` | **create** | E2E：設定排程 → 狀態顯示 → 前台消失/出現 |

## 資料流分析

### 流程 A：站長設定排程（REST `POST bundle_products/{id}`）

```
FormData(online/offline timestamp) ──▶ sanitize ──▶ validate ──▶ 寫入 meta ──▶ Q3 過去時間? ──▶ Response(+schedule_notice)
        │                                  │            │             │              │                    │
        ▼                                  ▼            ▼             ▼              ▼                    ▼
   [欄位缺失?→不動]                  [非數字?→0]   [負數?→0]    [save 失敗?]   [已過去→立即切換狀態]   [前端 message 提示]
   [清除→傳 0/空]                                              [可感知 meta]   [未過去→僅存 meta]
```

### 流程 B：ActionScheduler 到點輪詢（`Bootstrap::SCHEDULE_ACTION`，每 10 分鐘）

```
時間到 ──▶ SQL 查到期方案 ──▶ 逐筆切換狀態(wp_update_post) ──▶ 記錄 done_at meta ──▶ 完成
   │            │                      │                          │
   ▼            ▼                      ▼                          ▼
[ActionScheduler [無到期→空集合,    [post 已刪/狀態已變→        [後台列表/編輯頁
 未啟用?→略過]    迴圈不執行]        SQL 已過濾,安全略過]        顯示「已於 X 自動上/下線」]
```

### 流程 C：下線後購物車失效（Q2=B）

```
方案轉 draft ──▶ 顧客既有購物車 ──▶ WC check_cart_item_validity ──▶ 移除失效項目 ──▶ 無法結帳
                 新顧客加入 ──▶ woocommerce_add_to_cart_validation ──▶ 阻擋(return false)
                 已成立訂單 ──▶ 不掃描購物車/不變更 ──▶ 照常存在
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `handle_special_fields`（解析排程） | timestamp 非數字/負數 | 驗證 | 正規化為 0（視為清除排程） | 否（靜默正規化，符合「選填」語義） |
| `handle_special_fields`（Q3 立即執行） | 過去時間 | 業務規則 | 立即切換狀態 + 回傳 `schedule_notice` | 是（前端 message） |
| `Schedule::run_immediately` | `wp_update_post` 失敗 | 系統 | `WC::logger()` 記錄；回應仍回 200（meta 已存） | 否（記 log，不阻斷儲存） |
| `Schedule` 輪詢 | 方案已刪除 | 資料缺失 | SQL `post_type=product` 過濾天生略過 | 否 |
| `Schedule` 輪詢 | 方案狀態已被手動改變 | 競態 | SQL `post_status` 條件過濾；不重複動作 | 否 |
| `add_to_cart_validation` | 方案已下線(draft) | 業務規則 | `wc_add_notice` 錯誤 + return false | 是（購物車提示） |
| 前端 DatePicker | 使用者未選日期 | 空值 | 欄位選填，提交 undefined → 後端視為不變更 | 否 |

> 無「處理方式=無 且 靜默」的 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| 設定過去時間 | 方案無聲立即消失 | 是（Q3=B 回傳 notice） | IT | 是 | 站長可重設時間/重新發佈 |
| online+offline 同時設定 | 互相干擾誤切換 | 是（依當下狀態獨立判斷） | IT | 否 | — |
| 輪詢時方案已刪 | Fatal/警告 | 是（SQL 過濾） | IT | 否 | — |
| 下線後購物車殘留可結帳 | 超賣 | 是（WC 原生移除 + add_to_cart 阻擋） | IT | 是 | — |
| 下線誤傷已成立訂單 | 訂單異常 | 是（僅切 post_status，不碰訂單） | IT | 否 | — |
| ActionScheduler 未啟用 | 排程不執行 | 是（`function_exists` 防呆，與既有一致） | — | 否 | 手動上下架 |
| raw SQL 寫 post 後 cache stale | 讀到舊狀態 | 是（用 `wp_update_post` 不直寫） | — | 否 | — |

## 實作步驟

### 第一階段：後端資料層（Helper + meta）

1. **新增排程 meta 常數與 getter**（檔案：`inc/classes/BundleProduct/Helper.php`）
   - 行動：新增 `const SCHEDULE_ONLINE_META_KEY = 'bundle_schedule_online';`、`const SCHEDULE_OFFLINE_META_KEY = 'bundle_schedule_offline';`；新增 `get_schedule_online(): int` / `get_schedule_offline(): int`（讀 meta，cast int，預設 0）。
   - 原因：集中銷售方案 meta 操作於 Helper，與既有 `PRODUCT_QUANTITIES_META_KEY` 等慣例一致。
   - 依賴：無　風險：低

### 第二階段：後端排程服務（核心）

2. **建立 Schedule 服務**（檔案：`inc/classes/BundleProduct/Service/Schedule.php`，新建）
   - 行動：`final class Schedule`，`SingletonTrait`；建構式 `add_action(Bootstrap::SCHEDULE_ACTION, [__CLASS__, 'run_schedule'])`。
     - `run_schedule()`：兩段 `$wpdb->prepare` 查詢（沿用 `register_course_launch` pattern）：
       - 下線：`post_type='product' AND post_status='publish' AND meta(bundle_schedule_offline) > 0 AND meta < time()` → 逐筆 `wp_update_post(['post_status'=>'draft'])` + `update_post_meta(id,'bundle_schedule_offline_done_at', time())`。
       - 上線：`post_status='draft' AND meta(bundle_schedule_online) > 0 AND meta < time()` → 逐筆切 `publish` + 記 `bundle_schedule_online_done_at`。
     - `public static function run_immediately(\WC_Product $product, string $direction): void`：供 Q3=B 立即切換單一方案狀態並記 done_at。
   - 原因：完全複用既有每 10 分鐘輪詢架構（Q6=A），不引入新排程註冊。
   - 依賴：步驟 1　風險：中（SQL 正確性 → 以 IT 覆蓋）

3. **註冊服務**（檔案：`inc/classes/Resources/Loader.php`）
   - 行動：在既有 `Course\LifeCycle::instance();` 附近加 `BundleProduct\Service\Schedule::instance();`。
   - 依賴：步驟 2　風險：低

### 第三階段：後端 REST API

4. **解析排程參數 + Q3 立即執行 + 回傳欄位**（檔案：`inc/classes/Api/Product.php`）
   - 行動：
     - `handle_special_fields()`：讀取 `bundle_schedule_online/offline`，正規化為 int（非數字/負 → 0）寫入 meta；判斷是否 `0 < value < time()`，若是且狀態符合條件 → 呼叫 `Schedule::run_immediately()` 並在回應掛 `schedule_notice`（用 instance property 或回傳結構承載提示）。
     - `format_product_details()`：在回傳陣列加 `Helper::SCHEDULE_ONLINE_META_KEY`、`SCHEDULE_OFFLINE_META_KEY`（int，0 → 回傳 0 或 null 比照 `course_schedule` nullable 慣例避免前端 DatePicker 誤判 1970）、`bundle_schedule_offline_done_at` / `online_done_at`（供 Q5 狀態顯示）。
     - 兩個 callback（`post_bundle_products_callback` 新增 / `post_bundle_products_with_id_callback` 修改）回應 body 帶入 `schedule_notice`。
   - 原因：沿用既有 meta 寫入流程；nullable 處理參照 `CourseScheduleNullableTest`（Issue #222）避免 DatePicker Invalid date。
   - 依賴：步驟 1、2　風險：中

### 第四階段：後端購物車失效（Q2=B）

5. **下線後阻擋購買**（檔案：`inc/classes/FrontEnd/Purchasable.php`）
   - 行動：新增 `woocommerce_add_to_cart_validation` filter：若加入的商品為 bundle 且 `status !== 'publish'`，`wc_add_notice` 錯誤後 return false。draft 商品的既有購物車項目由 WC 原生 `check_cart_item_validity()` 自動移除（IT 驗證），已成立訂單不掃描故不受影響。
   - 原因：複用既有 Purchasable 類別集中可購買性邏輯；最小侵入。
   - 依賴：步驟 2　風險：中（須 IT 確認 WC 原生移除行為）

### 第五階段：前端

6. **編輯頁排程欄位**（檔案：`BundleForm.tsx`）
   - 行動：用既有 `@/components/formItem` 的 `DatePicker`（`normalize: value.unix()`）新增兩個 Item：`bundle_schedule_online`、`bundle_schedule_offline`，label「自動上線時間」「自動下線時間」，下方加站台時區提示（如 `以站台時間 Asia/Taipei 為準`，時區字串可由 `window.power_course_data` 或站台設定取得；若無則顯示通用提示）。
   - 依賴：步驟 4　風險：低

7. **儲存後提示 + 型別**（檔案：bundle `index.tsx`、`ProductTable/types`）
   - 行動：`handleOnFinish` 成功 callback 讀 `mutation` 回應的 `schedule_notice`，有則 `message.info`；`TBundleProductRecord` 補欄位型別。
   - 依賴：步驟 4　風險：低

8. **列表狀態顯示**（檔案：`CourseBundles/ListItem/`）
   - 行動：依 `bundle_schedule_online/offline` 與 `done_at` 顯示 Tag：未到 →「⏰ 將於 X 自動上/下線」；已執行 →「已於 X 自動下線」（Q4=A、Q5=A）。
   - 依賴：步驟 4　風險：低

### 第六階段：i18n 與測試

9. **i18n**（檔案：`scripts/i18n-translations/manual.json`）
   - 行動：新增所有新英文 msgid（"Auto online time"、"Auto offline time"、"Based on site time %s"、"Bundle has been taken offline immediately" 等）的繁中翻譯；跑 `pnpm run i18n:build`，commit `.pot/.po/.mo/.json`。
   - 依賴：步驟 6-8　風險：低

10. **整合測試 + E2E**（步驟 11-13 檔案）
    - 見下方測試策略。

## 測試策略

- **整合測試（PHPUnit，主力）**
  - `BundleScheduleTest.php`（對應 `設定銷售方案排程.feature` + `銷售方案自動上下線.feature`）：
    - 設定未來上線/下線/兩者 → meta 正確寫入、狀態維持。
    - 選填語義：不傳排程 → meta 為 0、不被切換。
    - 修改 / 清除排程。
    - Q3=B：設定過去下線時間（publish）→ 立即轉 draft + `schedule_notice`；過去上線時間（draft）→ 立即 publish + notice。
    - 輪詢 `run_schedule()`：到期 publish→draft、未到期維持、到期 draft→publish；online+offline 同方案先上後下；資料保留（名稱/價格/綁定不變）。
    - 邊界：已是 draft 的下線輪詢略過不報錯；已刪除方案輪詢安全略過。
  - `BundleScheduleCartTest.php`（對應 `銷售方案自動上下線.feature` 購物車規則）：
    - 下線後購物車內該方案項目失效、無法結帳。
    - 下線後新顧客 `add_to_cart` 失敗。
    - 下線前已成立訂單不受影響。
- **E2E（Playwright）** `bundle-schedule.spec.ts`：後台設定下線時間 → 列表顯示排程 Tag → （模擬到點或直接驗證 draft）前台銷售頁不顯示該方案。
- **測試執行指令**：`composer run test`（IT）、`pnpm run test:e2e:admin`（E2E）、`pnpm run lint:php`、`composer run phpstan`。
- **關鍵邊界情況**：0/'0'/空字串/不存在 meta → API 回傳 nullable（避免 DatePicker Invalid date，比照 Issue #222）；timestamp 比對一律 `time()` UTC。

## 依賴項目

- ActionScheduler（既有，`as_schedule_recurring_action` 已於 `Bootstrap` 啟用）。
- WooCommerce cart / `woocommerce_add_to_cart_validation`、`woocommerce_is_purchasable`（既有 Purchasable 已用）。
- 前端既有 `@/components/formItem/DatePicker`、Refine `useForm`。

## 風險與緩解措施

- **高**：購物車失效（Q2=B）攔截點正確性 — IT 明確覆蓋 WC 原生移除 + add_to_cart 阻擋 + 訂單不受影響三情境。
- **中**：輪詢 SQL 正確性與 online/offline 互不干擾 — 沿用 `register_course_launch` 已驗證 pattern，IT 覆蓋同方案先上後下。
- **中**：Q3=B 立即執行與 meta 儲存的交易一致性 — `run_immediately` 失敗僅記 log 不阻斷儲存，回應仍 200。
- **低**：時區顯示 — 複用 `course_schedule` 既有 DatePicker（unix），輪詢用 `time()` 無時區歧義。

## 錯誤處理策略

採「驗證 → 正規化 → 顯式提示」：排程值非法一律正規化為 0（選填語義，靜默合理）；Q3=B 過去時間屬「使用者需知道」的業務事件，必回傳 `schedule_notice` 前端 message；系統層失敗（`wp_update_post`）記 `WC::logger()` 不中斷儲存。狀態切換一律 `wp_update_post()` 避免 cache stale。

## 限制條件（本計劃不做）

- ❌ 不做 Email 通知（Q4=A 僅後台提示，未選 C）。
- ❌ 不引入新的 ActionScheduler 單次精準排程（Q6=A 選複用 10 分鐘輪詢，誤差 ≤ ~10 分鐘）。
- ❌ 不改動前台 `sider.php` 顯示邏輯（draft 自動隱藏天生成立）。
- ❌ 不刪除方案資料（下線 = 轉草稿）。
- ❌ 不處理「已下單未付款訂單」的自動取消（Q2=B 僅擋新購買與購物車，已成立訂單不動）。

## 成功標準

- [ ] 編輯頁可設定/修改/清除 `bundle_schedule_online` / `bundle_schedule_offline`（選填），標示站台時區。
- [ ] 不填排程的既有方案行為完全不變（meta 為 0、不被切換）。
- [ ] 列表 + 編輯頁顯示「將於 X 自動上/下線」與「已於 X 自動下線」。
- [ ] 輪詢到點：publish→draft、draft→publish 正確；前台對應消失/出現。
- [ ] 過去時間立即執行並回傳 `schedule_notice` 提示（不無聲消失）。
- [ ] 下線後該方案無法加入購物車、購物車內失效；已成立訂單不受影響。
- [ ] 下線僅轉草稿、資料保留、可重新發佈。
- [ ] `composer run test` / `pnpm run lint:php` / `phpstan level 9` / `pnpm run test:e2e:admin` 全綠。
- [ ] i18n：新字串英文 msgid + manual.json 繁中 + `i18n:build` 四檔已 commit。

## 預估複雜度：中

（後端排程沿用成熟 pattern；主要複雜度集中在 Q2=B 購物車失效驗證與 online/offline 雙向輪詢測試。）
