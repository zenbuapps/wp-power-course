# Issue #190 — 課程內頁「新增學員」優化：以 UserTable 取代 UserSelector

> 狀態：**Discovery Clarify Complete（Phase 01 完成，待落地 Phase 05 實作）**
> Milestone：v1.3
> 澄清紀錄：[`specs/clarify/2026-04-21-1747-issue190.md`](../clarify/2026-04-21-1747-issue190.md)
> 相關 UI 規格：[`specs/ui/課程學員管理-用戶挑選彈窗.md`](../ui/課程學員管理-用戶挑選彈窗.md)
> 相關 Feature 規格：[`specs/features/student/透過彈窗挑選用戶加入課程.feature`](../features/student/透過彈窗挑選用戶加入課程.feature)

---

## 1. 目標

把課程編輯頁「課程學員」分頁的「新增學員」UI（現為單一下拉 Select）**升級為完整的 UserTable**，共用 `/admin/students` 頁面的篩選、勾選、分頁能力，但**後端零改動**。

## 2. 核心決策（Non-Negotiable）

### 2.1 後端零改動
- **不新增** REST API，**不擴充** `users` resource 的 filter 語意。
- 排除「已在本課程的學員」的語意完全**沿用既有 `students` resource 的能力**：`meta_key=avl_course_ids` + `meta_value ne <courseId>`。此能力來自既有 `UserSelector/index.tsx` L:49-58。
- 批次加入學員 API **沿用** `POST {apiUrl}/courses/add-students`，`payload = { user_ids: string[], course_ids: [courseId], expire_date?: number }`。

### 2.2 前端以「模式切換」方式改造 UserTable
現行 `@/components/user/UserTable` 硬編碼 `resource: 'users'`、permanent filter = `meta_keys: ['is_teacher', 'avl_courses']`。本次改造新增 `mode` prop：

```ts
type UserTableMode = 'global' | 'course-exclude'

type UserTableProps = {
  mode?: UserTableMode          // 預設 'global'
  canGrantCourseAccess?: boolean
  tableProps?: TableProps<TUserRecord>
  cardProps?: CardProps & { showCard?: boolean }
}
```

| mode 值 | resource | dataProviderName | permanent filter（完全覆寫） | 使用場景 |
|---------|----------|------------------|----------------------------|---------|
| `'global'`（預設） | `'users'` | Refine 預設 | `[{ field: 'meta_keys', operator: 'eq', value: ['is_teacher', 'avl_courses'] }]` | `/admin/students` 全局學員管理頁 |
| `'course-exclude'` | `'students'` | `'power-course'` | 見下方 | 課程編輯頁「新增學員」區塊 |

**`course-exclude` 模式的 permanent filter**（`courseId` 來自 `useParsed()`）：

```ts
[
  { field: 'meta_key',   operator: 'eq', value: 'avl_course_ids' },
  { field: 'meta_value', operator: 'ne', value: courseId },
  { field: 'meta_keys',  operator: 'eq', value: ['formatted_name'] },
]
```

> 注意：`mode='course-exclude'` 時 UserTable 內部必須 **guard** `useParsed().id` 存在才啟用 query（`queryOptions.enabled = !!courseId`）。

### 2.3 子元件取捨（`course-exclude` 模式的 UI 行為）

| 元素 | global 模式 | course-exclude 模式 |
|------|-------------|---------------------|
| `Filter`（Card，含 search / avl_course_ids / include 三欄位） | 顯示 | **完整顯示三欄位** |
| `FilterTags` | 顯示 | 顯示 |
| `rowSelection`（勾選） | 顯示 | 顯示 |
| Table columns（`useColumns`） | 沿用 | 沿用（既有 `useColumns` 已透過 `useParsed()` 支援 `currentCourseId` 切換 granted courses 顯示） |
| 分頁（`pageSize: 20`） | 顯示 | 顯示（與全局一致） |
| `SelectedUser` 已選清單 | 顯示 | **顯示**（改由 UserTable 內部自行 render） |
| `GrantCourseAccess` 按鈕 | 當 `canGrantCourseAccess=true` 才顯示 | **隱藏** |
| 批次「更新觀看到期日」DatePicker + 按鈕 | 當 `canGrantCourseAccess=true` 才顯示 | **隱藏** |
| 批次「移除學員」`RemoveCourseAccess` | 當 `canGrantCourseAccess=true` 才顯示 | **隱藏** |
| CSV 匯出按鈕 + CSV 上傳 Modal | 當 `canGrantCourseAccess=true` 才顯示 | **隱藏** |
| `HistoryDrawer` | 顯示 | **隱藏** |
| **新增**：「加入本課程」按鈕 + DatePicker（到期日） | ─ | **顯示** |

> ⚠️「加入本課程」按鈕與 DatePicker 由 **UserTable 內部**根據 `mode === 'course-exclude'` render，**不透過 `actions` slot prop**（封裝最乾淨）。

### 2.4 加入學員流程（course-exclude 模式專屬）

**UI 行為**：
1. 勾選若干 rows（使用 UserTable 既有的 `rowSelection` + `selectedUserIdsAtom`）。
2. （可選）在 DatePicker 指定到期日；不選代表永久（`expire_date = 0`）。
3. 點「Add N students to this course」按鈕（按鈕 label 動態顯示目前勾選人數）。
4. 呼叫 `POST {apiUrl}/courses/add-students`，payload：
   ```json
   {
     "user_ids": ["<勾選的 user ids>"],
     "course_ids": ["<當前 courseId>"],
     "expire_date": 0 | <DatePicker 選擇值的 unix timestamp>
   }
   ```
5. 成功：
   - 顯示 message.success（i18n：`"Students added successfully"`）
   - `invalidate({ resource: 'students', dataProviderName: 'power-course', invalidates: ['list'] })`
   - reset `selectedUserIdsAtom` 為 `[]`
   - 清空 DatePicker 值
   - **副作用（自動發生）**：上方 UserTable（course-exclude）與下方 StudentTable 同時 refetch，剛加入的人從上方消失、出現在下方
6. 失敗：
   - 顯示 message.error（i18n：`"Failed to add students to course"`）
   - 不 reset 勾選狀態（讓使用者重試）
   - 不清空 DatePicker

### 2.5 DatePicker 規格

- `placeholder`：i18n `"Leave empty for permanent access"`
- `showTime`：是（格式 `YYYY-MM-DD HH:mm`，與 `StudentTable` 批次到期日 DatePicker 一致）
- `disabledDate`：`(current) => current && current < dayjs().startOf('day')`（沿用 `js/src/components/emails/SendCondition/Specific.tsx` L:25-28 寫法）
- 送出值：有值時傳 `value.unix()`；未選時傳 `0`
- **不支援** `subscription_{id}` 格式（後端 API 支援、但此 UI 不提供該輸入）

### 2.6 `pages/admin/Courses/Edit/tabs/CourseStudents/` 目錄變更

**刪除**：
- `UserSelector/index.tsx`（整個 `UserSelector/` 目錄）

**修改**：`CourseStudents/index.tsx`
- 移除 `import UserSelector from './UserSelector'`
- 將 `<UserSelector />` 替換為 `<UserTable mode="course-exclude" />`
- Alert 提示文字可精簡：移除「up to 30 results per query」（已改為分頁 20 筆）。

**保留**：`StudentTable/index.tsx`（不動）

### 2.7 UserTable 實作影響範圍

檔案：`js/src/components/user/UserTable/index.tsx`
- 新增 `mode?: UserTableMode` prop
- 依 mode 切換 `resource` / `dataProviderName` / permanent filter
- 依 mode 條件式 render：
  - 加入按鈕 + DatePicker 區塊（只在 `course-exclude` 顯示）
  - `GrantCourseAccess` / 批次「更新觀看到期日」/ `RemoveCourseAccess` / CSV 匯出 / CSV 上傳 Modal / `HistoryDrawer`（只在 `canGrantCourseAccess` 且 `mode === 'global'` 時顯示）
- 型別：`import type { Dayjs } from 'dayjs'` 用於 DatePicker state
- i18n 全部走 `@wordpress/i18n` 的 `__` / `sprintf`，text domain **固定** `'power-course'`

## 3. 驗收標準

### 3.1 功能驗收
- [ ] `UserTable` 新增 `mode` prop，預設 `'global'`，現有 `/admin/students` 頁面行為完全不變（視覺、功能、分頁、篩選、匯出 CSV 全部維持）
- [ ] `UserTable mode='course-exclude'` 顯示於 `CourseStudents` 分頁上方，取代原 `UserSelector`
- [ ] 排除已加入學員：搜尋時不會出現已在本課程的人
- [ ] 勾選 N 人後，按鈕顯示「Add N students to this course」
- [ ] 未選 DatePicker 時送出 `expire_date = 0`
- [ ] 選擇未來日期時送出該日期的 `unix timestamp`
- [ ] `disabledDate` 正確禁用今天以前的日期
- [ ] 加入成功後：message 顯示、上下兩表同步 refetch、勾選狀態清空、DatePicker 清空
- [ ] 加入失敗後：message 顯示、勾選保留、DatePicker 保留
- [ ] `UserSelector/` 目錄已刪除，且無任何 import 殘留（跑 `pnpm run lint:ts` 通過）

### 3.2 i18n 驗收
- [ ] 所有新增 msgid 均 `__(..., 'power-course')`，text domain 為連字號 `'power-course'`
- [ ] 含變數字串用 `sprintf(__('... %s ...', 'power-course'), value)`，不用 template literal
- [ ] 跑 `pnpm run i18n:build` 後 `power-course-zh_TW.po` 含本 spec 第 4 節所有 msgid 的繁中譯文
- [ ] `scripts/i18n-translations/manual.json` 含本 spec 第 4 節的增量 JSON
- [ ] `.pot` / `.po` / `.mo` / `.json` 同 commit 一起進版

### 3.3 程式碼品質驗收
- [ ] `pnpm run lint:ts` 通過（無 `any` 型別、無 eslint error）
- [ ] `pnpm run format` 無改動（格式正確）
- [ ] `composer run phpstan`（level 9）通過——本次零後端改動，應無新增錯誤
- [ ] UserTable 不再有 `// @ts-nocheck` 等品質繞過標記（若原 UserSelector 有，跟著目錄一起刪除）

### 3.4 E2E 驗收（後續 Phase 06 補）
- [ ] Playwright 用 `tests/e2e/admin/` 加一條：打開課程編輯頁 → 搜尋學員 → 勾選 → 設定到期日 → 加入 → 驗證上下表同步
- [ ] Playwright 用 `tests/e2e/admin/` 加一條：DatePicker 為空時加入 → 驗證 `expire_date = 0`

## 4. i18n 新增 msgid 清單 + `manual.json` 增量 JSON

### 4.1 msgid 清單（所有字串以 `@wordpress/i18n` 的 `__` / `sprintf` 撰寫）

| 使用位置 | msgid（英文原文） | 用法 |
|---------|-------------------|------|
| 加入按鈕 label（動態人數） | `Add %s students to this course` | `sprintf(__('Add %s students to this course', 'power-course'), selectedRowKeys.length)` |
| 加入按鈕 disabled 狀態 label | `Add students to this course` | `__('Add students to this course', 'power-course')` |
| DatePicker placeholder | `Leave empty for permanent access` | `__('Leave empty for permanent access', 'power-course')` |
| DatePicker 旁的 hint 文字 | `Expire date (applied to all selected)` | `__('Expire date (applied to all selected)', 'power-course')` |
| 批次加入成功 message | `Students added successfully` | `__('Students added successfully', 'power-course')`（**既有字串**，無需新增） |
| 批次加入失敗 message | `Failed to add students to course` | `__('Failed to add students to course', 'power-course')` |

> **注意**：`Students added successfully` 與 `Failed to add students` 這兩個 msgid 在既有 `UserSelector` 已存在，pipeline 會重用既有翻譯。`Failed to add students to course` 是稍微修辭過的新 msgid，需加入 `manual.json`。

### 4.2 `scripts/i18n-translations/manual.json` 增量 JSON

```json
{
  "Add %s students to this course": "加入 %s 名學員到本課程",
  "Add students to this course": "加入學員到本課程",
  "Leave empty for permanent access": "留空代表永久開通",
  "Expire date (applied to all selected)": "到期日（套用至所有勾選）",
  "Failed to add students to course": "加入學員到本課程失敗"
}
```

> **操作步驟**（由 master agent 於 Phase 05 執行）：
> 1. 開啟 `scripts/i18n-translations/manual.json`
> 2. 將上方 5 條 key/value 併入既有 JSON（注意保持尾逗號合法性）
> 3. 跑 `pnpm run i18n:build` 自動更新 `.pot` / `.po` / `.mo` / `.json`
> 4. 用 `git diff languages/` 確認產物正確，一起 commit

## 5. 不在本次範圍內（Out of Scope）

- 訂閱綁定（`subscription_{id}`）的到期日 UI——維持現狀，若需要請走 WC 訂閱商品流程
- 逐用戶設定不同到期日——維持「批次統一」，需要個別調整請加入後再用 `StudentTable` 的批次更新到期日按鈕
- `search_field`（email / name / id 窄化搜尋欄位）——不新增，沿用 `UserTable` 既有 `search` contains 即可
- 任何後端 PHP 變更——本次零後端改動
- 任何新增後端 API / 擴充 `users` resource filter 語意——改走既有 `students` resource

## 6. 實作優先序（建議）

1. **Step A**：UserTable `mode` prop 骨架（先不動行為，僅型別擴充 + 預設值保證 global 模式零回歸）
2. **Step B**：permanent filter 依 mode 切換邏輯 + `useParsed()` 讀 courseId + `queryOptions.enabled` guard
3. **Step C**：`course-exclude` 模式的 UI 取捨（隱藏不該顯示的區塊）
4. **Step D**：加入按鈕 + DatePicker + `useCustomMutation` + i18n（含 `manual.json` 更新 + `pnpm run i18n:build`）
5. **Step E**：`CourseStudents/index.tsx` 改用 `<UserTable mode="course-exclude" />`；刪除 `UserSelector/` 目錄
6. **Step F**：`pnpm run lint:ts` / `pnpm run format` / `composer run phpstan` 全綠
7. **Step G**：Playwright E2E 補測（Phase 06）

## 7. 風險與注意事項

- **風險 1**：`useColumns.tsx` 目前在 `course-exclude` 模式下仍會顯示「Granted courses」欄位，該欄位對「尚未加入本課程」的學員而言會顯示「其他已開通課程」，屬於額外資訊不構成矛盾。但 `Switch`（顯示全部 vs 僅本課程）對 `course-exclude` 模式的學員意義不大（因為尚未加入本課程）——**決策**：保留，但 hint tooltip 文字不改，讓視覺一致。
- **風險 2**：`selectedUserIdsAtom` 是全局 Jotai atom；同一頁面若同時出現 global 與 course-exclude 兩個 UserTable 會互相干擾。目前設計上兩種 mode 不會在同一頁出現（`/admin/students` 只有 global；課程編輯頁只有 course-exclude），無衝突。若未來需要並存，再考慮 atomFamily 或 props 注入 atom。
- **風險 3**：`AddOtherCourse`（現 `StudentTable` 使用）與 `GrantCourseAccess`（UserTable 使用）是不同元件，刪除 `UserSelector` 時要留意 `import` 不要誤刪 `AddOtherCourse`。

## 8. 交付檔案清單（Discovery 產出）

- [x] `specs/clarify/2026-04-21-1747-issue190.md`（澄清紀錄）
- [x] `specs/open-issue/190-course-students-user-selector-refactor.md`（本檔）
- [x] `specs/ui/課程學員管理-用戶挑選彈窗.md`（UI 規格）
- [x] `specs/features/student/透過彈窗挑選用戶加入課程.feature`（前端行為規格，`@ignore @command`）
