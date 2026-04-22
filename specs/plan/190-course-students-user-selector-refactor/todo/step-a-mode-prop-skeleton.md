# Step A — UserTable `mode` prop 骨架

> 對應 Spec §6 Step A｜依賴：無｜風險：低｜預估：0.5h｜建議執行者：`@zenbu-powers:react-master`

## 目標

為 `UserTable` 擴充 `mode: 'global' | 'course-exclude'` prop，**不改 runtime 行為**。Step A 只擴型別骨架，確保專案編譯通過、`/admin/students` 頁面完全不變。

## 變更檔案

- `js/src/components/user/UserTable/index.tsx`

## 具體動作

1. 在元件檔頂部新增型別匯出（與現有 `TUserRecord` / `CardProps` 平級）：
   ```ts
   export type UserTableMode = 'global' | 'course-exclude'
   ```
2. 擴充 `UserTableComponent` Props type（L:38-46），新增 `mode?: UserTableMode`，預設 `'global'`。
3. **此步不使用 `mode`**：不改 useTable、不改 JSX。只是把 prop 納入簽名。
4. 若 export 形式允許，同步將 `UserTableMode` 透過 `export * from './atom'` 相鄰位置補一行 re-export（或放在檔末 named export）。

## 驗收指令

```bash
pnpm run lint:ts                         # 零 error
pnpm run format --check                  # 無未格式化檔
pnpm run build                           # TypeScript strict 編譯通過
```

## 驗收條件

- [ ] `UserTable` 型別多出 `mode?: 'global' | 'course-exclude'`
- [ ] `/admin/students` 頁面視覺 + 行為 0 變化（手動驗收或 screenshot diff）
- [ ] 無 `@ts-nocheck` 新增
- [ ] 無 `as any`
