# 實作計劃：課程編輯頁 DatePicker「Invalid date」顯示修復 (Issue #222)

## 概述

課程編輯頁「課程訂價」頁籤的「開課時間」DatePicker 在新建課程時會顯示「Invalid date」字樣，使用者必須點 ✕ 才能正常輸入。根因鏈為「後端 `course_schedule` 空值已回 `null`（Issue #203 已修）→ 前端共用 utility `parseDatePickerValue(null)` 走 fallback `dayjs(null)` → 回傳 Invalid Date dayjs 物件 → AntD DatePicker 顯示 "Invalid date"」。

本計劃採「**前端共用 utility 一次修好 + 後端 API `'0' / 0` 對齊 `null` 語義（雙保險）+ PHPUnit/E2E 守門**」三管齊下，避免散落式修補留下技術債。

## 範圍模式：HOLD SCOPE

**預估影響**：4 個生產檔案（後端 1 + 前端 1 + 翻譯 0 + 測試 2 — 1 新檔 + 1 擴充）。範圍由 clarifier 明確收斂於 `Q1-A / Q2-A / Q3-A / Q4-C / Q5-B / Q6-A`，本計劃專注於防彈架構與邊界情況，**不擴大改動**。

## 需求重述（依澄清結果 Q1–Q6）

1. **Q1-A** — 「開課時間」欄位未設定時顯示 placeholder「Select date」，不偽造今天/現在
2. **Q2-A** — 修共用 utility `parseDatePickerValue`，所有共用此 utility 的 DatePicker 一起修正
3. **Q3-A** — `parseDatePickerValue` 對「無效輸入」回傳 `undefined`（對齊 AntD Form「未設值」標準語義），絕不可回 Invalid Date dayjs 物件
4. **Q4-C** — 前後端雙保險：後端 API 把 `course_schedule` 為 `'0'` / `0` / `''` / 不存在皆轉為 `null`；前端 utility 對 `0` / `'0'` / `''` / `null` / `undefined` / `NaN` 都視為「未設定」
5. **Q5-B** — 擴充既有 `course-edit-empty-fields.spec.ts`，對所有用到 `parseDatePickerValue` 的欄位（`course_schedule`、`date_created`）都加上「不顯示 Invalid date」斷言
6. **Q6-A** — 不寫 DB migration，runtime 雙保險已能兼容歷史髒資料

## 已知風險（來自程式碼研究）

| 風險 | 緩解措施 |
| --- | --- |
| `format_course_base_records` (L471-474) 目前只處理 `''` → null，未處理 `'0'` / `0`，會回傳 `(int) 0` 給前端 → 前端 `parseDatePickerValue` 走 number 分支 length 1 不在 10/13 → 回 `undefined`（**目前其實也不會顯示 1970**，僅 number 分支自帶保護）。**但**：若未來某條路徑送來 `dayjs(0)` 等 truthy 但 invalid 的值，仍會炸。本次完整收斂雙保險 | 後端抽 helper `meta_int_or_null( WC_Product $product, string $meta_key ): ?int`，前端入口加 falsy 短路 |
| `parseDatePickerValue` fallback 為 `dayjs(value)`，當 `value === null` 時回 Invalid Date 物件——這是 Issue #222 的**直接根因** | 入口先做 `isFalsyEmpty(value)` 短路；fallback 改為 `const result = dayjs(value); return result.isValid() ? result : undefined` |
| `parseDatePickerValue` 對非預期物件（如 `{}` / 自訂物件）會 `dayjs({})` 回 Invalid Date | `.isValid()` 守衛同時涵蓋此 case |
| AntD DatePicker `value={undefined}` 與 `value={null}` 行為差異——本專案 antd 5.x，兩者皆會顯示 placeholder，但 `undefined` 是 controlled-uncontrolled hybrid 標準；測試需驗證 placeholder 確實出現 | E2E 用 `input.value === ''` 斷言而非 textContent；placeholder 額外用 `placeholder` attribute 斷言 |
| 後端 helper 抽出後是否影響其他 timestamp meta（`date_on_sale_from/to`、subscription、chapter 開放時間）—— 本次明確 **Out of Scope**，僅 `course_schedule` 走新 helper，避免擴大改動 | helper 留作 future use；本次只在 `course_schedule` 一處呼叫 |
| utility 變動影響範圍：`AnnouncementForm`、`StudentTable`、`BundleProduct/Edit` 等也共用 `parseDatePickerValue` | 共用 utility 的修正屬「更安全」的單向變更（從會回 Invalid Date 變成回 undefined）；其他頁面只會更乾淨，不會壞。E2E `bundle-product.spec.ts` 既有 smoke 可作為 regression 守衛 |
| i18n：本次新增/修改字串清單為**空**（"Select date" 已存在於 `manual.json`） | 不需跑 `pnpm run i18n:build`；若 reviewer 要求驗證，跑一次確認 diff 為空 |
| PHPStan level 9：新 helper 簽名 `?int` 與既有 strict_types=1 相容；需確認 `WC_Product::get_meta()` 回傳型別宣告 | helper 使用 `(string)` cast 後再判斷，避免型別陷阱 |
| 後端規範要求 REST callback 第一行呼叫 `\nocache_headers()` (Issue #216) — 但本次只動 `format_course_base_records` 內部，不動 callback 入口，現有 `\nocache_headers()` 保持不動 | 不需改動 |

## 資料流分析

### 修正前（Issue #222 觸發鏈）

```
新建課程 → 後端 course_schedule meta 不存在
        → format_course_base_records L471-474
        → '' === (string) get_meta('course_schedule') ? null : (int) get_meta
        → null
        → REST response 回傳 course_schedule: null
        ↓
前端 Refine.dev useOne 取得 record.course_schedule === null
        ↓
<DatePicker formItemProps={{ name: ['course_schedule'] }} />
        ↓ getValueProps(value=null) → { value: parseDatePickerValue(null) }
        ↓
parseDatePickerValue(null)
  → not instanceof dayjs ✓
  → typeof !== 'number' ✓
  → fallback: dayjs(null) → Invalid Date dayjs 物件 ❌
        ↓
AntD <DatePicker value={Invalid Date} /> → 顯示 "Invalid date" 字樣 ❌
```

### 修正後

```
新建課程 → 後端 course_schedule meta 不存在
        → format_course_base_records 改呼叫 meta_int_or_null($product, 'course_schedule')
        → '' / '0' / 0 / 不存在 一律回 null；合法 timestamp 回 int
        → REST response 回傳 course_schedule: null  （與既有相容）
        ↓
前端 Refine.dev useOne 取得 record.course_schedule === null
        ↓
<DatePicker /> getValueProps(value=null) → { value: parseDatePickerValue(null) }
        ↓
parseDatePickerValue(null)
  → isFalsyEmpty(null) === true → return undefined ✅
        ↓
AntD <DatePicker value={undefined} /> → 顯示 placeholder "Select date" ✅
```

### 髒資料防禦（後端 → 前端的多重保險）

```
DB postmeta course_schedule = '0'  (歷史髒資料)
        ↓
get_meta('course_schedule') → '0' (string)
        ↓
[修正後] meta_int_or_null → (string) === '0' → null
        ↓
REST response course_schedule: null → DatePicker placeholder ✅

[假設後端被繞過或外部路徑送入 0]
record.course_schedule === 0 (number)
        ↓
parseDatePickerValue(0)
  → isFalsyEmpty(0) === true → return undefined ✅
```

## 錯誤處理登記表

| 來源 | 場景 | 處理策略 |
| --- | --- | --- |
| 後端 `meta_int_or_null` 收到非預期型別（陣列等） | `get_meta` 回 array | `(string)` cast 後若為 `'' / '0'` 回 `null`，否則 `(int)` cast → 自然降級為 0 / null（仍走 `'0' → null` 分支） |
| 前端 `parseDatePickerValue` 收到 dayjs **無效** instance | `dayjs.isValid() === false` 但 instanceof dayjs === true | 入口先檢查 `value instanceof dayjs && !value.isValid()` → 回 `undefined`（補強：原本僅 `instanceof dayjs` 直接回傳，會把 invalid dayjs 直接給 AntD） |
| 前端 `parseDatePickerValue` 收到 NaN | `typeof value === 'number' && isNaN(value)` | falsy 短路會吃到（`Number.isNaN(NaN) → true` + length 不在 10/13 → undefined）；額外用 `Number.isFinite` 守衛確保 |
| 前端 `parseDatePickerValue` 收到字串數字（如 `'1735689600'`） | string | 目前 fallback `dayjs('1735689600')` 會回 Invalid Date（dayjs 不支援純秒級字串）。**改進**：先嘗試 `Number(value)`，若是 10/13 位純數字字串，走 number 分支；否則進 `dayjs(value).isValid()` 守衛 |
| 前端 `parseDatePickerValue` 收到 ISO 8601 字串（如 `'2025-01-01T00:00:00Z'`） | string | `dayjs(value)` 能正確解析 → 走 fallback 的 `.isValid()` 守衛回傳 valid dayjs |
| E2E：DatePicker placeholder 因 i18n 差異變成中文「請選擇日期」 | locale = zh_TW | 斷言用 `input.value === ''` 為主，placeholder 只做次要斷言；若需斷言 placeholder，用 regex `/Select date|請選擇日期/` |

## 架構變更

### 後端（PHP）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/classes/Api/Course.php` | 修改 | 1. 新增 private helper：`private function meta_int_or_null( \WC_Product $product, string $meta_key ): ?int` — 對 `'' / '0' / 0 / null / 不存在` 回 `null`，否則 `(int)` cast。<br>2. L471-474 改呼叫 `'course_schedule' => $this->meta_int_or_null( $product, 'course_schedule' )`<br>3. **不**動其他 meta 欄位（Out of Scope） |

### 前端（TypeScript）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `js/src/utils/functions/dayjs.ts` | 修改 `parseDatePickerValue` | 1. 入口加 `isFalsyEmpty` 守衛：`null / undefined / '' / 0 / '0' / NaN` 一律回 `undefined`<br>2. `value instanceof dayjs` 分支補 `.isValid()` 檢查，無效時回 `undefined`<br>3. number 分支保留現有 length 10/13 邏輯；補 `Number.isFinite` 守衛<br>4. 新增字串數字分支：若 `typeof value === 'string'` 且 `/^\d{10}$|^\d{13}$/`，轉 number 後走 number 分支<br>5. fallback `dayjs(value)` 包 `.isValid()` 守衛，無效回 `undefined`<br>6. 既有 try/catch 保留作為最後保險 |

### i18n

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `scripts/i18n-translations/manual.json` | **不變** | "Select date" 已存在；本次不新增字串 |

### 測試

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `tests/Integration/Course/CourseScheduleNullableTest.php` | 新增 | PHPUnit 覆蓋 `meta_int_or_null` 與 `format_course_base_records` 5 種 `course_schedule` 輸入：<br>① meta 不存在 → null<br>② `''` → null<br>③ `'0'` (字串) → null<br>④ 數字 `0` → null<br>⑤ `'1735689600'` → `1735689600` (int)<br>透過 REST `WP_REST_Request` GET `/power-course/v2/courses/{id}` 走完整 callback path，並 assert response.course_schedule 型別與值 |
| `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` | 擴充 | 在既有 `test.describe('Issue #203 ...')` 內新增 `test.describe('Issue #222 - DatePicker 不顯示 Invalid date', ...)`：<br>**Test A** 新建課程進入「課程訂價」→ `course_schedule` input value === '' 且 page body 不含 "Invalid date" 字樣<br>**Test B** 新建課程進入「其他」頁籤 → `date_created` DatePicker 不顯示 "Invalid date"（注意 `date_created` 有預設 WP 建立時間，input value 不應為空——僅斷言**不**出現 "Invalid date"）<br>**Test C** 設過 `course_schedule = 1735689600` 的課程進入編輯頁 → input value 為 `"2025-01-01 08:00"`（或時區對應值）且不含 "Invalid date"<br>**Test D** Regression：歷史髒資料 `course_schedule = '0'`（用 API 寫 postmeta）→ 進入編輯頁不顯示 "Invalid date"，input value === '' |

### 規格檔案

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `specs/plans/issue-222-datepicker-invalid-date-fix.md` | 新增（本檔） | 實作計劃 |
| `specs/features/course/開課時間DatePicker顯示.feature` | **不變** | 已由 clarifier 寫好；後續 TDD red 階段直接對應 |
| `specs/features/course/取得課程詳情.feature` | **延後** | 本計劃不動；若 reviewer 認為需補 `course_schedule` 空值/零值 API 回傳契約段，由 follow-up 補（避免擴大改動） |
| `specs/open-issue/222-datepicker-invalid-date-fix.md` | **不變** | clarifier 產出，作為背景文件 |

## 實作順序（TDD：Red → Green → Refactor）

### Phase 1 — Red（測試先行，全部紅燈）

1. **新增 PHPUnit**：`tests/Integration/Course/CourseScheduleNullableTest.php`
   - 5 個 Example case（meta 不存在 / `''` / `'0'` / `0` / `'1735689600'`）
   - 對 `'0'` / `0` 那兩個 case 目前後端會回 `0` (int)，**測試應紅燈**
   - 跑 `composer run test -- --filter CourseScheduleNullableTest` 確認紅燈
2. **擴充 E2E**：`tests/e2e/01-admin/course-edit-empty-fields.spec.ts`
   - 加入 Issue #222 describe block 與 4 個 Test
   - 跑 `pnpm run test:e2e:admin --grep "Issue #222"` 確認紅燈（新建課程當下會看到 "Invalid date"）

> 紅燈標準：PHPUnit 對 `'0'`/`0` case fail；E2E Test A 因 "Invalid date" 出現而 fail。Test C 可能已綠（合法 timestamp 路徑本來就 work），保留作 regression 守衛。

### Phase 2 — Green（最小實作讓綠燈）

3. **修 `js/src/utils/functions/dayjs.ts::parseDatePickerValue`**：
   - 加 `isFalsyEmpty` 短路
   - dayjs instance 補 `.isValid()` 守衛
   - 新增字串數字分支
   - fallback 加 `.isValid()` 守衛
4. **修 `inc/classes/Api/Course.php`**：
   - 新增 `meta_int_or_null` private helper（PHPDoc 完整，含 `@phpstan-ignore` 註解若必要）
   - L471-474 改呼叫 helper
5. 跑 `composer run test`、`pnpm run test:e2e:admin --grep "Issue #222"`、`pnpm run test:e2e:admin --grep "Issue #203"`（確保 Issue #203 既有 spec 未壞）

### Phase 3 — Refactor & 品質

6. 跑 `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）
7. 跑 `pnpm run lint:ts`（ESLint --fix）
8. 跑 `pnpm run format`（Prettier）
9. 跑 `pnpm run build` 確認 TS 編譯通過
10. 跑 `pnpm run i18n:build` 確認 `.pot / .po / .mo / .json` 無預期外 diff（應為 0 diff）

### Phase 4 — 視覺驗證（playwright-cli skill）

11. 用 playwright-cli 連 `https://local-turbo.powerhouse.tw/wp-admin`（從 `.env` 讀帳密）
12. 建立新課程 → 進入「課程訂價」頁籤 → 截圖確認「開課時間」顯示空白 placeholder
13. 進入「其他」頁籤 → 截圖確認「上架時間」不顯示 "Invalid date"
14. 設個合法 timestamp 後儲存 → 重新整理 → 截圖確認日期正確顯示

## 測試策略

### PHPUnit Integration Test (`tests/Integration/Course/CourseScheduleNullableTest.php`)

**測試模式**：仿照 `CourseTrialVideosTest.php` 的 REST request driven pattern。

```php
// 結構大綱（不寫實作 code）
class CourseScheduleNullableTest extends TestCase {
    private CourseApi $api;
    
    protected function configure_dependencies(): void {
        $this->api = CourseApi::instance();
    }
    
    /**
     * @test
     * @group course
     * @group issue-222
     * @group happy
     */
    public function test_meta不存在時API回傳null(): void { ... }
    
    /**
     * @test
     * @group edge
     */
    public function test_meta為空字串時API回傳null(): void { ... }
    
    /**
     * @test
     * @group edge
     */
    public function test_meta為字串0時API回傳null(): void { ... }
    
    /**
     * @test
     * @group edge
     */
    public function test_meta為數字0時API回傳null(): void { ... }
    
    /**
     * @test
     * @group happy
     */
    public function test_meta為合法timestamp時API回傳對應integer(): void { ... }
}
```

**驗證點**：每個 test 都呼叫 `$this->api->format_course_base_records($product)` 並斷言 `$formatted['course_schedule']` 的型別（`null` vs `int`）與值。

### E2E (`tests/e2e/01-admin/course-edit-empty-fields.spec.ts`)

**新增 describe block**：

```ts
test.describe('Issue #222 - DatePicker 不顯示 Invalid date', () => {
    test.describe.configure({ mode: 'serial', timeout: 90_000 })
    test.use({ storageState: '.auth/admin.json' })

    // Test A: 新建課程 course_schedule 未設定
    // Test B: 新建課程 date_created 預設值
    // Test C: course_schedule = 1735689600 正常顯示
    // Test D: postmeta course_schedule = '0' 髒資料 regression
})
```

**核心斷言（避免多語環境誤判）**：

```ts
// 1. input value 斷言為主
const dpInput = page.locator('.ant-form-item').filter({ hasText: /開課時間|Course start time/ }).locator('input').first()
await expect(dpInput).toHaveValue('')

// 2. 全頁不出現 "Invalid date" 文字（i18n-safe，因為 dayjs 在 invalid 時固定回英文 "Invalid Date"）
const pageText = await page.textContent('body')
expect(pageText ?? '').not.toContain('Invalid date')
expect(pageText ?? '').not.toContain('Invalid Date')

// 3. console 不噴 warning
const consoleErrors: string[] = []
page.on('console', (msg) => msg.type() === 'error' && consoleErrors.push(msg.text()))
// ... 最後斷言 consoleErrors 為空
```

**髒資料 Test D 準備**：透過 `api.wcUpdate(`products/${id}`, { meta_data: [{ key: 'course_schedule', value: '0' }] })` 或直接 SQL（透過 helper）寫入髒資料。

### Regression 守衛（不新增 spec，靠既有 spec 兜底）

- `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` 既有 Issue #203 三個 test 必須繼續綠燈
- `tests/e2e/01-admin/bundle-product.spec.ts` 既有 smoke 必須繼續綠燈（共用 utility 修改 regression）
- `tests/e2e/01-admin/course-edit.spec.ts` 必須繼續綠燈

### 不寫的測試（顯式 defer）

- **JS unit test for `parseDatePickerValue`**：本專案無 vitest/jest 基礎建設，建立成本 >> 收益；行為由 E2E + 後端 PHPUnit + 在 `.feature` 規格中標註行為矩陣三層守住
- **AnnouncementForm / StudentTable DatePicker 的 E2E**：Out of Scope，本次只驗證 utility 修好不會壞 Bundle Edit；其他頁面的補測由日後觸發再開
- **DB migration test**：Q6-A 已決定不做 migration

## 涉及的現有程式碼

| 檔案 | 角色 | 行號參考 |
| --- | --- | --- |
| `inc/classes/Api/Course.php` | `format_course_base_records` 處理 `course_schedule` 序列化 | L471-474（Issue #203 已修部分） |
| `js/src/utils/functions/dayjs.ts` | `parseDatePickerValue` — 主修點 | L107-128 |
| `js/src/components/formItem/DatePicker/index.tsx` | DatePicker formItem — 不改本體，僅驗證 `getValueProps` 收 `undefined` 表現 | L19-39 |
| `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx` | 使用 `<DatePicker name=['course_schedule'] />` | L110-116 |
| `js/src/pages/admin/Courses/Edit/tabs/CourseOther/index.tsx` | 使用 `<DatePicker name=['date_created'] />` | L421-431 |
| `tests/Integration/Course/CourseTrialVideosTest.php` | PHPUnit pattern 參考（REST request driven） | 全檔 |
| `tests/e2e/01-admin/course-edit-empty-fields.spec.ts` | E2E 擴充標的 | L19-236 |

## Out of Scope（本次不做）

- **DB migration / 一鍵清理工具**：runtime 雙保險足矣（Q6-A）
- **Bundle 編輯、Chapter 編輯、Announcement、StudentTable 的 DatePicker**：未回報相同 bug，且共用 utility 修好已自動受益，無需特別動其元件
- **`formatDatePickerValue` 反向防禦**：format 端目前 `value instanceof dayjs` 守衛已足
- **其他 timestamp meta 欄位的同類重構**（`date_on_sale_from/to`、subscription、chapter 開放時間）：避免擴大改動範圍；本次僅 `course_schedule` 走新 helper，helper 留作 future use
- **`format_course_details` 改名**：spec 寫的 `format_course_details` 實為 `format_course_base_records`（命名差異）；本次不改名以免破壞既有呼叫
- **i18n 字串更動**：「Select date」已存在，本次無新增/修改字串
- **JS unit test 基礎建設**：建立 vitest 成本 >> 本次收益，由 E2E + PHPUnit + 規格三層守住

## 交接給 tdd-coordinator

本計劃可直接由 `@zenbu-powers:tdd-coordinator` 執行：

1. **Red Phase 派發**：`@zenbu-powers:test-creator` 建立 PHPUnit (`CourseScheduleNullableTest.php`) + 擴充 E2E spec
2. **Green Phase 派發**：
   - `@zenbu-powers:wordpress-master` 修 `inc/classes/Api/Course.php`（新增 helper + 改 L471-474）
   - `@zenbu-powers:react-master` 修 `js/src/utils/functions/dayjs.ts::parseDatePickerValue`
3. **Refactor Phase**：跑 `pnpm run lint:php` / `pnpm run lint:ts` / `pnpm run format` / `pnpm run build` / `composer run test` / `pnpm run test:e2e:admin --grep "Issue #222|Issue #203"`
4. **Reviewer**：`*-reviewer` 為 opt-in，本次不主動派發；若用戶顯式喚醒則上場
5. **doc-updater**：本計劃落地後，doc-updater 應同步檢查 `.claude/rules/react.rule.md` 與 `wordpress.rule.md` 是否需補「DatePicker null-safety」段落（非必須）

## 自我檢查 Red Flags

| 警示訊號 | 自檢結果 |
| --- | --- |
| 跳過步驟 0-4？ | 否，已 Read `CLAUDE.md` / 兩條 rule / spec / feature / open-issue / 現有 PHPUnit / E2E pattern |
| 使用者沒提卻自行擴張？ | 否，所有 Out of Scope 都顯式列出 |
| 測試策略丟給下游？ | 否，PHPUnit + E2E 已具體到 case + 斷言 |
| 範圍模式漂移？ | 否，HOLD SCOPE 維持；4 個生產檔案在預估內 |
| 風險先 defer 沒寫？ | 否，所有風險條列於上方「已知風險」與「錯誤處理登記表」 |
| Refactor 階段是否自動派 reviewer？ | 否，依規則 `*-reviewer` opt-in |
| i18n 漏掉？ | 否，已確認本次無字串變動 |
| PHPStan level 9 / PHPCS 風險？ | helper 簽名 `?int` 與 strict_types 相容；用 `(string)` cast 後判斷避開型別陷阱 |
