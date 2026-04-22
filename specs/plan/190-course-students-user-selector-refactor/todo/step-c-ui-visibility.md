# Step C — course-exclude 模式 UI 取捨（隱藏 global 專屬區塊）

> 對應 Spec §6 Step C｜依賴：Step B｜風險：中｜預估：1.0h｜建議執行者：`@zenbu-powers:react-master`

## 目標

在 `UserTable` JSX 內，將「global 模式才有」的區塊改為條件式 render：`mode === 'global' && canGrantCourseAccess`（或 `mode === 'global'`，視區塊屬性）。

## 變更檔案

- `js/src/components/user/UserTable/index.tsx`

## 具體動作

| 區塊 | 既有條件 | 新條件 | 行號（改動前） |
|------|---------|--------|--------------|
| `GrantCourseAccess` 按鈕 | `canGrantCourseAccess` | `mode === 'global' && canGrantCourseAccess` | L:270-277 |
| 批次到期日 + RemoveCourseAccess 區塊 | `canGrantCourseAccess` | `mode === 'global' && canGrantCourseAccess` | L:279-310 |
| CSV 匯出 + CSV 上傳按鈕 | `canGrantCourseAccess` | `mode === 'global' && canGrantCourseAccess` | L:311-325 |
| CSV Upload Modal | `canGrantCourseAccess` | `mode === 'global' && canGrantCourseAccess` | L:356-366 |
| `HistoryDrawer` | 永遠 render | `mode === 'global' && <HistoryDrawer />` | L:368 |

> 建議：把整塊 `{canGrantCourseAccess && (...)}` 外層包 `{mode === 'global' && canGrantCourseAccess && (...)}`，或改寫成 `const showAdminFeatures = mode === 'global' && canGrantCourseAccess` 區域變數，避免多處散落條件。

保留：

- `Filter` card（L:257-268）— **course-exclude 也顯示**
- `FilterTags`（L:264-267）— 顯示
- `SelectedUser`（L:328-339）— 顯示
- `Table`（L:341-354）— 顯示

## 驗收指令

```bash
pnpm run lint:ts
pnpm run build
```

## 驗收條件

- [ ] `mode='global' + canGrantCourseAccess=true`：6 個 admin 區塊全顯示（`/admin/students` 頁驗）
- [ ] `mode='global' + canGrantCourseAccess=false`：6 個 admin 區塊全隱藏
- [ ] `mode='course-exclude'`：6 個 admin 區塊全隱藏（試掛 UserTable 到 CourseStudents 驗證）
- [ ] `mode='course-exclude'`：Filter / FilterTags / SelectedUser / Table / 分頁仍完整顯示
- [ ] `HistoryDrawer` 在 course-exclude 模式完全不 render（不是 `visible=false`，而是不掛載）

## 風險提示

- `HistoryDrawer` 內部可能有全域 atom 訂閱，避免 unmount 引發副作用：確認它只接 `historyDrawerAtom`，不會造成其他頁副作用
- 若未來要新增其他「僅 global」功能，統一用 `showAdminFeatures` 區域變數集中
