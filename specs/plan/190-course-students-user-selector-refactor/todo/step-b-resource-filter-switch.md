# Step B — Resource / Permanent Filter / useParsed + enabled guard

> 對應 Spec §6 Step B｜依賴：Step A｜風險：中｜預估：1.5h｜建議執行者：`@zenbu-powers:react-master`

## 目標

依 `mode` 切換 `useTable` 的 `resource` / `dataProviderName` / `permanent filter` / `queryOptions.enabled`。**global 模式的設定必須與改動前逐字相同**。

## 變更檔案

- `js/src/components/user/UserTable/index.tsx`

## 具體動作

1. 在元件頂部加入：
   ```ts
   import { useParsed } from '@refinedev/core'
   const { id: courseId } = useParsed()
   ```
2. 將 useTable 的 config 依 `mode` 條件產生（建議用區域變數 `useTableConfig`，避免三元式塞在 JSX 中）：
   - `mode === 'course-exclude'`：
     ```ts
     {
       resource: 'students',
       dataProviderName: 'power-course',
       pagination: { pageSize: 20 },
       filters: {
         permanent: [
           { field: 'meta_key',   operator: 'eq', value: 'avl_course_ids' },
           { field: 'meta_value', operator: 'ne', value: courseId },
           { field: 'meta_keys',  operator: 'eq', value: ['formatted_name'] },
         ],
       },
       queryOptions: { enabled: !!courseId },
       onSearch: /* 既有 contains 邏輯不變 */,
     }
     ```
   - `mode === 'global'`（預設，**沿用既有**）：
     ```ts
     {
       resource: 'users',
       pagination: { pageSize: 20 },
       filters: {
         permanent: [
           { field: 'meta_keys', operator: 'eq', value: ['is_teacher', 'avl_courses'] },
         ],
       },
       onSearch: /* 既有 contains 邏輯不變 */,
     }
     ```
3. `useTable` 型別泛型維持 `<TUserRecord, HttpError, TFilterValues>`。
4. 不改 rowSelection / useColumns / getDefaultPaginationProps 等既有邏輯。

## 驗收指令

```bash
pnpm run lint:ts
pnpm run build
```

## 驗收條件

- [ ] `/admin/students` 頁面手動驗收：Network tab 觀察仍呼叫 `users` resource，query 參數 `meta_keys[]=is_teacher&meta_keys[]=avl_courses`
- [ ] 暫時在 `CourseStudents/index.tsx` 加測試掛載 `<UserTable mode="course-exclude" />`（**不刪 UserSelector，只額外試掛**），進課程編輯頁確認：
  - Network 呼叫 `students` resource（路徑含 `power-course/v2/students`）
  - query 含 `meta_key=avl_course_ids`、`meta_value[not_in]=<courseId>` 或 `operator=ne` 對應的 WP 語法
  - 已加入本課程的學員不在回傳列表
- [ ] URL 還沒 resolve 完成（`courseId=undefined`）時，Network 無 `students` list 請求
- [ ] 驗收完畢後**把試掛的 `<UserTable mode="course-exclude" />` 恢復**（Step E 才正式替換）

## 風險提示

- **絕對不改** global 模式的 permanent filter — 該陣列會被既有 `/admin/students` 的 `useColumns` 與 `Filter` 依賴
- `useParsed()` 在非 Refine route 之下 `id` 會是 `undefined`；`queryOptions.enabled = !!courseId` 是必要 guard
