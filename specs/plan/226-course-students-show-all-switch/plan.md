# Issue #226 實作計畫 — 課程學員 TAB「顯示全部」Switch 移到表頭

> 範圍模式：**HOLD SCOPE**（影響檔案 ≤4，無 API 變動，純前端 UI 微調）
> Spec 來源：[`specs/open-issue/226-course-students-show-all-switch-to-header.md`](../../open-issue/226-course-students-show-all-switch-to-header.md)
> Feature：[`specs/features/student/課程學員列表批次切換顯示全部課程.feature`](../../features/student/課程學員列表批次切換顯示全部課程.feature)

---

## 1. 需求重述

把 `/wp-admin/admin.php?page=power-course#/courses/edit/{course_id}` 課程學員 TAB 中**每個 user row 各自的「顯示全部」switch**，統一上提到 `Granted courses` 欄位表頭（th），改為**一次套用全部 user** 的批次切換。switch 狀態僅本次頁面停留有效（`useState`，不寫 localStorage）。

## 2. 影響檔案清單

| # | 檔案 | 動作 | 重點變更 |
|---|------|------|---------|
| 1 | `js/src/components/user/AvlCoursesList/index.tsx` | **MODIFY** | 改為純展示元件：移除 `showToggle` prop、內部 `useState`、switch UI 區塊；新增 `showAllCourses?: boolean` prop |
| 2 | `js/src/components/user/UserTable/hooks/useColumns.tsx` | **MODIFY** | 新增 `useState<boolean>(false)` 管理 `showAllCourses`；`Granted courses` column 的 `title` 改為 ReactNode 渲染「標題 + Switch」（僅當 `currentCourseId` 存在時）；把 state 傳給 `<AvlCoursesList showAllCourses={...} />` |
| 3 | `js/src/pages/admin/Teachers/Edit/Detail/Learning/index.tsx` | **MODIFY**（mechanical） | 註解更新（拆除已不存在的 `showToggle` 描述）；不需傳 `showAllCourses`，因 `currentCourseId` 不存在會 fallback 永遠展開 |
| 4 | `tests/e2e/01-admin/course-students-show-all.spec.ts` | **NEW** | 新增 Show all switch 行為的 E2E 驗證 |

> **無後端變動**：不修改 `inc/`、不新增 REST endpoint、不變更 `students` resource filter。

## 3. 詳細設計

### 3.1 `AvlCoursesList` 改造

#### Before（現況）

```tsx
export const AvlCoursesList: FC<{
  record: TUserRecord
  currentCourseId?: string | number
  showToggle?: boolean
}> = ({ record, currentCourseId, showToggle = false }) => {
  const [showAllCourses, setShowAllCourses] = useState(!currentCourseId)
  // ...
  return (
    <>
      {showToggle && currentCourseId && (
        <div className="mb-2 flex items-center justify-end gap-2 text-xs">
          <Tooltip title={__('Show all courses granted to the user', 'power-course')}>
            <span>{__('Show all', 'power-course')}</span>
          </Tooltip>
          <Switch checked={showAllCourses} onChange={setShowAllCourses} size="small" />
        </div>
      )}
      {filtered_avl_courses.map(...)}
    </>
  )
}
```

#### After

```tsx
export const AvlCoursesList: FC<{
  record: TUserRecord
  currentCourseId?: string | number
  /**
   * 是否展開全部已授權課程（由外部控制；不傳則預設 false）
   * - 若 currentCourseId 不存在：永遠展全部（忽略此 prop）
   * - 若 currentCourseId 存在且 showAllCourses=true：展全部
   * - 若 currentCourseId 存在且 showAllCourses=false：只顯示該課程
   */
  showAllCourses?: boolean
}> = ({ record, currentCourseId, showAllCourses = false }) => {
  const { id: user_id, formatted_name, display_name } = record
  const avl_courses = (record?.avl_courses ?? []) as TAVLCourse[]

  const filtered_avl_courses =
    showAllCourses || !currentCourseId
      ? avl_courses
      : avl_courses.filter(
          (course) => String(course.id) === String(currentCourseId)
        )
  // ...（map 渲染部分不變）
}
```

**移除的 import**：`useState`、`Switch`、`Tooltip`（若無其他使用）—— 請以 ESLint 結果為準。

### 3.2 `useColumns` 改造

#### Before

```tsx
const useColumns = (params?: TUseColumnsParams) => {
  const handleClick = params?.onClick
  const { id: currentCourseId } = useParsed()

  const columns: TableProps<TUserRecord>['columns'] = [
    // ...
    {
      title: __('Granted courses', 'power-course'),
      dataIndex: 'avl_courses',
      width: 240,
      render: (_avl_courses, record) => (
        <AvlCoursesList
          record={record}
          currentCourseId={currentCourseId as string | undefined}
          showToggle
        />
      ),
    },
    // ...
  ]
  return columns
}
```

#### After

```tsx
const useColumns = (params?: TUseColumnsParams) => {
  const handleClick = params?.onClick
  const { id: currentCourseId } = useParsed()
  const [showAllCourses, setShowAllCourses] = useState(false)

  const columns: TableProps<TUserRecord>['columns'] = [
    // ...
    {
      title: currentCourseId ? (
        <div className="flex items-center justify-between gap-2">
          <span>{__('Granted courses', 'power-course')}</span>
          <div className="flex items-center gap-2 text-xs font-normal">
            <Tooltip title={__('Show all courses granted to the user', 'power-course')}>
              <span>{__('Show all', 'power-course')}</span>
            </Tooltip>
            <Switch
              checked={showAllCourses}
              onChange={setShowAllCourses}
              size="small"
            />
          </div>
        </div>
      ) : (
        __('Granted courses', 'power-course')
      ),
      dataIndex: 'avl_courses',
      width: 240,
      render: (_avl_courses, record) => (
        <AvlCoursesList
          record={record}
          currentCourseId={currentCourseId as string | undefined}
          showAllCourses={showAllCourses}
        />
      ),
    },
    // ...
  ]
  return columns
}
```

**新增的 import**：`useState`（react）、`Switch`、`Tooltip`（antd）。

**關鍵點**：

- `currentCourseId` 不存在時 → title 退化為純字串，**不渲染** switch；同時 `showAllCourses` 傳 `false` 但 `AvlCoursesList` 內部會因 `!currentCourseId` fallback 為展全部 → 完全與既有行為一致
- `Granted courses` column 的 `title` 從 string 變 ReactNode，Ant Design Table 完全支援；無 sorter，無排序 icon 衝突
- column 陣列每次 render 重建是常規做法，符合既有專案慣例（其他 `useColumns` 也如此）

### 3.3 `Learning/index.tsx` 微調

只更新註解（拆掉已不存在的 `showToggle` 描述），呼叫端 `<AvlCoursesList record={record} />` 不變 —— 因為沒傳 `currentCourseId`，`AvlCoursesList` 會 fallback 永遠展開全部。

```tsx
/**
 * 講師 Edit 頁 — 學習紀錄 Tab（Q11=A：講師本人作為學員）
 *
 * 重用：
 * - AvlCoursesList：顯示 record.avl_courses
 * - HistoryDrawer：點「學習歷程」按鈕打開章節 timeline
 *
 * 不傳 currentCourseId，所以會顯示講師本人的所有授權課程。
 */
```

### 3.4 E2E 測試

**新檔**：`tests/e2e/01-admin/course-students-show-all.spec.ts`

需準備的測試 fixture：
- 課程 A（test 自建，使用 `api.createCourse`）
- 課程 B（另一堂課，用 `api.createCourse` 建立）
- 一個 user 同時被授權兩堂課（透過 `api.grantCourseAccess` 或同等 API；若無現成 helper，可在 spec 內直接呼叫 `power-course/v2/courses/add-students`）

**測試案例**：

| Test | AC | 驗證內容 |
|------|----|---------|
| `預設只顯示本課程，switch OFF` | AC-1 / AC-2 | th 內 Switch 存在；checked=false；row 內 `Granted courses` 只看到課程 A |
| `切換 ON 後全部 row 展開` | AC-3 | 點擊 switch → checked=true → row 內同時看到課程 A 與課程 B |
| `再切換 OFF 後 row 收回` | AC-4 | 再點 → checked=false → 只看到課程 A |
| `每個 row 內不再有獨立 switch` | AC-5 | `[role="switch"]` 在 `.ant-table-tbody` 內數量為 0；在 `.ant-table-thead` 內數量為 1 |
| `重新整理頁面後 switch 重置 OFF` | AC-6 | reload page → checked=false |
| `/admin/students 全局頁不顯示 switch` | AC-7 | 走 `/students` URL → `[role="switch"]` 在 `Granted courses` th 內數量為 0 |
| `/teachers/edit/{id} Learning Tab 不顯示 switch` | AC-8 | 走 teacher edit → Learning tab → 該 tab 內無 `[role="switch"]` 給 AvlCoursesList |

> **既有 test**：`course-edit.spec.ts` 內的「學員管理 Tab」smoke test 不變，仍應通過。

### 3.5 i18n

| msgid | 用途 | 狀態 |
|-------|------|------|
| `Granted courses` | 表頭文字 | 既有，無變動 |
| `Show all` | Switch 標籤 | 既有（從 `AvlCoursesList` 搬到 `useColumns`） |
| `Show all courses granted to the user` | Tooltip | 既有（同上） |

**無需新增字串**。但 PR 仍應跑 `pnpm run i18n:pot` 確認 `.pot` 無 diff（驗收 AC-9）。

## 4. 實作順序（Red → Green → Refactor）

依 TDD 流程：

1. **Red — E2E 測試先行**
   - 建立 `tests/e2e/01-admin/course-students-show-all.spec.ts`，所有 AC 都先寫成失敗的測試
   - 跑 `pnpm run test:e2e:admin --grep "course-students-show-all"` 確認全紅

2. **Green — 元件改造**
   - Step 2.1：先改 `AvlCoursesList`（移除 switch、新增 prop） → 跑 `pnpm run lint:ts` + `pnpm run build` 確認 TS 報錯（因為 `useColumns` 還在傳 `showToggle`）
   - Step 2.2：改 `useColumns`（搬 switch 到 title、加 useState） → TS 過
   - Step 2.3：微調 `Learning/index.tsx` 註解
   - 跑 E2E 確認全綠

3. **Refactor**
   - 檢查 `AvlCoursesList` 是否還有未用 imports（`useState` / `Switch` / `Tooltip`），ESLint auto-fix 清理
   - 確認 `useColumns` 新增的 import 排序符合 ESLint
   - 跑 `pnpm run format` + `pnpm run lint:ts`

4. **驗收**
   - `pnpm run build` 全綠（TypeScript 編譯通過）
   - `pnpm run lint:ts` 無 error
   - `pnpm run lint:php` 無變動（無 PHP 改動）
   - `pnpm run i18n:pot` 後 `git diff languages/power-course.pot` 無 diff
   - `pnpm run test:e2e:admin` 全綠（含新增 spec）

## 5. 風險評估與緩解

| 風險 | 機率 | 影響 | 緩解 |
|------|------|------|------|
| 拆除 `showToggle` prop 是 breaking change，可能有未發現的 caller | 低 | 中 | 已 grep 全專案，只有 `useColumns` 與 `Learning/index.tsx` 兩處 caller；TypeScript strict mode 會在 build 期擋下任何遺漏 |
| Ant Design Table 的 `column.title` 接受 ReactNode 但需注意排序 icon 等內建功能 | 低 | 低 | `Granted courses` column 無 sorter，無排序 icon 衝突 |
| `useColumns` 加 `useState` 後 `useMemo` 是否需要 | 低 | 低 | 既有專案慣例：所有 `useColumns` 都是普通陣列、每次 render 重建。Ant Design Table 接收新 columns 會正確 re-render。無需 `useMemo` |
| Switch 在 th 內可能與 column resizer / sorter 視覺衝突 | 極低 | 低 | 該欄無 resizer / sorter；UI 規格已限定靠右對齊 `justify-between` + 小尺寸 |
| `/admin/students` 全局頁的行為改變 | 低 | 中 | 已驗證：`useParsed()` 在無 `/edit/{id}` 路徑時 `id` 為 undefined → switch 不渲染、`AvlCoursesList` 走 `!currentCourseId` fallback 展開全部，與既有行為一致 |
| Teacher Edit 的 Learning Tab 行為改變 | 極低 | 低 | 該頁 `<AvlCoursesList record={record} />` 不傳 `currentCourseId` → 邏輯與舊版完全一致 |
| memo() 包裹的 `StudentTable` 可能阻止 column re-render | 低 | 中 | `useColumns` 的 useState 變動會觸發 `StudentTable` 自身 re-render（hook 在內部）→ memo 不會阻止；columns 是內部變數，每次 re-render 重新計算 |

## 6. 驗收標準（與 spec 對應）

| AC | 來源 | 驗證方式 |
|----|------|---------|
| AC-1 | 表頭出現 Show all + Switch | E2E test: `預設只顯示本課程，switch OFF` |
| AC-2 | 預設 OFF，row 只看本課程 | E2E test 同上 |
| AC-3 | 切 ON 全部 row 展開 | E2E test: `切換 ON 後全部 row 展開` |
| AC-4 | 切 OFF 全部 row 收回 | E2E test: `再切換 OFF 後 row 收回` |
| AC-5 | row 內無 switch（DOM 只 1 個） | E2E test: `每個 row 內不再有獨立 switch` |
| AC-6 | 重新整理 / 切 tab / 跳堂 後 重置 | E2E test: `重新整理頁面後 switch 重置 OFF` |
| AC-7 | `/admin/students` th 無 switch | E2E test: `/admin/students 全局頁不顯示 switch` |
| AC-8 | Teacher Edit Learning Tab 無 switch | E2E test: `/teachers/edit/{id} Learning Tab 不顯示 switch` |
| AC-9 | `pnpm run i18n:pot` 無 diff | CI script |
| AC-10 | `pnpm run build` / `lint:ts` 全綠 | CI script |

## 7. 不在本次範圍

- StudentTable 其他 UI（DatePicker / Export CSV / Remove access 等）
- `AddOtherCourse`、`HistoryDrawer` 元件
- 後端 API、`students` resource filter
- 任何 PHP 改動
- localStorage / atom 持久化（spec 已明確排除）
- 新增 i18n msgid（既有 3 個 msgid 全部沿用）

## 8. 交接 tdd-coordinator

下一步：tdd-coordinator 依本計畫安排：

- **Red**：派 `test-creator` 建立 `tests/e2e/01-admin/course-students-show-all.spec.ts`
- **Green**：派 `react-master` 依 §3.1 / §3.2 / §3.3 修改三個 tsx 檔
- **Refactor**：派 `react-master` 跑 lint / format / build 確認綠
- **驗收**：派 `react-master` 跑 `i18n:pot` 確認 `.pot` 無 diff

> ⚠️ 本次無 PHP 改動，無需 `wordpress-master`；i18n 無新字串，無需 `pnpm run i18n:build`。
