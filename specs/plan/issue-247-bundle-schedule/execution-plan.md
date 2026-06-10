# Execution Plan — Issue #247 銷售方案自動上下線時間

> 需求：站長可為銷售方案（Bundle 商品）設定「自動上線時間」與「自動下線時間」，
> 到點由背景排程自動切換 publish ↔ draft，省去人工守時上下架。

## 已澄清決策（用戶回覆 `B B B A A A A`）

| # | 決策 | 內容 |
|---|------|------|
| Q1 | B | 上線 + 下線一起做（對稱的自動上線時間 + 自動下線時間） |
| Q2 | B | 自動下線那一刻，連購物車內未結帳項目一併失效（最嚴格）；已成立訂單不受影響 |
| Q3 | B | 設定「過去時間」允許儲存並立即執行（上/下線），同時回傳提示 |
| Q4 | A | 自動執行後後台顯示提示（列表標記「已於 X 自動下線/上線」） |
| Q5 | A | 方案列表 + 編輯頁都顯示排程 / 已執行狀態 |
| Q6 | A | 複用既有每 10 分鐘輪詢排程（ActionScheduler，與 course_schedule 開課同機制），誤差 ≤ 約 10 分鐘 |
| Q7 | A | 一律以站台 WordPress 設定時區為準，UI 標示時區 |

## 技術背景

- 「銷售方案」= WooCommerce Bundle 商品（`bundle_type` meta 非空）；上下線 = `post_status` publish ↔ draft。
- 既有「排程開課」用 `course_schedule` meta（Unix timestamp）+ 每 10 分鐘輪詢比對 `< time()`（`inc/classes/Resources/Course/LifeCycle.php`）。自動上下線沿用此 pattern。
- 前台 `sider.php` 僅顯示 publish 方案，draft 自動隱藏 → 「下線後顧客看不到」天生成立。
- 新增兩個 bundle 商品 meta：`bundle_schedule_online`、`bundle_schedule_offline`（0 = 無排程）。

## 概覽

| 類型 | 數量 |
|------|------|
| Create | 1 activity + 2 feature |
| Modify | api.yml + erm.dbml |
| Delete | 0 |

## Phase 01: Discovery（本階段產出）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | activities/銷售方案排程上下線流程.activity | 管理員設定排程 → ActionScheduler 到點執行流程 |
| create | features/bundle/設定銷售方案排程.feature | @command @管理員：設定/修改/清除排程、過去時間立即執行、時區 |
| create | features/bundle/銷售方案自動上下線.feature | @command @ActionScheduler：到點自動上/下線、購物車失效、已成立訂單不受影響、後台可感知 |

## Phase 02: Entity Modeling

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | entity/erm.dbml → bundle_products | 新增 bundle_schedule_online / bundle_schedule_offline 兩欄位（postmeta） |

## Phase 04: API Contract

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | api/api.yml → createBundle / updateBundle requestBody | 新增 bundle_schedule_online / bundle_schedule_offline 選填參數 |
| modify | api/api.yml → BundleProduct / BundleProductSummary schema | 新增排程欄位 + schedule_notice 提示欄位 |

## Phase 05-08: Implementation（交給 tdd-coordinator）

| 操作 | 目標 | 說明 |
|------|------|------|
| backend | Bundle 商品 meta 寫入 + REST 參數 | createBundle / updateBundle 接收排程參數、過去時間立即執行並回傳 schedule_notice |
| backend | ActionScheduler 輪詢 job | 沿用 course_schedule pattern，新增 bundle 排程輪詢：到點切換 publish↔draft |
| backend | 購物車失效邏輯 | 自動下線時使該 bundle 的未結帳購物車項目失效、阻擋加入購物車 |
| frontend | 編輯頁日期時間選擇器 | 自動上線/下線時間欄位（選填），標示站台時區 |
| frontend | 列表 / 編輯頁狀態顯示 | 「將於 X 自動上/下線」「已於 X 自動上/下線」 |
| e2e | 端對端驗證 | 設定 → 到點 → 狀態切換 → 前台消失/出現 |

## 驗收標準對應（Issue #247）

- [x] 可設定「自動下線時間」（選填）→ 設定銷售方案排程.feature
- [x] 選填、不填維持現有行為、既有方案不受影響 → 設定銷售方案排程.feature（選填語義 Rule）
- [x] 列表 / 編輯頁顯示排程狀態 → BundleProductSummary 排程欄位 + 自動上下線.feature（後台可感知 Rule）
- [x] 到點自動轉草稿、前台不再顯示 → 銷售方案自動上下線.feature
- [x] 隨時修改 / 清除排程 → 設定銷售方案排程.feature（修改/清除 Rule）
- [x] 過去時間明確提示，不無聲消失 → 設定銷售方案排程.feature（Q3=B 立即執行並提示）
- [x] 以站台時區為準、UI 標示 → 兩 feature 時區 Rule + api.yml 描述
- [x] 僅轉草稿、保留資料、可重新發佈 → 自動上下線.feature（保留資料 Rule）
- [x] 購物車 / 進行中訂單處理規則明確（Q2=B）→ 自動上下線.feature（購物車失效 + 已成立訂單不受影響 Rule）

## 待後續注意

- 「自動上線」（Q1=B）為對稱新增功能，測試成本加倍，需確保 online/offline 獨立輪詢互不干擾。
- 購物車失效（Q2=B）需釐清 WooCommerce cart / store API 攔截點，屬實作階段風險。
