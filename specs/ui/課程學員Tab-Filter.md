# 課程學員 Tab — Filter 篩選器

> Issue #227 — 對應 spec：`specs/features/student/課程學員Filter篩選.feature`

## 描述

課程編輯頁的「學員」Tab 上方新增 Filter 篩選器，提供「關鍵字搜尋」與「課程進度查詢」兩種條件，協助站長快速找到落後 / 領先 / 已完成 / 未開始的學員，並針對篩選結果做匯出或批次操作。

## 位置

- 頁面：`/admin.php?page=power-course#/courses/edit/{course_id}` → 學員 Tab
- 元件：`js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/Filter/index.tsx`（新增）
- 套用於：`js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/index.tsx`（修改）

## 設計決策（澄清結論）

| 決策點 | 決策 | 出處 |
|--------|------|------|
| 搜尋欄位範圍 | 跟 UserTable 一致，**單一輸入框**比對 ID / login / Email / display_name / billing 姓名 | Q1=A |
| 進度篩選 UI | **運算子 Select + 數值 InputNumber 雙欄位**（=, !=, <, <=, >, >= × 0–100） | Q2=B |
| 課程進度欄位 | **不加進 Table 欄位**，只做 Filter | Q3=C |
| 視覺風格 | **完全參考 UserTable Filter**：`<Card title="篩選">` 包外層 + `<FilterTags />` 顯示已套用條件 + 「篩選 / 重設」按鈕 | Q4=A |
| 匯出行為 | **匯出套用 Filter**：search / progress_operator / progress_value 一起帶進 export query string | Q5=A |
| 狀態保留 | **保留 Filter 條件**（React state / Refine `useTable` filters），切 Tab 不清空、重整頁面才清空 | Q6=A |
| 後端篩選實作 | **SQL 層**：在 ExtendQuery 加入子查詢計算「已完成章節 / 課程總章節」並 HAVING 條件 | Q7=A |
| 批次操作交集 | **當前頁全選**（與既有 Ant Design Table / UserTable 行為一致），跨頁全選不在此 Issue 範圍 | Q8=B |

## 互動流程

```
1. 站長進入課程編輯頁，切到「學員」Tab
2. 學員 Table 上方顯示 Filter Card（預設展開）
3. 站長在「關鍵字搜尋」輸入 "alice"
4. 站長在「課程進度」選擇運算子 "<" 與數值 30
5. 站長點擊「篩選」按鈕
6. Refine.dev useTable 觸發 refetch，帶入 search + progress_operator + progress_value
7. Table 顯示符合條件的學員（與 pagination 重置為第 1 頁）
8. FilterTags 顯示「關鍵字: alice」「進度 < 30%」兩個 chip，點 chip 上的 ✕ 可移除單一條件
9. 站長點「匯出學員 CSV」→ 開新分頁帶入相同 Filter 參數
10. 站長切到「章節」Tab 再切回「學員」Tab → Filter 條件仍保留
11. 站長重整頁面 → Filter 條件清空
12. 站長點「重設」按鈕 → 清空所有 Filter 欄位並重新 fetch（顯示全部學員）
```

## 元素規格

### Filter Card（外框）

- Ant Design `<Card>`，title = `__('篩選', 'power-course')` 對應英文 msgid `'Filter'`
- 預設 collapsed = false（展開）
- 對齊既有 `UserTable/Filter`（`js/src/components/user/UserTable/Filter/index.tsx`）的視覺風格

### 欄位 1：關鍵字搜尋

- 元件：Ant Design `<Input>` 或 `<Input.Search>`，包在 `<Form.Item name="search" label="關鍵字搜尋">` 內
- 對應 i18n：
  - label msgid: `'Keyword search'`
  - placeholder msgid: `'Enter user ID, username, email or display name'`（直接 reuse UserTable Filter 既有翻譯）
- 行為：onBlur / Form submit 時觸發 Refine `useTable` 的 `setFilters`
- 後端對應參數：`search`（單一欄位，多欄位比對由後端 default search_field 處理）
- allowClear：✓

### 欄位 2：課程進度（雙欄位）

包在同一 `<Form.Item label="課程進度">` 內，使用 `<Space.Compact>` 或 grid 並排：

#### 2a. 運算子 Select

- 元件：Ant Design `<Select>`
- 寬度：80px
- 選項（msgid 一律英文，msgstr 走 manual.json 補繁中）：

  | value | label (msgid) | 繁中 msgstr |
  |-------|---------------|-------------|
  | `=`   | `Equal to`         | 等於       |
  | `!=`  | `Not equal to`     | 不等於     |
  | `<`   | `Less than`        | 小於       |
  | `<=`  | `Less or equal`    | 小於或等於 |
  | `>`   | `Greater than`     | 大於       |
  | `>=`  | `Greater or equal` | 大於或等於 |

- 預設值：無（placeholder = `__('Operator', 'power-course')`）

#### 2b. 數值 InputNumber

- 元件：Ant Design `<InputNumber>`
- 寬度：100px
- min = 0、max = 100、step = 1、precision = 0（整數）
- 後綴：`%`（透過 `addonAfter` 或 `formatter` 顯示）
- 預設值：無（placeholder = `__('0-100', 'power-course')`）

#### 雙欄位驗證規則

- 兩欄位必須同時有值才會 submit；只填一個時前端顯示 inline 錯誤：`__('Both operator and value are required', 'power-course')`
- 兩欄位皆空時不帶 `progress_operator` / `progress_value`，後端視為「不套進度篩選」

### 操作按鈕

對齊 UserTable Filter：

- 「篩選」按鈕：Primary，`<SearchOutlined />` icon，文字 `__('Filter', 'power-course')`
- 「重設」按鈕：Default，`<UndoOutlined />` icon，文字 `__('Reset', 'power-course')`，點擊後呼叫 `form.resetFields()` + `form.submit()`

### FilterTags 區（已套用條件）

- 元件：reuse `js/src/components/user/UserTable/FilterTags`（若已存在）或新建類似元件
- 顯示位置：Filter Card 底部，按鈕上方
- 每個套用條件顯示為 `<Tag closable>` chip，點 ✕ 移除單一條件
- Chip 格式範例：
  - `關鍵字: alice` （msgid: `Keyword: %s`）
  - `進度 < 30%` （msgid: `Progress %1$s %2$d%%`，translators 註：1=運算子, 2=百分比）

## 對既有 StudentTable 的修改

`StudentTable/index.tsx` 已存在的修改點：

1. **新增 Filter 引入**：`import Filter from './Filter'`
2. **useTable 改為承接動態 filters**：除 permanent filters（meta_key/meta_value/meta_keys）外，新增 `filters.initial` 或透過 `setFilters` 控制 search / progress_operator / progress_value
3. **取得 form props**：使用 `useTable` 回傳的 `searchFormProps`，傳入 `<Filter formProps={searchFormProps} />`
4. **匯出 URL 加 Filter 參數**（Q5=A）：
   ```ts
   const handleExport = () => {
     const params = new URLSearchParams({
       _wpnonce: NONCE,
       ...(search && { search }),
       ...(progress_operator && progress_value !== undefined && {
         progress_operator,
         progress_value: String(progress_value),
       }),
     })
     window.open(`${apiUrl}/students/export/${courseId}?${params}`, '_blank')
   }
   ```

## 對後端的修改點（給 wordpress-master 參考）

> 細節屬實作範疇，由 tdd-coordinator 階段拆分為 TDD 任務。本段僅做指向。

1. **REST API 註冊新參數**（`inc/classes/Resources/Student/Core/Api.php`）：在 `get_students_callback` 中接收 `progress_operator` / `progress_value`，傳給 Query
2. **Query 層擴充**（`inc/classes/Resources/Student/Service/Query.php`）：將 progress 條件轉為 SQL clause
3. **ExtendQuery 加進度子查詢**（`inc/classes/Resources/Student/Core/ExtendQuery.php`）：
   - 在 user query 中 LEFT JOIN `pc_avl_chaptermeta`（透過 `users_pre_query` filter 或自訂 SQL）
   - 子查詢計算：`(SELECT COUNT(*) FROM pc_avl_chaptermeta WHERE user_id = users.ID AND parent_id IN (course_chapters) AND finished_at > 0) / total_chapters * 100`
   - 將計算結果以 HAVING 套用 progress_operator + progress_value
4. **參數驗證**（在 callback 開頭）：
   - 兩參數成對檢查 → 400「progress_operator 與 progress_value 必須同時提供」
   - `progress_operator` 白名單 → 400「progress_operator 只能是 =、!=、<、<=、>、>=」
   - `progress_value` 0-100 整數 → 400「progress_value 必須是 0 到 100 之間的整數」
5. **匯出端點同步擴充**（`Service/ExportCSV.php`）：constructor 接收 search / progress filter，套用於 query

## 注意事項

- 使用 `@wordpress/i18n` 的 `__()`、`sprintf()`，**禁止** i18next（見 `.claude/rules/i18n.rule.md`）
- 所有新 msgid 一律英文完整句子，繁中翻譯加進 `scripts/i18n-translations/manual.json`，跑 `pnpm run i18n:build` 後一起 commit `.po` / `.mo` / `.json` / `.pot`
- 後端 SQL 必須 `$wpdb->prepare()`，避免 injection（progress_operator 雖然是白名單，仍以 prepare 處理）
- progress 計算公式須與既有 `CourseUtils::get_course_progress()` 結果一致（取整數百分比），避免 UI 顯示與 Filter 結果不符
- 課程無章節（total_chapters = 0）時：所有學員進度視為 0%，避免除以零
- Filter 套用後切換 Tab 保留條件依靠 Refine `useTable` 的 React state；重整頁面或離開課程編輯頁則清空（A 方案，非 URL query string）
