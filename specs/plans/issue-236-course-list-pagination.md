# Execution Plan — Issue #236 課程列表分頁

## 需求摘要

為既有 `[pc_courses]` 短代碼新增「純 AJAX 傳統頁碼分頁」。本次**只做分頁（功能一）**，
原許願的「前台分類篩選列（功能二）」經 3 輪澄清後拍板砍除（站長仍可用既有 `category` 參數寫死）。

## 拍板決策（3 輪澄清結論）

| # | 決策 | 內容 |
|---|------|------|
| Q1 | 載入方式 | 純 AJAX 無刷新、**不更動 URL**（一頁可能有多個 `[pc_courses]`，URL 無法記錄所有狀態） |
| Q2 | `limit` 語意 | 改為「每頁顯示幾門」（預設 12），可翻頁看完全部 |
| Q5 | URL 狀態 | 不帶 URL；refresh 回到第 1 頁（可接受） |
| Q6 | 分頁樣式 | 傳統頁碼導航 `‹ 1 2 3 4 ›`，點數字跳頁 |
| Q7 | 功能二 | 拿掉前台分類篩選列、不做 `filter_categories` 參數 |
| — | 向下相容 | `category` / `include` / `exclude` / `tag` / `orderby` / `order` / `columns` / `exclude_avl_courses` 行為不變 |

## 概覽

| 類型 | 數量 |
|------|------|
| Create | 5（actor / activity / ui / feature / api endpoint） |
| Modify | 0（規格層） |
| Delete | 0 |

## 產出物（Phase 01 Discovery）

| 操作 | 檔案 | 說明 |
|------|------|------|
| create | `specs/actors/訪客.md` | 新 Actor：未登入訪客 |
| create | `specs/activities/課程列表分頁瀏覽流程.activity` | 進入頁面 → 判斷頁數 → AJAX 翻頁 |
| create | `specs/ui/課程列表頁.md` | `[pc_courses]` 列表頁的分頁 / 空狀態 / loading 呈現 |
| create | `specs/features/shortcode/課程列表分頁.feature` | 8 條 Rule + Examples（@ignore @frontend） |
| create | `specs/api/api.yml` → `GET /courses-shortcode-page` | AJAX 取得某一頁課程卡片 HTML + 分頁資訊 |

## Phase 04: API Contract

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `GET /wp-json/power-course/courses-shortcode-page` | 公開（`permission_callback=null`），回傳 `{code,message,data:{html,total,total_pages,current_page}}` |

## Phase 05-08: Implementation（交由 planner / tdd-coordinator 規劃）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `inc/classes/Shortcodes/General.php::pc_courses_callback` | `limit` 改每頁數量語意；輸出分頁容器與 data 屬性（query 參數）供前台 AJAX 重建查詢 |
| modify | `inc/templates/components/list/pricing.php` | 課程卡片區塊包成可被抽換的容器；底部頁碼導航區；空狀態提示 |
| create | `inc/classes/Api/` 新增分頁端點 callback（或擴充 `Api/Shortcode.php`） | `wc_get_products` 查 publish+visible，回傳渲染 HTML + total/max_num_pages；開頭 `nocache_headers()` |
| create | 前台 vanilla TS（`inc/assets/src/`） | 參考 `events/comment/index.ts` + `components/Pagination.ts`（已具備傳統頁碼 UI），各 shortcode 實例獨立維護 current page，AJAX 抽換、loading 狀態 |
| i18n | 空狀態文案等 | `power-course` text domain、英文 msgid，翻譯寫入 `scripts/i18n-translations/manual.json` 後跑 `pnpm run i18n:build` |

## 測試重點（給 test-creator）

- E2E（frontend）：課程數 > / = / < 每頁數量時頁碼導航顯隱；點頁碼 AJAX 抽換不整頁 reload、URL 不變；多 shortcode 獨立分頁；空狀態提示；`category` + 翻頁仍受過濾。
- 向下相容：未加新參數的 `[pc_courses]` 行為一致。
