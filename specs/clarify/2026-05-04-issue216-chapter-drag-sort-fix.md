# Clarify Session 2026-05-04 — Issue #216

## Idea

### 標題：子章節拖曳不生效 + 編輯子章節後父子關係消失

#### Bug #1：拖動章節排序後 UI 不更新 / 顯示舊排列

在課程後台拖動章節調整順序後，UI 畫面會彈回舊位置。資料庫雖有寫入正確資料，但前端顯示不更新，需要 F5 才能看到正確結果。

#### Bug #2：編輯子章節後子章節從巢狀結構消失

將章節拖入另一章節成為子章節後，再編輯該子章節的內容（例如修改標題），儲存後子章節的 `post_parent` 被重置為 0，從巢狀結構消失，變回頂層章節。

### 根因分析（由用戶 @MsRainPoon 完整定位）

#### Bug #1 三層問題

1. **1a — 拖拉庫內部 state 與 React state 脫節**：
   - `js/src/components/course/SortableChapters/index.tsx` 使用 `@ant-design/pro-editor` 的 `<SortableTree>`。
   - SortableTree 維護自己的內部 state，與 React 的 `treeData` 是兩套獨立系統。
   - 排序成功後，`useList` refetch 拿到新資料、`setTreeData` 更新 React state，但 SortableTree 內部 state 沒有跟著更新。
   - 下次拖曳時 library 用舊的 `from_tree` 計算 diff，導致判斷為「沒有變化」而不呼叫 API，或呼叫但寫入錯誤數據。

2. **1b — LiteSpeed Cache 邊緣快取 REST API 回應**：
   - `inc/classes/Resources/Chapter/Utils/Utils.php::sort_chapters()` 使用 raw SQL `UPDATE`，不觸發 LiteSpeed Cache 的 Smart Purge 機制。
   - 排序後再次 `GET /wp-json/power-course/chapters` 仍回傳舊的快取結果。

3. **1c — Reload 後 drag library 初始化時序錯誤**：
   - 即使強制 `location.reload()`，drag library 在 chapters API response 到達前就已初始化（使用空或舊資料）。
   - API 到達後 React state 更新，但 drag library 已建立內部 state，不會重新初始化。

#### Bug #2 根因

- `sort_chapters()` 使用 raw `$wpdb->query()` 更新 `post_parent`，不清除 WordPress object cache。
- 之後當用戶編輯章節時，`wp_update_post()` 會觸發 `wp_insert_post_data` filter，filter 內呼叫 `wp_get_post_parent_id()` 查詢 parent。
- `wp_get_post_parent_id()` 從 object cache 讀取，拿到的是更新前的舊值（0）。
- 系統因此判斷「這個章節沒有 parent」並在儲存時清除 `post_parent`。

### 影響範圍

- 影響所有使用 Power Course 的後台拖曳排序章節功能（管理員 / 講師）
- 影響任何已設為子章節後再被編輯的章節（不限層數，最深可達 `MAX_DEPTH = 5`）
- 啟用 LiteSpeed Cache（或同類邊緣快取）的站點 Bug #1b 影響加劇

### Code source

- `inc/classes/Resources/Chapter/Utils/Utils.php::sort_chapters()`（Bug #1b、#2 根因）
- `inc/classes/Resources/Chapter/Service/Crud.php::sort()`
- `inc/classes/Resources/Chapter/Core/Api.php`（REST 端點，Q3 nocache_headers 注入點）
- `js/src/components/course/SortableChapters/index.tsx`（Bug #1a、Q5 父章節展開）

## Q&A

- Q1 (Bug #2 修復策略): **A** — 在 `sort_chapters()` raw SQL UPDATE 後逐筆呼叫 `clean_post_cache($id)`，從根因清除 stale object cache，並同步觸發 `clean_post_cache` action 讓其他外掛掛勾。
- Q2 (Bug #1a drag state 同步): **A** — 排序成功後變更 `<SortableTree key={...}>` 觸發 unmount/remount，library 從最新 React state 重新初始化。可接受約 0.3 秒視覺彈跳，換取最低風險。
- Q3 (Bug #1b 快取處理): **D** — 在 power-course REST API 註冊時呼叫 `nocache_headers()` 產出 `Cache-Control: no-store` 標頭（從根本避開 LiteSpeed / WP Rocket / Cloudflare），並在文件補充說明站長設定方式。
- Q4 (拖曳排序失敗 UI): **A** — 顯示錯誤 toast，章節順序自動還原為拖曳前位置，避免「視覺成功但資料失敗」的誤導。
- Q5 (編輯子章節儲存後 UI): **C** — 自動重新讀取章節列表，並主動展開父章節，讓使用者明確看到「子章節仍在原位」，給予清晰視覺確認。
- Q6 (測試覆蓋): **D** — PHPUnit（`sort_chapters` + 編輯子章節後 `post_parent` 持久性 + cache 行為驗證）+ Playwright E2E（拖曳互動 + 編輯後巢狀結構保留）。
- Q7 (多層巢狀覆蓋範圍): **B** — 修復覆蓋全部 5 層巢狀，測試案例至少覆蓋 2 層與 3 層；不變更 `MAX_DEPTH` 維持向下相容。

## 修復方案總覽

### 後端修復（PHP）

1. **`Utils::sort_chapters()` 補上 cache 失效**：
   ```php
   // 在每次 batch UPDATE 成功後
   foreach ( $ids as $id ) {
       clean_post_cache( $id );
   }
   ```
   - 觸發位置：`$wpdb->query($sql)` 成功後、TRANSACTION COMMIT 之前
   - 對所有被更新的 post_id（含 batch_size = 50 的分批）逐筆清除

2. **REST API 標頭加上 nocache**：
   - 於 `Resources/Chapter/Core/Api.php` 與其他 power-course API 端點的 `register_rest_route` 回呼中，呼叫 `nocache_headers()`
   - 或統一在 `J7\PowerCourse\Api\` namespace 的 ApiBase 層加入

### 前端修復（React）

1. **SortableTree 強制重新初始化**：
   - `<SortableTree key={treeVersion}>` — 在 `mutate.onSettled` 或 `useList` 的新資料 hash 改變時遞增 `treeVersion`
   - 重新 mount 後 library 以最新 React state 為唯一資料源

2. **編輯子章節後展開父章節**：
   - `ChapterEdit` 儲存成功 → `invalidate('chapters')` → 等 `useList` 回新資料
   - 從新資料中找到被編輯章節的 `post_parent`，設定為展開狀態
   - 若 `post_parent` 仍為原父章節 ID，視覺上應立即看到子章節仍嵌套在內

3. **失敗時還原視覺位置**：
   - `mutate.onError` 中 `setTreeData(originTree)` 回到拖曳前狀態
   - `originTree` 已存在於現有程式碼

### 文件補充

- `.claude/rules/wordpress.rule.md` 或 `README.md`：建議站長在 LiteSpeed Cache 排除 `/wp-json/power-course/*` 路徑
- 即使後端已加 `nocache_headers()`，部分快取設定可能 override，文件提示作為雙保險

## 規格產出

| 檔案 | 動作 |
|------|------|
| `specs/features/chapter/排序章節.feature` | 更新：補 cache 失效規則、失敗時還原規則、巢狀層數覆蓋 |
| `specs/features/chapter/更新章節.feature` | 更新：補 post_parent 持久性規則、編輯後父章節展開 |
| `specs/features/chapter/章節CPT結構與層級.feature` | 更新：補 nocache_headers REST 標頭規則 |
| `specs/clarify/2026-05-04-issue216-chapter-drag-sort-fix.md` | 新增：本文件 |
