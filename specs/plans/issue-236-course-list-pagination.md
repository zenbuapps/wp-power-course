# Implementation Plan — Issue #236 課程列表分頁

> 本檔為 **可交付 tdd-coordinator 執行** 的詳細實作計畫（Phase 05-08）。
> Phase 01-04 規格（actor / activity / ui / feature / api.yml）已完成，見文末「規格來源」。

## 範圍模式

**HOLD SCOPE** — 經 3 輪澄清收斂為「純 AJAX 傳統頁碼分頁」，**不做**前台分類篩選列（功能二已砍）。
預估影響 7 個程式檔 + 2 個測試檔，範圍明確。

## 拍板決策（3 輪澄清結論）

| # | 決策 | 內容 |
|---|------|------|
| Q1/Q5 | 載入方式 | 純 AJAX 無刷新、**不更動 URL**（一頁可有多個 `[pc_courses]`，URL 無法記錄所有狀態）；refresh 回第 1 頁可接受 |
| Q2 | `limit` 語意 | 改為「每頁顯示幾門」（預設 12），可翻頁看完全部 |
| Q6 | 分頁樣式 | 傳統頁碼導航 `‹ 1 2 3 4 ›`，點數字跳頁 |
| Q7 | 功能二 | 拿掉前台分類篩選列、不做 `filter_categories` 參數 |
| — | 向下相容 | `category` / `include` / `exclude` / `tag` / `orderby` / `order` / `columns` / `exclude_avl_courses` 行為不變 |

---

## 架構總覽（資料流）

```
[首次載入 / 無 JS]
  頁面含 [pc_courses limit=12 category=程式]
   → General::pc_courses_callback()
       → General::get_courses_page($params)  // 共用查詢+渲染（wc_get_products）
       → 輸出 <div class="pc-courses" data-limit data-category ...>
             <div class="pc-courses__list"> 第1頁卡片 (list/pricing) </div>
             <div class="pc-courses__pagination"></div>   // 由 JS 依 data 屬性渲染
          </div>

[點擊頁碼 (JS)]
  CoursesListApp(.pc-courses 實例) 讀 data 屬性
   → GET /wp-json/power-course/courses-shortcode-page?page=2&limit=12&category=程式...
       → Api\Shortcode::get_courses_shortcode_page_callback()
           → nocache_headers()
           → General::get_courses_page($params)  // 同一支共用方法
           → JSON { code, message, data:{ html, total, total_pages, current_page } }
   → 抽換 .pc-courses__list 內容（不整頁刷新、不動 URL）
   → 依 total_pages/current_page 重繪 .pc-courses__pagination
```

**核心設計：** 抽出 `General::get_courses_page()` 共用方法，讓「shortcode 首頁渲染」與「AJAX 取頁」走**同一條查詢與渲染路徑**，杜絕兩邊邏輯漂移（例如 `exclude_avl_courses`、分類過濾在翻頁後失效）。

---

## 檔案清單與具體修改

### 後端 PHP

#### 1. `inc/classes/Shortcodes/General.php`（modify）

- **抽出共用方法**（把現有 `pc_courses_callback` L62-106 的參數正規化 + 查詢邏輯搬進去）：
  ```php
  /**
   * 查詢並渲染某一頁課程卡片
   * @param array $params 短代碼/AJAX 參數（limit, page, columns, category, tag, include, exclude, orderby, order, exclude_avl_courses）
   * @return array{html:string, total:int, total_pages:int, current_page:int, columns:int}
   */
  public static function get_courses_page( array $params ): array
  ```
  內部：合併 `$default_args`（含 `paginate=true`）→ 正規化 `include/exclude/tag/category`、`exclude_avl_courses`、`columns` → `wc_get_products($args)` → `Plugin::load_template('list/pricing', [...], false)`。回傳 `html / total / max_num_pages / page / columns`。
- **`pc_courses_callback` 改寫**：呼叫 `get_courses_page()` 取第 1 頁 → 輸出外層容器 + data 屬性 + list 區 + pagination 區（見下方容器規格）。
- **容器 HTML 規格**（所有屬性值用 `esc_attr()`）：
  ```html
  <div class="pc-courses"
       data-limit="12" data-columns="3" data-orderby="date" data-order="DESC"
       data-category="程式" data-tag="" data-include="" data-exclude=""
       data-exclude_avl_courses="false"
       data-total="15" data-total-pages="2" data-current-page="1">
    <div class="pc-courses__list"><!-- list/pricing 第1頁卡片 --></div>
    <div class="pc-courses__pagination"></div>
  </div>
  ```
  > data 屬性只回傳「原始短代碼參數」（不含使用者私有資料）；`exclude_avl_courses` 僅傳布林旗標，實際排除清單由後端依當前登入者即時計算，**不外洩** avl_course_ids。

#### 2. `inc/templates/components/list/pricing.php`（modify）

- 現有模板輸出 `<div class='grid ...'>卡片</div>`，保持卡片 grid 不變。
- **新增空狀態**：`$products` 為空時，輸出友善提示
  `echo '<p class="pc-courses__empty">' . esc_html__( 'No courses match the criteria', 'power-course' ) . '</p>';`
  （依 feature Rule：查詢為空顯示提示且不顯示頁碼導航）
- 此模板的回傳值即為容器內 `.pc-courses__list` 的內容（AJAX `data.html` 也是這支模板的輸出，確保首頁與翻頁渲染一致）。

#### 3. `inc/classes/Api/Shortcode.php`（modify）

- `$apis` 陣列新增：
  ```php
  [ 'endpoint' => 'courses-shortcode-page', 'method' => 'get', 'permission_callback' => null ],
  ```
- 新增 callback（方法名由 ApiBase 推導為 `get_courses_shortcode_page_callback`）：
  ```php
  public function get_courses_shortcode_page_callback( \WP_REST_Request $request ): \WP_REST_Response {
      \nocache_headers();                       // Issue #216 規範，必須第一行
      $p = $request->get_params();
      $params = [
          'page'                => max( 1, absint( $p['page'] ?? 1 ) ),
          'limit'               => absint( $p['limit'] ?? 12 ) ?: 12,
          'columns'             => absint( $p['columns'] ?? 3 ) ?: 3,
          'orderby'             => sanitize_text_field( (string) ( $p['orderby'] ?? 'date' ) ),
          'order'               => in_array( strtoupper( (string) ( $p['order'] ?? 'DESC' ) ), ['ASC','DESC'], true ) ? strtoupper(...) : 'DESC',
          'category'            => sanitize_text_field( (string) ( $p['category'] ?? '' ) ),
          'tag'                 => sanitize_text_field( (string) ( $p['tag'] ?? '' ) ),
          'include'             => sanitize_text_field( (string) ( $p['include'] ?? '' ) ),
          'exclude'             => sanitize_text_field( (string) ( $p['exclude'] ?? '' ) ),
          'exclude_avl_courses' => filter_var( $p['exclude_avl_courses'] ?? false, FILTER_VALIDATE_BOOLEAN ),
      ];
      // 空字串的 category/tag/include/exclude 需 unset，避免污染 wc_get_products
      $page = \J7\PowerCourse\Shortcodes\General::get_courses_page( $params );
      return new \WP_REST_Response([
          'code'    => 'get_courses_shortcode_page_success',
          'message' => __( 'Courses page retrieved successfully', 'power-course' ),
          'data'    => [
              'html'         => $page['html'],
              'total'        => $page['total'],
              'total_pages'  => $page['total_pages'],
              'current_page' => $page['current_page'],
          ],
      ], 200);
  }
  ```
- **安全**：public 端點僅查 `status=publish` + `visibility=visible`（沿用 `get_courses_page` 預設，不接受外部覆寫 status/visibility）；所有輸入經 `absint`/`sanitize_text_field`；不執行任意 shortcode（與既有 `/shortcode` 端點不同，本端點只重建查詢）。

### 前端 vanilla TS

#### 4. `inc/assets/src/events/courses/index.ts`（create）

- 仿 `events/comment/index.ts` 的 class 模式，但 **以 class selector `.pc-courses` 支援同頁多實例**（comment 用 id，這裡不可）。
- `CoursesListApp`：
  - constructor 讀 `.pc-courses` 上 data 屬性（limit/columns/orderby/order/category/tag/include/exclude/exclude_avl_courses/total-pages/current-page）。
  - 初始即依 `total-pages`/`current-page` 呼叫 `Pagination` 渲染 `.pc-courses__pagination`（`totalPages <= 1` 則不渲染）。
  - 綁定 prev/pages/next 點擊 → 計算目標 page → `loadPage(page)`。
  - `loadPage(page)`：顯示 loading（卡片區 skeleton，仿 comment `set isLoading`）→ `$.ajax` GET `${site_url}/wp-json/power-course/courses-shortcode-page`，data 帶所有 query 參數 + page，headers 帶 `X-WP-Nonce: window.pc_data?.nonce` → success：`.pc-courses__list` 換成 `res.data.html`、依 `res.data.total_pages/current_page` 重繪 pagination、更新 `current` 狀態 → error：console + 還原。
  - 每個 `.pc-courses` 各自獨立 `current`，互不干擾。
- 匯出 `export const courses = () => { $('.pc-courses').each((_, el) => new CoursesListApp(el)) }`。
- **Pagination 重用**：直接 `import { Pagination } from '../comment/components/Pagination'`（既有元件已具備 `‹ 1 2 3 4 ›` + `.pc-pagination` 樣式、prev/next disabled）。
  - ⚠️ 注意：comment 的 click 綁定在 `.pc-comment-pagination` 容器，本模組需自綁在 `.pc-courses__pagination`，事件處理寫在 `CoursesListApp` 內（不複用 comment 的 `bindPaginationEvents`）。
  - （選配重構，非必要）可將 `Pagination.ts` 移到 `inc/assets/src/components/Pagination.ts` 共用並更新 comment import；若採此法需確認 ESLint import 排序。**預設不移動，直接 import 既有路徑以縮小 blast radius。**

#### 5. `inc/assets/src/events/index.ts`（modify）

- 新增 `export * from './courses'`。

#### 6. `inc/assets/src/main.ts`（modify）

- import `courses` 並在 `$(document).ready` 內呼叫 `courses()`。

### i18n

#### 7. `scripts/i18n-translations/manual.json`（modify）→ `pnpm run i18n:build`

- 新增字串：
  - `"No courses match the criteria"` → 繁中 `"目前沒有符合條件的課程"`（context: `inc/templates/components/list/pricing.php`）
  - `"Courses page retrieved successfully"` → 繁中 `"課程頁面取得成功"`（context: `Api/Shortcode.php`，非使用者可見，可選）
- 跑 `pnpm run i18n:build`，一起 commit `.pot` / `.po` / `.mo` / `.json` 四檔。**禁止手改 .po**。
- 新詞 `目前沒有符合條件的課程` 應加進 `.claude/rules/i18n.rule.md` 術語表（PR 規範 #10）。

---

## 實作順序（依依賴關係）

| 步驟 | 內容 | 依賴 |
|------|------|------|
| 1 | **後端共用方法**：`General::get_courses_page()` 抽出 + `pc_courses_callback` 改寫輸出容器 | 無 |
| 2 | **模板**：`list/pricing.php` 加空狀態 | 步驟 1（確認回傳結構） |
| 3 | **AJAX 端點**：`Api/Shortcode.php` 新增 `courses-shortcode-page` callback | 步驟 1（呼叫 `get_courses_page`） |
| 4 | **i18n**：manual.json + `i18n:build` | 步驟 2 字串確定 |
| 5 | **前端 TS**：`events/courses/index.ts` + index.ts + main.ts 接線 | 步驟 1/3（容器屬性 + 端點契約） |
| 6 | `pnpm run lint:php` + `pnpm run lint:ts` + `pnpm run build` 全綠 | 全部 |

> TDD：步驟 1/3 屬後端業務邏輯，先寫 Integration Test（Red）再實作（Green）。步驟 5 前端無 unit test，靠 E2E 驗收。

---

## 測試策略

### A. PHP Integration Test（`tests/Integration/Shortcode/CoursesPaginationTest.php`，create）

仿既有 `ShortcodeRenderTest.php`（同目錄、`Tests\Integration\Shortcode` namespace、`@group shortcode`、`create_course` / `enroll_user_to_course` helper）。

涵蓋 feature 8 條 Rule 的後端可測部分：

| # | 測試 | 對應 Rule |
|---|------|-----------|
| 1 | 建 15 門課，`get_courses_page(['limit'=>12,'page'=>1])` → `total=15, total_pages=2, current_page=1`，html 含 12 張卡片 | 預設每頁 12 |
| 2 | `limit=6` → `total_pages=3`；`page=1` html 含 6 張 | limit 改每頁數量 |
| 3 | `page=2, limit=12` → html 為第 2 頁 3 張卡片 | AJAX 取頁正確 |
| 4 | 僅 5 門課、`limit=12` → `total_pages=1`（前端據此不顯示頁碼） | 只有 1 頁 |
| 5 | `category=程式`（8 門）+ `limit=6, page=2` → 只回該分類第 2 頁 2 門，不含他類 | 翻頁沿用 category |
| 6 | Alice 已購課程 100、`exclude_avl_courses=true` 登入 + `page=2` → 結果不含課程 100 | 翻頁沿用排除已購 |
| 7 | 空分類 → `total=0, total_pages=0`，html 含空狀態字串 `No courses match the criteria` | 空狀態 |
| 8 | 3 門課、`[pc_courses]` 預設 → `total_pages=1`、html 3 張（向下相容） | 向下相容 |
| 9（端點層）| 用 `\WP_REST_Request` 打 `GET /power-course/courses-shortcode-page?page=2&limit=12` → 200、`data.html/total/total_pages/current_page` 結構正確 | 端點契約 |
| 10（安全）| 端點傳 `status=draft` 之類覆寫參數無效，只回 publish；XSS/超大整數 page 不崩潰 | public 端點防護 |

> 注意：`wc_get_products` 在測試環境需 WooCommerce 完整初始化（既有 `ShortcodeRenderTest` 已示範繞過；若部分 assertion 受限於測試環境 WC 未完全載入，採 `ShortcodeRenderTest` 同款「contains 容錯」策略，並於測試註解說明）。

### B. E2E Frontend（`tests/e2e/02-frontend/0XX-courses-shortcode-pagination.spec.ts`，create）

仿 `02-frontend/*.spec.ts`，需前置建立一個含 `[pc_courses]` 的測試頁（helper 建頁 + 建 ≥13 門 publish 課程）。

| 場景 | 驗收 |
|------|------|
| 課程 > 每頁 | 底部出現 `.pc-pagination`，含第 1、2 頁，第 1 頁標 `current` |
| 課程 ≤ 每頁 | 不出現 `.pc-pagination` |
| 點頁碼「2」 | `.pc-courses__list` 內容抽換為第 2 頁、**無整頁 reload**（監看 navigation）、**URL 不變**、載入中有 loading |
| 多個 shortcode | 同頁兩個 `[pc_courses]`，翻 A 不影響 B 的 current |
| 空狀態 | `[pc_courses category=無課程分類]` 顯示提示、無頁碼 |
| 向下相容 | 既有 `[pc_courses]` 在課程數少時呈現一致 |

> 驗證「無整頁刷新」：監聽 `page.on('framenavigated')` 或比對 AJAX 前後 `window.performance.navigation` / 一個首屏注入的旗標未被重置。
> 驗證「URL 不變」：`expect(page.url())` 翻頁前後相同。

### C. 品質閘門

`pnpm run lint:php`（phpcbf+phpcs+phpstan L9）、`pnpm run lint:ts`、`pnpm run build`、`composer run test`（PHPUnit）全綠。

---

## 風險與注意事項

| 風險 | 說明 | 對策 |
|------|------|------|
| **PHP/TS 分頁 markup 漂移** | pagination 由 TS `Pagination` 渲染、首屏也靠 JS；若改回 PHP 渲染會有兩份 markup | 維持「卡片 PHP 渲染 + 頁碼 JS 渲染」單一來源（與 comment 一致）；首屏短暫無頁碼可接受（pure-AJAX 設計） |
| **`exclude_avl_courses` 隱私** | 端點 public，但需依當前登入者排除已購 | data 屬性只傳布林；排除清單後端用 `get_current_user_id()` 即時算，不外洩 ID |
| **多實例事件衝突** | 同頁多個 `.pc-courses`，事件須 scope 在各自容器 | 事件綁 `instance.$element.find('.pc-courses__pagination')`，不用全域 selector |
| **快取 stale** | 邊緣快取分頁回應 | callback 第一行 `nocache_headers()`（Issue #216 規範） |
| **`limit` 語意變更** | 既有站台 `limit=12` 行為從「只顯示 12」變「每頁 12 可翻頁」 | 已 Q2 拍板接受（正向改變）；E2E 向下相容案例驗證少量課程時呈現一致 |
| **空字串參數污染查詢** | `category=''` 傳進 `wc_get_products` 可能誤過濾 | 端點與共用方法內，空字串的 category/tag/include/exclude 一律 `unset` |
| **WC 測試環境** | `wc_get_products` 依賴 WC 初始化 | 沿用 `ShortcodeRenderTest` 既有容錯模式 |

---

## 交接 tdd-coordinator 的執行提示

- **語言分工**：步驟 1-4 PHP → `wordpress-master`；步驟 5 TS → 前台 vanilla TS 亦由 `wordpress-master`/`react-master` 視專案慣例（`inc/assets/src` 為 jQuery-based vanilla TS，非 React，建議由熟 TS 的 master 處理）。
- **TDD 邊界**：後端（步驟 1/3）走 Red→Green（先 `CoursesPaginationTest`）；前端走實作 + E2E 驗收。
- **Reviewer**：`*-reviewer` 為 opt-in，本計畫不自動派；若需安全複查（public 端點），用戶可顯式喚醒 `@zenbu-powers:security-reviewer`。

---

## 規格來源（Phase 01-04，已完成）

- `specs/actors/訪客.md`
- `specs/activities/課程列表分頁瀏覽流程.activity`
- `specs/ui/課程列表頁.md`
- `specs/features/shortcode/課程列表分頁.feature`（8 條 Rule）
- `specs/api/api.yml` → `GET /courses-shortcode-page`（L5437~5563）
</content>
</invoke>
