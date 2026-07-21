# Execution Plan — Issue #256 排程中的課程保留在後台課程列表

> 模式：CHANGE（Existing）。需求已完成澄清（Issue #256 第 1 輪 6 題，用戶回覆 `A A A A B A`）。
> 核心根因：後台課程列表查詢 `status` 硬編碼 `['publish', 'draft']`，缺少 `future`（排程）與 `private`（私密）。

## 已確認決策映射

| # | 決策 | 選項 | 影響 |
|---|------|------|------|
| Q1 | 排程定義範圍 | A | 僅 WordPress 原生排程發佈（`post_status = future`），不含 course_schedule / 銷售方案排程 |
| Q2 | 排程中篩選 | A | 後台列表新增「排程中」狀態篩選選項 |
| Q3 | 上架時間呈現 | A | 狀態標籤旁直接顯示預計上架日期時間文字 |
| Q4 | 標籤視覺 | A | 沿用課程公告的藍色「排程中」Tag 與用語 |
| Q5 | 是否納入 private | **B** | 一併補上 `private`，後台可管理所有非垃圾桶狀態課程 |
| Q6 | 共用查詢層 | A | REST（`Api/Course.php`）與 MCP（`Service/Query.php`）兩處一致調整 |

**最終後台查詢 status 集合：`['publish', 'draft', 'future', 'private']`**

## 紅線（驗收前必檢）

前台學員課程列表**絕不能**出現尚未上架的排程課程。前台走獨立查詢路徑
（`wc_get_products(status=publish, visibility=visible)`，見 `課程列表分頁瀏覽流程.activity`），
與後台 admin REST 為不同路徑，天然隔離；本次僅動後台路徑，前台行為不變。

## 概覽

| 類型 | 數量 |
|------|------|
| Create | 1（後台管理流程 Activity）|
| Modify | 2（查詢課程列表.feature、api.yml）|
| Delete | 0 |

## Phase 02: Entity Modeling
| 操作 | 目標 | 說明 |
|------|------|------|
| no-op | erm.dbml | 課程為 WooCommerce 商品，狀態存於 WP 核心 `wp_posts.post_status`；無新增資料表、無 schema 變更 |

## Phase 03: BDD Analysis
| 操作 | 目標 | 說明 |
|------|------|------|
| modify | features/course/查詢課程列表.feature | 新增 future/private 課程出現在後台列表、依「排程中」篩選、前台排除排程課程 的 Examples |

## Phase 04: API Contract
| 操作 | 目標 | 說明 |
|------|------|------|
| modify | api/api.yml `GET /courses` `status` 參數 | enum `[publish, draft]` → `[publish, draft, future, private]`，更新預設值描述 |

## Phase 05-07: Implementation（交棒 planner → tdd-coordinator）
| 操作 | 目標 | 說明 |
|------|------|------|
| red-green-refactor | inc/classes/Api/Course.php:144 | status 預設值補 `future`, `private` |
| red-green-refactor | inc/classes/Resources/Course/Service/Query.php:37 | 同上（MCP 課程工具路徑一致）|
| frontend | js/src/utils/constants.ts `statusOptions` | 新增「排程中(future)」選項（藍色），篩選器可選 |
| frontend | js/src/pages/admin/Courses/List/hooks/useColumns.tsx | 狀態欄 future 顯示藍色「排程中」Tag + 預計上架時間（沿用 CourseAnnouncement/StatusTag.tsx 藍色 scheduled 樣式與用語）|
| verify | 前台紅線 | E2E 驗證前台 `[pc_courses]` 不出現排程課程 |
