# Execution Plan — Issue #252 課程權限包（Access Pass）

> 目標 codebase：`power-course`（wp-power-course），milestone v1.8
> 核心架構決策：**compute-on-read**（觀看時即時計算，不 materialize 課程 id 到 avl_course_ids）
> 來源 clarify：`specs/clarify/2026-06-23-1656-issue252-course-permission-pack.md`

## 概覽

| 類型 | 數量 |
|------|------|
| Create | 11（3 activity 連動檔不計，新建 8 feature + 1 ui + 新 domain；entity 4 表 3 enum；api 6 endpoint 1 schema） |
| Modify | 3（課程購買開通流程.activity + erm.courses 加欄位 + api tags） |
| Delete | 0 |

## Phase 02: Entity Modeling（已於 discovery 連動寫入 erm.dbml）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | Enum access_pass_scope_type | all / category / specific |
| create | Enum access_pass_limit_mode | permanent / follow_subscription / limited |
| create | Enum access_pass_status | active / disabled |
| create | Table access_passes | CPT pc_access_pass（名稱/範圍/期限/狀態） |
| create | Table access_pass_scope_terms | category 範圍的 product_cat/product_tag 聯集 |
| create | Table access_pass_scope_courses | specific 範圍的固定課程清單 |
| create | Table pc_user_access_pass | 使用者持有權限包關係（compute-on-read 來源，含 expire_date） |
| modify | Table courses | +access_pass_id（商品掛載 meta，1:1，與 bind_courses_data 並集） |

## Phase 03: BDD Analysis（新 domain features/access-pass/，已含 Rules + Examples）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | features/access-pass/建立課程權限包.feature | command — CRUD create + scope/limit 參數驗證 |
| create | features/access-pass/更新課程權限包.feature | command — 改名/改範圍/改期限 |
| create | features/access-pass/停用課程權限包.feature | command — disable，已購保留 |
| create | features/access-pass/刪除課程權限包.feature | command — confirm 二次確認 + 收回 + 不誤砍單獨購買 |
| create | features/access-pass/查詢課程權限包.feature | query — 列表 + 已掛載商品數 + status 篩選 |
| create | features/access-pass/掛載權限包到商品.feature | command — 1:1 掛載 + 與逐課綁定並存 |
| create | features/access-pass/購買開通權限包.feature | command — 訂單完成授予持有關係（不展開 avl_course_ids） |
| create | features/access-pass/權限包觀看判定.feature | query — compute-on-read：3 scope × 3 limit × OR 疊加 × 動態範圍 |

## Phase 04: API Contract（已連動寫入 api.yml）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | GET /access-passes | listAccessPasses |
| create | POST /access-passes | createAccessPass |
| create | DELETE /access-passes | deleteAccessPass（confirm 必填） |
| create | PUT /access-passes/{id} | updateAccessPass |
| create | POST /access-passes/{id}/disable | disableAccessPass |
| create | POST /access-passes/{id}/attach | attachAccessPass |
| create | schema AccessPass | 共用回應 schema |
| modify | tags | +AccessPasses |

## Phase 05-08: Implementation（後續 planner / tdd-coordinator 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| red-green-refactor | 新 CPT pc_access_pass 註冊 + DTO + Repository | 對照既有 Announcement CPT 模式（inc/classes/Resources/Announcement/Core/CPT.php） |
| red-green-refactor | Domain Resources/AccessPass + Api/AccessPass | 繼承 ApiBase，namespace power-course/v2 |
| red-green-refactor | 觀看判定疊加層 | 在 CourseUtils::is_avl()（Utils/Course.php:531）之上/之內加「有效權限包是否涵蓋此課程」compute-on-read；連動 get_avl_status / 章節 / 留言 / 公告 / 進度的閘門 |
| red-green-refactor | 訂單開通掛鉤 | Order::add_meta_to_avl_course / add_course_item_meta_by_subscription（Resources/Order.php）讀 access_pass_id → 寫 pc_user_access_pass |
| red-green-refactor | 期限判定 | 沿用 expire_date 慣例（0/timestamp/subscription_）；limited 計算到期；follow_subscription 即時查訂閱 active/pending-cancel |
| frontend-build | js/src/pages/admin/AccessPasses/ | React 18 + Refine + Ant Design CRUD 管理頁；範圍動態警告 + 刪除影響提示 |
| frontend-build | 商品設定頁掛載權限包欄位 | Products 編輯頁加 access_pass_id 選擇器（與 bind_courses_data 並存） |

## 關鍵實作座標（discovery 已驗證）

- 觀看閘門：`inc/classes/Utils/Course.php`（is_avl L531 / is_expired L571 / get_avl_status L658 / get_avl_courses_by_user L350）
- is_avl 被消費點（blast radius）：course-product body、single-pc_chapter、Comment、Announcement、ChapterProgress Api、Chapter LifeCycle
- 開通鏈：`inc/classes/Resources/Order.php`（L207 / L64）→ `Resources/Course/LifeCycle.php`（L127）
- 訂閱有效狀態既有定義：`Resources/Course/ExpireDate.php:131`、`Utils/Course.php:594`（['active','pending-cancel']）
- CPT 範例：`inc/classes/Resources/Announcement/Core/CPT.php:83`
- 課程 taxonomy：`product_cat` / `product_tag`（WC 標準，hierarchical 子分類需展開）
