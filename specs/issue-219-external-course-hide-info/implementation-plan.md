# Issue #219 實作計畫：外部課程隱藏「課程資訊」區塊

> 由 planner 產出，交接 `@zenbu-powers:tdd-coordinator` 執行。
> 範圍模式：**HOLD SCOPE**（bug 修復，範圍已由站長決策鎖定，無擴張空間）。

## 1. 需求重述（站長最終決策）

> 「不用做成開關，如果是外部課程，就整個區域隱藏。」（Issue #219 留言 2026-06-16）

- 外部課程（`WC_Product_External`）銷售頁：**整塊**「課程資訊」區域（含標題 + 6 個統計項）完全不渲染，前台不出現任何 `-` 佔位符。
- **不**新增任何後台開關 / meta / UI。
- 站內課程：維持現有逐項 `show_*` 開關行為，**完全不受影響**。
- 既有外部課程升級後**無需資料 migration**，靠 runtime `$is_external` 判斷立即變乾淨。

## 2. 影響範圍（單一檔案）

| 檔案 | 變更類型 | 摘要 |
|------|---------|------|
| `inc/templates/pages/course-product/body.php` | 修改 | 將「課程資訊」區塊（建立 items → 過濾 → 渲染標題/info）整段包進 `if ( ! $is_external )` 守衛，並移除 `$is_external ? '-' : ...` 佔位三元式 |

**不需異動：**
- ❌ React 後台（`CourseOther/index.tsx` 已用 `{!isExternal && ...}` 隱藏逐項開關，維持原狀）
- ❌ DB migration / 新 meta
- ❌ `course-product/info.php` 模板（外部課程根本不會呼叫它）
- ❌ i18n（不新增 / 不刪除任何 msgid；移除的只是 PHP 三元式分支，字串本身不變）

## 3. 具體修改內容

`body.php` 現況：line 62–145 無條件計算統計變數、建立 `$items`（含 `$is_external ? '-' : X` 佔位）、`array_filter` 後 `if ($items)` 渲染標題與 info 模板。

**改法（wrap-and-clean）：**

1. 將 line 62–145 整段（`$course_schedule_in_timestamp` 計算起，到 info 模板 `load_template` 的 `}` 止）包進 `if ( ! $is_external ) { ... }`。
   - 這些統計變數（`$course_schedule`、`$course_hour/minute`、`$count_all_chapters`、`$total_student`、`$limit_labels`）**只在此區塊使用**，line 145 之後不再引用，移入守衛無副作用。
2. 守衛內 `$is_external` 恆為 `false`，故將 6 個項目的 `'value' => $is_external ? '-' : X` **簡化為 `'value' => X`**，徹底移除 `-` 佔位邏輯（呼應 spec「連帶移除佔位邏輯」）。
3. 站內路徑其餘邏輯（`array_filter` 依 `show_*` 過濾 → `if ($items)` 才渲染標題）**原封不動**保留，逐項開關語意不變。
4. 移除 line 76 註解 `// 外部課程：所有統計項顯示「-」`（已過時），改為說明站內課程才建立統計項的繁中註解。

**改動後邏輯等價於 `hide-course-info.mmd` 決策流程：**
`$is_external === true` → 整塊跳過；`false` → 建立 6 項 → 過濾 → 非空才渲染標題（全關時連標題一起消失，沿用既有行為）。

> ⚠️ 注意 line 44 的 `$is_avl = ! $is_external && ...` 與 line 166+ 的 mobile CTA 區塊**不在本次變更範圍**，不得更動。

## 4. 實作順序（單步，無跨檔依賴）

1. **(Red)** 先寫測試（見第 5 節），確認外部課程銷售頁仍渲染出「課程資訊」→ 測試失敗。
2. **(Green)** 修改 `body.php`，加上 `if ( ! $is_external )` 守衛並簡化三元式 → 測試轉綠。
3. **(Refactor)** 清理註解、確認站內路徑 diff 為零（除縮排）。
4. **(品質)** 跑 `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）必須全綠。

## 5. 測試策略

### 5.1 E2E（Playwright，`tests/e2e/02-frontend/`）— 主力

新增 spec（建議 `051-external-course-hide-info.spec.ts`）。需先在 helper / fixtures 建立一筆**外部課程**測試資料（目前 `tests/e2e/fixtures` 無外部課程，需新增 setup）：

| 案例 | 斷言 |
|------|------|
| 外部課程銷售頁不出現課程資訊區塊 | body 內**不含**「課程資訊 / Course information」標題；不含任一統計項 label（開課時間 / 課程時長 / 章節數量 / 觀看時間 / 學員人數）；body 區塊內**不含** `-` 佔位符 |
| 外部課程殘留 `show_total_student=yes` 舊 meta 仍隱藏 | 設定該 meta 後，銷售頁仍不出現課程資訊區塊與「學員人數」 |
| 站內課程（回歸）不受影響 | 站內課程銷售頁**仍顯示**「課程資訊」標題，且依 `show_*` 顯示對應項目 |

> 對應 `hide-course-info.feature` 三個 Rule。回歸案例可沿用既有站內課程 fixture（`FRONTEND_COURSE`）。

### 5.2 PHPUnit Integration（`tests/Integration/`）— 選配補強

若 E2E 外部課程 fixture 成本高，可改以 Integration test 直接驗證：建立 `WC_Product_External` → 以 `Plugin::load_template('course-product/body', ...)` 捕獲輸出（`ob_start`）→ assert 輸出**不含**「Course information」與統計項；站內課程 `WC_Product_Simple` → assert **含**「Course information」。可參考 `tests/Integration/` 既有 TestCase 模式。

### 5.3 邊界情況

- 外部課程 `show_*` meta 全殘留 `yes` → 仍整塊隱藏（守衛在 `show_*` 判斷之前）。
- 站內課程 `show_*` 全部 `no` → 沿用既有 `if ($items)` 行為，整塊（含標題）不渲染（與 Issue #219 無關，但不可回歸）。

## 6. 風險評估與注意事項

| 風險 | 等級 | 緩解 |
|------|------|------|
| 誤動站內課程顯示邏輯 | 中 | 守衛只包外部分支；站內 `array_filter` + `if ($items)` 程式碼零改動，靠回歸 E2E 守住 |
| `$is_external` 判斷錯誤（subclass / WC 版本） | 低 | 沿用既有 line 34 `$product instanceof \WC_Product_External`，與全檔一致，不新增判斷方式 |
| 變數移入守衛後被後段引用 | 低 | 已確認 line 145 後無引用；phpstan level 9 會抓未定義變數 |
| changelog 未告知既有外部課程前台變化 | 低（非工程） | 建議發版說明標註「外部課程銷售頁不再顯示課程資訊區塊」，避免站長誤判資料消失 |

## 7. 完成定義（DoD）

- [ ] `body.php` 外部課程整塊隱藏，站內路徑 diff 僅縮排
- [ ] 新增 E2E spec 三案例（外部隱藏 / 殘留 meta 仍隱藏 / 站內回歸）通過
- [ ] `pnpm run lint:php` 全綠（phpcs + phpstan level 9）
- [ ] `pnpm run test:e2e:frontend` 通過
- [ ] commit message 繁體中文（Conventional Commits）
