# 課程編輯頁 — DatePicker「Invalid date」顯示修復（Issue #222）

## Idea（原始需求）

> **標題：開課時間預設是 Invalid date，每次新開課程都會顯示這個，需要按叉叉才能正常**
>
> 在 `wp-admin/admin.php?page=power-course#/courses/edit/{id}` 的「課程訂價」頁籤，
> 「開課時間」DatePicker 在新建課程時會顯示「Invalid date」字樣，
> 使用者必須點 ✕ 才能正常輸入。

### 驗收標準（原文）

1. 新建課程後進入編輯頁的「課程訂價」頁籤
2. 「開課時間」DatePicker 應顯示 placeholder（空白），**不**顯示「Invalid date」
3. 其他用到共用 `parseDatePickerValue` 的 DatePicker（如「上架時間」`date_created`）也應對齊行為

---

## 根因分析（問題現況）

| 環節 | 問題描述 | 檔案 |
|------|---------|------|
| 前端 utility | `parseDatePickerValue(value)` 在 fallback 分支執行 `dayjs(value)`，當 `value === null` 時回傳 **Invalid Date 物件**（不是 `undefined`） | `js/src/utils/functions/dayjs.ts:107-127` |
| 前端 utility | `value === 0` 走 number 分支：`length !== 10 && length !== 13` 時回 `undefined`（正確），但 length === 10 的 `0` 會回 `dayjs(0)` 即 1970-01-01（早期歷史髒資料風險） | 同上 |
| 前端元件 | `DatePicker` formItem 的 `getValueProps` 直接把 `parseDatePickerValue(value)` 結果丟給 AntD `<DatePicker>`，AntD 拿到 Invalid Date 後顯示「Invalid date」字樣，需按 ✕ 才能清除 | `js/src/components/formItem/DatePicker/index.tsx:22` |
| 後端 API | `Course.php::format_course_details` 對 `course_schedule` 已處理「空字串 → null」（Issue #203），但**未處理** `meta_value === '0'` 的情況 → API 回傳 `(int) 0` | `inc/classes/Api/Course.php:471-474` |

### 觸發路徑

1. 新建課程 → 後端 `course_schedule` meta 不存在 → `get_meta()` 回傳 `''` → API 已轉 `null`（Issue #203）
2. 前端 `parseDatePickerValue(null)` → fallback `dayjs(null)` → **Invalid Date**
3. AntD DatePicker 顯示「Invalid date」文字
4. 副作用範圍：所有使用 `<DatePicker>` formItem 的欄位都會受影響（`course_schedule`、`date_created`、`AnnouncementForm` 內的開始/結束時間、`StudentTable` 內的批次設定到期日）

---

## 澄清結果（Q1–Q6 摘要）

| # | 項目 | 最終決策 |
|---|------|---------|
| Q1 | 欄位空值時顯示什麼 | **A** — 完全空白，只顯示 placeholder「Select date」（不偽造今天/現在） |
| Q2 | 修復範圍 | **A** — 一次修好共用 utility `parseDatePickerValue`，所有用此 utility 的 DatePicker 一起修正 |
| Q3 | `parseDatePickerValue` 對無效輸入回傳什麼 | **A** — 回傳 `undefined`（對齊 AntD Form「未設值」標準語義） |
| Q4 | 後端 `course_schedule === 0` 處理 | **C** — 前後端雙保險：後端 API 把 `0` / `'0'` 也轉為 `null`；前端 `parseDatePickerValue` 把 `0` / `'0'` 也視為「未設定」 |
| Q5 | E2E 覆蓋範圍 | **B** — 擴充 `tests/e2e/01-admin/course-edit-empty-fields.spec.ts`，把所有用到 `parseDatePickerValue` 的欄位都加上「不顯示 Invalid date」斷言 |
| Q6 | 資料庫髒資料 migration | **A** — 不需要 migration，runtime 已能兼容（Q3 + Q4 雙保險） |

---

## 技術決策（依澄清結果收斂）

### 前端

1. **`parseDatePickerValue` 防禦補強**（`js/src/utils/functions/dayjs.ts`）：
   - 入口先判斷 `value` 是否為 falsy（`null` / `undefined` / `''` / `0` / `'0'`）→ 直接回 `undefined`
   - number 分支內保留 length 10/13 判斷；不在範圍時回 `undefined`
   - 最後 fallback 改為：`const result = dayjs(value); return result.isValid() ? result : undefined`
   - 不可再有「直接 `dayjs(value)` 回非 valid dayjs」路徑
2. **不需修 DatePicker 元件本體**：`getValueProps` 收到 `undefined` 後，AntD 會自動顯示 placeholder（與 RangePicker 行為一致）
3. **不需在頁面層特殊處理**：所有 `<DatePicker>` 使用點（CoursePrice 的 `course_schedule`、CourseOther 的 `date_created`、AnnouncementForm 的 `start_at` / `end_at`、StudentTable 的批次到期日）都共用同一 utility，一次修好

### 後端

1. **`format_course_details` 對齊 null 語義**（`inc/classes/Api/Course.php` L471-474）：
   - 原本：`'' === (string) $product->get_meta( 'course_schedule' ) ? null : (int) $product->get_meta( 'course_schedule' )`
   - 改為：把 `'0'` / `''` / 不存在皆視為 `null`；只有 `> 0` 的合法 timestamp 才回 int
   - 抽小工具函式 `meta_int_or_null(string $meta_key, WC_Product $product): ?int`，內部統一處理 `'' / '0' / 0` → `null`
2. **同步處理其他 timestamp meta 欄位（防禦性）**：`date_on_sale_from` / `date_on_sale_to` 已在 Issue #203 處理，本次審查是否還有遺漏（如 chapter 開放時間、subscription 起算日）
3. **不寫 migration**：runtime 兼容已足；若日後發現舊資料污染再評估

### 測試

1. **PHPUnit（後端）**：
   - 新增 `tests/php/Api/CourseScheduleNullableTest.php`
   - 覆蓋：`course_schedule` meta 為 `''` / `'0'` / `0` / 不存在 / `'1735689600'` 五種輸入
   - 預期：前四種 API 回 `null`，最後一種回 `1735689600`
2. **E2E（擴充既有 spec）**（`tests/e2e/01-admin/course-edit-empty-fields.spec.ts`）：
   - 新增 scenario：「新建課程 → 進入編輯頁 → 各 DatePicker 欄位不顯示 Invalid date」
   - 斷言對象：
     - `course_schedule`（CoursePrice 頁籤的「開課時間」）
     - `date_created`（CourseOther 頁籤的「上架時間」）
   - 斷言內容：
     - DatePicker input 內 `value` attribute 為空字串
     - DatePicker 容器內**不包含**文字「Invalid date」（多語環境下用 `getAttribute('value')` 而非 textContent 比對）
     - 不需點 ✕ 即可直接輸入

---

## 規格檔案清單

| 類型 | 路徑 | 動作 |
|------|------|------|
| Open Issue 摘要 | `specs/open-issue/222-datepicker-invalid-date-fix.md` | 新增（本檔） |
| Feature（新增） | `specs/features/course/開課時間DatePicker顯示.feature` | 新增 — Gherkin 規格 |
| Feature（更新） | `specs/features/course/取得課程詳情.feature` | 補充 `course_schedule` 空值/零值 API 回傳 `null` 契約 |
| 測試（擴充） | `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` | 加入「DatePicker 不顯示 Invalid date」斷言 |
| 測試（新） | `tests/php/Api/CourseScheduleNullableTest.php` | PHPUnit 覆蓋後端 `course_schedule` 5 種輸入 → null/int 契約 |

---

## 涉及的現有程式碼

| 檔案 | 說明 |
|------|------|
| `js/src/utils/functions/dayjs.ts` | `parseDatePickerValue` — 主修點，補 falsy 防禦與 `.isValid()` 檢查 |
| `js/src/components/formItem/DatePicker/index.tsx` | DatePicker formItem 元件 — 不改本體，但驗證 `getValueProps` 收到 `undefined` 時的行為 |
| `inc/classes/Api/Course.php` | `format_course_details` L471-474 — `course_schedule` 空值/零值轉 null |
| `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` | 擴充既有 spec，新增「不顯示 Invalid date」斷言 |

---

## Out of Scope（本次不做）

- **DB migration / 一鍵清理工具**：runtime 已能兼容 `0` / `''` 髒資料，不強制清庫（Q6 A）
- **Bundle 編輯頁、Chapter 編輯頁的 DatePicker**：結構獨立，未回報相同 bug，待後續 issue 觸發再處理
- **AnnouncementForm / StudentTable 的 DatePicker**：雖然共用 `parseDatePickerValue`，但 AnnouncementForm / StudentTable 的初始值來自不同 API，本次只驗證 utility 修好後不再噴 Invalid date；不改其資料源
- **`formatDatePickerValue` 對應的反向防禦**：本次只修 parse 端；format 端目前 `value instanceof dayjs` 守衛已足
- **其他 timestamp meta 欄位的同類重構**：本次只動 `course_schedule`，避免擴大改動範圍；若 PR review 發現其他欄位也有相同問題，視情況再開 follow-up issue
