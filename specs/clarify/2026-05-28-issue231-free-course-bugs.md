# Clarify Session 2026-05-28 — Issue #231

## Idea

### 標題：[Bug] 免費課程的三種異常情境

Power Course 課程定價有三種設定方式（互相獨立的邏輯路徑）：

1. **免費課程開關**：後台勾選「This is a free course」（`is_free = yes`），系統自動把價格歸零，前台走 `single-product-free.php` 卡片
2. **手動設 0 元**：不勾開關，直接把價格填 0（`price = 0`，`is_free` 仍為 `no`），前台走 `single-product-sale.php` 卡片
3. **銷售方案（Bundle）**：課程可額外建立多個 bundle product，各自獨立定價

另有「隱藏單堂課購買」開關（`hide_single_course = yes`），啟用後前台應只顯示銷售方案卡片，不顯示主課程購買卡片。

回報的三個異常：

1. 免費課程 + 隱藏單堂課 + 有銷售方案時，隱藏功能失效（免費卡片仍顯示）
2. 手動 0 元主課程 + 0 元銷售方案時，銷售方案無法下單
3. 主課程非 0 元 + 銷售方案 0 元，可以通過（目前唯一可行路徑）

---

## 現況掃描（程式碼定位）

| 觀察項目 | 內容 |
|---------|------|
| 卡片路由入口 | `inc/templates/components/card/single-product.php`：先判斷 `is_external` → 再判斷 `is_free`（`yes` 載入 free 卡片並 `return`）→ 否則載入 sale 卡片 |
| 免費卡片 | `inc/templates/components/card/single-product-free.php`：**完全沒有 `hide_single_course` 檢查** ← Bug #1 根因 |
| 付費卡片 | `inc/templates/components/card/single-product-sale.php`：lines 26–29 有 `if ( 'yes' === $hide_single_course ) { return; }` |
| 方案卡片 | `inc/templates/components/card/bundle-product.php`：以 `is_purchasable() && is_in_stock()` 決定按鈕可否點擊 |
| Sidebar 組裝 | `inc/templates/pages/course-product/sider.php`：先 `card/single-product`，再 foreach 已 `publish` 的 bundle products 各渲染一張 `card/bundle-product` |
| 後台定價 UI | `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx`：`is_free` 與 `hide_single_course` 兩個 `FiSwitch`（lines 86–101）；勾 `is_free` 時 `useEffect` 自動把價格設 0 |
| 訂單授權 | `inc/classes/Resources/Order.php`：掛在 `woocommerce_order_status_{course_access_trigger}`（completed/processing），0 元訂單 WC 會自動完成 → 既有 hook 即可授權 |

**Bug #1 根因確認**：卡片路由在 `is_free=yes` 時直接載入 `single-product-free.php` 並 `return`，而 `hide_single_course` 的隱藏判斷只寫在 `single-product-sale.php`，免費卡片從未檢查此開關 → 免費課程的隱藏失效。

---

## Q&A（用戶確認 `Q1 A / Q2 A / Q3 B / Q4 A / Q5 B / Q6 B / Q7 A`）

> 第 2 輪偵測到 Q1 A 與 Q5 文案內部矛盾、且 Q1 A 推翻原驗收標準 #2，追加 Q7 收斂，用戶選 **A**：
> 「若真的發生『免費課程 + 隱藏單堂課 + 0 個已發佈方案』，那是 ADMIN 刻意為之，不需要過多干涉。」

- **Q1 [情境] 免費課程無已發佈方案時免費卡片是否顯示**：**A — 一律隱藏**
  - 只要開「隱藏單堂課」，免費卡片一律隱藏，即使完全沒有銷售方案也不顯示（與付費課程行為一致）
  - 經 Q7 確認貫徹此選項：**移除原驗收標準 #2**（無方案時仍顯示免費卡片）

- **Q2 [工程] Bug #2 處理方向**：**A — 修復結帳流程**
  - 讓「手動 0 元主課程 + 0 元方案」也能正常下單，不加任何阻擋
  - 理由：核心抱怨是「無法下單」，直接修好最符合「有卡片就能下單」

- **Q3 [情境] 手動 0 元 vs 免費開關是否引導**：**B — 後台提示但不強制**
  - 站長手動把課程價格設為 0（且 `is_free` 未開）時，後台跳出提示「是否改用免費課程開關？」
  - 僅提示，保留站長刻意用「0 元非免費」做特殊定價的彈性

- **Q4 [情境] 0 元方案下單後授權方式**：**A — 自動授予**
  - 完成 0 元訂單後自動授予課程觀看權，與付費方案完全一致（沿用既有訂單授權 hook）

- **Q5 [情境] 後台「隱藏單堂課購買」是否補說明**：**B — 補說明文字**
  - 在 toggle 旁加說明，文案依 Q7 A 修正為：**「此功能對免費課程同樣生效（會隱藏免費卡片）」**
  - （原 Q5 文案「需有已發佈方案才會隱藏」因 Q7 A 作廢）

- **Q6 [工程] 付費課程相同邊界是否一併修**：**B — 只修免費課程**
  - 付費課程維持現狀（`hide_single_course=yes` 就一律隱藏，無方案 = 無法購買）
  - 免費課程 Q1 A 後行為已與付費課程一致，無需額外改付費卡片

- **Q7 [情境] 收斂 Q1/Q5 矛盾**：**A — 貫徹 Q1 A**
  - 「免費課程 + 隱藏單堂課 + 0 方案」= 站長刻意為之，學員無路可進是可接受的設定後果
  - 移除原驗收標準 #2、Q5 說明文字改為「此功能對免費課程同樣生效（會隱藏免費卡片）」

---

## 修訂後驗收標準

- [ ] 免費課程 + 隱藏單堂課 + **有**已發佈銷售方案 → 前台**不顯示**免費卡片，只顯示方案卡片
- [ ] 免費課程 + 隱藏單堂課 + **無**已發佈銷售方案 → 前台**也不顯示**任何卡片（admin 刻意決策，不干涉）〔取代原 #2〕
- [ ] 免費課程 + **未開**隱藏單堂課 → 免費卡片正常顯示（行為不變）
- [ ] 手動 0 元主課程 + 0 元方案 → 學員可正常完成下單結帳
- [ ] 0 元方案訂單完成後 → 自動授予課程授權（與付費方案一致）
- [ ] 站長手動把課程價格設為 0（`is_free` 未開）→ 後台跳出提示建議改用免費課程開關（不強制、可忽略）
- [ ] 後台「隱藏單堂課購買」toggle 旁顯示說明「此功能對免費課程同樣生效（會隱藏免費卡片）」
- [ ] 現有路徑「主課程非 0 元 + 方案 0 元 + 隱藏單堂課」行為不受影響
- [ ] 付費課程「隱藏單堂課 + 無方案 = 無購買卡片」行為維持現狀（Q6 B，不在本次改動範圍）

---

## 實作方案摘要（交 tdd-coordinator）

### Bug #1 — 免費卡片補 `hide_single_course` 檢查（PHP）

`inc/templates/components/card/single-product-free.php`：在 `$product instanceof WC_Product` 檢查後，比照 `single-product-sale.php` lines 26–29 加入：

```php
$hide_single_course = $product->get_meta( 'hide_single_course' ) ?: 'no';
if ( 'yes' === $hide_single_course ) {
    return; // Q1 A / Q7 A：一律隱藏，不因 0 方案而 fallback
}
```

### Bug #2 — 0 元方案結帳修復（PHP）

- 根因待 tdd-coordinator 深入（可能落在 WC `is_purchasable()` 對 0 元 bundle 的判定、或某處 0 元 guard）
- 目標：手動 0 元主課程 + 0 元 bundle 能 add-to-cart 並完成結帳
- 授權（Q4 A）：0 元訂單 WC 自動完成，`Resources/Order.php` 既有 hook 應自動授權；需驗證 0 元 bundle 訂單確實觸發授權

### Q3 + Q5 — 後台定價 UI（React）

`js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx`：

1. **Q3**：監聽 `regular_price`/`sale_price`，當價格為 0 且 `is_free !== 'yes'` 時顯示非阻擋提示（建議改用免費課程開關）
2. **Q5**：在 `hide_single_course` 的 `FiSwitch` 旁加說明文字「此功能對免費課程同樣生效（會隱藏免費卡片）」
3. i18n：新字串走英文 msgid + `scripts/i18n-translations/manual.json`，跑 `pnpm run i18n:build`（見 `.claude/rules/i18n.rule.md`）

### 不在範圍

- 付費課程卡片邏輯（Q6 B 維持現狀）
- 統一「手動 0 元」與「免費開關」的底層邏輯（Q3 選 B，僅提示不合併）
