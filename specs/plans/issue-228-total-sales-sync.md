# Issue #228 實作計畫 — 課程已售出數量（total_sales）同步修復

> 範圍模式：**HOLD SCOPE（bug 修復）**；本次修訂為 **REDUCTION**（Q7=C→B 移除手動觸發介面）。
> 澄清決議：**C B A A A A B**（最新；見 issue #228 clarifier 留言與 specs 檔頭 commit `bd5f77ff`）。
> 下方 §0–§10 為 Q7=C 原始計畫，**§-1 修訂 v2 為最新 Q7=B 的權威差異說明，凡與下文衝突以 §-1 為準**。

---

## §-1. 修訂 v2 — Q7 改為純自動遷移（C B A A A A B）⭐ 權威

### 背景
原計畫（§0–§10）依 Q7=**C**（升級自動跑一次 **＋** 設定頁手動按鈕 ＋ REST 端點）撰寫，tdd-coordinator 已完整實作於 `issue/228` 分支。
用戶最新答覆將 Q7 改為 **B（純自動）**：歷史資料修復**只在 plugin 升級時自動跑一次性遷移**，**不提供**設定頁手動按鈕，**不提供** `POST /courses/recalculate-total-sales` REST 端點。specs 已同步（commit `bd5f77ff`）。

本次任務 = **從已實作的 Q7=C 狀態，刪除「手動觸發介面」這一層**。核心同步引擎、重算服務、升級自動遷移**全部保留**。

### ✅ 保留（已實作且滿足 Q7=B，不動）
| 檔案 | 理由 |
|------|------|
| `inc/classes/Resources/Course/Service/TotalSalesSync.php` | 即時同步引擎，與 Q7 無關 |
| `inc/classes/Resources/Course/Service/RecalculateTotalSales.php` | 重算服務本體 → 升級自動遷移仍需呼叫 `schedule()` |
| `inc/classes/Compatibility/Compatibility.php`（§4.3 gate, line 98-101） | **這就是 Q7=B 的唯一觸發點**，直接呼叫 `RecalculateTotalSales::instance()->schedule()`，不經 REST |
| `inc/classes/Resources/Loader.php` | 服務註冊 |
| `tests/Integration/Order/TotalSalesSyncTest.php` | 即時同步測試 |
| `tests/Integration/Course/RecalculateTotalSalesTest.php` | 純 service 測試（已驗證無 permission/REST/管理員觸發引用） |
| `tests/Integration/Course/TotalSalesMigrationTest.php` | 升級自動遷移測試 → Q7=B 的核心驗收 |

### ❌ 移除（Q7=C 專屬的「手動觸發介面」）
| # | 檔案 | 具體動作 |
|---|------|----------|
| R1 | `inc/classes/Api/Course.php` | 刪除 `$apis` 中 `courses/recalculate-total-sales` 項（約 line 93-97）；刪除 `check_recalc_permission()`（約 1124-1126）；刪除 `post_courses_recalculate_total_sales_callback()`（約 1136-1158）；刪除 `use ...RecalculateTotalSales;` import（line 20，本檔移除後已無其他用途，**移除後務必確認 PHPStan 無 unused / 無遺漏引用**） |
| R2 | `tests/Integration/Course/RecalculateTotalSalesApiTest.php` | **整檔刪除**（測的是已移除的 REST 端點 + 403/200 權限） |
| R3 | `js/src/pages/admin/Settings/General/RecalculateTotalSales.tsx` | **整檔刪除**（手動按鈕元件） |
| R4 | `js/src/pages/admin/Settings/hooks/useRecalculateTotalSales.tsx` | **整檔刪除**（呼叫 REST 的 hook） |
| R5 | `js/src/pages/admin/Settings/General/index.tsx` | 刪除 `import RecalculateTotalSales from './RecalculateTotalSales'`（line 9）與 `<RecalculateTotalSales />`（line 225） |
| R6 | `scripts/i18n-translations/manual.json` | 移除已成孤兒的 msgid：`Course sales data`、`Recalculate total sales`、`Recalculate the sold count of all courses based on valid orders. This is useful after order refunds or cancellations.`、`Recalculate all courses' sold count? This rebuilds from valid orders.`、`Failed to recalculate total sales`、`Recalculation scheduled`（callback 移除後亦孤兒）。改完跑 `pnpm run i18n:build` 重建 `.pot/.po/.mo/.json` 並一起 commit |

### 移除順序（交給 tdd-coordinator，依依賴）
1. **前端**：R3、R4 刪檔 → R5 拆引用 → `tsc --noEmit` / `pnpm run lint:ts` 確認無 dangling import。
2. **後端**：R1 拆端點 + callback + permission + import → R2 刪測試檔 → `php -l` → PHPStan L9（確認無 unused import / 無對已刪 method 的引用）。
3. **i18n**：R6 清 `manual.json` → `pnpm run i18n:build` → commit 重建檔。
4. **回歸驗證**（權威 Green Gate，下游 integration-tests job）：保留的三個 PHP 測試（`TotalSalesSyncTest`、`RecalculateTotalSalesTest`、`TotalSalesMigrationTest`）須維持綠燈——尤其 `TotalSalesMigrationTest` 證明 Q7=B 自動遷移仍正常。

### 風險 / 注意
- **不可誤刪 `RecalculateTotalSales.php` 服務本體**：它被 `Compatibility.php` 自動遷移呼叫，是 Q7=B 的引擎。只移除「對外手動入口（REST + React 按鈕）」。
- **import 連帶**：`Api/Course.php` 移除 callback 後，line 20 的 `use RecalculateTotalSales` 變孤兒 → 一併移除，否則 PHPStan/PHPCS 報 unused。`Compatibility.php` 與兩個保留測試的同名 import **保留**。
- **i18n pipeline**：`manual.json` 是唯一翻譯來源，孤兒 msgid 不清會殘留在 `.po`；務必清後 `i18n:build`，**禁止手改 `.po`**。
- 驗收標準不變（仍覆蓋全部 10 條）；差別僅「歷史資料修復」由「手動按鈕＋自動」縮為「**純升級自動一次**」，對應 issue 驗收標準第 7 條仍滿足。

---

## 0. 需求重述（一句話）

WooCommerce 課程商品的 `total_sales`（已售出數量）目前「只進不出」——訂單取消 / 退費 / 付款失敗時不扣減，導致前台徽章、後台列表色階、報表排名全部虛高。本計畫讓 Power Course **主動接管課程相關的 `total_sales` 增減**（進入觸發狀態 +、離開觸發狀態 −、冪等、含 Bundle 與訂閱語意），並提供**一次性歷史資料重算**（手動按鈕 + 升級自動跑一次）。

對應規格：
- `specs/features/order/同步課程已售出數量.feature`
- `specs/features/report/重新計算課程已售出數量.feature`
- `specs/api/api.yml` → `POST /courses/recalculate-total-sales`
- `specs/activities/課程購買開通流程.activity`（STEP:4b / DECISION:4c）

---

## 1. 關鍵現況調查（規劃依據）

| 主題 | 位置 | 重點 |
|------|------|------|
| 訂單開通課程 hook | `inc/classes/Resources/Order.php:30` | 僅監聽 `woocommerce_order_status_{trigger}`（**只有進入、沒有離開**） |
| 觸發狀態設定 | `inc/classes/Resources/Settings/Model/Settings.php:15` | `course_access_trigger`，預設 `completed`，option key `power_course_settings` |
| 課程綁定資料 | `BindCoursesData::instance($pid)->get_course_ids()`；商品 meta `bind_courses_data` | 單課程商品 `_is_course=yes`；綁課商品有 `bind_courses_data` |
| Bundle 展開 | `Order.php:95-147` | `woocommerce_new_order` 時把 Bundle 展開成實際 order item（qty = `pbp_product_quantities × 購買份數`）——**到達 completed 時 order items 已是展開後的明細** |
| 訂閱 | `Order.php:27,64-85` | `woocommerce_subscription_payment_complete`；既有邏輯確保只有 parent order（首付）觸發、續訂不觸發 |
| total_sales 讀取點 | 前台 `inc/templates/components/stock/total_sales.php:30`、`.../customer_amount/index.php:22`；API `Api/Product.php:540`、`Api/Course.php:437`；報表 SQL `Utils/Course.php:768`、`Resources/Report/Service/Stats.php:61` | **全部讀 WC 原生 `$product->get_total_sales()`**，故採 Q6=A 改寫原生欄位即可全數受惠，前端零改動 |
| **目前無任何寫入 total_sales 的程式碼** | — | 現有增量全部來自 **WooCommerce 原生** `wc_update_total_sales_counts()`（只增不減＝本 bug 根因） |
| Action Scheduler 範例 | `Api/User.php:477,61,516`（批次）；`Compatibility/Compatibility.php:45`（一次性） | `as_enqueue_async_action($hook,$args,$group)` + `add_action($hook, cb)` |
| REST 註冊 | `Api/Course.php`（`extends ApiBase`，`$apis` 陣列，callback 自動推導） | 新增 `courses/recalculate-total-sales` / `post` |
| 升級遷移 | `Compatibility/Compatibility.php:81-102` | `version_compare` gate + `update_option('pc_compatibility_action_scheduled', Plugin::$version)` |
| 服務註冊點 | `inc/classes/Resources/Loader.php:17`（`Order::instance()`） | 新服務在此 `instance()` |
| 設定頁前端 | `js/src/pages/admin/Settings/General/index.tsx`；`hooks/useSave.tsx`（`useCustomMutation` + `useApiUrl('power-course')`） | 新增「重新計算」按鈕 |
| PHP 測試基類 | `tests/Integration/TestCase.php`（`create_course()` / `enroll_*` / assert helper）；範例 `tests/Integration/Order/OrderAutoGrantCourseTest.php` | 沿用 |

---

## 2. ⚠️ 首要架構決策：避免與 WooCommerce 原生計數雙重計算

**根因**：課程 `total_sales` 目前由 WC 原生 `wc_update_total_sales_counts()` 在訂單到 `completed/processing/on-hold` 時 +1，並寫 `_recorded_sales` order meta，但 **WC 原生不會在退費 / 取消時扣減**（這是 WooCommerce 已知行為）。

**Q6=A 決議**：「由本外掛主動 increment / decrement」WC 原生 `total_sales` 欄位。若我們新增自己的 increment 又不處理 WC 原生 increment，單課程商品會被 **+2**。

> **決策（須在實作中落實，列為 #1 風險）**：Power Course **完整接管課程相關商品的 `total_sales`**。實作時移除 / 抑制 WC 原生對「課程商品 / 綁課商品 / Bundle 展開商品」的原生計數，改由本計畫的 `TotalSalesSync` 服務獨佔管理。
>
> 建議做法（master 擇一，預設 A）：
> - **A（推薦）**：在 `TotalSalesSync` 啟動時 `remove_action` 掉 WC 原生 `wc_update_total_sales_counts` 對相關狀態的掛載，本服務全面接管 → 邏輯單一、與 recalc 一致。需評估對「非課程商品」的副作用（total_sales 為 per-product，全站 hook）；若站內同時賣非課程商品，改用 B。
> - **B（保守）**：保留 WC 原生 increment，本服務只補「decrement + 綁課商品 + Bundle 課程」缺口，並以 `_recorded_sales` 與本服務旗標協調避免雙計。複雜度高、易漏。
>
> 無論 A/B，**recalc（第 4 節）是最終真相來源**，可自我修復任何漂移，降低此決策的長期風險。

---

## 3. 即時同步：`TotalSalesSync` 服務（feature: 同步課程已售出數量）

### 3.1 新檔案
`inc/classes/Resources/Course/Service/TotalSalesSync.php`（`final class`，`SingletonTrait`，`declare(strict_types=1)`）

### 3.2 核心方法

```
資料解析：
  resolve_course_quantities(\WC_Order $order): array<int $course_id, int $qty>
    - 取 $order->get_items()（Bundle 已於下單時展開成明細）
    - 逐 item：
        $pid = variation_id ?: product_id; $qty = $item->get_quantity()
        if CourseUtils::is_course_product($pid):  累加 [$pid => +$qty]
        綁課：BindCoursesData::instance($pid)->get_course_ids() 逐課程累加 [$course_id => +$qty]
      （與 Order::add_meta_to_avl_course 的「哪些課程被開通」解析一致 → 符合 Q1=C）

旗標常數：META_COUNTED = '_pc_counted_in_total_sales'（值 'yes' / 'no'）

increment(\WC_Order $order): void
  - guard：旗標已是 'yes' → return（冪等，Q2=B）
  - guard：is_subscription_renewal($order) → return（訂閱僅首付計入，Q3=A：續訂/resubscribe/switch 跳過）
  - foreach resolve_course_quantities：$product->set_total_sales(get_total_sales()+$qty); $product->save()
  - $order->update_meta_data(META_COUNTED,'yes'); $order->save()

decrement(\WC_Order $order, ?array $course_qty = null): void
  - guard：旗標非 'yes' → return（未計入不誤扣）
  - guard：is_subscription_related($order) → return（訂閱不扣減，Q3=A）
  - $map = $course_qty ?? resolve_course_quantities($order)
  - foreach：$product->set_total_sales( max(0, get_total_sales()-$qty) ); save（floor 0，不為負）
  - 若為「整單離開」：旗標設 'no'；若為部分退費：旗標維持 'yes'
```

### 3.3 Hook 掛載（在服務 `__construct`，由 `Loader.php` `instance()`）

| Hook | 觸發 | 動作 |
|------|------|------|
| `woocommerce_order_status_changed` (`$id,$from,$to,$order`) | 任何訂單狀態變更 | `if $to === trigger` → `increment()`；`elseif $from === trigger && $to in {cancelled, failed, pending, on-hold}` → `decrement()`（**排除 `refunded`**，交給退費 hook） |
| `woocommerce_order_refunded` (`$order_id,$refund_id`) | 全額 / 部分退費 | 讀 `WC_Order_Refund` 的退費明細，逐被退商品 → 對應課程 `decrement(qty=被退數量)`；退費後若整單已全退 → 旗標 'no'，否則維持 'yes'（部分退費 Q4 邊界） |

> **`refunded` 狀態雙計防護**：全額退費時 WC 會同時 (a) 建立 refund（觸發 `woocommerce_order_refunded`）與 (b) 將狀態轉為 `refunded`（觸發 `order_status_changed`）。**統一由 `woocommerce_order_refunded` 處理退費扣減**，`order_status_changed` 的 decrement 分支**排除 `refunded`**，避免雙重扣減。此為實作關鍵，務必有測試守住。

> **訂閱判定**：沿用 `wcs_order_contains_subscription()`。
> - increment 跳過：`['renewal','resubscribe','switch']`（只認首付 / parent / 一般訂單）。
> - decrement 跳過：`['parent','renewal','resubscribe','switch']`（訂閱一律不扣，Q3=A「曾成立即算」）。
> - `class_exists('WC_Subscription')` 防呆（未裝 Subscriptions 外掛時略過）。

### 3.4 對應 feature Example 驗證對照
- 付款失敗不計入 → `failed` 非 trigger，increment 不觸發 ✓
- processing 未達 trigger 不計入 / trigger=processing 即計入 → 依設定動態判斷 ✓
- 完成 +1、多份依 qty、Bundle 依 `pbp_product_quantities×份數` → `resolve_course_quantities` ✓
- 取消 / 全退扣減、不為負、冪等來回切換、已計入不重複、未計入不誤扣 → 旗標 guard ✓
- 部分退費只扣被退課程 → refund 明細逐品項 ✓
- 訂閱首付 +1 / 取消不扣 / 續訂不重複 → 訂閱 guard ✓
- 手動加學員不影響 total_sales → 手動授權不經訂單 hook ✓（無需改動）

---

## 4. 歷史重算 + 升級遷移（feature: 重新計算課程已售出數量）

### 4.1 重算服務
新檔案 `inc/classes/Resources/Course/Service/RecalculateTotalSales.php`（`SingletonTrait`）：

```
const AS_HOOK   = 'pc_recalculate_total_sales';
const AS_GROUP  = 'power_course_recalculate_total_sales';
const BATCH_SIZE = 50; // 每批訂單數，避免 timeout

schedule(): array  // 回傳 ['scheduled'=>bool,'course_count'=>int]
  - 步驟一：歸零所有課程 total_sales（_is_course=yes 的 product），並清掉所有訂單 META_COUNTED 旗標
            （冪等：直接覆寫，非累加。歸零與重算需在同批/有序進行）
  - 步驟二：排入 Action Scheduler 分批掃描「有效訂單」
            有效 = 狀態達 course_access_trigger 且 未被 cancelled/refunded/failed
            （以 wc_get_orders status 篩選 + 分頁）

process_batch(int $offset): void  // AS callback
  - 取一批有效訂單；逐單 resolve_course_quantities → 累加各課程 total_sales；標記該單 META_COUNTED='yes'
  - 訂閱：僅 parent（首付）計一次，renewal/resubscribe/switch 跳過（與 increment 同規則）
  - 仍有下一批 → 再排程 process_batch(offset+BATCH_SIZE)

重算邏輯與 increment 共用 resolve_course_quantities()，確保即時同步與重算結果一致。
```

> **歸零/重算的原子性風險**：先全部歸零再分批累加，期間若有新訂單同時觸發 increment 可能造成短暫不一致。建議：重算期間以一個「重算中」option 旗標讓即時 increment/decrement 暫時改為 no-op 或延後，重算完成再解除；或在歸零時連同旗標一併重置使即時邏輯自然吻合。列為風險（見 §7）。

### 4.2 REST 端點
`inc/classes/Api/Course.php`：
- `$apis` 新增 `['endpoint'=>'courses/recalculate-total-sales','method'=>'post','permission_callback'=>[self::class,'check_recalc_permission']]`
- 新 callback `post_courses_recalculate_total_sales_callback(\WP_REST_Request $request): \WP_REST_Response`
  - 第一行 `\nocache_headers();`（專案規範）
  - 呼叫 `RecalculateTotalSales::instance()->schedule()`
  - 回傳 `{ code:'recalculate_total_sales_scheduled', message:'重新計算已排程', data:{scheduled, course_count} }`（200）
- 權限：**`current_user_can('manage_options')`**（依 `api.yml` 明文「僅 manage_options」與 feature「僅管理員」；非管理員回 403）。
  - 註：專案多數端點用 `manage_woocommerce`；此處依規格採 `manage_options`。若團隊偏好統一用 `check_manage_woocommerce_permission`，屬可接受的小調整（ASM-1，見 §6）。

### 4.3 升級自動跑一次
`inc/classes/Compatibility/Compatibility.php` 的 `compatibility()` 內新增 version gate：

```php
// Issue #228：total_sales 一次性重算（升級自動跑一次，冪等不重複）
if ( ! \get_option('pc_issue228_total_sales_migrated') ) {
    RecalculateTotalSales::instance()->schedule();
    \update_option('pc_issue228_total_sales_migrated', 'yes');
}
```

> 用**獨立 boolean option**（`pc_issue228_total_sales_migrated`）而非綁版本號，精準對應 feature「已執行過遷移不重複自動執行」。`schedule()` 本身亦冪等（覆寫式），雙重保險。

---

## 5. 設定頁手動按鈕（前端）

`js/src/pages/admin/Settings/General/index.tsx`：
- 新增區塊 `<Heading>{__('Course sales data','power-course')}</Heading>` + 「重新計算」`<Button>`
- `useCustomMutation` + `useApiUrl('power-course')` → `POST {apiUrl}/courses/recalculate-total-sales`
- `Popconfirm` 二次確認（避免誤點）；`message.loading/success/error`
- 成功訊息：「重新計算已排程，背景處理中」
- i18n：英文 msgid + `scripts/i18n-translations/manual.json` 補繁中；改完跑 `pnpm run i18n:build`
  - 新增字串（建議）：`Course sales data`→「課程銷售資料」、`Recalculate total sales`→「重新計算已售出數量」、`Recalculate all courses' sold count? This rebuilds from valid orders.`→確認文案、`Recalculation scheduled`→「重新計算已排程」

> ⚠️ 遵守 `.claude/rules/i18n.rule.md`：msgid 一律英文、text domain 字面量 `'power-course'`、禁止手改 `.po`。

---

## 6. 假設（ASM）與缺口（GAP）登記

| 代號 | 內容 | 處置 |
|------|------|------|
| ASM-1 | recalc 權限採 `manage_options`（依 api.yml/feature）。若團隊要統一 `manage_woocommerce` 亦可 | 預設 manage_options，實作時可由 reviewer 決定，差異極小 |
| ASM-2 | 採 §2 決策 A（接管 WC 原生計數）。若站內大量販售非課程商品，改採 B | 預設 A；master 視 WC 原生 hook 全站影響評估 |
| ASM-3 | 「有效訂單」狀態定義 = `course_access_trigger` 達標且非 cancelled/refunded/failed | 已於 feature 明示 |
| GAP-1 | 重算期間與即時同步的並發一致性（§4.1） | 以「重算中」旗標或歸零+旗標重置處理，列風險 |
| GAP-2 | `set_total_sales` 後是否需 `clean_post_cache`/WC 快取失效（report SQL 直讀 postmeta） | `$product->save()` 會處理 meta；report 為即時 SQL 不受 object cache 影響。實作後以 E2E 驗證徽章即時更新 |

---

## 7. 失敗模式 / 錯誤處理登記

| 場景 | 預期行為 |
|------|----------|
| total_sales 將被扣為負 | `max(0, …)` floor 為 0 |
| 訂單無 customer / 商品已刪除 | `wc_get_product` 為 false → skip 該 item，不中斷 |
| 未裝 WooCommerce Subscriptions | `class_exists('WC_Subscription')` 防呆，跳過訂閱判定 |
| 重算大量資料 timeout | Action Scheduler 分批（BATCH_SIZE=50），立即回「已排程」 |
| 全額退費雙 hook | `order_status_changed` 排除 `refunded`，退費統一走 `woocommerce_order_refunded` |
| 重複升級 / 重複點按鈕 | option 旗標 + 覆寫式 recalc，冪等 |
| 並發 increment 與 recalc | GAP-1 旗標保護 |

---

## 8. 實作順序（依依賴，交給 tdd-coordinator 走 Red→Green→Refactor）

> 每步先寫 / 補測試（PHP Integration Test 為主），再實作至綠。

1. **`TotalSalesSync` 核心解析 + increment/decrement**（不含 hook）
   - 測試：`tests/Integration/Order/TotalSalesSyncTest.php` — 涵蓋完成 +qty、多份、Bundle、取消/退費扣減、不為負、冪等來回、旗標 guard、訂閱 guard、部分退費。
   - 實作 `resolve_course_quantities` / `increment` / `decrement`。
2. **掛載 hooks + §2 接管 WC 原生計數**
   - `order_status_changed` / `order_refunded`；於 `Resources/Loader.php` `TotalSalesSync::instance()`。
   - 測試：以 `do_action('woocommerce_order_status_changed', …)` / refund 模擬，守住 `refunded` 不雙計、訂閱續訂不增。
3. **`RecalculateTotalSales` 服務 + Action Scheduler 分批**
   - 測試：`tests/Integration/Course/RecalculateTotalSalesTest.php` — 虛高修正為有效訂單數、冪等重複執行一致、依份數、標記旗標、大量課程排入 AS。
4. **REST 端點 `POST /courses/recalculate-total-sales`**
   - 測試：非管理員 403、管理員 200 回 `scheduled`、`nocache_headers`。
5. **升級遷移 gate**（`Compatibility.php` + option 旗標）
   - 測試：首次升級排程、已遷移不重複。
6. **設定頁按鈕（React）+ i18n**
   - `General/index.tsx`；`manual.json` 補譯；`pnpm run i18n:build`。
7. **E2E（Playwright，前後台驗證）**
   - 後台點「重新計算」→ 成功提示；前台徽章 / 後台列表色階反映修正後數值（退費後下降）。
8. **品質關卡**：`pnpm run lint:php`（PHPCS + PHPStan L9）、`pnpm run lint:ts`、`composer run test`、相關 `pnpm run test:e2e:*`。

---

## 9. 檔案清單總覽

**新增**
- `inc/classes/Resources/Course/Service/TotalSalesSync.php`
- `inc/classes/Resources/Course/Service/RecalculateTotalSales.php`
- `tests/Integration/Order/TotalSalesSyncTest.php`
- `tests/Integration/Course/RecalculateTotalSalesTest.php`
- （視需要）`tests/e2e/admin/total-sales-recalculate.spec.ts`

**修改**
- `inc/classes/Resources/Loader.php`（註冊 `TotalSalesSync::instance()`）
- `inc/classes/Api/Course.php`（新端點 + callback + 權限）
- `inc/classes/Compatibility/Compatibility.php`（升級遷移 gate）
- `js/src/pages/admin/Settings/General/index.tsx`（重新計算按鈕）
- `scripts/i18n-translations/manual.json` + `languages/power-course.pot/.po/.mo/.json`（跑 `i18n:build`）
- （§2 決策 A）抑制 WC 原生課程計數的掛載點（於 `TotalSalesSync`）

**不需改**：前台徽章、後台列表色階、報表、API 回應欄位（皆讀 WC 原生 `total_sales`，Q6=A 自動受惠）。

---

## 10. 驗收標準對照（issue #228）

| 驗收標準 | 由哪步滿足 |
|----------|-----------|
| 前台徽章=有效訂單數 | §3 即時同步 + §4 重算（Q6=A 自動連動 UI） |
| 取消自動減少 | §3 `order_status_changed`→decrement |
| 退費自動減少 | §3 `order_refunded` |
| 付款失敗不累加 | §3 `failed` 非 trigger |
| 後台列表排名 / 報表反映修正 | 讀 WC 原生欄位，自動 |
| 既有資料修復機制 | §4 recalc（手動 + 升級自動） |
| 冪等（來回切換） | §3 `_pc_counted_in_total_sales` 旗標 |
| 部分退費僅扣對應課程 | §3 refund 明細逐品項 |
| 更新日誌說明 | release 時於 changelog 補（交付提醒，非程式碼） |

---

> **交接**：本計畫交由 `@zenbu-powers:tdd-coordinator` 依 §8 順序執行 TDD。
> 實作時務必先落實 §2 架構決策與 §3.3 的 `refunded` 雙計防護——這是本 issue 最易出錯之處。
