# Step D — 加入按鈕 + DatePicker + useCustomMutation + i18n

> 對應 Spec §6 Step D｜依賴：Step C｜風險：中｜預估：3.0h｜建議執行者：`@zenbu-powers:react-master`（必讀 `.claude/rules/i18n.rule.md` + skill `wordpress-i18n`）

## 目標

在 `mode === 'course-exclude'` 時，於 `SelectedUser` 下方（Table 之上）新增：

- 加入按鈕（type=primary）
- DatePicker（到期日，showTime）
- 呼叫 `POST /courses/add-students` 的 useCustomMutation handler
- 完整 i18n 流程

## 變更檔案

- `js/src/components/user/UserTable/index.tsx`（主要邏輯）
- `scripts/i18n-translations/manual.json`（新增 5 條 k/v）
- `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json`（pipeline 自動產出）

## 具體動作

### D-1：新增 imports

```ts
import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { DatePicker } from 'antd'
import dayjs, { type Dayjs } from 'dayjs'
import { sprintf } from '@wordpress/i18n'  // __ 已有
```

### D-2：新增 state + mutation handler

```ts
const [expireDate, setExpireDate] = useState<Dayjs | undefined>(undefined)
const apiUrl = useApiUrl('power-course')
const invalidate = useInvalidate()
const { mutate: addStudents, isLoading: isAdding } = useCustomMutation()

const handleAddStudents = () => {
  if (!courseId || selectedRowKeys.length === 0) return
  addStudents(
    {
      url: `${apiUrl}/courses/add-students`,
      method: 'post',
      values: {
        user_ids: selectedRowKeys as string[],
        course_ids: [courseId],
        expire_date: expireDate ? expireDate.unix() : 0,
      },
    },
    {
      onSuccess: () => {
        message.success({
          content: __('Students added successfully', 'power-course'),
          key: 'add-students',
        })
        invalidate({
          resource: 'students',
          dataProviderName: 'power-course',
          invalidates: ['list'],
        })
        setSelectedUserIds([])
        setExpireDate(undefined)
      },
      onError: () => {
        message.error({
          content: __('Failed to add students to course', 'power-course'),
          key: 'add-students',
        })
      },
    }
  )
}
```

> 注意：`selectedRowKeys` 與 `selectedUserIds` 在 UserTable 內是等價的（L:82-112 的 onChange 會同步），成功後重設 `selectedUserIdsAtom` 會透過 L:129-134 的 useEffect 自動清空 `selectedRowKeys`（打勾也清空）。

### D-3：JSX 新增區塊（位置：`<SelectedUser />` 之下，`<Table />` 之上）

```tsx
{mode === 'course-exclude' && (
  <div className="mt-4 flex gap-x-4 items-end">
    <Button
      type="primary"
      onClick={handleAddStudents}
      loading={isAdding}
      disabled={selectedRowKeys.length === 0 || isAdding || !courseId}
    >
      {selectedRowKeys.length > 0
        ? sprintf(
            // translators: %s: 勾選人數
            __('Add %s students to this course', 'power-course'),
            selectedRowKeys.length
          )
        : __('Add students to this course', 'power-course')}
    </Button>
    <div className="flex flex-col">
      <label className="text-xs text-gray-500 mb-1">
        {__('Expire date (applied to all selected)', 'power-course')}
      </label>
      <DatePicker
        value={expireDate}
        onChange={(value) => setExpireDate(value ?? undefined)}
        placeholder={__('Leave empty for permanent access', 'power-course')}
        showTime
        format="YYYY-MM-DD HH:mm"
        disabledDate={(current) => current && current < dayjs().startOf('day')}
        disabled={isAdding}
      />
    </div>
  </div>
)}
```

### D-4：更新 `scripts/i18n-translations/manual.json`

依 Spec §4.2 加入 5 條（注意檔案現有格式是 array 或 object，follow 既有風格，見 `scripts/build-zhtw-po.mjs::extractList()`）：

```json
{
  "Add %s students to this course": "加入 %s 名學員到本課程",
  "Add students to this course": "加入學員到本課程",
  "Leave empty for permanent access": "留空代表永久開通",
  "Expire date (applied to all selected)": "到期日（套用至所有勾選）",
  "Failed to add students to course": "加入學員到本課程失敗"
}
```

> `Students added successfully` 為既有 msgid，既有翻譯會被 pipeline 保留，**不需重複加入**。

### D-5：跑 i18n pipeline

```bash
pnpm run i18n:build
```

輸出應更新四檔：`power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json`。

### D-6：驗 pipeline 產物

```bash
# 確認 msgstr 非空
grep -A 1 '"Add %s students to this course"' languages/power-course-zh_TW.po
grep -A 1 '"Failed to add students to course"' languages/power-course-zh_TW.po
```

## 驗收指令

```bash
pnpm run lint:ts
pnpm run format --check
pnpm run build
pnpm run i18n:build
git diff languages/                      # 確認四檔都有 diff
```

## 驗收條件

- [ ] 未選 DatePicker + 勾 1 人 + 按按鈕 → Network 看 payload `expire_date === 0`
- [ ] 選未來日期 `2027-01-01 00:00` + 勾 1 人 + 按按鈕 → Network 看 payload `expire_date === 1798732800`（或等價 unix）
- [ ] DatePicker 點開，昨天 disabled、今天 enabled
- [ ] 成功：顯示 `message.success`「Students added successfully」/「已成功加入學員」；勾選清空；DatePicker 清空；上下表同步 refetch
- [ ] 失敗（手動斷網或 mock 500）：顯示 `message.error`「Failed to add students to course」/「加入學員到本課程失敗」；勾選保留；DatePicker 保留
- [ ] 按鈕 label：
  - 零勾選：「Add students to this course」
  - 勾 1 人：「Add 1 students to this course」（注意 Spec 使用 `%s students`，單複數統一以 `students` 處理）
  - 勾 3 人：「Add 3 students to this course」
- [ ] `languages/power-course-zh_TW.po` 新 msgid 皆有非空 msgstr
- [ ] UserTable 內無 `@ts-nocheck` 新增，無 `as any` 新增（`selectedRowKeys as string[]` 除外，既有行為）

## 風險提示

- **i18n pipeline 必跑**：漏跑會導致 fallback 顯示英文 msgid（rule.md §前台 bundle shim 已排除 Vite 問題，但 pipeline 沒跑仍會失敗）
- `sprintf` 第二參數請直接傳 number，不要先 `String(N)`（`@wordpress/i18n` 的 sprintf 內部處理）
- DatePicker `value={undefined}` 和 `value={null}` 行為不同：統一用 `undefined` 表示未選
- `expireDate.unix()` 是秒級 timestamp，與後端 `expire_date` 欄位一致（毫秒級需乘 1000 — 本次是秒級）
- message 的 `key: 'add-students'` 讓同 key 訊息互相取代，避免連按多次時堆疊
