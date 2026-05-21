# Issue #226 — 課程學員 TAB UI 調整：「顯示全部」switch 移至欄位標題列

> 狀態：**Discovery Clarify Complete（Phase 01 完成，待落地實作）**
> 澄清紀錄：[`specs/open-issue/clarify/2026-05-21-1044.md`](./clarify/2026-05-21-1044.md)
> 相關 UI 規格：[`specs/ui/課程學員管理-用戶挑選彈窗.md`](../ui/課程學員管理-用戶挑選彈窗.md)（本次不變更上半部「新增學員」UserTable）
> 相關 Feature 規格：[`specs/features/student/查詢學員列表.feature`](../features/student/查詢學員列表.feature)
> 新增 Feature 規格：[`specs/features/student/課程學員列表批次切換顯示全部課程.feature`](../features/student/課程學員列表批次切換顯示全部課程.feature)

---

## 1. 目標

把 `/wp-admin/admin.php?page=power-course#/courses/edit/{course_id}` 頁面「課程學員」TAB 中，目前**每一個 user row 各自帶有的**「顯示全部」switch，**統一上提到 `Granted courses` 欄位的表頭（th）位置**，並改為「**一次套用到全部 user**」的批次切換。

## 2. 核心決策（Non-Negotiable）

### 2.1 後端零改動

- 不新增 / 不修改任何 REST API。
- 不變更 `students` resource 的 query 行為。
- 既有的 `avl_courses` payload 結構維持不變（`AvlCoursesList` 渲染時只是套不同 filter 顯示）。

### 2.2 元件職責重新分配

| 元件 | 原職責 | 改造後職責 |
|------|--------|-----------|
| `AvlCoursesList` (`js/src/components/user/AvlCoursesList/index.tsx`) | 內部管理 `showAllCourses` state + 渲染 switch | **純展示元件**：接收 `showAllCourses: boolean` prop 由外部控制；**移除** `showToggle` prop 與內部 switch |
| `useColumns` (`js/src/components/user/UserTable/hooks/useColumns.tsx`) | 純定義 columns | 內部以 `useState` 管理 `showAllCourses`，在 `Granted courses` column 的 `title` slot 渲染「欄位標題 + Switch」；render 時把 state 傳給 `AvlCoursesList` |
| `StudentTable` (`js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/index.tsx`) | 透過 `useColumns()` 取得 columns | **無變動**（state 全部封裝在 `useColumns` 內） |

### 2.3 UI 規格

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│  Student        │ Granted courses     [Show all 🔘]  │ Registered at             │
├──────────────────────────────────────────────────────────────────────────────────┤
│  Alice          │ #100 PHP 基礎課  ... [Learning history]  ...                   │
│  劉小明         │                                                                 │
├──────────────────────────────────────────────────────────────────────────────────┤
│  Bob            │ #100 PHP 基礎課  ... [Learning history]  ...                   │
│  WangBob        │                                                                 │
└──────────────────────────────────────────────────────────────────────────────────┘
```

- Switch 位於 `Granted courses` 表頭，**靠右對齊**，前置文字 `Show all` + Tooltip（沿用既有 i18n key `Show all courses granted to the user`）
- Switch `size="small"`，沿用既有視覺
- 預設 OFF（只看本課程）；切換 ON → 全部 row 同步展開為「全部已授權課程」
- **個別 row 內不再渲染 switch**

### 2.4 狀態管理範圍

- 使用 `useState<boolean>(false)` 在 `useColumns` 內管理，**不寫入 localStorage / atom**
- 切換 tab、重新整理頁面、跳轉到其他課程的 Edit 頁 → 全部重置為預設 OFF
- 用戶問題 Q3 已確認此為刻意行為（最單純、無 side effect）

### 2.5 i18n

- 沿用既有英文 msgid：
  - `Granted courses`（欄位標題，既存）
  - `Show all`（switch 標籤，既存於 `AvlCoursesList`）
  - `Show all courses granted to the user`（Tooltip，既存於 `AvlCoursesList`）
- **無需新增字串**，無需重跑 `pnpm run i18n:build`

### 2.6 適用範圍

| 頁面 | 是否受影響 | 理由 |
|------|----------|------|
| `/courses/edit/{course_id}` 課程學員 TAB | ✅ 本次改造目標 | `StudentTable` → `useColumns` → `AvlCoursesList` |
| `/admin/students` 全局學員管理 | ⚠️ 連帶受影響 | 同樣透過 `useColumns` 渲染。需確認新版本在 `currentCourseId` 為 `undefined` 時，switch 是否隱藏（無「只看本課程」可切，應預設隱藏 switch 並永遠展開全部） |
| `/teachers/edit/{teacher_id}` Learning Tab | ❌ 不受影響 | 未經 `useColumns`，直接使用 `<AvlCoursesList showToggle=false>`（注意：本次會移除 `showToggle` prop，需同步調整呼叫端為「不傳 prop」並以 `showAllCourses=true` 永遠展開全部） |

> **關鍵調整**：`useColumns` 內偵測 `currentCourseId` 是否存在來決定 switch 是否渲染。
> - `currentCourseId` 存在（Course Edit 頁）→ 渲染 switch，預設 OFF
> - `currentCourseId` 不存在（`/admin/students` 全局頁）→ **不渲染 switch**，固定 `showAllCourses=true`（與既有 `AvlCoursesList` 在 `!currentCourseId` 時的 fallback 一致）

## 3. 不在本次範圍

- StudentTable 的其他 UI（DatePicker / Update watch time / Export CSV / Remove access 等）
- `AddOtherCourse`、`HistoryDrawer` 元件
- 後端 API、`students` resource filter
- Teacher Edit 頁的 Learning Tab UI（除了 `showToggle` prop 拆除這一個 mechanical 修改）

## 4. 驗收標準（Acceptance Criteria）

- [ ] **AC-1**：`/wp-admin/admin.php?page=power-course#/courses/edit/{course_id}` 課程學員 TAB 的 `Granted courses` 欄位表頭出現「Show all + Switch」
- [ ] **AC-2**：預設 switch 為關閉（OFF），所有 user row 的 `Granted courses` 只顯示本課程
- [ ] **AC-3**：切換 switch 為開啟（ON），**所有** user row 同步展開為「該 user 全部已授權課程」
- [ ] **AC-4**：再次切換為 OFF，所有 row 收回為「只看本課程」
- [ ] **AC-5**：個別 user row 內**不再渲染** switch（DOM 中應只剩 1 個 `Show all` switch，在 th 內）
- [ ] **AC-6**：重新整理頁面、切換 tab 或跳到其他課程 Edit 頁後，switch 重置為 OFF
- [ ] **AC-7**：`/admin/students` 全局學員管理頁的 `Granted courses` 欄位**不顯示** switch，每個 user 的 `avl_courses` 直接展開全部（與既有行為一致）
- [ ] **AC-8**：`/teachers/edit/{teacher_id}` Learning Tab 行為完全不變（永遠展開全部已授權課程）
- [ ] **AC-9**：i18n msgid 與翻譯零變動，`languages/power-course.pot` 跑 `pnpm run i18n:pot` 後無 diff
- [ ] **AC-10**：TypeScript `pnpm run build` / ESLint `pnpm run lint:ts` 全綠

## 5. 影響檔案清單（預估）

| 檔案 | 動作 |
|------|------|
| `js/src/components/user/AvlCoursesList/index.tsx` | **修改**：移除內部 switch + state，新增 `showAllCourses` prop，移除 `showToggle` prop |
| `js/src/components/user/UserTable/hooks/useColumns.tsx` | **修改**：新增 `showAllCourses` useState，`Granted courses` column 的 `title` 改為 ReactNode 渲染「標題 + Switch」（僅當 `currentCourseId` 存在時） |
| `js/src/pages/admin/Teachers/Edit/**` | **修改**（如有用到 `AvlCoursesList`）：拆除 `showToggle={false}` prop，改為 `showAllCourses={true}` |
| `tests/e2e/admin/**` | **新增**（如需要）：E2E 驗證 switch 行為 |

## 6. 風險與緩解

| 風險 | 緩解 |
|------|------|
| 移除 `showToggle` 是 breaking change，可能漏改 caller | 全專案 grep `showToggle` 與 `<AvlCoursesList`，逐一更新；TypeScript strict mode 會在 build 期擋下 |
| Ant Design Table 的 `column.title` 接受 ReactNode 但需注意排序 icon 等內建功能不受影響 | 使用 `<div className="flex justify-between items-center">` 包裹，sorter 在此欄不啟用，無衝突 |
| `useColumns` 加入 useState 後，呼叫端如為 memo 元件需確認重 render 行為正常 | `StudentTable` 已 `memo()` 包裹但 `useColumns()` 內部 state 變動會觸發 column 重建，Ant Design Table 會正確 re-render |

## 7. 下一步

待 planner 接手規劃實作步驟 → tdd-coordinator 安排 Red/Green/Refactor → react-master 落地實作。
