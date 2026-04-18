# 課程編輯頁 — 可清空選填欄位儲存修復（Issue #203）

## Idea（原始需求）

> **標題：sale_price 與 sale_date_range 空值儲存問題**
>
> 例如在 `https://local-turbo.powerhouse.tw/wp-admin/admin.php?page=power-course#/courses/edit/109`
> 把 sale_price 與 sale_date_range 都清空，然後按下儲存
> invalidate 後 sale_price 與 sale_date_range 欄位還是會顯示出之前儲存的值，代表 "空值沒有被儲存進入 DB"
>
> 其他欄位可能也會有這樣的問題，也一併檢查

### 驗收標準（原文）

1. 使用 Playwright 前往 `https://local-turbo.powerhouse.tw/wp-admin/admin.php?page=power-course#/courses/edit/109`
2. 清空 sale_price 與 sale_date_range 後，儲存，等待 API invalidate
3. API invalidate 後 sale_price 與 sale_date_range 欄位為空值

---

## 根因分析（問題現況）

| 環節 | 問題描述 | 檔案 |
|------|---------|------|
| 前端表單 | `handleOnFinish` 呼叫 `formatDateRangeData(values, 'sale_date_range', [...])`：當 RangePicker 被清空，產出的 `date_on_sale_from` / `date_on_sale_to` 為 `undefined`。InputNumber 清空時，`sale_price` 值為 `null` / `undefined`。 | `js/src/pages/admin/Courses/Edit/index.tsx` |
| 前端傳輸 | `antd-toolkit/refine` 的 `dataProvider` 透過 axios 序列化 payload；`undefined` 欄位會**整個從 body 中消失**（axios / URLSearchParams 的預設行為）。 | `antd-toolkit` 套件內部 |
| 後端分流 | `J7\PowerCourse\Api\Course::separator()` 只把「有進到 body 的 key」拆入 `$data` / `$meta_data`。缺 key 的欄位在 `$data` 中根本不存在。 | `inc/classes/Api/Course.php` L524 |
| 後端寫入 | `handle_save_course_data()` 僅 `foreach ( $data as $key => $value )` 動態呼叫 `set_{key}()`。key 不存在 → 不呼叫 setter → **DB meta 保留舊值**。 | `inc/classes/Api/Course.php` L575 |
| 後端回傳 | `Course::format_course_details` 把 `date_on_sale_from/to` 以 `(int) $timestamp` 回傳；空值時等於 `0`，前端 RangePicker 把 `0` 解讀為 1970-01-01。 | `inc/classes/Api/Course.php` L402 |

---

## 澄清結果（Q1–Q5 + Q7–Q8 一頁摘要）

| # | 項目 | 最終決策 |
|---|------|---------|
| Q1 | 修復範圍 | B — 盤點並修復「課程編輯頁 (Courses Edit)」所有可清空欄位，建立通用解法；Bundle / Chapter 等其他編輯頁留給後續 issue |
| Q2 | 清空後 DB 應存何值 | A — 空字串 `''`（與 WC 原生 `WC_Product::set_sale_price('')` 行為一致）。**附加**：前端 InputNumber、DatePicker.RangePicker 收到 `''` / `null` / `undefined` / `[0,0]` 時不得噴 React warning 或 runtime error |
| Q3 | 前後端合約 | B — 前端在 `handleOnFinish` 顯式把「被清空的欄位」補成空字串；後端收到空字串就呼叫對應 setter 清空 |
| Q4 | 欄位盤點（Courses Edit 選填可清空） | B — `sale_price`、`date_on_sale_from`、`date_on_sale_to`、`short_description`、`slug`（清空自動由 WP 重建）、`purchase_note`、`limit_type`、`limit_value`、`limit_unit`、`course_schedule`、`feature_video`、`trial_video`、`button_text`（外部課程）、`sku` |
| Q5 | sale_date_range 單側清空 | B — 不支援單側清空；後端收到「一側為空另一側有值」時統一視為兩側都清空（與 antd RangePicker 介面一致） |
| Q7 | 前端 graceful 處理 | C — 前後端都修：後端 GET 空值回 `null`（不是 `0`、不是 `''`）；前端 RangePicker / InputNumber 加 guard 防禦 `[0, 0]`、`''`、`null`、`undefined` 四種輸入都不會爆掉 |
| Q8 | 測試涵蓋 | D — 雙重保險：E2E 覆蓋 Q4 清單所有欄位的清空循環（每欄位 1 個 scenario）+ 後端 PHPUnit 覆蓋 Q4 所有欄位（每欄位 1 個 assert） |

---

## 技術決策（依澄清結果收斂）

### 前端

1. **`handleOnFinish` 補空字串策略**：在 `js/src/pages/admin/Courses/Edit/index.tsx` 的 `parseData`/`handleOnFinish` 中，針對 Q4 清單欄位，若值為 `undefined` / `null` / `NaN` / RangePicker 回傳 `null`，統一改送 `''`（對 `date_on_sale_from/to` 同樣送 `''`）。
2. **RangePicker parse 防禦**：修改 `RangePicker` 元件（或表單初始化流程），將後端回傳的 `[0, 0]` / `[null, null]` / `null` 統一轉為 `undefined`，避免 dayjs 解讀為 1970-01-01。
3. **InputNumber 防禦**：確認 antd `InputNumber` 在 `value={null}` / `value={''}` 時不觸發 React warning；若有 controlled/uncontrolled 警告則補 `?? null`。

### 後端

1. **GET response 語義修正**：`inc/classes/Api/Course.php` 的 `format_course_details`（或等價函式）把 `date_on_sale_from/to` 空值回 `null`；`sale_date_range` 空值回 `null`。`sale_price` 空值回 `''` 或 `null`（與 WC_Product getter 語義一致，**新增測試以鎖定契約**）。
2. **POST update 清空處理**：`handle_save_course_data()` 對 `$data` 既有的欄位照原 loop 呼叫 setter；**不改既有「缺 key = 保持原狀」語義**，改由前端顯式送 `''`。WC 原生 `set_sale_price('')`、`set_date_on_sale_from(null)` 皆可清空。
3. **單側 date 正規化**：`handle_save_course_data()` 在呼叫 setter 前，若 `date_on_sale_from` 與 `date_on_sale_to` 只有一側為空，將另一側也強制設為空字串（對應 Q5 決策）。
4. **meta_data 同上**：`handle_save_course_meta_data()` 對 `short_description`、`purchase_note`、`limit_*`、`course_schedule`、`feature_video`、`trial_video`、`button_text`、`sku` 等，若收到空字串就寫入空字串（既有行為已支援，需驗證 `update_meta_data($key, '')` 不會被中間層過濾掉）。

---

## 規格檔案清單

| 類型 | 路徑 | 動作 |
|------|------|------|
| 澄清紀錄 | `specs/clarify/2026-04-17-1724-issue203.md` | 新增（已產出） |
| Open Issue 摘要 | `specs/open-issue/005-empty-value-save-fix.md` | 新增（本檔） |
| Feature（新增） | `specs/features/course/清空選填欄位並儲存.feature` | 新增 |
| Feature（更新） | `specs/features/course/更新課程.feature` | 補充清空欄位規則 |
| Feature（更新） | `specs/features/course/取得課程詳情.feature` | 補充空值回傳 `null` 契約 |
| API（更新） | `specs/api/api.yml` — POST `/courses/{id}` | 在 `sale_price` / `date_on_sale_from` / `date_on_sale_to` 等欄位標註 `nullable: true`，加 "空字串 = 清空" 的行為說明；GET response schema 將同欄位標為 `nullable` |
| 測試（新） | `tests/e2e/admin/courses-edit-empty-fields.spec.ts` | E2E 覆蓋 Q4 全欄位清空循環（共 1 spec，多個 scenario） |
| 測試（新） | `tests/php/Api/CourseUpdateEmptyFieldsTest.php` | PHPUnit 覆蓋 Q4 全欄位後端清空行為 |

---

## 涉及的現有程式碼

| 檔案 | 說明 |
|------|------|
| `inc/classes/Api/Course.php` | `separator()`, `handle_save_course_data()`, `handle_save_course_meta_data()`, `format_course_details`（L402 date timestamp 輸出） |
| `js/src/pages/admin/Courses/Edit/index.tsx` | `parseData` / `handleOnFinish` — 新增空值補字串邏輯 |
| `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple.tsx` | 價格 InputNumber / RangePicker 防禦 |
| `js/src/components/formItem/RangePicker/` | RangePicker 元件 parse `[0,0]` / `null` 為 undefined |
| `powerhouse/vendor/j7-dev/wp-utils/src/classes/WP.php` | `WP::separator()` 與 `get_data_fields()`；**本次不修改**，僅依其白名單定位 data vs meta 欄位 |

---

## Out of Scope（本次不做）

- Bundle 編輯頁（`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/`）的清空行為：結構類似，留給後續 issue。
- Chapter 編輯頁、章節字幕設定頁的清空行為。
- 跨 REST API 的通用 null handling 中介層（若後續 Bundle / Chapter 也要處理，可以在那時抽共用 trait）。
- WooCommerce 原生商品編輯頁（`wp-admin/post.php?post={id}`）對齊檢查，本次僅保證 Power Course Admin SPA 的表現正確。
