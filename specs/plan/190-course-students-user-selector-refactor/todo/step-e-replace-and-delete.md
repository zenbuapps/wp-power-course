# Step E — CourseStudents 改用 UserTable + 刪除 UserSelector

> 對應 Spec §6 Step E｜依賴：Step D｜風險：中｜預估：0.5h｜建議執行者：main agent 自理 或 `@zenbu-powers:react-master`

## 目標

把 `<UserSelector />` 替換成 `<UserTable mode="course-exclude" />`；刪除 `UserSelector/` 整個目錄；精簡 Alert 文字。

## 變更檔案

- `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/index.tsx`（修改）
- `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/UserSelector/`（**整個目錄刪除**）

## 具體動作

### E-1：改 `CourseStudents/index.tsx`

改動前（L:1-46）：
```tsx
import StudentTable from './StudentTable'
import UserSelector from './UserSelector'
// ...
<UserSelector />
```

改動後：
```tsx
import { UserTable } from '@/components/user/UserTable'

import StudentTable from './StudentTable'
// ...
<UserTable mode="course-exclude" />
```

**同時精簡 Alert 三行提示**（Spec §2.6）：

- 保留：「Changes here take effect immediately, no save required」
- 保留：「Cannot find the user? They may already be enrolled in this course」
- **刪除**：「Search by keyword to find users (up to 30 results per query)」（已改為分頁 20 筆）

可替換為「Select users from the table and click 'Add N students to this course'」等更貼切描述（**需加進 `manual.json`**），或保留既有兩條。建議最簡：只保留既有兩條，不新增 msgid，減少 i18n 變動面。

**若新增描述，記得同步 `manual.json` 並跑 `pnpm run i18n:build`**。

### E-2：刪除 UserSelector 目錄

```bash
rm -rf "js/src/pages/admin/Courses/Edit/tabs/CourseStudents/UserSelector"
```

（Windows PowerShell：`Remove-Item -Recurse -Force`；或直接在 IDE/git 刪資料夾）

### E-3：全域一致性掃描（依全域規則觸發）

依 `CLAUDE.md` 全域一致性守則，刪名稱類變更必跑 sweep：

```bash
grep -r "UserSelector" js/src/pages/admin/Courses/Edit/tabs/CourseStudents
grep -r "from.*CourseStudents/UserSelector" js/src
grep -rn "UserSelector" js/src
```

期望：`js/src` 下完全無 `UserSelector`（目錄刪除後所有 import 皆刪）。若其他目錄有同名字串，確認不是本 UserSelector。

> ⚠️ **別誤刪** `SelectedUser`（注意：大小寫與字序不同；`SelectedUser` 是 UserTable 子元件，仍在使用）。

### E-4：AddOtherCourse 存活驗證

```bash
grep -rn "AddOtherCourse" js/src
```

期望仍有命中（`StudentTable/` 使用中）。若零命中代表 R5 風險實現，回滾。

## 驗收指令

```bash
pnpm run lint:ts                         # 零 import error（重點確認無 dead import）
pnpm run build
pnpm run format --check
```

## 驗收條件

- [ ] `CourseStudents/UserSelector/` 目錄不存在
- [ ] `grep -r "UserSelector" js/src` 零命中
- [ ] `grep -rn "AddOtherCourse" js/src` 仍有命中（StudentTable）
- [ ] 課程編輯頁「課程學員」tab：
  - 上方顯示完整 UserTable（Filter + FilterTags + SelectedUser + 加入按鈕 + DatePicker + Table 分頁 20 筆）
  - 下方 StudentTable 不變
  - 加入流程端到端可跑通
- [ ] Alert 提示文字精簡正確

## 風險提示

- 刪目錄前必須 Step D 完全綠燈（按鈕/DatePicker/mutation 可跑通）
- git delete 確保在 commit 時紀錄為 `delete`（非 rename），後續 review 更清楚
