# 實作計劃：Issue #190 課程學員「新增學員」升級為 UserTable（course-exclude 模式）

> Phase 02+ 工程計劃 — 由 planner 產出，交接給 tdd-coordinator / react-master 執行。
>
> **範圍模式**：HOLD SCOPE（Spec §6 已定義 Step A~G，不擴張範圍）。
>
> **來源規格**（最終版，請勿重跑澄清）：
>
> - [`specs/open-issue/190-course-students-user-selector-refactor.md`](../../open-issue/190-course-students-user-selector-refactor.md)
> - [`specs/ui/課程學員管理-用戶挑選彈窗.md`](../../ui/課程學員管理-用戶挑選彈窗.md)
> - [`specs/features/student/透過彈窗挑選用戶加入課程.feature`](../../features/student/透過彈窗挑選用戶加入課程.feature)
> - [`specs/clarify/2026-04-21-1747-issue190.md`](../../clarify/2026-04-21-1747-issue190.md)

---

## 1. 概述

將「課程編輯頁 > 課程學員 tab」上方的下拉式「新增學員」UI（既有 `UserSelector`）升級為完整 `UserTable`，共用 `/admin/students` 頁面的篩選、勾選、分頁能力。新增批次到期日 DatePicker。**後端零改動**，完全沿用 `POST /power-course/v2/courses/add-students` + 既有 `students` resource。

## 2. 需求重述

給 `UserTable` 增加 `mode: 'global' | 'course-exclude'` prop。`course-exclude` 模式：

- 切 `resource` 到 `students`、`dataProviderName='power-course'`、使用 `useParsed()` 讀 courseId，`queryOptions.enabled=!!courseId` 作 guard。
- permanent filter 改為排除「已加入本課程」的人（`meta_value ne <courseId>`）。
- 保留 `Filter` / `FilterTags` / `SelectedUser` / `Table` / `rowSelection` / 分頁 20 筆。
- 隱藏：`GrantCourseAccess` / 批次到期日 / `RemoveCourseAccess` / CSV 匯出 / CSV Modal / `HistoryDrawer`。
- 新增：加入按鈕 + DatePicker（批次到期日，`showTime`、`disabledDate` 禁今天以前）。
- 成功 → `invalidate students:list` + 清 atom + 清 DatePicker；失敗 → 保留狀態、使用者可重試。

## 3. 已知風險（來自 Spec §7 + 程式碼 baseline）

| 風險 | 描述 | 等級 | 緩解 |
|------|------|------|------|
| R1 | global 模式**回歸破壞**：既有 `/admin/students` 頁面行為必須 100% 不變 | 高 | 所有新增邏輯都走「`mode === 'course-exclude'` 時才...」的條件分支；`mode` 預設 `'global'`；Step A 先只擴型別不改行為 |
| R2 | `@ts-nocheck` 現存於 `UserSelector/index.tsx` L:1-2，若新 UserTable 沿用同 pattern 會違反品質標準 | 中 | 新增區塊必須完整型別，不加 `@ts-nocheck`；`Dayjs` 型別從 `dayjs` 匯入 |
| R3 | i18n pipeline 把 `.po` 覆寫：若手改 `.po` 會被下次 build 清掉 | 高 | 新翻譯**只**寫進 `scripts/i18n-translations/manual.json`，跑 `pnpm run i18n:build` 後一起 commit 四個檔 |
| R4 | `selectedUserIdsAtom` 是全域 Jotai atom，理論上兩個 UserTable 並存會互干擾 | 低 | Spec §7 已確認兩種 mode 不會在同一頁並存，本次不改 atom 結構。未來若並存再用 atomFamily |
| R5 | 誤刪 `AddOtherCourse`（StudentTable 仍用） | 中 | 刪除時只刪 `UserSelector/` 目錄；Step E 驗收包含 `grep -r "AddOtherCourse"` 確認仍存在 |
| R6 | `SelectedUser/index.tsx` 內硬編碼繁中（「已選擇...個用戶」「清除選取」），本次不屬改動範圍但會有 i18n 違規殘留 | 低 | **不在本次範圍**。標記為後續 Issue，不阻擋本 PR |
| R7 | UserTable 掛載時 `useEffect` 會 `setSelectedUserIds([])`（L:136-139），切換 mode 不會遺留舊選擇 | — | 現有行為正確，無需改動 |

> **未發現額外已知風險**（Refine v4 / antd 5 / dayjs / @wordpress/i18n 均為既有 runtime，不引入新依賴）。

## 4. 架構變更（檔案層面）

| 動作 | 檔案路徑 | 說明 |
|------|---------|------|
| **修改** | `js/src/components/user/UserTable/index.tsx` | 新增 `mode` prop + 依 mode 切換 resource / filter / UI 區塊；新增加入按鈕 + DatePicker 邏輯 |
| **修改** | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/index.tsx` | `<UserSelector />` → `<UserTable mode="course-exclude" />`；Alert 文字精簡（刪除「up to 30 results per query」） |
| **刪除** | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/UserSelector/` | 整個目錄（僅 `index.tsx` 一檔） |
| **修改** | `scripts/i18n-translations/manual.json` | 新增 5 條 msgid → zh_TW 對照 |
| **產出（由 pipeline 自動）** | `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json` | `pnpm run i18n:build` 自動更新 |

**不動**：`StudentTable/`、UserTable 的 `Filter/` / `SelectedUser/` / `HistoryDrawer/` / `CsvUpload/` / `hooks/useColumns.tsx` / `atom.tsx`。

## 5. 資料流分析

### 5.1 掛載 / courseId 解析流程

```
URL /courses/edit/100  ─▶  useParsed()  ─▶  { id: '100' }
                             │
                             ▼
             UserTable(mode='course-exclude')
                             │
                             ├─▶ queryOptions.enabled = !!courseId
                             │        │
                             │        ▼
                             │   [courseId missing?]  ─▶  不發 API（等 URL resolve）
                             │
                             ├─▶ resource='students' + dataProviderName='power-course'
                             │
                             └─▶ permanent filter = [
                                   meta_key eq 'avl_course_ids',
                                   meta_value ne courseId,
                                   meta_keys eq ['formatted_name'],
                                 ]
```

**Shadow paths**：

- nil：`courseId === undefined` → query disabled，不掉 API（見 feature §Rule 2）
- empty：API 回 `data: []` → Table 顯示 "No data"（antd 預設）
- error：API 500 → Refine 預設 error handler（現有行為，沿用）

### 5.2 勾選 + 加入流程

```
User clicks row checkbox
         │
         ▼
useRowSelection.onChange ─▶ setSelectedUserIds(newArray)  (Jotai atom)
         │                           │
         │                           ▼
         │                  按鈕 label = sprintf('Add %s students to this course', N)
         │                  按鈕 disabled = (N === 0 || isLoading)
         │
User picks date (optional)
         │
         ▼
setExpireDate(dayjs | undefined)  (useState)
         │
         ▼
User clicks 加入按鈕
         │
         ▼
useCustomMutation.mutate({
  url: `${apiUrl}/courses/add-students`,
  method: 'post',
  values: {
    user_ids: selectedUserIds,
    course_ids: [courseId],
    expire_date: expireDate ? expireDate.unix() : 0,
  }
})
         │
    ┌────┴────┐
    ▼         ▼
onSuccess   onError
    │         │
    │         └─▶ message.error + 保留 atom + 保留 DatePicker
    │
    ├─▶ message.success (key='add-students')
    ├─▶ invalidate({ resource:'students', dataProviderName:'power-course', invalidates:['list'] })
    ├─▶ setSelectedUserIds([])      ← 副作用：既有 useEffect 清 selectedRowKeys
    └─▶ setExpireDate(undefined)
          │
          ▼
上方 UserTable（course-exclude）refetch → 剛加入的人消失（被 meta_value ne courseId 濾掉）
下方 StudentTable refetch → 剛加入的人出現（meta_value eq courseId）
```

**Shadow paths**：

- nil：`courseId === undefined` 時按鈕應 disabled（Spec 未強制，但 guard 已在 `queryOptions.enabled` 阻擋 list，加入按鈕也要同步 disable 避免送 `course_ids: [undefined]`）
- empty：`selectedUserIds.length === 0` → 按鈕 disabled
- error：API 失敗 → 保留勾選讓使用者重試
- stale：用戶在 mutation 飛行中切換頁 → Refine 會自動 cancel，但 message 仍會觸發；接受現狀

## 6. 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|----------|-------------|---------|---------|-----------|
| `useTable({ resource:'students', ... })` | `courseId === undefined` | 前置條件缺失 | `queryOptions.enabled = !!courseId` 阻止發查 | 否（Table loading 空白） |
| `useTable` list API 500 | 網路/後端錯誤 | HTTP | Refine 預設 errorNotification（既有） | 是（notification） |
| `useCustomMutation` `/courses/add-students` | 權限不足 / 資料驗證失敗 / 網路 | HTTP 4xx/5xx | `onError` → `message.error('Failed to add students to course', key:'add-students')`；保留 atom / DatePicker | 是（message.error） |
| 加入按鈕點擊 | `courseId` 意外為空（競態） | 前置條件缺失 | 按鈕 disabled 條件加 `!courseId`；若仍漏，`onError` 兜底 | 否（按鈕不可按） |
| DatePicker onChange | 傳入非 Dayjs 或 null | 型別不符 | 以 `value ?? undefined` 容錯 | 否 |
| `invalidate()` | QueryClient 異常（極罕見） | Runtime | 不 catch，交給 React ErrorBoundary（現有） | 否（通常不會發生） |

> **CRITICAL GAP 檢查**：無「處理方式=無 + 使用者可見=靜默」的組合。

## 7. 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|----------|---------|--------|--------|-----------|---------|
| `mode='global'` 掛載在 `/admin/students` | 意外切到 `students` resource 造成回歸 | ✅（預設值 + 條件分支） | ✅（feature Rule §後置狀態/mode 切換驗證） | — | 無需恢復，測試守住 |
| `mode='course-exclude'` 掛載但 `courseId=undefined` | 發出空查詢或送壞的 course_ids | ✅（`enabled` guard + 按鈕 disabled guard） | ✅（feature Rule §前置狀態/Rule 2） | 否 | queryOptions.enabled 擋下 |
| 連續快速雙擊加入按鈕 | 重複送 `add-students` request | ✅（`loading={isLoading}` + disabled） | 部分（E2E 可補） | 是（loading spinner） | Refine 自動管狀態 |
| 加入成功但 invalidate 前 user 已離開頁面 | React unmount warning | ✅（React 18 suppress unmount setState warning） | 否 | 否 | 無副作用 |
| DatePicker 選了過去的時間（繞過 disabledDate） | 後端收到過期 timestamp | ⚠️ 前端已 disable，後端邏輯**不在本次範圍** | 部分（feature Rule §前置參數） | 否 | 後端 API 若接受，該學員立即過期（既有行為） |
| 刪除 `UserSelector/` 後仍有未清的 import | 編譯錯誤 | ✅（`pnpm run lint:ts` + `pnpm run build`） | ✅（build 階段） | — | lint / build 阻擋 |
| i18n msgid 漏進 manual.json | zh_TW 介面 fallback 顯英文 | ✅（Step D 明確列 5 條） | 手動驗收 | 是 | 跑 `i18n:build` 後 grep `.po` 確認 msgstr 非空 |
| Vite bundle 未經 @wordpress/i18n shim | runtime 讀不到翻譯 | ✅（`vite.config-for-wp.ts` 已設 alias，本次不改 config） | — | — | 既有設定無需改 |

## 8. 實作步驟（對應 Spec §6 Step A~G）

> **命名固定為 Step A~G**，不要重新命名以免與 Spec 失去映射。每張卡片詳見 `todo/step-X.md`。

### Phase 1：前置骨架（零行為變更）

#### Step A — UserTable `mode` prop 骨架
- **目標**：擴充型別 + 預設值，確保 `mode='global'` 行為 100% 不變。
- **檔案**：`js/src/components/user/UserTable/index.tsx`
- **變更類型**：新增 prop、不改 runtime 邏輯。
- **依賴**：無。
- **風險**：低。
- **驗收**：`pnpm run lint:ts` + `pnpm run format --check` + `pnpm run build` 全綠，`/admin/students` 頁面視覺/行為零變化。

### Phase 2：resource 與 filter 切換（核心邏輯）

#### Step B — permanent filter + useParsed + enabled guard
- **目標**：依 `mode` 切換 `resource` / `dataProviderName` / `permanent filter` / `queryOptions.enabled`。
- **檔案**：`js/src/components/user/UserTable/index.tsx`
- **變更類型**：useTable 設定擴充（條件式產生 config object）。
- **依賴**：Step A。
- **風險**：中（回歸風險：global 模式的 resource/filter 必須未變）。
- **驗收**：暫時在 `CourseStudents` 試掛 `<UserTable mode="course-exclude" />`（暫不刪 UserSelector），確認 Table 資料來自 `students` resource 且已排除 courseId=100 的學員。

### Phase 3：UI 條件式 render（隱藏 global 專屬區塊）

#### Step C — course-exclude 模式的 UI 取捨
- **目標**：`GrantCourseAccess` / 批次到期日 / `RemoveCourseAccess` / CSV 匯出 / CSV Modal / `HistoryDrawer` 改為「`mode === 'global' && canGrantCourseAccess`」才顯示。
- **檔案**：`js/src/components/user/UserTable/index.tsx`
- **變更類型**：JSX 條件分支調整。
- **依賴**：Step B。
- **風險**：中（`HistoryDrawer` 是獨立元件，需確認不在 course-exclude 模式渲染即不觸發 Drawer 開關）。
- **驗收**：`mode='course-exclude'` 下 6 個區塊全隱藏；`mode='global' + canGrantCourseAccess=true` 下全顯示。

### Phase 4：新增加入按鈕 + DatePicker + mutation

#### Step D — 加入按鈕 + DatePicker + useCustomMutation + i18n
- **目標**：新增 `course-exclude` 模式專屬的加入按鈕 + DatePicker 區塊，連接 `POST /courses/add-students`。完整 i18n 流程（含 `manual.json` + `pnpm run i18n:build`）。
- **檔案**：
  - `js/src/components/user/UserTable/index.tsx`
  - `scripts/i18n-translations/manual.json`
  - `languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json`（pipeline 產物）
- **變更類型**：新增 state + JSX + mutation handler + i18n 資料。
- **依賴**：Step C。
- **風險**：中（DatePicker unix timestamp 換算、i18n pipeline 執行）。
- **驗收**：
  - 未選 DatePicker → payload.expire_date === 0
  - 選未來日期 → payload.expire_date === Dayjs.unix()
  - `disabledDate` 禁今天以前
  - 成功：message + invalidate + atom 清空 + DatePicker 清空
  - 失敗：保留 atom + DatePicker
  - `pnpm run i18n:build` 後 `grep 'Add %s students to this course' languages/power-course-zh_TW.po` 有對應 msgstr

### Phase 5：落地替換 + 清理

#### Step E — CourseStudents 改用 UserTable + 刪除 UserSelector
- **目標**：`CourseStudents/index.tsx` 的 `<UserSelector />` → `<UserTable mode="course-exclude" />`；刪除 `UserSelector/` 目錄；精簡 Alert 文字。
- **檔案**：
  - `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/index.tsx`
  - `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/UserSelector/` **（整個目錄刪除）**
- **變更類型**：import / JSX 替換 + 目錄刪除。
- **依賴**：Step D。
- **風險**：中（R5：避免誤刪 `AddOtherCourse`）。
- **驗收**：
  - `grep -r "UserSelector" js/src` 零命中（除非是 user 目錄下的 `SelectedUser` 元件，須確認字串不同）
  - `grep -r "AddOtherCourse" js/src` 仍命中（StudentTable 使用中）
  - 課程編輯頁「課程學員」tab 視覺正確（UserTable 在上、StudentTable 在下）

### Phase 6：品質把關

#### Step F — 全綠閘門
- **目標**：lint / format / phpstan / build 全綠。
- **檔案**：無新增；跑驗證指令。
- **變更類型**：驗證。
- **依賴**：Step E。
- **風險**：低。
- **驗收**：
  - `pnpm run lint:ts` 零 error
  - `pnpm run format` 無未格式化檔
  - `composer run phpstan`（level 9）無新 error
  - `pnpm run build` 成功
  - UserTable 中無新增 `@ts-nocheck` 或 `as any`

### Phase 7：E2E 補測（Phase 06，可延後）

#### Step G — Playwright E2E
- **目標**：依 feature 規格補 2 條 E2E（成功加入、expire_date=0 加入）。
- **檔案**：`tests/e2e/admin/` 新增測試檔。
- **變更類型**：新增測試。
- **依賴**：Step F。
- **風險**：低。
- **驗收**：`pnpm run test:e2e:admin` 新測試通過。

## 9. Dependency Graph

```
Step A (型別骨架)
   │
   ▼
Step B (resource / filter / enabled guard)
   │
   ▼
Step C (UI 條件式 render)
   │
   ▼
Step D (加入按鈕 + DatePicker + i18n)  ◄── i18n pipeline 在這裡同步跑
   │
   ▼
Step E (替換 + 刪除 UserSelector)
   │
   ▼
Step F (lint / format / phpstan / build 全綠)
   │
   ▼
Step G (Playwright E2E — 可延後至 Phase 06)
```

**Critical Path**：A → B → C → D → E → F（六步**必須序列**，因為全部改同一檔 `UserTable/index.tsx` 的 runtime 行為，且 E 依賴 D 完成 mutation 流程）。

**可平行**：無（Step G 是 Phase 06 範圍，可與本 PR 解耦；若本 PR 要完整交付則 G 序列在 F 後）。

## 10. 預估工時（粗略）

| Step | 預估（小時） | 說明 |
|------|------------|------|
| Step A | 0.5 | 純型別擴充 |
| Step B | 1.5 | useTable config 條件式 + useParsed + enabled guard |
| Step C | 1.0 | JSX 條件分支改寫 |
| Step D | 3.0 | 新 state + 按鈕 + DatePicker + mutation + i18n pipeline（含 `pnpm run i18n:build` 與四檔 diff 確認） |
| Step E | 0.5 | 替換 + 刪目錄 + grep 驗收 |
| Step F | 1.0 | lint / format / phpstan / build（含修小問題） |
| Step G | 2.0 | Playwright 2 條 E2E |
| **合計** | **9.5h** | 不含 code review 往返；單人連續作業預估 1.5~2 個工作日 |

## 11. 建議執行者

| Step | 建議執行者 | 理由 |
|------|----------|------|
| A | `@zenbu-powers:react-master`（或 tdd-coordinator 分派） | 純型別擴充，react-master 直接落 |
| B | `@zenbu-powers:react-master` | Refine useTable config 專業 |
| C | `@zenbu-powers:react-master` | JSX 條件 render |
| D | `@zenbu-powers:react-master` + i18n 規範 skill `wordpress-i18n` | 含 i18n pipeline，需嚴格遵 rule |
| E | main agent 自理 或 `@zenbu-powers:react-master` | 替換 + 目錄刪除，簡單 |
| F | `@zenbu-powers:react-reviewer`（審）+ `@zenbu-powers:react-master`（修） | 品質審查 |
| G | `@zenbu-powers:test-creator` | E2E 專業 |

> **整體協調**：建議由 `@zenbu-powers:tdd-coordinator` 拿本計劃，先分派 Step A~D 給 `@zenbu-powers:react-master` 連續做（同一檔改動集中），再由 main agent 做 Step E，最後 Step F 交 reviewer。Step G 可延後，視 Phase 06 規劃獨立 PR。

## 12. 測試策略

> **Unit test**：專案目前無前端 unit test（見 `.claude/rules/react.rule.md`），本次**不新增** unit test。

- **型別 / 編譯測試**：`pnpm run build`（TypeScript strict）
- **Lint**：`pnpm run lint:ts` / `pnpm run format`
- **Static analysis**：`composer run phpstan`（level 9，本次零後端改動，應無新 error）
- **E2E（Playwright）**：Step G 補兩條（指令 `pnpm run test:e2e:admin`）：
  1. 課程編輯頁 → 搜尋 → 勾選 → 設到期日 → 加入 → 驗上下兩表同步 refetch + atom 清空
  2. 課程編輯頁 → 搜尋 → 勾選 → **不**選 DatePicker → 加入 → 驗 `expire_date=0`
- **手動回歸**：
  - `/admin/students` 全局學員管理頁全功能（CSV 匯出、批次到期日、RemoveCourseAccess、HistoryDrawer）
  - 課程編輯頁「課程學員」tab 新 UI 完整使用流程
  - 中英文兩語系顯示正確

**關鍵邊界情況**（由 Step G E2E 或手動回歸覆蓋）：

- `courseId` 尚未解析時不應發查（Rule 2）
- 零勾選時按鈕 disabled（Rule 3 Example 1）
- DatePicker 過去日期 disabled（Rule 4）
- 失敗後保留狀態可重試（後置 Rule 5）
- global 模式無回歸（後置 Rule 7）

## 13. 依賴項目

| 類別 | 項目 | 版本 | 現有/新增 |
|------|-----|------|----------|
| npm | `@wordpress/i18n` | 依 package.json | 現有 |
| npm | `dayjs` | 依 package.json | 現有 |
| npm | `antd` | 5.x | 現有（DatePicker / Button / message） |
| npm | `@refinedev/core` | 4.x | 現有（`useParsed`, `useCustomMutation`, `useInvalidate`, `useApiUrl`, `useTable`） |
| npm | `jotai` | 依 package.json | 現有（`selectedUserIdsAtom`） |
| 外部 API | `POST /wp-json/power-course/v2/courses/add-students` | — | 現有，零改動 |
| 外部 Skill | `wordpress-i18n` | — | 已安裝，Step D 需遵 |

**不引入新依賴**。

## 14. 錯誤處理策略

- **前端**：沿用 Refine 的 `onError` callback（`useCustomMutation`）+ antd `message.error`；失敗時**不 reset** UI 狀態，讓使用者重試。
- **後端**：本次零改動；既有 `POST /courses/add-students` 的驗證與錯誤格式沿用。
- **edge case 防線**：
  - `queryOptions.enabled = !!courseId` 阻止無 courseId 發查
  - 加入按鈕 `disabled = selectedRowKeys.length === 0 || isLoading || !courseId`
  - DatePicker `disabled = isLoading`（避免飛行中被改值）
  - message 用 `key: 'add-students'` 避免多則堆疊

## 15. 限制條件（此計劃不做的事）

- ❌ **不改後端** PHP / REST / resource filter 語意
- ❌ **不改** `selectedUserIdsAtom` 結構（不引入 atomFamily）
- ❌ **不改** `SelectedUser/index.tsx` 內的硬編碼繁中（R6 — 留待後續 i18n 補強 Issue）
- ❌ **不新增** `subscription_{id}` 到期日格式 UI（Spec §2.5）
- ❌ **不新增** `search_field`（email / name / id 窄化）(Spec §5)
- ❌ **不新增** 前端 unit test 框架
- ❌ **不改** Vite config / shim 設定（既有已正確）
- ❌ **不改** `StudentTable/` 任何檔案
- ❌ **不改** `vendor/` 任何檔案

## 16. 成功標準

依 Spec §3 驗收標準全數勾選：

- [ ] Spec §3.1 功能驗收 10 項全通過
- [ ] Spec §3.2 i18n 驗收 5 項全通過
- [ ] Spec §3.3 程式碼品質驗收 4 項全通過
- [ ] Spec §3.4 E2E 驗收 2 項（Step G，可獨立 PR）
- [ ] feature file 的 13 Example 全部可被 E2E 或手動回歸覆蓋
- [ ] Dependency Graph 中 A→B→C→D→E→F 路徑全綠

## 17. 預估複雜度：**中**

- 變更集中單檔（`UserTable/index.tsx`），無跨模組協作
- 後端零改動 + 無新依賴 = 降低不確定性
- 唯一複雜點：i18n pipeline（Step D 含 `manual.json` 維護 + 四檔 commit）、global mode 零回歸保證
- 整體為 HOLD SCOPE 型重構 + 小幅擴充，非全新功能

## 18. 新發現的 TBD / 風險

**無新增 TBD**。澄清紀錄（`specs/clarify/2026-04-21-1747-issue190.md`）已涵蓋所有關鍵決策，Spec §7 已羅列風險。

**規劃階段觀察到但不阻擋本 PR 的後續 Issue**：

- 🆕 **後續 Issue 候選**：`SelectedUser/index.tsx` L:18-32 硬編碼繁中字串（「已選擇...個用戶」「清除選取」「顯示已選用戶」），違反 `i18n.rule.md` 第 1 條。建議另開 Issue 統一 i18n 化。**不阻擋本 PR**（因非本次改動範圍，原樣保留）。
- 🆕 **Technical debt**：`UserSelector/index.tsx` 既存的 `@ts-nocheck` 隨目錄一併刪除，正面效益。

---

## Appendix A — 關鍵程式碼位置索引

| 引用點 | 檔案:行號 | 用途 |
|--------|----------|------|
| UserTable Props 定義 | `js/src/components/user/UserTable/index.tsx:38-46` | Step A 擴 `mode` 入口 |
| UserTable useTable config | `js/src/components/user/UserTable/index.tsx:49-76` | Step B 改 `resource/filters` |
| rowSelection + atom 同步 | `js/src/components/user/UserTable/index.tsx:82-139` | Step D 不動，但觀察 `selectedUserIds.length===0 → setSelectedRowKeys([])` 作為成功後清空打勾的副作用 |
| canGrantCourseAccess 條件 render 集中區 | `js/src/components/user/UserTable/index.tsx:270-326, 356-366, 368` | Step C 改條件為 `mode==='global' && canGrantCourseAccess` |
| SelectedUser 插入點 | `js/src/components/user/UserTable/index.tsx:328-339` | Step D 加入按鈕 + DatePicker 放此附近 |
| DatePicker disabledDate 範本 | `js/src/components/emails/SendCondition/Specific.tsx:25-28` | Step D 直接複製 |
| `useCustomMutation` 既有呼叫範本 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/UserSelector/index.tsx:82-120` | Step D 可大幅複製（刪前先抄 payload 結構） |
| useParsed 讀 courseId 範本 | `js/src/components/user/UserTable/hooks/useColumns.tsx:2, 24` | Step B 驗證 UserTable 已可用 `useParsed` |
| CourseStudents 組裝點 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/index.tsx:5-46` | Step E 改 import + JSX + Alert |
| i18n 規範 | `.claude/rules/i18n.rule.md` | Step D 必讀 |
| i18n pipeline 指令 | `.claude/CLAUDE.md`「全域建置指令 > i18n」 | Step D 跑 `pnpm run i18n:build` |
| manual.json 增量範本 | `specs/open-issue/190-course-students-user-selector-refactor.md` §4.2 | Step D 抄 5 條 k/v |

## Appendix B — 計劃自檢（警示訊號 checklist）

- [x] 步驟都有明確檔案路徑
- [x] 各階段可獨立驗證（Step A~F 每步皆有驗收指令）
- [x] 非平凡資料流已繪製 ASCII 圖（§5.1 掛載流、§5.2 加入流）
- [x] 副作用方法有錯誤處理登記表（§6）
- [x] 有測試策略（§12）
- [x] 使用者互動邊界情況已分析（重複點擊、過去日期、courseId 未解析、失敗重試）
- [x] 無硬編碼值（i18n 全走 msgid、courseId 從 `useParsed` 取、pageSize 20 與全局一致）
- [x] 無預期的過大函式（UserTable 已 memo，新增區塊以局部 const 拆解）
- [x] 無過深嵌套（條件 render 以 early return + 單層 ternary）
- [x] 計劃不含無法獨立交付的階段（Step G 可獨立 PR）
- [x] 已說明 HOLD SCOPE 模式不擴張（§0 頂部 + §15 限制條件）
