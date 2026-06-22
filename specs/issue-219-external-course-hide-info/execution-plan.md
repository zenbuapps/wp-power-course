# Issue #219 執行計畫：外部課程隱藏「課程資訊」區塊

## 需求摘要

外部課程（`WC_Product_External`）銷售頁的「課程資訊」統計區塊（開課時間、課程時長、章節數量、觀看時間、學員人數、全數上架）對外部課程沒有真實資料，過去一律以 `-` 佔位顯示，造成前台一定出現一整排橫線。

**站長決策（Issue #219 留言 2026-06-16）**：
> 不用做成開關，如果是外部課程，就整個區域隱藏。

→ **不新增任何後台開關**，外部課程直接整塊（含「課程資訊」標題）隱藏；站內課程維持現有逐項 `show_*` 開關，完全不受影響；既有外部課程升級後**無需資料 migration**，前台立即變乾淨。

> 註：此決策推翻原 Issue PM 文件的「旅程 2（管理員可選擇性顯示部分資訊）」與上一輪 clarifier 提案的「總開關 / 逐項勾選」方案。最終結論以站長留言為準。

## 影響範圍

| 檔案 | 變更 |
|------|------|
| `inc/templates/pages/course-product/body.php` | 課程資訊區塊（`typography/title` + `course-product/info`，約 line 76–145）以 `$is_external` 判斷整塊跳過。外部課程不建立 / 不渲染統計項。 |

> 站內課程路徑（`array_filter` 依 `show_*` 過濾、`if ($items)` 才渲染標題）完全保留，不得更動。

## 實作要點

1. 外部課程（`$is_external === true`）：完全略過課程資訊區塊的標題與內容渲染，連帶移除原本 `$is_external ? '-' : ...` 的佔位邏輯（外部課程根本不會走到建立 items 的程式）。
2. 站內課程：維持既有「建立 6 項 → 依 `show_*` 過濾 → 非空才渲染標題」邏輯，逐項開關行為不變。
3. 無 DB migration、無新 meta、無後台 UI 變更。

## 驗收標準（對應 Issue）

- [x] 新建立的外部課程，前台銷售頁預設不顯示「課程資訊」區塊（含標題），不出現任何 `-`
- [x] 外部課程編輯頁**不新增**任何顯示開關（依站長決策簡化）
- [x] 站內課程「課程資訊」區塊顯示行為與調整前完全一致
- [x] 既有外部課程升級後前台不再強制顯示 `-` 佔位區塊（靠 `$is_external` 判斷，零 migration）

## 規格產出

- `specs/issue-219-external-course-hide-info/hide-course-info.feature` — BDD 行為規格
- `specs/issue-219-external-course-hide-info/hide-course-info.mmd` — 渲染決策流程圖
- `specs/features/external-course/外部課程銷售頁展示.feature` — 既有規格同步更新（移除舊「顯示『-』」Rule，改為「整塊隱藏」）

## 測試建議（交 tdd-coordinator）

- E2E（frontend）：外部課程銷售頁 DOM 不含「課程資訊」標題與統計項、不含 `-`；站內課程銷售頁仍含「課程資訊」區塊。
- 邊界：外部課程殘留 `show_total_student = yes` 舊 meta 時仍隱藏。
