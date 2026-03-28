# Power Course - 架構概覽

## 後端架構 (inc/)

### 入口與啟動
- `plugin.php` → `Plugin` class (Singleton) → `Bootstrap::instance()`
- Plugin 使用 `J7\WpUtils\Traits\PluginTrait` 和 `SingletonTrait`

### 資料層
- **自訂資料表**: `AbstractTable.php` 管理 4 張表：
  - `pc_avl_coursemeta` — 學員課程授權
  - `pc_avl_chaptermeta` — 學員章節進度
  - `pc_email_records` — 郵件發送紀錄
  - `pc_student_logs` — 學員活動日誌
- **WordPress Post Type**: `product` (WooCommerce), `pc_chapter` (章節), `pc_email` (郵件模板)

### API 層
- REST API namespace: `power-course/v2`
- 兩種 API 組織方式:
  1. `inc/classes/Api/` — 獨立 API 類別 (Course, Product, User, Comment, Upload, Option, Shortcode)
  2. `inc/classes/Resources/*/Core/Api.php` — Resource 內嵌 API (Chapter, Student, Settings, Subtitle)
- 所有 API 使用 `J7\WpUtils` 的 `ApiBase` trait，透過 `$apis` 陣列宣告路由

### Resource 模式 (inc/classes/Resources/)
每個 Resource 包含: Core (API/CPT/LifeCycle), Model, Service, Utils
- **Course**: MetaCRUD, AutoGrant, BindCourseData, ExpireDate, Limit, LifeCycle
- **Chapter**: Core (Api/CPT/LifeCycle/Loader/Templates), Model/Chapter, Subtitle/Api, Utils
- **Student**: Core (Api/ExtendQuery), Service (ExportCSV/Query)
- **Settings**: Core/Api, Model/Settings
- **StudentLog**: CRUD, StudentLog
- **Teacher**: Core/ExtendQuery

### 郵件系統 (PowerEmail)
- 獨立子模組: `inc/classes/PowerEmail/`
- Email CPT + API + Trigger 條件引擎 + Replace 變數替換
- 支援排程發送 (Action Scheduler)

### 商業邏輯
- **Order**: 訂單完成 → 自動開通課程權限 (avl_course)
- **BundleProduct**: 組合商品管理
- **Compatibility**: 版本遷移與相容性處理

## 前端架構 (js/src/)

### 入口
- `main.tsx` — 兩個 React App:
  1. App1: 管理後台 SPA (Refine.dev + React Router)
  2. App2: 前台 VidStack 視頻播放器

### 頁面 (pages/admin/)
Courses, Products, Students, Teachers, Emails, Analytics, Settings, Shortcodes, MediaLibrary, BunnyMediaLibrary

### 元件組織 (components/)
按功能領域分類: chapters, course, emails, formItem, general, layout, post, product, user

### 狀態管理
- Jotai atoms: 局部 UI 狀態 (如 sidebar collapsed, selected items)
- TanStack Query: 伺服器狀態管理 (CRUD 操作)
- Refine.dev hooks: useList, useOne, useCreate, useUpdate, useDelete

### 資料提供者
三種 data provider: `wp-rest` (wp/v2), `wc-rest` (wc/v3), `wc-store` (wc/store/v1)
