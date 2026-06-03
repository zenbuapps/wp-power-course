# Issue #237 — 課程虛擬商品 Switch 實作計畫

> 對應 Issue：[#237 課程儲存時，會強制將課程商品設定為"虛擬商品"](https://github.com/j7-dev/wp-power-course/issues/237)
> 對應規格：
> - `specs/features/course/設定課程虛擬商品狀態.feature`（核心 BDD）
> - `specs/features/course/建立課程.feature` (Rule: virtual 預設規則)
> - `specs/features/course/更新課程.feature` (Rule: virtual 由 payload 決定)
> - `specs/ui/課程商品設定頁.md`（UI 落腳處）
>
> Clarifier 釐清結果：`A A A B A A`
>
> | Q | 決議 |
> | --- | --- |
> | Q1 | Switch 放在 **CoursePrice tab** |
> | Q2 | 建立新課程時預設 **ON（虛擬商品）** |
> | Q3 | **不執行 migration**，既有 DB 維持 `yes` |
> | Q4 | Switch 旁顯示 **Tooltip 文字提示**（不跳 Modal） |
> | Q5 | **僅切 `_virtual`**，不聯動 downloadable / manage_stock / shipping |
> | Q6 | Label `Virtual product` / Tooltip `Virtual products are intangible and do not require shipping.`（沿用 WC 原生措辭） |

---

## 1. 範圍模式判定

**HOLD SCOPE（維持）**

- 本案是局部行為調整（單一硬寫 + 加 Switch UI），不是 greenfield 也非大型重構
- 預估影響檔案數 ≤ 8 個，遠低於 15
- 規格邊界由 clarifier 完整鎖定（6 題澄清），無模糊空間
- 防彈點：向下相容（既有合約不變）、不聯動其他屬性、不執行 migration

---

## 2. 需求重述（一行）

讓「課程是否為虛擬商品」由 Admin 在 CoursePrice tab 透過 Switch 控制（預設 ON），後端不再強制覆寫 `_virtual=true`，request 未送 `virtual` 時保持原狀（向下相容），切換 `virtual` 不聯動其他欄位。

---

## 3. 風險評估

| 風險 | 影響 | 緩解 |
| --- | --- | --- |
| **既有合約變更**：GET response `virtual` 目前是 `bool`，TS 型別 `boolean`。若改成 `'yes' \| 'no'` 會 break TS 編譯與其他消費者 | 中（編譯失敗、列表/卡片可能誤判） | 採用 **方案 B**（改為 `wc_bool_to_string` 回 string）並同步更新 TS 型別 + 全 codebase 搜尋既有 `record.virtual === true` 寫法逐一改為 `=== 'yes'`。詳見 §5.1 |
| Refine `useForm` initial value 來自 GET，若 GET 仍回 bool 而 FiSwitch 期望 `'yes'`，初始狀態會永遠 OFF | 高（UI bug） | §5.1 方案 B 連動處理；若選方案 A 需在 `parseData`/`getValueProps` 客製轉換 |
| `set_virtual(string)` vs `set_virtual(bool)` 行為差異 | 低 | WC `set_virtual()` 內部會 `wc_string_to_bool()`，`'yes'`/`'no'`/`true`/`false` 皆 OK |
| `make-pot` 抓不到新字串導致繁中無翻譯 | 中 | §5.4 強制執行 `pnpm run i18n:build` + 更新 `scripts/i18n-translations/manual.json` |
| 移除 `$data['virtual'] = true` 後，**未送 `virtual` key 的舊前端版本**會踩到 WC `set_virtual` 預設行為 | 低 | 由於 separator/handle_save_course_data 僅遍歷 `$data` 內的 key 呼叫對應 setter，未送 key 時 `set_virtual` 不會被呼叫，product `_virtual` meta 保持原值。Issue #203 的「未送 key = 保持原狀」契約已驗證此行為 |
| 整合測試 (`CourseCRUDTest`) 內未有 virtual 相關 case | 低 | §6 新增 4 個 PHPUnit case |
| E2E `course-edit.spec.ts` 未驗證 Switch | 低 | §6 在現有 `課程訂價 Tab` test 補一個 sub-test 或新增 case |

---

## 4. 缺口審視（GAP / ASM）

- **GAP-1**：BDD 規格 `設定課程虛擬商品狀態.feature` 行 119 寫 `回應資料的 virtual 欄位應為 "yes"`，但目前後端回 `bool`。
  → 解法：採方案 B（後端統一回 `'yes'`/`'no'`），詳見 §5.1。
- **GAP-2**：規格未說明 list endpoint (`GET /courses`) 是否也回 `virtual`。
  → 解法：`get_courses_callback` 共用 `format_meta_record` 路徑，方案 B 同步生效，無需額外處理。確認步驟列在 §5.1 step C。
- **ASM-1**：既有 `record.virtual` 在前端僅用於 Refine `useForm` 初始化，**未被其他 UI 邏輯消費**。
  → 驗證步驟：`rg "\.virtual" js/src` 一次掃完，列入 §5.2 必做檢查。
- **ASM-2**：MCP tools 內若有 course 相關 tool 暴露 `virtual`，型別變動可能影響 schema。
  → 驗證步驟：`rg "'virtual'" inc/classes/Api/Mcp/Tools/Course` 一次掃完，列入 §5.1 step C。

---

## 5. 實作步驟（分檔案、按依賴順序）

### 5.1 後端 — `inc/classes/Api/Course.php`

#### Step A：移除 virtual 硬寫（核心修正）

**檔案**：`inc/classes/Api/Course.php`
**位置**：`handle_save_course_data()` 第 612-642 行

**修改**：

```diff
 private function handle_save_course_data( \WC_Product $product, array $data ): void {
-		$data['virtual'] = true; // 課程固定為虛擬商品
+		// Issue #237: virtual 由 request payload 決定，不再強制覆寫。
+		// 若 request 未送 'virtual' key，本迴圈不會呼叫 set_virtual()，
+		// product `_virtual` meta 保持原值（向下相容既有合約）。

 		// Issue #203: date_on_sale 單側清空時，強制兩側同步清空
```

**驗收（BDD 對應）**：

- ✅ `Rule: 後置（狀態）- 課程儲存時不再強制覆寫 virtual（移除 hardcode）`
- ✅ `Example: 將虛擬課程改為實體課程，儲存後 DB 確實為 no`
- ✅ `Example: 將實體課程改為虛擬課程，儲存後 DB 確實為 yes`

#### Step B：GET response 統一回 `'yes'`/`'no'`

**檔案**：`inc/classes/Api/Course.php`
**位置**：`format_meta_record()`（讀單一 product → array）第 422 行

**修改**：

```diff
-			'virtual'            => $product->get_virtual(),
+			'virtual'            => \wc_bool_to_string( $product->get_virtual() ),
```

**理由**：

- 與相鄰欄位 `manage_stock` (442)、`backorders_allowed` (445)、`backordered` (446)、`sold_individually` (448) 一致使用 `wc_bool_to_string`
- 對齊 `_virtual` post_meta 在 DB 的儲存格式（`'yes'`/`'no'`）
- 讓 FiSwitch 的 `getValueProps={(value) => (value === 'yes' ? { checked: true } : {})}` 直接生效，不需客製轉換
- 對齊 BDD `回應資料的 virtual 欄位應為 "yes"`

**驗收（BDD 對應）**：

- ✅ `Rule: 後置（狀態）- GET 課程詳情回應內含 virtual 欄位（既有合約不變）`

#### Step C：掃描既有 `virtual` 消費者

跑以下指令並逐一確認：

```bash
rg "->virtual\b|\['virtual'\]|\"virtual\":" inc/classes/Api/Mcp/Tools/Course
rg "->virtual\b|\['virtual'\]|\"virtual\":" inc/classes/Resources/Course
rg "'virtual'\s*=>" inc/classes/Api/Course.php
```

若有 MCP tool / Resource 內也用 `(bool) $virtual` 或直接 truthy 判斷，需改為 `=== 'yes'`。

---

### 5.2 前端型別更新 — `js/src/pages/admin/Courses/List/types/index.ts`

**檔案**：`js/src/pages/admin/Courses/List/types/index.ts`
**位置**：第 26 行

**修改**：

```diff
-	virtual: boolean
+	virtual: 'yes' | 'no'
```

**配套掃描**：

```bash
rg "\.virtual\b" js/src --type ts --type tsx
```

對每處消費點：

- `record.virtual === true` → `record.virtual === 'yes'`
- `record.virtual ? ... : ...` → `record.virtual === 'yes' ? ... : ...`

**預期消費點**：根據既有 codebase 觀察，`virtual` 主要由 Refine `useForm` 取回後直接灌入 form，list table 未顯示此欄位。掃描結果若有額外消費者一律改寫。

---

### 5.3 前端 UI — 新增 Switch 到 CoursePrice tab

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx`

**修改重點**：

1. import `FiSwitch`（同檔已 import 路徑：`@/components/formItem`）
2. import `Tooltip` from `'antd'`、`QuestionCircleOutlined` from `'@ant-design/icons'`（或用既有的 icon）
3. 在「商品種類 Select」下方、`<StockFields />` 上方插入 Switch，**外部課程隱藏**（與 `is_free` / `hide_single_course` 一致的條件）

**插入位置**（在第 64 行 `</Item>` 與第 66 行 `{isSubscription && ...}` 之間，或更精準地放在 `<StockFields />` 上方）：

```tsx
{!isExternal && (
  <FiSwitch
    formItemProps={{
      name: ['virtual'],
      label: (
        <span>
          {__('Virtual product', 'power-course')}
          &nbsp;
          <Tooltip
            title={__(
              'Virtual products are intangible and do not require shipping.',
              'power-course'
            )}
          >
            <QuestionCircleOutlined />
          </Tooltip>
        </span>
      ),
      initialValue: 'yes', // 新建課程預設 ON（虛擬商品）
    }}
  />
)}
```

**注意事項**：

- `FiSwitch` 內部 `initialValue={false}`，但 spread `{...formItemProps}` 在後，會被外層覆蓋
- `initialValue: 'yes'` 配合 `getValueProps`（`value === 'yes' ? { checked: true } : {}`）讓新建模式預設 ON
- 編輯模式下 Refine `useForm` 會從 GET response 灌入 `virtual: 'yes' | 'no'`，覆蓋 `initialValue`
- 切換時 `normalize` 會把 `boolean` 轉回 `'yes' | 'no'` 送到後端

**驗收（BDD 對應）**：

- ✅ `Rule: UI - 「Virtual product」Switch 顯示於 CoursePrice tab`
- ✅ `Rule: UI - 建立新課程時，Switch 預設為 ON（虛擬商品）`
- ✅ `Rule: UI - 切換 Switch 後，儲存時隨 update payload 一併送出`

---

### 5.4 i18n — 新增繁中翻譯到對照表

**檔案**：`scripts/i18n-translations/manual.json`

**新增條目**（**絕對禁止手改 `.po`**，pipeline 會覆寫）：

```json
[
  {
    "msgid": "Virtual product",
    "msgstr_zh_TW": "虛擬商品",
    "context": "js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx — Issue #237"
  },
  {
    "msgid": "Virtual products are intangible and do not require shipping.",
    "msgstr_zh_TW": "虛擬商品為無形商品，不需要設定運送資訊。",
    "context": "js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx — Issue #237 Tooltip"
  }
]
```

**執行**：

```bash
pnpm run i18n:build   # pot → merge → mo → json 一次串完
```

**驗收**：

- ✅ `git diff languages/` 必須看到 4 個檔案（`.pot` / `.po` / `.mo` / `.json`）有 `Virtual product` 與 Tooltip 文案的變動
- ✅ `[build-zhtw-po] 未翻譯` 數量不上升

> ⚠️ **新詞彙術語表更新**：依 `.claude/rules/i18n.rule.md` 規範，若 `Virtual product` 尚未在術語表內，PR 內需同步更新術語表（加上 `虛擬商品 → Virtual product`）。

---

### 5.5 （可選）術語表補充 — `.claude/rules/i18n.rule.md`

若 `Virtual product` / `虛擬商品` 不在現有術語表內，補一行到「術語表（Glossary）」表格：

```
| 虛擬商品 | Virtual product |
```

---

## 6. 測試策略

### 6.1 PHP Integration Tests（PHPUnit）

**檔案**：`tests/Integration/Course/CourseVirtualTest.php`（**新建**）

> 為何不加進 `CourseCRUDTest`：CRUD 測試已 343 行且圍繞 LifeCycle，virtual 主題集中在獨立檔案更易維護，符合既有 `CourseScheduleNullableTest`（Issue #222）、`CourseTrialVideosTest`（Issue #10）的命名慣例。

**Test 1**：`test_儲存課程時不再強制覆寫virtual為true`

- Given：建立 `_virtual='no'` 的課程
- When：呼叫 `Course::handle_save_course_data($product, ['name' => '新名稱'])`（未送 `virtual`）
- Then：`get_post_meta($id, '_virtual', true) === 'no'`

**Test 2**：`test_儲存課程時virtual_false能正確寫入DB`

- When：呼叫 `handle_save_course_data($product, ['virtual' => false])`
- Then：`get_post_meta($id, '_virtual', true) === 'no'`

**Test 3**：`test_儲存課程時virtual_true能正確寫入DB`

- Given：建立 `_virtual='no'` 的課程
- When：呼叫 `handle_save_course_data($product, ['virtual' => true])`
- Then：`get_post_meta($id, '_virtual', true) === 'yes'`

**Test 4**：`test_GET課程詳情回應virtual欄位為yes或no字串`

- Given：建立 `_virtual='yes'` 的課程
- When：呼叫 `Course::format_meta_record($product)`
- Then：`assertSame('yes', $result['virtual'])`
- 再 Given `_virtual='no'`：`assertSame('no', $result['virtual'])`

**Test 5（邊界）**：`test_儲存課程時virtual接受字串等價值`

- Scenario Outline 對應規格 Examples：
  - `'true'` → `'yes'`
  - `'false'` → `'no'`
  - `'yes'` → `'yes'`
  - `'no'` → `'no'`

> WC `set_virtual()` 內部以 `wc_string_to_bool()` 處理，所有字串等價值都應正常 round-trip。

**測試指令**：

```bash
composer run test -- --filter CourseVirtualTest
```

### 6.2 E2E Tests（Playwright）

**檔案**：`tests/e2e/01-admin/course-edit.spec.ts`（**擴充既有檔**）

**新增測試**（加在現有 `課程訂價 Tab` 區塊內）：

```ts
test('課程訂價 Tab 顯示虛擬商品 Switch（Issue #237）', async ({ page }) => {
  await navigateToAdmin(page, `/courses/edit/${courseId}`)
  await waitForFormLoaded(page)
  await clickTab(page, '課程訂價')

  // Switch 應該存在
  const virtualSwitch = page.locator('[data-test-id="virtual-switch"]')  // 或用 form name selector
  await expect(virtualSwitch).toBeVisible()

  // 預設 ON（新建課程 createCourse helper 走後端 API，DB 此時是 yes）
  await expect(virtualSwitch).toHaveAttribute('aria-checked', 'true')
})

test('切換虛擬商品 Switch 後儲存並 reload，狀態保留（Issue #237）', async ({ page }) => {
  await navigateToAdmin(page, `/courses/edit/${courseId}`)
  await waitForFormLoaded(page)
  await clickTab(page, '課程訂價')

  // 切到 OFF
  await page.locator('[data-test-id="virtual-switch"]').click()

  // 儲存
  await page.getByRole('button', { name: '儲存' }).click()
  await expect(page.locator('.ant-message-success')).toBeVisible()

  // Reload
  await page.reload()
  await waitForFormLoaded(page)
  await clickTab(page, '課程訂價')

  // Switch 應為 OFF
  await expect(page.locator('[data-test-id="virtual-switch"]')).toHaveAttribute('aria-checked', 'false')
})
```

> **selector 策略**：FiSwitch 沒有預設 `data-test-id`。實作時建議：
> (1) 直接用 `page.locator('button[role="switch"]').nth(N)`（風險：tab 內可能有多個 Switch）
> (2) 或在 FiSwitch 元件加 `data-test-id` prop 支援。建議走 (2)，並補成獨立小 PR；本案先用 ant-form `[name="virtual"]` 父層加 `button[role="switch"]` 鎖定。

**測試指令**：

```bash
pnpm run test:e2e:admin -- course-edit.spec.ts
```

### 6.3 不需新增的測試

- ❌ 不需 unit test（前端無 unit test 基礎建設，依賴 ESLint + TS strict + E2E）
- ❌ 不需 migration test（規格明定不做 migration）
- ❌ 不需 frontend 前台測試（virtual 是後台屬性，不影響前台渲染）

---

## 7. 實作順序（含依賴關係）

```
Phase A: 後端（無依賴）
  └─ 5.1 Step A: 移除 $data['virtual'] = true
  └─ 5.1 Step B: GET 改 wc_bool_to_string
  └─ 5.1 Step C: 掃 MCP / Resource 消費者
  └─ 6.1: PHPUnit Test 1-5 (TDD Red → Green)

Phase B: 前端型別（依賴 Phase A 完成）
  └─ 5.2: TS type virtual: boolean → 'yes' | 'no'
  └─ 5.2: 配套修正所有 `record.virtual` 消費點
  └─ TypeScript 編譯通過

Phase C: 前端 UI（依賴 Phase B）
  └─ 5.3: CoursePrice tab 加 FiSwitch + Tooltip
  └─ 5.4: manual.json 補繁中翻譯
  └─ pnpm run i18n:build
  └─ 6.2: E2E test 撰寫與通過

Phase D: 品質檢查
  └─ pnpm run lint:php
  └─ pnpm run lint:ts
  └─ composer run phpstan
  └─ pnpm run i18n:build (確認無新增未翻譯)
  └─ pnpm run build (產出 bundle 確認無 TS 編譯錯誤)
```

**TDD 對應**（交給 `@zenbu-powers:tdd-coordinator`）：

- **Red**：6.1 五個 PHPUnit case 全部撰寫並失敗（virtual=true hardcode 還在時，Test 1/2 會 fail）
- **Green**：5.1 Step A/B 修正後 PHPUnit 全綠
- **Refactor**：5.2/5.3/5.4 前端與 i18n 補齊，6.2 E2E 補齊

---

## 8. 資料流分析

```
[Admin 在 CoursePrice tab 切換 Switch]
        │
        ▼
[FiSwitch normalize: bool → 'yes'/'no']
        │
        ▼
[Refine useForm collect values]
        │
        ▼
[handleOnFinish (index.tsx) → onFinish]
        │
        │ request body 含 virtual: 'yes' | 'no'
        ▼
[POST/PATCH /power-course/v2/courses/{id}]
        │
        ▼
[Course::post_courses_with_id_callback → separator → handle_save_course_data]
        │
        │ $data['virtual'] = 'yes' | 'no' （或不存在）
        │ (Issue #237) 不再被強制覆寫為 true
        ▼
[foreach loop → $product->set_virtual($value)]
        │
        │ WC: wc_string_to_bool('yes') → true → update_post_meta('_virtual', 'yes')
        ▼
[DB: wp_postmeta._virtual = 'yes' | 'no']
        │
        ▼
[GET /power-course/v2/courses/{id}]
        │
        ▼
[Course::format_meta_record]
        │
        │ wc_bool_to_string($product->get_virtual()) → 'yes' | 'no'
        ▼
[Response JSON: { virtual: 'yes' | 'no', ... }]
        │
        ▼
[Refine useForm initial values → FiSwitch getValueProps → checked: true | false]
```

---

## 9. 錯誤處理登記表

| 錯誤情境 | 來源 | 處理方式 |
| --- | --- | --- |
| `virtual` 欄位值為非預期型別（如 `null`） | request payload | WC `set_virtual()` 內部 `wc_string_to_bool(null)` 回 `false` → 寫入 `'no'`。不額外驗證 |
| `virtual` 欄位完全未送 | request payload | `$data` 不含此 key → foreach 不呼叫 `set_virtual` → DB 保持原值（既有合約 #203 已驗證） |
| GET 時 `_virtual` meta 不存在（極端：手工建立的舊 product） | DB | WC `get_virtual()` 回 `false` → `wc_bool_to_string(false)` 回 `'no'` → FiSwitch 顯示 OFF。符合 WC 預設行為 |
| 切到 OFF 後 WC 顯示運送 tab | 後台 Classic Editor | 規格已說明（Q5），不在本案範圍內，由 Admin 自行決定。Tooltip 文字已提示 |

---

## 10. 自我檢查（對照 `/zenbu-powers:plan` 警示訊號）

- [x] 已讀完 `.claude/CLAUDE.md` / `.claude/rules/wordpress.rule.md` / `.claude/rules/react.rule.md` / `.claude/rules/i18n.rule.md` / `.claude/rules/e2e-testing.rule.md`
- [x] 已讀完 `specs/features/course/設定課程虛擬商品狀態.feature` / `建立課程.feature` / `更新課程.feature` 及 `specs/ui/課程商品設定頁.md`
- [x] 已實際讀過 `inc/classes/Api/Course.php`（402-642 行）、`CoursePrice/index.tsx`、`FiSwitch/index.tsx`、`Edit/index.tsx`、`TCourseBaseRecord` 型別
- [x] 範圍模式：HOLD SCOPE（影響 ≤ 8 檔，無漂移風險）
- [x] 資料流圖完成（§8）
- [x] 錯誤處理登記表完成（§9）
- [x] 測試策略明確（5 個 PHPUnit + 2 個 E2E）
- [x] 所有 `ASM:` / `GAP:` 已在 §4 顯式列出並提供驗證/解法
- [x] 範圍未漂移（仍是「加 Switch + 移硬寫」原始任務）
- [x] i18n 流程明確（manual.json + i18n:build，禁手改 .po）
- [x] 預估影響：6 個檔案修改 + 2 個檔案新建 = 8 個 ✅ 在 HOLD SCOPE 範圍內

---

## 11. 交付清單（給 tdd-coordinator）

### 修改檔案（6）

1. `inc/classes/Api/Course.php`（移硬寫 + GET 改 `wc_bool_to_string`）
2. `js/src/pages/admin/Courses/List/types/index.ts`（`virtual: boolean` → `'yes' | 'no'`）
3. `js/src/pages/admin/Courses/Edit/tabs/CoursePrice/index.tsx`（加 FiSwitch + Tooltip）
4. `scripts/i18n-translations/manual.json`（兩條繁中翻譯）
5. `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json`（由 `pnpm run i18n:build` 產出）
6. `tests/e2e/01-admin/course-edit.spec.ts`（補 2 個 test case）
7. （可選）`.claude/rules/i18n.rule.md`（術語表補一行）

### 新建檔案（1）

1. `tests/Integration/Course/CourseVirtualTest.php`（5 個 PHPUnit case）

### 驗收指令

```bash
# Backend
composer run test -- --filter CourseVirtualTest
pnpm run lint:php
composer run phpstan

# Frontend
pnpm run lint:ts
pnpm run build

# i18n
pnpm run i18n:build
git diff languages/   # 應看到 4 個檔案有 Virtual product 相關 diff

# E2E
pnpm run test:e2e:admin -- course-edit.spec.ts
```

### 驗收標準

- ✅ 所有 PHPUnit case 通過
- ✅ ESLint / Prettier / TS / PHPStan / PHPCS 全綠
- ✅ E2E 兩個新 case 通過（含 reload 後 Switch 狀態保留）
- ✅ `git diff languages/` 顯示繁中翻譯已正確產出
- ✅ 手動瀏覽器驗證（playwright-cli 走 `https://local-turbo.powerhouse.tw/wp-admin`）：
  - (a) 開既有課程編輯頁 → CoursePrice tab → Switch 顯示 ON
  - (b) Hover Tooltip 顯示「虛擬商品為無形商品，不需要設定運送資訊。」
  - (c) 切到 OFF → 儲存 → reload → Switch 仍為 OFF
  - (d) 新建課程表單 → CoursePrice tab → Switch 預設 ON

---

## 12. 不在本案範圍

- ❌ 既有資料 migration（Q3 決議：不做）
- ❌ 切到 OFF 時跳 Confirm Modal（Q4 決議：Tooltip 即可）
- ❌ `virtual=false` 時自動聯動 `downloadable=false` 等其他欄位（Q5 決議：單一職責）
- ❌ MCP tool 新增 `update_course_virtual` 之類獨立 tool（未在 Issue 範圍）
- ❌ Classic Product Editor 端的行為調整（用戶若進 WC 原生編輯器仍可手動切，本案僅處理 Refine Admin SPA）
