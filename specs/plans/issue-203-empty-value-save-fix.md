# 實作計劃：課程編輯頁選填欄位空值儲存修復 (Issue #203)

## 概述

課程編輯頁 14 個選填可清空欄位目前無法清空儲存：使用者清空後按儲存，API invalidate 後欄位仍顯示舊值。根因橫跨前後端三個環節——前端 axios 序列化把 `undefined` 過濾、後端 `handle_save_course_data` foreach 只處理 payload 中存在的 key、後端 GET response 對空值回 `0` 造成 RangePicker 誤解為 1970-01-01。本計劃採「前端顯式補空字串 + 後端 GET response 空值契約修正 + 前端 graceful 防禦」三管齊下。

## 範圍模式：HOLD SCOPE

**預估影響**：9 個生產檔案（後端 2 + 前端 5 + 測試 2 新檔）+ 2 個新測試檔，總計 11 個檔案。範圍已由 clarifier 明確收斂，本計劃專注於防彈架構與邊界情況。

## 需求重述

1. 課程編輯頁 14 個選填欄位使用者清空並儲存 → DB 對應欄位清空為 `''`（WooCommerce 原生 setter 相容語義）
2. 清空欄位清單：
   - **data fields**（WC_Product 內建 setter）：`sale_price`、`date_on_sale_from`、`date_on_sale_to`、`short_description`、`slug`、`purchase_note`、`sku`
   - **meta fields**（`update_meta_data` 寫入）：`limit_type`、`limit_value`、`limit_unit`、`course_schedule`、`feature_video`、`trial_video`、`button_text`
3. `date_on_sale_from` 與 `date_on_sale_to` 單側為空時，後端強制兩側同步清空
4. 後端 GET response：`date_on_sale_from/to`/`sale_date_range` 空值回 `null`；`sale_price` 空值回 `''`；`course_schedule` 空值回 `null`
5. 前端 RangePicker / InputNumber 對 `[0,0]` / `''` / `null` / `undefined` 四種輸入不噴 React warning、不顯示 1970-01-01
6. 未送 key 視為保持原狀（向下相容既有合約）
7. 特例：`button_text` 清空時 fallback 為 `'Visit course'`（外部課程既有行為）；`slug` 清空由 WP 依 `post_title` 重建

## 已知風險（來自程式碼研究）

| 風險 | 緩解措施 |
| --- | --- |
| axios JSON 序列化會省略 `undefined`，但會保留 `null`。現行 `parseData`/`handleOnFinish` 把 RangePicker 清空轉成 `{ date_on_sale_from: null, date_on_sale_to: null }`，null 理論上會送達後端，但 PHP `$request->get_body_params()` 可能把 `"null"` 字串化或過濾 | 統一強制送 `''` 字串，確保 `separator()` 能辨識為「欄位存在且為空」 |
| `WP::sanitize_text_field_deep` 對 `''` 不會過濾（`sanitize_text_field('')` 回 `''`），但對 `null` 會轉成 `''`。行為可預期，但須 assert 覆蓋 | PHPUnit 測試每個欄位送 `''` 與送 `null` 都覆蓋 |
| `handle_save_course_data` 直接呼叫 `set_{key}()`。WC setter 的行為：`set_sale_price('')` 清空、`set_date_on_sale_from('')` 實際會走 `DateTime` parse，空字串處理待驗證 | 在綠燈階段先跑 PHPUnit，驗證 `WC_Product::set_date_on_sale_from('')` 能清空，不能的話手動 convert `''` → `null` |
| `format_course_base_records` L402 的 `(int) $product->get_date_on_sale_from()?->getTimestamp()` 當 null 時 `(int) null = 0`，必須改寫為 `null` 回傳 | 改寫為 ternary：`$from = $product->get_date_on_sale_from(); $timestamp_from = $from ? (int) $from->getTimestamp() : null;` |
| `format_course_base_records` L466 的 `'course_schedule' => (int) $product->get_meta( 'course_schedule' )` 空值時回 `0`，會讓前端 DatePicker 顯示 1970-01-01 | 改寫為 `$schedule_meta = $product->get_meta( 'course_schedule' ); 'course_schedule' => '' === $schedule_meta ? null : (int) $schedule_meta;` |
| `js/src/utils/functions/dayjs.ts::parseRangePickerValue` 對 `[0, 0]` 會回 `[dayjs(0), dayjs(0)]`（1970-01-01）。屬於已知前端防禦缺口 | 修改 `parseRangePickerValue`，把 `[0, 0]` / `[null, null]` / `[undefined, undefined]` 視為 `[undefined, undefined]` |
| antd InputNumber `value={null}` 官方支援，但 `value={''}` 在 controlled 模式下可能觸發 warning；`min={0}` 時 `value={NaN}` 會被 clamp | 確認 InputNumber 允許 `null` 作為 controlled value；若前端收到空字串則 normalize 為 `null` |
| `useEffect` 中 `if (watchIsFree) form.setFieldsValue({ regular_price: 0, sale_price: 0, sale_date_range: undefined })` 會把 `sale_price` 設 `0`，不是清空。此為既有邏輯，與本次修復正交，不處理 | 保留現狀，僅在測試中註記 |
| 前端 graceful 防禦可能影響 Bundle Edit 頁（也使用 `RangePicker`、`parseRangePickerValue`）— 共享元件變更 | 變更 `parseRangePickerValue` 屬於 pure function 修正，對 Bundle Edit 也是向下相容；但需在測試中確認 Bundle Edit 仍正常 |

## 架構變更

### 後端（PHP）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/classes/Api/Course.php` | 修改 | 1. `format_course_base_records` L402/L430-431 改寫空值契約：`date_on_sale_from/to` 與 `sale_date_range` 空時回 `null`、`sale_price` 空時回 `''`、`course_schedule` L466 空時回 `null`。<br>2. `handle_save_course_data` 新增「date_on_sale 單側同步清空」的 normalize 邏輯（在 foreach 呼叫 setter 之前）。<br>3. 保持既有語義：未送 key = 保持原狀 |
| `tests/Integration/Course/CourseUpdateEmptyFieldsTest.php` | 新增 | PHPUnit 雙重保險：14 欄位 × 清空 scenario + GET response 空值契約 assert |

### 前端（TypeScript）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `js/src/pages/admin/Courses/Edit/index.tsx` | 修改 | `handleOnFinish` 在 `parseData` 之後加入「可清空欄位 normalize 為空字串」邏輯：對 14 欄位清單若值為 `undefined` / `null` / `NaN`，統一改送 `''`（對 `date_on_sale_from/to` 同樣送 `''`）。 |
| `js/src/utils/functions/dayjs.ts` | 修改 | `parseRangePickerValue` 新增 guard：`[0, 0]` / `[null, null]` / `[undefined, undefined]` / 含 falsy 元素時回 `[undefined, undefined]`，避免 dayjs(0) 解讀為 1970-01-01 |
| `js/src/components/formItem/RangePicker/index.tsx` | （視 2 的修正是否充分而定） | 若 `parseRangePickerValue` 修正已足夠，本檔不動。否則在 `getValueProps` 補二次 guard |
| `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple.tsx` | 修改 | InputNumber 加 `value` normalize（antd v5 原生支援 `null`；確認無 controlled/uncontrolled warning） |
| `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` | 新增 | Playwright E2E 覆蓋 14 欄位清空循環（每欄位 1 scenario）+ invalidate 後仍為空的驗證 + console warning 檢查 |

> 注意：`antd-toolkit` 的 `formatDateRangeData` 不修改（第三方依賴），由 `handleOnFinish` 後處理覆蓋。

### 規格文件

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `specs/plans/issue-203-empty-value-save-fix.md` | 新增（本檔） | 實作計劃 |

## 資料流分析

### 資料流 1：使用者清空欄位並儲存（Write Path）

```
USER ACTION          FORM VALUES          PARSE_DATA          HANDLE_ON_FINISH         AXIOS SERIALIZE           PHP SEPARATOR            HANDLE_SAVE_COURSE_DATA / META
──────────           ──────────           ──────────          ────────────────         ──────────────           ─────────────            ──────────────────────────
清空 sale_price   →  {sale_price: null}   passthrough    →    [Phase C]             →  JSON {sale_price:""}  →  $data['sale_price']=''  →  $product->set_sale_price('')
                                                               normalize null→''                                                           (已驗證 WC 支援)
清空 RangePicker  →  {sale_date_range:    formatDateRange-    [Phase C]             →  JSON {date_on_sale_   →  $data 含兩鍵 ''         →  $product->set_date_on_sale_
                      undefined}           Data 轉成          null→''、兩側正        from:"", ..._to:""}                                      from('') / _to('')
                                           {date_on_sale_     規化單側清空
                                           from:null, to:null}
未送 sale_price   →  {other fields}       passthrough        未觸及 sale_price      →  JSON 不含 sale_price   →  $data 不含 sale_price   →  foreach 不呼叫 setter →
                                                                                                                                             DB 保持原狀（合約）

SHADOW PATHS:
  │                    │                   │                    │                        │                          │                          │
  ▼                    ▼                   ▼                    ▼                        ▼                          ▼                          ▼
使用者連點儲存        form 重複提交        N/A                  [idempotent]             axios retry               duplicate update          WC setter idempotent
                     antd 預設防抖                                                        無副作用                   保持相同結果              安全
清空網路斷線          N/A                  N/A                  onFinish reject          axios 500                 未抵達 PHP                 無副作用
                                                                mutation.isError        Refine notify error
僅清空 from 有 to    {sale_date_range:     formatDateRange-    保持 [null, to]      →  JSON {from:"", to:ts} →  Phase A normalize:        $product->set_date_on_
                      [null, dayjs]}       Data 回 {from:       （Phase C 不介入）                               from='' 且 to!='' → to=''  sale_from('') / _to('')
                                           null, to:ts}                                                            （強制兩側同步）           兩側同步清空
```

### 資料流 2：清空後 invalidate 重新取得詳情（Read Path）

```
API INVALIDATE    →  GET /courses/{id}       →  FORMAT_COURSE_BASE_RECORDS    →  RESPONSE                  →  REFINE CACHE UPDATE  →  FORM INITIAL VALUES
────────────         ────────────────           ─────────────────────────         ────────                     ────────────────         ──────────────────

Refine useForm       WC_Product               [Phase B]                           JSON                         TanStack Query            parseRangePickerValue
invalidates cache    DB meta empty             - sale_price: '' (WC getter       {sale_price: "",              setData                   [Phase D]
                                                 回 '')                            date_on_sale_from: null,                              [0,0] / [null,null] →
                                              - date_on_sale_from: null (空時     date_on_sale_to: null,                                  [undefined, undefined]
                                                 不回 0)                           sale_date_range: null,
                                              - sale_date_range: null             course_schedule: null,
                                              - course_schedule: null             on_sale: false}

SHADOW PATHS:
  │                       │                       │                                │                              │                       │
  ▼                       ▼                       ▼                                ▼                              ▼                       ▼
invalidate 失敗          WP_Error                 Exception                        non-JSON                        stale cache             Form 初值錯誤
Refine 顯示錯誤          404/403                  PHP fatal                        Refine 靜默失敗                 showRefreshModal       導致 UI 顯示錯誤
                         → 403 test case                                                                                                  → Phase D guard
只一側有值                from=ts, to=null         直接輸出                          {from: ts, to: null}         正常                    RangePicker 只渲
                                                                                                                                           染起始，終止為 empty
sale_price 有值           '888'                   WC getter 回 '888'              {sale_price: "888"}           正常                    InputNumber 顯示 888
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| `Api/Course.php::handle_save_course_data` | `$product->set_sale_price('')` 拋例外（理論上 WC 支援） | `\Throwable` | 不接管，保持現狀；WC 例外會被 Refine 的 notificationProvider 捕捉 | 是（toast） |
| `Api/Course.php::handle_save_course_data` | 單側 date 強制同步清空後呼叫 setter 拋例外 | `\Throwable` | 同上 | 是 |
| `Api/Course.php::format_course_base_records` | `$product->get_date_on_sale_from()` 拋例外 | `\Throwable` | 現行 `?->getTimestamp()` 已 guard；改寫後保持 null-safe | 否（底層） |
| `handleOnFinish` (前端) | `formatDateRangeData` 回 `{from:null,to:null}`，normalize 為 `''` 時拋例外 | `TypeError` | normalize 使用純 `??`/三元運算，不會拋例外 | 否 |
| `parseRangePickerValue` (前端) | 接收 `[NaN, NaN]` 或混合型別 | `TypeError` | 既有 `every(typeof === 'number')` guard 已足夠；Phase D 擴充 falsy guard | 否 |

> 所有「靜默 + 無處理」= CRITICAL GAP。本計劃中無 critical gap。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| 前端清空 → 送 `undefined` → axios 省略 → 後端不收 | DB 保持舊值（**本 bug**） | Phase C 修復 | Phase F E2E | 是（欄位顯示舊值） | Phase C 後自動修復 |
| 前端清空 → 送 `''` → 後端 setter 失敗 | DB 保持舊值 + 500 error | Phase A 測試覆蓋 | Phase E PHPUnit | 是（toast） | 前端顯示 error toast，使用者重試 |
| 後端回 `0` → RangePicker 誤解為 1970-01-01 | UI 顯示錯誤日期 | Phase B 修復 | Phase F E2E | 是（錯誤日期） | Phase B 後自動修復 |
| 後端回 `null` → 前端未 guard → React warning | 開發者 console warning | Phase D 修復 | Phase F E2E（console check） | 否（使用者不見） | Phase D 後自動修復 |
| 單側 date 清空 → 後端接受單側空 | DB meta 不一致 | Phase A 修復 | Phase E PHPUnit | 否（邏輯層） | Phase A 後自動修復 |
| 外部課程清空 `button_text` → fallback 'Visit course' | 既有行為 | 既有 L625 處理 | Phase E assert | 是（按鈕文字） | N/A |
| 清空 `slug` → WP 自動重建 | 既有 WP 行為 | 既有 sanitize_title | Phase E assert | 是（URL 變動） | N/A |

## 實作步驟（TDD 強制順序）

> **關鍵原則**：Phase E（PHPUnit red state）與 Phase F（E2E red state）必須在 Phase A/B/C/D 實作前建立並確認測試失敗。由 `@wp-workflows:tdd-coordinator` 協調測試先行流程。

### Dependency Graph

```
              ┌─────────────────────────────┐
              │ Phase E: PHPUnit Red State  │  ← 必先，驅動 Phase A+B
              └──────────┬──────────────────┘
                         │
              ┌──────────▼──────────────────┐
              │ Phase F: E2E Red State      │  ← 必先，驅動 Phase C+D
              └──────────┬──────────────────┘
                         │
       ┌─────────────────┼─────────────────┐
       ▼                 ▼                 ▼
┌────────────┐    ┌────────────┐    ┌────────────┐
│ Phase A    │    │ Phase B    │    │ Phase D    │
│ 後端 Write │    │ 後端 Read  │    │ 前端 Parse │ （三者可平行）
│ (setter)   │    │ (getter)   │    │ Guard      │
└──────┬─────┘    └──────┬─────┘    └──────┬─────┘
       │                 │                 │
       └────────┬────────┘                 │
                ▼                          │
         ┌─────────────┐                   │
         │  Phase E    │                   │
         │  PHPUnit    │                   │
         │  Green      │                   │
         └──────┬──────┘                   │
                │                          │
       ┌────────┴──────────────────────────┘
       ▼
┌──────────────┐
│ Phase C      │  ← 依賴 Phase A 綠燈（後端能接受 '')
│ 前端 Write   │
│ Normalize    │
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ Phase F      │  ← E2E 綠燈驗證全鏈路
│ E2E Green    │
└──────────────┘
```

---

### 第一階段（Phase E）：PHPUnit 紅燈 — 後端測試先行

> **Agent**：`@wp-workflows:wordpress-master`（由 tdd-coordinator 先派給 `test-creator` 寫測試，再派 wordpress-master 實作）

**目標**：建立 `tests/Integration/Course/CourseUpdateEmptyFieldsTest.php`，覆蓋 14 欄位 × 清空 + GET response 空值契約，執行後預期全部紅燈（失敗）。

**步驟 E.1**：**建立測試檔**（檔案：`tests/Integration/Course/CourseUpdateEmptyFieldsTest.php`）

- 行動：建立 PHPUnit 測試類別 `CourseUpdateEmptyFieldsTest extends TestCase`
- 測試方法清單：
  - `test_清空_sale_price_後_DB_meta_應為空字串`
  - `test_清空_date_on_sale_from_to_後_DB_meta_應為空字串`
  - `test_單側_date_on_sale_from_空_to_有值_後_兩側同步清空`
  - `test_單側_date_on_sale_to_空_from_有值_後_兩側同步清空`
  - `test_清空_short_description_後_DB_應為空字串`
  - `test_清空_purchase_note_後_meta_應為空字串`
  - `test_清空_sku_後_DB_應為空字串`
  - `test_清空_slug_後_WP_依_post_title_重建_post_name`
  - `test_清空_limit_type_value_unit_後_meta_應為空字串`
  - `test_清空_course_schedule_後_meta_應為空字串`
  - `test_清空_feature_video_後_meta_應為空字串`
  - `test_清空_trial_video_後_meta_應為空字串`
  - `test_外部課程清空_button_text_後_fallback_為_Visit_course`
  - `test_未送_sale_price_key_時_保持原狀（向下相容）`
  - **GET response 空值契約**：
    - `test_date_on_sale_from_to_為空時_GET_回_null`
    - `test_只有_from_有值時_from_回_timestamp_to_回_null`
    - `test_sale_price_為空時_GET_回空字串_on_sale_為_false`
    - `test_course_schedule_為空時_GET_回_null`
- 測試方式：建立課程 + 有資料 → 呼叫 `Course::post_courses_with_id_callback`（模擬 REST request body 含 `''`）→ assert `get_post_meta` / `get_sale_price` 等
- 原因：測試先行驅動 Phase A+B 實作
- 依賴：無
- 風險：中（需正確模擬 `WP_REST_Request` body params；可參考既有 `CourseCRUDTest` 的 factory 模式）

**步驟 E.2**：**執行 PHPUnit 確認紅燈**

- 行動：`composer run test -- --filter CourseUpdateEmptyFieldsTest`
- 預期：所有測試紅燈（功能尚未實作）
- 原因：確認測試有效、未誤綠
- 依賴：E.1
- 風險：低

**階段成功標準**：
- [ ] 測試檔案建立完成
- [ ] 執行後至少 14 個 test methods 失敗（紅燈）
- [ ] 失敗訊息清楚指向 Phase A/B 的預期行為

---

### 第二階段（Phase F-Red）：Playwright E2E 紅燈 — 前端測試先行

> **Agent**：`@wp-workflows:react-master`（由 tdd-coordinator 先派給 `test-creator`）

**目標**：建立 `tests/e2e/01-admin/course-edit-empty-fields.spec.ts`，覆蓋 14 欄位清空 + invalidate + UI 驗證，執行後預期紅燈。

**步驟 F1.1**：**建立 E2E 測試檔**（檔案：`tests/e2e/01-admin/course-edit-empty-fields.spec.ts`）

- 行動：建立 Playwright spec，使用 `test.use({ storageState: '.auth/admin.json' })` + `api-client` 預建課程資料
- 測試 scenario 清單（每 scenario 建立獨立課程避免互擾）：
  - `清空 sale_price → 儲存 → invalidate → InputNumber 顯示為空 placeholder`
  - `清空 sale_date_range → 儲存 → invalidate → RangePicker 顯示 placeholder，非 1970-01-01`
  - `清空 short_description → 儲存 → invalidate → BlockNoteDrawer 為空`
  - `清空 purchase_note → 儲存 → invalidate → TextArea 為空`
  - `清空 sku → 儲存 → invalidate → SKU 為空`（注意：sku 目前不在 Edit 頁表單，若不存在則以 API fallback 驗證後端）
  - `清空 slug → 儲存 → invalidate → post_name 由 WP 重建`
  - `改為 unlimited 清空 limit_value/limit_unit → 儲存 → invalidate → 設定正確`
  - `清空 course_schedule → 儲存 → invalidate → DatePicker 為 placeholder`
  - `清空 feature_video → 儲存 → invalidate → VideoInput 為初始態`
  - `清空 trial_video → 儲存 → invalidate → VideoInput 為初始態`
  - `外部課程清空 button_text → 儲存 → invalidate → 顯示 'Visit course' fallback`
  - **UI 防禦驗證**：
    - `開啟編輯頁 DB 為空的課程 → 無 React warning、無 runtime error、無 1970-01-01`（console listener）
- 測試方式：
  - 使用 `api-client.createCourse` + `api-client.updateCourse` 預建課程（含或不含 sale_price 等資料）
  - 用 Playwright 操作 UI 清空欄位 → 點擊儲存 → `waitForResponse` 等 invalidate → assert 欄位狀態
- 原因：測試先行驅動 Phase C+D 實作
- 依賴：無（可與 E 平行）
- 風險：中（Playwright strict mode locator 需精準；VideoInput 清空可能需要特殊操作）

**步驟 F1.2**：**執行 E2E 確認紅燈**

- 行動：`pnpm run test:e2e:admin -- course-edit-empty-fields`
- 預期：所有 scenario 失敗
- 原因：確認測試有效
- 依賴：F1.1
- 風險：低

**階段成功標準**：
- [ ] Spec 檔建立完成，至少 12 個 scenario
- [ ] 執行後全部紅燈
- [ ] console listener 已正確捕捉 React warning

---

### 第三階段（Phase A）：後端 Write Path — API 支援清空

> **Agent**：`@wp-workflows:wordpress-master`

**目標**：修改 `Api/Course.php::handle_save_course_data`，新增 date_on_sale 單側同步清空邏輯。設定完成後 Phase E 的 write-path 測試綠燈。

**步驟 A.1**：**修改 `handle_save_course_data`**（檔案：`inc/classes/Api/Course.php` 第 575 行附近）

- 行動：在 `foreach ( $data as $key => $value )` 之前加入：
  ```php
  // Issue #203: date_on_sale 單側清空時，強制兩側同步清空
  $has_from = array_key_exists( 'date_on_sale_from', $data );
  $has_to   = array_key_exists( 'date_on_sale_to', $data );
  if ( $has_from || $has_to ) {
      $from_empty = $has_from && '' === (string) ( $data['date_on_sale_from'] ?? '' );
      $to_empty   = $has_to && '' === (string) ( $data['date_on_sale_to'] ?? '' );
      // 只要有一側為空（而且至少有一側被送進來），兩側同步清空
      if ( $from_empty || $to_empty ) {
          $data['date_on_sale_from'] = '';
          $data['date_on_sale_to']   = '';
      }
  }
  ```
- 原因：對齊 antd RangePicker 介面語義（Q5 決策）
- 依賴：Phase E 紅燈建立
- 風險：低（pure function 變更，不影響其他欄位）

**步驟 A.2**：**驗證 WC setter 對 `''` 的行為**

- 行動：在 PHPUnit 中 assert `set_sale_price('')`、`set_date_on_sale_from('')`、`set_date_on_sale_to('')`、`set_sku('')`、`set_short_description('')`、`set_purchase_note('')`、`set_slug('')` 都能正確清空
- 若 `set_date_on_sale_from('')` 不能直接清空（WC 內部可能要求 null），則在 step A.1 中 convert `''` → `null`（僅對 date 欄位）：
  ```php
  if ( '' === $data['date_on_sale_from'] ) {
      $data['date_on_sale_from'] = null;
  }
  if ( '' === $data['date_on_sale_to'] ) {
      $data['date_on_sale_to'] = null;
  }
  ```
- 原因：對齊 WC setter 的 null-handling 合約
- 依賴：A.1
- 風險：低

**步驟 A.3**：**外部課程 button_text 現行 fallback 保留**

- 行動：確認 `handle_save_course_meta_data` L625 的 `'' !== $button_text ? $button_text : __('Visit course', 'power-course')` 邏輯仍有效；在 E 階段加 assert 覆蓋
- 依賴：A.1
- 風險：低（既有行為）

**階段成功標準**：
- [ ] Phase E write-path 測試由紅轉綠（14 個欄位清空 + 單側同步）
- [ ] `composer run phpstan` 仍通過 level 9
- [ ] `pnpm run lint:php` 通過

---

### 第四階段（Phase B）：後端 Read Path — GET Response 空值契約

> **Agent**：`@wp-workflows:wordpress-master`

**目標**：修改 `format_course_base_records`，空值欄位回 `null`（date）/ `''`（price），而非 `0`。

**步驟 B.1**：**修改 date_on_sale_range 空值契約**（檔案：`inc/classes/Api/Course.php` L402, L430-431）

- 行動：重寫 L402：
  ```php
  // Issue #203: 空值回 null，避免前端 dayjs 誤解為 1970-01-01
  $date_from       = $product->get_date_on_sale_from();
  $date_to         = $product->get_date_on_sale_to();
  $timestamp_from  = $date_from ? (int) $date_from->getTimestamp() : null;
  $timestamp_to    = $date_to ? (int) $date_to->getTimestamp() : null;
  $sale_date_range = ( null === $timestamp_from && null === $timestamp_to )
      ? null
      : [ $timestamp_from, $timestamp_to ];
  ```
- L430-431 保持：
  ```php
  'sale_date_range'    => $sale_date_range,      // null 或 [from, to]（含 null 元素）
  'date_on_sale_from'  => $timestamp_from,       // null 或 int
  'date_on_sale_to'    => $timestamp_to,         // null 或 int
  ```
- 原因：對齊 spec `取得課程詳情.feature` 的 Rule「空值回 null」
- 依賴：Phase E 紅燈建立（read-path 部分）
- 風險：中（前端消費者可能期望 array；需確認 `CourseBundles/Edit/index.tsx` 的 Bundle 編輯頁對 `sale_date_range = null` 的處理 — 實際上 Bundle 頁有自己的 API，本欄位只影響 Course Edit 頁的初值）

**步驟 B.2**：**修改 course_schedule 空值契約**（檔案：`inc/classes/Api/Course.php` L466）

- 行動：
  ```php
  // 舊：'course_schedule' => (int) $product->get_meta( 'course_schedule' ),
  // 新：
  'course_schedule'    => '' === $product->get_meta( 'course_schedule' )
                          ? null
                          : (int) $product->get_meta( 'course_schedule' ),
  ```
- 原因：避免前端 DatePicker 收到 `0` 後顯示 1970-01-01
- 依賴：B.1
- 風險：低

**步驟 B.3**：**sale_price 空值契約驗證**（檔案：`inc/classes/Api/Course.php` L427）

- 行動：保持 `'sale_price' => $sale_price` 現狀（`WC_Product::get_sale_price()` 空時回 `''`）
- 新增：在 PHPUnit 中 assert `sale_price === ''`（非 `null`、非 `0`）
- 原因：對齊 WC getter 契約，前端 InputNumber 收到 `''` 能 graceful 處理（Phase D）
- 依賴：B.1
- 風險：低

**步驟 B.4**：**前端類型更新**（檔案：`js/src/pages/admin/Courses/List/types/index.ts`）

- 行動：若型別定義中 `date_on_sale_from`/`to` / `sale_date_range` / `course_schedule` 不是 nullable，補為 `number | null` / `[number | null, number | null] | null`
- 原因：TypeScript strict 能 catch 後續消費者的處理缺漏
- 依賴：B.1~B.3
- 風險：低

**階段成功標準**：
- [ ] Phase E read-path 測試由紅轉綠
- [ ] `composer run phpstan` 通過 level 9
- [ ] `pnpm run lint:ts` 與 `tsc --noEmit` 通過

---

### 第五階段（Phase D）：前端 Read Path Guard — RangePicker / InputNumber

> **Agent**：`@wp-workflows:react-master`

**目標**：修改 `parseRangePickerValue` 等 pure function，讓前端對 `[0, 0]` / `null` / `''` 等輸入 graceful 處理。本 phase 不依賴 Phase B，可平行開發。

**步驟 D.1**：**修改 `parseRangePickerValue`**（檔案：`js/src/utils/functions/dayjs.ts` L37-61）

- 行動：在「`every(typeof === 'number')`」分支之前補 guard：
  ```ts
  export function parseRangePickerValue(values: unknown) {
      if (!Array.isArray(values)) {
          return [undefined, undefined]
      }
      if (values.length !== 2) {
          return [undefined, undefined]
      }
      // Issue #203: [0, 0] / [null, null] / [undefined, undefined] 視為空
      if (values.every((v) => v === 0 || v === null || v === undefined || v === '')) {
          return [undefined, undefined]
      }
      if (values.every((value) => value instanceof dayjs)) {
          return values
      }
      if (values.every((value) => typeof value === 'number')) {
          // 單側空時，僅轉換非零側
          return values.map((value) => {
              if (value === 0) return undefined
              if (value.toString().length === 13) return dayjs(value)
              if (value.toString().length === 10) return dayjs(value * 1000)
              return undefined
          })
      }
      return [undefined, undefined]
  }
  ```
- 原因：核心防禦邏輯；無論後端是否修完 Phase B，前端都不會顯示 1970-01-01
- 依賴：無（與 Phase B 平行）
- 風險：低（pure function；Bundle Edit 頁同樣會受益）

**步驟 D.2**：**InputNumber 驗證**（檔案：`js/src/pages/admin/Courses/Edit/tabs/CoursePrice/ProductPriceFields/Simple.tsx`）

- 行動：確認 antd v5 `InputNumber` 對 `value={''}` / `value={null}` 不會 warning；若有則加 `getValueProps={(v) => ({ value: v === '' ? null : v })}`
- 驗證：F1.2 的 console listener 覆蓋此行為
- 依賴：無
- 風險：低

**步驟 D.3**：**DatePicker 防禦**（檔案：`js/src/components/formItem/DatePicker/index.tsx`）

- 行動：Grep 確認 `DatePicker` 元件是否對 `0` / `null` / `''` 都能顯示 placeholder（而非 1970-01-01）
- 若需要，在元件層補 `parseDatePickerValue` guard 類似 D.1
- 依賴：無
- 風險：低

**階段成功標準**：
- [ ] Phase F UI 防禦 scenario 綠燈（console 無 warning，無 1970-01-01）
- [ ] `pnpm run lint:ts` + `pnpm run build` 通過

---

### 第六階段（Phase C）：前端 Write Path — handleOnFinish Normalize

> **Agent**：`@wp-workflows:react-master`

**目標**：在 `handleOnFinish` 補上「可清空欄位值為 `undefined`/`null`/`NaN` 時 normalize 為 `''`」邏輯。依賴 Phase A（後端能接受 `''`）。

**步驟 C.1**：**定義可清空欄位清單常數**（檔案：`js/src/pages/admin/Courses/Edit/index.tsx`）

- 行動：在 component 外部定義：
  ```ts
  // Issue #203: Courses Edit 頁可清空的選填欄位（未送 key = 保持原狀；送 '' = 清空）
  const CLEARABLE_FIELDS = [
      'sale_price',
      'date_on_sale_from',
      'date_on_sale_to',
      'short_description',
      'slug',
      'purchase_note',
      'limit_type',
      'limit_value',
      'limit_unit',
      'course_schedule',
      'feature_video',
      'trial_video',
      'button_text',
      'sku',
  ] as const
  ```
- 原因：集中管理，避免散落
- 依賴：無
- 風險：低

**步驟 C.2**：**新增 normalize 函式**（檔案：`js/src/pages/admin/Courses/Edit/index.tsx`）

- 行動：在 `handleOnFinish` 中，`parseData` 之後加入：
  ```ts
  const handleOnFinish = (values: Partial<TCourseRecord>) => {
      const formattedValues = parseData(values)

      // Issue #203: 對已進表單的可清空欄位，若值為 null/undefined/NaN/空陣列，normalize 為空字串
      const normalized: Record<string, unknown> = { ...formattedValues }
      for (const key of CLEARABLE_FIELDS) {
          if (!(key in normalized)) continue // 未動過的欄位保持原樣（未送 key = 保持原狀）
          const v = normalized[key]
          if (
              v === undefined ||
              v === null ||
              (typeof v === 'number' && Number.isNaN(v))
          ) {
              normalized[key] = ''
          }
          // feature_video / trial_video 是 object，清空時為 {type:'none', id:''} 或 null
          // 後端已有 ?: fallback，送 '' 代表清空 meta
      }

      const {
          images = [],
          // @ts-ignore
          files,
          ...rest
      } = normalized
      const [mainImage, ...galleryImages] = (images as TImage[])

      onFinish({
          ...rest,
          image_id: mainImage ? mainImage.id : '0',
          gallery_image_ids: galleryImages?.length
              ? galleryImages.map(({ id }) => id)
              : '[]',
      })
  }
  ```
- 原因：解決 axios 省略 `undefined` 的根本問題
- 依賴：Phase A 綠燈（後端能處理 `''`）
- 風險：中（需確認 `feature_video` / `trial_video` 這類 object 欄位的清空契約—通常前端清空是 `null` 或 `{type:'none', id:''}`，送 `''` 後端會以 `update_meta_data('feature_video', '')` 處理，wc get_meta 回 `''` ∉ 前端 type，需確保 Phase B 的 format 有 fallback `?: {type:'none', id:'', meta:[]}`—實際既有行為已有，見 L300-309）

**步驟 C.3**：**驗證 `limit_type` 切換邏輯**（檔案：`js/src/components/formItem/WatchLimit/index.tsx`）

- 行動：現行 `handleReset` 已把 `limit_value`/`limit_unit` 設為 `''`（line 22-34），C.2 的 normalize 會把這些空字串保留送出，確保後端能清空 meta
- 原因：既有邏輯與 Phase C 對齊
- 依賴：C.2
- 風險：低

**階段成功標準**：
- [ ] Phase F E2E 全部綠燈
- [ ] 手動驗證 URL `#/courses/edit/{id}` 清空 sale_price + sale_date_range 可儲存
- [ ] `pnpm run lint:ts` + `pnpm run build` 通過

---

### 第七階段（Phase E-Green）：PHPUnit 綠燈

> **Agent**：`@wp-workflows:wordpress-master`

**目標**：確認 Phase A+B 實作後，Phase E 全部綠燈。

**步驟 E-G.1**：執行 `composer run test -- --filter CourseUpdateEmptyFieldsTest`
**步驟 E-G.2**：修復剩餘紅燈（若有）
**步驟 E-G.3**：執行 `pnpm run lint:php` + `composer run phpstan` 通過

---

### 第八階段（Phase F-Green）：Playwright E2E 綠燈

> **Agent**：`@wp-workflows:react-master`

**目標**：確認 Phase C+D 實作後，Phase F 全部綠燈。

**步驟 F-G.1**：執行 `pnpm run test:e2e:admin -- course-edit-empty-fields`
**步驟 F-G.2**：修復剩餘紅燈（若有）
**步驟 F-G.3**：執行 `pnpm run lint:ts` + `pnpm run build` 通過

---

### 第九階段（Review / Refactor）

> **Agent**：`@wp-workflows:wordpress-reviewer` + `@wp-workflows:react-reviewer`

- 跨 Agent 審查：安全、i18n、型別、效能
- 檢查 text domain 一律 `'power-course'`
- 檢查無新 `any` 型別、無硬編碼字串
- 確認 `specs/api/api.yml` 與實作對齊（已由 clarifier 更新，再次 diff 確認）

---

## 測試策略

### 整合測試（PHPUnit）— Phase E

- 路徑：`tests/Integration/Course/CourseUpdateEmptyFieldsTest.php`
- 範疇：14 個 write-path assert + 4 個 read-path contract assert
- 執行：`composer run test -- --filter CourseUpdateEmptyFieldsTest`
- 關鍵邊界：
  - 單側 date 清空
  - 未送 key 保持原狀（向下相容）
  - 外部課程 `button_text` fallback
  - `slug` 清空後由 WP 重建

### E2E 測試（Playwright）— Phase F

- 路徑：`tests/e2e/01-admin/course-edit-empty-fields.spec.ts`
- 範疇：12+ scenario 覆蓋全鏈路（UI 清空 → 儲存 → invalidate → UI 顯示空）
- 執行：`pnpm run test:e2e:admin -- course-edit-empty-fields`
- 關鍵邊界：
  - invalidate 後不出現 1970-01-01
  - console 無 React warning
  - RangePicker placeholder 狀態

### 靜態分析

- `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）
- `pnpm run lint:ts`（ESLint）
- `pnpm run build`（tsc + vite）

---

## 依賴項目

- WooCommerce WC_Product setter/getter 對空字串的行為（已驗證：`set_sale_price('')` 支援；`set_date_on_sale_from(null)` 支援，`''` 可能需 convert）
- antd `InputNumber` / `DatePicker.RangePicker` v5.x 對 `null` / `''` 的處理
- Refine 4.x invalidate 機制（正常運作）
- `@wordpress/i18n` 用於所有新增使用者可見字串

---

## 風險與緩解措施

| 等級 | 風險 | 緩解方法 |
| --- | --- | --- |
| **中** | WC setter 對 `date_on_sale_*` 送 `''` 可能需 convert 為 `null` | Phase A 實作後先跑 PHPUnit 驗證；若需要，統一在 `handle_save_course_data` 前把 date 欄位 `''` 轉 `null` |
| **中** | `feature_video` / `trial_video` 是 object 型別，送 `''` 會破壞前端 type 契約 | Phase B 已有 `?:` fallback（L300-309）；Phase C 若送 `''` 後端會 `update_meta_data('feature_video', '')`，GET 時 `get_meta` 回 `''`，觸發 `?:` fallback 回預設 object。向前端是透明的 |
| **中** | `parseRangePickerValue` 修改可能影響 Bundle Edit 頁 | 屬於向下相容修正（原本 `[0, 0]` 顯示 1970-01-01 也是 bug），Bundle Edit 頁同樣受益；在 F 階段加一個 Bundle Edit smoke 以確認未破壞 |
| **中** | `sku` 欄位目前不在 Courses Edit 頁 UI（僅有後端），E2E 無法從 UI 清空 | E2E 改用 `api-client.updateCourse` 送 `{ sku: '' }`，直接 assert DB；或在 Edit 頁補 sku Input（超出 Out of Scope，不做） |
| **低** | `slug` 清空後 WP 自動重建的 URL 可能與使用者預期不符 | spec Rule 已明確規範；E2E assert `post_name !== ''` 即可 |
| **低** | `CLEARABLE_FIELDS` 若未來新增欄位忘了補 | 在 `CoursesEdit` 元件附近加 inline comment 指向 Issue #203 |

---

## 錯誤處理策略

**選定策略：快速失敗 + 使用者可見提示（對齊既有架構）**

- 後端 setter 若拋例外 → `WP_REST_Response` 回 500 + error message → Refine notificationProvider 顯示 toast
- 前端 `handleOnFinish` normalize 為 pure function，不引入新的 error path
- 不引入檢查點、不做自動重試（本次變更非長流程）

---

## 限制條件（Out of Scope）

本計劃**不會**做：

- Bundle Edit 頁（`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/`）的清空行為修復 — 留給後續 issue
- Chapter Edit 頁、章節字幕設定頁的清空行為
- 跨 REST API 的通用 null handling 中介層（若 Bundle / Chapter 後續也要做，可抽共用 trait）
- WooCommerce 原生商品編輯頁（`wp-admin/post.php?post={id}`）對齊檢查
- 新增 `sku` 欄位到 Courses Edit 頁 UI（目前只在後端 API，前端未曝光；若產品要求可另開 issue）

---

## 預估工時

| Phase | 預估工時 | 主要工作 |
| --- | --- | --- |
| E (PHPUnit Red) | 3h | 建立 18 個 test methods 骨架 + 確認紅燈 |
| F (E2E Red) | 4h | 建立 12 scenario + console listener + 確認紅燈 |
| A (後端 Write) | 2h | 單側 date 同步邏輯 + setter 驗證 |
| B (後端 Read) | 2h | 空值契約改寫（3 處）+ 型別更新 |
| D (前端 Guard) | 2h | `parseRangePickerValue` 修正 + InputNumber 驗證 |
| C (前端 Write) | 3h | CLEARABLE_FIELDS normalize + 回歸測試 |
| E (Green) | 1h | 修復剩餘紅燈 |
| F (Green) | 2h | 修復剩餘紅燈 + Bundle Edit smoke |
| Review | 2h | 跨 Agent 審查 + i18n 檢查 |
| **合計** | **21h** | |

## 預估複雜度：中

理由：
- 範圍明確（14 欄位清單已釘死）
- 三個獨立的修復點（Write / Read / Guard）可平行
- 最大不確定性是 WC setter 對 `''` vs `null` 的實際行為，由 PHPUnit 驗證

## 成功標準

- [ ] 使用者在 `/wp-admin/admin.php?page=power-course#/courses/edit/{id}` 清空 14 個欄位任一並儲存，invalidate 後 DB 與 UI 皆為空
- [ ] RangePicker 對 `[0, 0]` / `[null, null]` / `null` 都顯示 placeholder，不顯示 1970-01-01
- [ ] console 無 React warning 或 runtime error
- [ ] PHPUnit 18 個新 test 全綠
- [ ] Playwright 12+ scenario 全綠
- [ ] 既有 Bundle Edit / Chapter Edit 流程未 regression
- [ ] `pnpm run lint:php`、`pnpm run lint:ts`、`pnpm run build` 皆通過
- [ ] PR 審查通過（i18n、安全、型別、效能）

---

## 交接說明

此計劃完成後，交由 `@wp-workflows:tdd-coordinator` 依序協調：

1. **先派 `test-creator`**（PHP + TS）建立 Phase E + Phase F 紅燈測試
2. **派 `@wp-workflows:wordpress-master`** 執行 Phase A + B（後端）
3. **派 `@wp-workflows:react-master`** 執行 Phase D（前端 Guard，可與 2 平行）
4. **派 `@wp-workflows:react-master`** 執行 Phase C（前端 Write，依賴 2）
5. **派 Reviewer agents** 做最終審查

> 注意：步驟 1~4 各階段完成後須跑對應的 lint + test 確認綠燈，才推進下一階段。
