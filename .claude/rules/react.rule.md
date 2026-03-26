---
paths:
  - "**/*.{ts,tsx}"
---

# React / TypeScript 前端開發規範

## 元件寫法

- 只使用 Functional Components，禁止 Class Components
- 元件檔案統一放在 `index.tsx`，目錄名即元件名
- 元件匯出使用 named export，頁面級元件可用 default export
- JSX 中使用 Ant Design / Ant Design Pro Components（ProTable, ProForm, ProDescriptions 等）

```tsx
// 正確：目錄結構
// components/general/FileUpload/index.tsx
export const FileUpload = () => { ... }

// 正確：頁面級
// pages/admin/Courses/List/index.tsx
const CoursesList = () => { ... }
export default CoursesList
```

## TypeScript 規範

- 路徑別名：`@/` 對應 `js/src/`，永遠使用 `@/` 引用內部模組
- 型別定義放 `js/src/types/`，按 API 來源分類（wpRestApi, wcRestApi, wcStoreApi）
- 避免 `any`（ESLint warn），優先定義明確的 `interface` 或 `type`
- 未使用參數以 `_` 前綴命名（如 `_event`）

## 格式化規則（Prettier + ESLint 強制）

- Tab 縮排（tabWidth: 2）
- 不使用分號
- 單引號
- 尾逗號（所有位置）
- import 順序：builtin > external > internal > parent > sibling > index（ESLint 強制，需空行分隔）

## Hooks 規範

- `react-hooks/rules-of-hooks`: error（嚴格執行）
- `react-hooks/exhaustive-deps`: warn（注意依賴陣列完整性）
- 自訂 Hooks 放 `js/src/hooks/` 或元件目錄內的 `hooks/` 子目錄
- 命名統一 `use` 前綴：`useCourseSelect`, `useColumns`, `useRevenue`

## 狀態管理

- **Jotai atoms**: 局部 UI 狀態（sidebar collapse、selected items、form state）
  - atom 檔案命名為 `atom.tsx`，放在對應功能目錄內
- **TanStack Query 4.x**: 所有伺服器端資料的 fetch/mutate
- **Refine.dev hooks**: `useList`, `useOne`, `useCreate`, `useUpdate`, `useDelete`, `useTable` , `useCustom`, `useCustomMutation` 等（詳見下方 Refine.dev 資料存取規範）
  - 透過 resource name 對應 API 端點
  - DataProvider 類型：`wp-rest` | `wc-rest` | `wc-store`

## Refine.dev 資料存取規範

### SKILL 優先參考

進行任何 Refine.dev 相關開發時，**必須**優先參考以下 SKILL 文件（位於 `~/.claude/skills/refine/references/`）：

| SKILL 文件 | 用途 |
|------------|------|
| `references/app-setup.md` | Refine App 初始化、Provider 配置 |
| `references/data-provider.md` | Data Provider 介面與自訂實作 |
| `references/rest-data-provider.md` | REST Data Provider 設定與 API 對接 |
| `references/auth-provider.md` | Auth Provider 認證流程 |
| `references/data-hooks.md` | `useList`, `useOne`, `useCreate`, `useUpdate`, `useDelete`, `useTable` , `useCustom`, `useCustomMutation` 等 Data Hooks |
| `references/antd-crud.md` | Ant Design 整合 CRUD 元件（List, Show, Edit, Create） |

### 禁止直接 API 呼叫

- **禁止**使用 `fetch`、`axios`、`ky` 或其他 HTTP 客戶端直接呼叫 API
- **所有資料讀取與寫入必須透過 Refine 的 Data Provider + Data Hooks** 進行
- 這確保與 Refine 的快取機制、樂觀更新、錯誤處理、loading 狀態管理完整整合
- 唯一例外：Refine Data Provider 本身的實作內部可使用 HTTP 客戶端

```tsx
// ❌ 錯誤：直接使用 fetch
const response = await fetch('/wp-json/power-course/v2/courses')

// ❌ 錯誤：直接使用 axios
const { data } = await axios.get('/wp-json/power-course/v2/courses')

// ✅ 正確：使用 Refine Data Hook
const { data } = useList({ resource: 'courses' })

// ✅ 正確：使用 Refine Data Hook 搭配 DataProvider 類型
const { data } = useList({
  resource: 'products',
  meta: { dataProviderName: 'wc-rest' },
})
```

## Refine.dev 資源定義

資源定義在 `js/src/resources/index.tsx`，對應後端 API：
- courses, chapters, teachers, students, products, shortcodes, settings, analytics, emails, media-library, bunny-media-library

## 前端環境變數

環境變數透過 `window.power_course_data.env` 注入（PHP `wp_localize_script`），前端使用 `simpleDecrypt` 解密：
```tsx
import { env, API_URL } from '@/utils/env'
```

## 元件庫慣例

- **表格**: 使用 `ProTable` 搭配自訂 `useColumns` hook
- **表單**: 使用 `ProForm` / `ProFormFields`
- **刪除確認**: 使用 `PopconfirmDelete` 元件
- **檔案上傳**: 使用 `FileUpload` 或 `OnChangeUpload` 元件
- **使用者選擇**: `UserTable` + `UserDrawer` 組合
- **視頻輸入**: `VideoInput` 元件支援 Bunny/Vimeo/YouTube/Code/Iframe 五種模式

## 前端測試

目前無前端 unit test，品質依賴 ESLint + TypeScript strict mode + E2E 測試。

```bash
pnpm run lint:ts     # ESLint 檢查並自動修正
pnpm run format      # Prettier 格式化
pnpm run build       # TypeScript 編譯驗證
```
