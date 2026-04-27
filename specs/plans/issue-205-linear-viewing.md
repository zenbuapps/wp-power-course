# 實作計劃：課程線性觀看功能 (Issue #205)

## 概述

為 Power Course 新增「線性觀看」（循序學習模式），管理員可針對個別課程開啟強制順序觀看，學員必須依 menu_order 平攤一維順序完成章節才能解鎖下一章。功能預設關閉，開啟後涵蓋教室頁面鎖定 UI、側邊欄即時解鎖、URL 存取導向、toggle API 雙層驗證、Ended 智慧判斷、管理員預覽免除等完整流程。

## 範圍模式：HOLD SCOPE

**預估影響**：14 個生產檔案（後端 PHP 6 + 前端 TS/TSX 5 + CSS 1 + 測試 2 新檔）。範圍已由 clarifier session 明確收斂，所有 7 個設計決策均已確認（B A A C A B A）。

## 需求重述

1. 管理員在課程「其他設定」分頁可啟用/關閉線性觀看（meta: `enable_linear_viewing`，`'yes'`/`'no'`，預設 `'no'`）
2. 啟用後，所有章節（含父章節）平攤為一維序列（`get_flatten_post_ids()`），第一個章節永遠解鎖
3. 解鎖邏輯採「最遠進度模式」：找到已完成章節中位置最後面的那一個，從第一章到該位置的下一個章節全部解鎖
4. 鎖定的章節在側邊欄顯示鎖頭圖示 + 指名提示文字（`data-locked`、`data-lock-message` HTML 屬性）
5. 學員點擊鎖定章節彈出 `<dialog>` 對話框，不跳轉頁面
6. URL 直接存取鎖定章節 → PHP 302 redirect 到當前應觀看章節 + `?linear_locked=1`，目標頁 JS 顯示 toast 後清除參數
7. toggle-finish API 回傳 `unlocked_chapter_ids` + `locked_chapter_ids`（僅線性觀看模式）；鎖定章節標記完成回 403
8. 完成/取消完成後，前端 jQuery DOM 操作即時更新側邊欄鎖定狀態
9. Ended.tsx 智慧判斷：自動完成觸發 → 正常倒數跳轉；未觸發 → 顯示引導提示
10. 底部「下一個」按鈕鎖定時顯示但禁用（灰色 + 鎖頭圖示）
11. 管理員預覽模式（`current_user_can('manage_woocommerce')` 且非學員）不受限制
12. 章節排序 API 回應包含線性觀看警告訊息
13. 修正 Ended.tsx 硬編碼中文字串（i18n）

## 已確認的設計決策

| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1 | 父章節處理 | **B — 包含**，父章節也算在線性序列中，需手動完成 |
| Q2 | 側邊欄即時更新 | **A — DOM 操作**，保持 PHP 渲染，jQuery 操作 DOM |
| Q3 | API 設計 | **A — 擴充現有 toggle-finish API**，不新增獨立端點 |
| Q4 | Ended 畫面 | **C — 智慧判斷**，自動完成則正常跳轉，否則顯示引導 |
| Q5 | URL 導向 | **A — Query 參數方式**，`?linear_locked=1` |
| Q6 | 排序影響 | **B — 即時重算 + 警告管理員** |
| Q7 | i18n 修正 | **A — 同 PR 一併修正** |

## 已知風險（來自程式碼研究）

| 風險 | 嚴重度 | 緩解措施 |
| --- | --- | --- |
| `get_flatten_post_ids()` 結果被 `prev_next` cache group 快取，排序變更後若 cache 未清，解鎖狀態計算錯誤 | 高 | `sort_chapters()` 已會清除 `prev_next` cache（確認）；新增的解鎖計算也使用同一 cache key，不另建 cache |
| `is_admin_preview()` 判斷為 `manage_woocommerce && !is_avl`，管理員若同時是學員（is_avl=true）則不算預覽 | 中 | 線性觀看免除改用 `current_user_can('manage_woocommerce')` 直接判斷，不走 `is_admin_preview()`。管理員無論是否為學員都免除限制 |
| 自動完成（95%）與 Ended.tsx 之間存在時序問題：`pc:auto-finish-chapter` 事件 dispatch → API 呼叫 → 回應 → 更新 `next_chapter_locked` → Ended 讀取。若 API 回應慢，Ended 可能先渲染為鎖定狀態 | 中 | Ended.tsx 監聯 `finishChapterAtom` store 變化，不用初始 `pc_data.next_chapter_locked`；API 成功後 store 更新觸發 re-render |
| `AVLChapterMeta::add` 對已存在的 `finished_at` 會新增重複 row（EAV 表無 unique constraint）| 低 | 現有 toggle API 已先檢查 `$is_this_chapter_finished`，僅在未完成時 add，不受影響 |
| PHP redirect 在 `wp_head()` 之後無法使用 `wp_safe_redirect()`（headers already sent）| 高 | redirect 邏輯放在 `single-pc_chapter.php` 最前面（line 42-63 的 `!current_user_can('manage_woocommerce')` 區塊內），在任何 HTML 輸出之前 |
| jQuery DOM 操作可能與未來 React 化衝突 | 低 | 以 `data-*` 屬性為 contract，DOM 操作封裝在獨立函式中，未來可替換為 React state |

## 架構變更

### 後端（PHP）— 6 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 1 | `inc/classes/Utils/LinearViewing.php` | **新增** | 線性觀看核心邏輯工具類（解鎖計算、鎖定驗證、鎖定訊息生成） |
| 2 | `inc/classes/Resources/Chapter/Core/Api.php` | 修改 | toggle-finish API 增加線性觀看驗證（403）與回應擴充（`unlocked/locked_chapter_ids`） |
| 3 | `inc/classes/Resources/Chapter/Utils/Utils.php` | 修改 | `get_children_posts_html_uncached()` 輸出 `data-locked` / `data-lock-message` 屬性；`get_chapter_icon_html()` 支援鎖定圖示 |
| 4 | `inc/templates/single-pc_chapter.php` | 修改 | 新增線性觀看 redirect 邏輯 + `pc_data` 注入 `next_chapter_locked` |
| 5 | `inc/templates/components/related-posts/prev-next.php` | 修改 | 「下一個」按鈕鎖定時 disabled 處理 |
| 6 | `inc/classes/Resources/Chapter/Core/Api.php` (sort) | 修改 | 排序 API 回應增加線性觀看警告 |

### 前端（TypeScript/CSS）— 6 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 7 | `inc/assets/src/events/finishChapter.ts` | 修改 | 處理 API 回應中的 `unlocked/locked_chapter_ids`，DOM 操作即時更新鎖定狀態 |
| 8 | `inc/assets/src/events/linearViewing.ts` | **新增** | 鎖定章節點擊攔截（dialog）、toast 提示（`?linear_locked=1`）、`history.replaceState` 清除參數 |
| 9 | `js/src/App2/Ended.tsx` | 修改 | 智慧判斷邏輯 + i18n 修正 |
| 10 | `js/src/pages/admin/Courses/Edit/tabs/CourseOther/index.tsx` | 修改 | 新增 `enable_linear_viewing` Switch 開關 |
| 11 | `inc/assets/src/styles/linear-viewing.css` | **新增** | 鎖定章節樣式（`.pc-chapter-locked`、`.pc-btn-disabled`、toast） |

### 測試 — 2 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 12 | `tests/e2e/01-admin/linear-viewing-setting.spec.ts` | **新增** | 管理端設定開關 E2E |
| 13 | `tests/e2e/02-frontend/linear-viewing-classroom.spec.ts` | **新增** | 教室前台線性觀看完整流程 E2E |

### 規格文件 — 1 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 14 | `specs/plans/issue-205-linear-viewing.md` | **新增** | 本計劃文件 |

**合計 14 個檔案**（含計劃文件），HOLD SCOPE 範圍內。

---

## 資料流分析

### 資料流 1：學員進入教室（Read Path — 初始鎖定狀態渲染）

```
BROWSER REQUEST          PHP single-pc_chapter.php        LinearViewing::get_unlock_status()     PHP sidebar render               BROWSER
──────────────           ──────────────────────           ─────────────────────────────          ──────────────────               ───────
GET /classroom/          1. 驗證 user login               1. get_flatten_post_ids($course_id)    get_children_posts_html_uncached  HTML with
  course/chapter/     →  2. 驗證 is_avl                →  2. 取得已完成 chapter IDs           →  - 每個 <li> 判斷 locked?      →  data-locked
                         3. 驗證 not expired              3. 找最遠已完成位置 (max_index)        - locked → data-locked="true"     attributes
                         4. 【新增】is_chapter_locked?    4. unlocked = [0..max_index+1]          + data-lock-message="..."        + pc_data.
                            - admin → skip                5. 回傳 {unlocked[], locked[]}        - icon → 鎖頭 SVG                  next_chapter
                            - locked → redirect                                                  - opacity: 0.5                     _locked
                            - unlocked → continue         SHADOW PATHS:                          - 提示文字
                                                          ├ nil: 無完成紀錄 → unlocked=[first]
                                                          ├ empty: course 無章節 → unlocked=[]
                                                          └ all done → unlocked=all
```

### 資料流 2：學員完成章節（Write Path — toggle + 即時解鎖）

```
USER CLICK               jQuery finishChapter.ts         PHP toggle-finish API                          jQuery DOM update
──────────               ──────────────────────          ──────────────                                 ────────────────
#finish-chapter           POST /toggle-finish-            1. 驗證 course_id 存在                        1. 從回應取
  __button click      →    chapters/{id}              →  2.【新增】線性觀看驗證:                     →    unlocked_chapter_ids
                           body: {course_id}                - 課程啟用 linear? → 檢查章節 locked?        + locked_chapter_ids
                                                            - locked 且為 mark-finish → 403            2. 對 unlocked 章節:
                                                            - 取消完成(unfinish) → 放行                   - remove .pc-chapter-locked
                                                          3. AVLChapterMeta add/delete                    - remove data-locked
                                                          4. 計算新的 unlock status                       - 恢復原始 icon
                                                          5. 回傳 {data: {                             3. 對 locked 章節:
                                                               unlocked_chapter_ids,                       - add .pc-chapter-locked
                                                               locked_chapter_ids,                         - set data-locked="true"
                                                               ...existing fields}}                        - icon → 鎖頭
                                                                                                        4. 更新 prev-next 按鈕
                                                          SHADOW PATHS:                                 5. 更新 Ended state
                                                          ├ 403: 章節 locked → 回傳 error
                                                          ├ course 未啟用 linear → null (不回傳 ids)
                                                          └ 取消完成 → 重算，可能 re-lock 後續章節
```

### 資料流 3：URL 直接存取鎖定章節（Redirect Path）

```
BROWSER                  PHP single-pc_chapter.php                                    BROWSER (redirected)
───────                  ──────────────────────                                       ──────────────────
GET /classroom/          1. chapter_id from URL                                        GET /classroom/
  course/locked-ch/   →  2. course = chapter->get_course_product()                  →    course/unlocked-ch/
                         3. is_admin? → skip                                              ?linear_locked=1
                         4. enable_linear_viewing? → check
                         5. LinearViewing::is_chapter_locked(chapter_id, user_id)?    JS linearViewing.ts:
                            YES → get_current_chapter_id() (第一個 locked 章節)        1. 偵測 ?linear_locked=1
                                → wp_safe_redirect(permalink + ?linear_locked=1)      2. 顯示 toast (info 藍色)
                                → exit                                                3. 5 秒自動消失
                            NO  → continue normal render                              4. history.replaceState
                                                                                         清除 query param
                         SHADOW PATHS:
                         ├ nil: course 不存在 → 前面已攔截 (is_avl 或 404)
                         ├ admin → 永遠 skip，正常渲染
                         └ linear 未啟用 → skip，正常渲染
```

### 資料流 4：影片播完 Ended 智慧判斷

```
VIDEO ended EVENT         Player.tsx                    finishChapter.ts              Ended.tsx (re-render)
────────────────          ──────────                    ────────────────              ────────────────────
onEnded fired         →   1. setIsEnded(true)        →  auto-finish API call      →  讀取 store state:
                          2. dispatchAutoFinishEvent      (若 ratio >= 95%)            IF !linear_viewing:
                          3. handleEnded()               API success →                  → 正常 5s 倒數跳轉
                                                          store.set({                 ELSE IF next unlocked:
                                                            isFinished: true,           → 正常 5s 倒數跳轉
                                                            ...                       ELSE (next locked):
                                                          })                            → 顯示引導提示
                                                                                        "Complete this chapter
                                                         API fail / ratio < 95%:         to unlock next"
                                                          store 不更新 →                → 無倒數、無跳轉
                                                          next_chapter_locked
                                                          仍為初始值 (true)
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| `LinearViewing::get_unlock_status()` | course 無章節（空陣列） | Edge case | 回傳 `{unlocked:[], locked:[]}` | 否（頁面無章節列表） |
| `LinearViewing::get_unlock_status()` | `get_flatten_post_ids()` cache 過期返回空 | Cache miss | 重新查詢 DB（已有 fallback） | 否 |
| `toggle-finish API` 403 | 學員嘗試完成鎖定章節 | Business rule | 回傳 403 + 訊息 `'此章節尚未解鎖，請先完成前面的章節'` | 是：dialog 顯示錯誤 |
| `single-pc_chapter.php` redirect | `get_current_chapter_id()` 找不到當前應觀看章節 | Edge case（所有章節已完成但仍被判為 locked — 不應發生） | Fallback 到課程第一個章節 | 是：redirect |
| `finishChapter.ts` DOM 操作 | `$('li[data-post-id="X"]')` 找不到元素（chapter 不在當前 DOM） | DOM miss | jQuery 靜默忽略（`.length === 0`） | 否 |
| `linearViewing.ts` dialog | dialog 元素不存在 | DOM miss | 動態建立 `<dialog>` 元素 | 否（正常不會發生） |
| `Ended.tsx` store 讀取 | finishChapterAtom 未更新（API timeout） | Race condition | 維持初始 `next_chapter_locked` 值（由 PHP 注入），保守顯示引導提示 | 是：不自動跳轉 |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| 學員在鎖定章節快速雙擊「完成」| 重複 API 呼叫 | 是（既有 loading spinner 防重複） | 現有 E2E | 否 | — |
| 學員完成章節後瀏覽器回上一頁 | 側邊欄狀態可能 stale | 否（需頁面重載） | E2E 覆蓋 | 是：下次載入自動修正 | 重新載入頁面 |
| 管理員啟用 linear 後立即調整排序 | 排序變更影響已登入學員的 cache | 是（`sort_chapters` 清 cache） | E2E 覆蓋 | 是：警告訊息 | 學員重新載入 |
| 自動完成 API 超時，Ended 顯示引導提示 | 學員以為沒完成，手動點完成 | 是（手動點擊無條件呼叫 API） | E2E 覆蓋 | 是：正常完成流程 | — |
| `wp_safe_redirect` 後未 `exit` | 繼續渲染頁面 | 是（明確 `exit`） | E2E 覆蓋 | 否 | — |

---

## 實作步驟

### 第一階段：核心後端邏輯（PHP）

> 此階段建立線性觀看的核心計算邏輯，為後續所有前後端整合奠基。

#### 1.1 新增 `LinearViewing` 工具類

**檔案**：`inc/classes/Utils/LinearViewing.php`（新增）
**複雜度**：中 | **風險**：低 | **依賴**：無

新增 `J7\PowerCourse\Utils\LinearViewing` 靜態工具類，包含以下方法：

```php
final class LinearViewing {
    /**
     * 課程是否啟用線性觀看
     * @return bool
     */
    public static function is_enabled(int $course_id): bool;

    /**
     * 計算學員在課程中的解鎖狀態
     * @return array{unlocked_ids: array<int>, locked_ids: array<int>}
     */
    public static function get_unlock_status(int $course_id, int $user_id): array;

    /**
     * 判斷特定章節是否對學員鎖定
     * @return bool
     */
    public static function is_chapter_locked(int $chapter_id, int $course_id, int $user_id): bool;

    /**
     * 取得學員當前應觀看的章節 ID（第一個未解鎖的章節）
     * 若所有章節都已解鎖，回傳最後一個章節
     * @return int|null
     */
    public static function get_current_chapter_id(int $course_id, int $user_id): ?int;

    /**
     * 取得鎖定提示文字（指名需完成的章節）
     * "Please complete 'Chapter X' to view this chapter"
     * @return string
     */
    public static function get_lock_message(int $chapter_id, int $course_id, int $user_id): string;

    /**
     * 是否免除線性觀看限制（管理員）
     * @return bool
     */
    public static function is_exempt(int $user_id): bool;
}
```

**核心演算法 `get_unlock_status()`**：

```
1. flatten_ids = ChapterUtils::get_flatten_post_ids(course_id)
2. IF flatten_ids 為空 → return {unlocked:[], locked:[]}
3. finished_ids = 從 pc_avl_chaptermeta 取得 user 在此課程已完成的章節 IDs
4. max_finished_index = -1
5. FOR each finished_id in finished_ids:
     index = array_search(finished_id, flatten_ids)
     IF index !== false AND index > max_finished_index:
       max_finished_index = index
6. unlock_up_to = max_finished_index + 1  (含)
7. unlocked = flatten_ids[0..unlock_up_to]  (至少包含 index 0)
8. locked = flatten_ids[unlock_up_to+1..]
9. return {unlocked_ids, locked_ids}
```

**`get_lock_message()` 邏輯**：

```
1. flatten_ids = get_flatten_post_ids(course_id)
2. {unlocked_ids, locked_ids} = get_unlock_status(course_id, user_id)
3. 找到 locked 章節在 flatten 序列中的前一個章節（即 unlocked 的最後一個）
4. prev_title = get_the_title(prev_chapter_id)
5. return sprintf(__('Please complete "%s" first to view this chapter', 'power-course'), prev_title)
```

**`is_exempt()` 邏輯**：

```php
return current_user_can('manage_woocommerce');
```

> 注意：不使用 `is_admin_preview()`，因為管理員即使是學員也應免除限制。

**成功標準**：
- [ ] `get_unlock_status()` 對 feature 中所有 8 個 scenario 回傳正確結果
- [ ] 無完成紀錄時僅解鎖第一章
- [ ] 跳躍完成時以最遠進度為基準
- [ ] 取消完成後正確重算
- [ ] 未啟用 linear 時回傳全部解鎖

#### 1.2 修改 toggle-finish API 增加線性觀看驗證

**檔案**：`inc/classes/Resources/Chapter/Core/Api.php` — `post_toggle_finish_chapters_with_id_callback()`
**複雜度**：中 | **風險**：中 | **依賴**：步驟 1.1

在現有方法中增加兩個邏輯：

**A. 鎖定章節完成驗證（403）**

在 line 275 `$is_this_chapter_finished` 判斷之後、line 291 toggle 邏輯之前新增：

```php
// 線性觀看驗證：鎖定的章節不允許標記為完成（取消完成不受限）
if (!$is_this_chapter_finished && LinearViewing::is_enabled($course_id)) {
    if (!LinearViewing::is_exempt($user_id) && LinearViewing::is_chapter_locked($chapter_id, $course_id, $user_id)) {
        return new \WP_REST_Response(
            [
                'code'    => '403',
                'message' => esc_html__('This chapter is not yet unlocked. Please complete the previous chapters first.', 'power-course'),
            ],
            403
        );
    }
}
```

**B. 回應擴充 `unlocked_chapter_ids` + `locked_chapter_ids`**

在兩個 return 區塊（line 302 unfinish + line 338 finish）的 `'data'` 陣列中增加：

```php
'unlocked_chapter_ids' => LinearViewing::is_enabled($course_id)
    ? LinearViewing::get_unlock_status($course_id, $user_id)['unlocked_ids']
    : null,
'locked_chapter_ids'   => LinearViewing::is_enabled($course_id)
    ? LinearViewing::get_unlock_status($course_id, $user_id)['locked_ids']
    : null,
```

> 注意：未啟用 linear 時回傳 `null`，前端判斷 `null` 即不做 DOM 操作。

**成功標準**：
- [ ] 鎖定章節 POST toggle → 403
- [ ] 取消已完成章節 → 200（不受鎖定限制）
- [ ] 回應包含正確的 unlocked/locked IDs
- [ ] 未啟用 linear 的課程不受影響（null）

#### 1.3 修改排序 API 增加線性觀看警告

**檔案**：`inc/classes/Resources/Chapter/Core/Api.php` — `post_chapters_sort_callback()`
**複雜度**：低 | **風險**：低 | **依賴**：步驟 1.1

在 `post_chapters_sort_callback()` 的成功回應（line 185-191）中，增加 warning 欄位：

```php
// 排序成功後，從 body_params 取得 course_id 並檢查線性觀看
$course_id = $this->get_course_id_from_sort_params($body_params);
$warning = null;
if ($course_id && LinearViewing::is_enabled($course_id)) {
    $warning = esc_html__(
        'This course has sequential learning enabled. Changing the chapter order will affect students\' chapter unlock status.',
        'power-course'
    );
}

return new \WP_REST_Response(
    [
        'code'    => 'sort_success',
        'message' => esc_html__('Sort order updated successfully', 'power-course'),
        'data'    => null,
        'warning' => $warning,
    ]
);
```

需要新增私有方法 `get_course_id_from_sort_params()` 從排序 body 中提取 course_id（從 `from_tree` 的第一個元素的 parent post 取得 `parent_course_id` meta）。

**成功標準**：
- [ ] 啟用 linear 的課程排序後回應包含 warning
- [ ] 未啟用 linear 的課程排序後 warning 為 null

---

### 第二階段：教室頁面後端整合

> 此階段將線性觀看邏輯整合到教室的 PHP 渲染層。

#### 2.1 修改章節模板增加 redirect 邏輯

**檔案**：`inc/templates/single-pc_chapter.php`
**複雜度**：中 | **風險**：高（redirect 必須在 HTML 輸出前） | **依賴**：步驟 1.1

在 line 42 `if (!current_user_can('manage_woocommerce'))` 區塊內，在 expired 檢查之後（line 57）、publish status 檢查之前（line 60），新增線性觀看驗證：

```php
// 線性觀看存取控制
if (LinearViewing::is_enabled((int) $course_product->get_id())) {
    if (LinearViewing::is_chapter_locked((int) $chapter_post->ID, (int) $course_product->get_id(), $current_user_id)) {
        $target_chapter_id = LinearViewing::get_current_chapter_id(
            (int) $course_product->get_id(),
            $current_user_id
        );
        if ($target_chapter_id) {
            \wp_safe_redirect(
                \add_query_arg('linear_locked', '1', \get_the_permalink($target_chapter_id))
            );
            exit;
        }
    }
}
```

同時在 `pc_data` JavaScript 物件中注入線性觀看相關資料（line 92-103）：

```php
$next_post_id = ChapterUtils::get_next_post_id((int) $chapter_post->ID);
$is_linear_enabled = LinearViewing::is_enabled((int) $course_product->get_id());
$next_chapter_locked = false;
if ($is_linear_enabled && $next_post_id && !LinearViewing::is_exempt($current_user_id)) {
    $next_chapter_locked = LinearViewing::is_chapter_locked(
        $next_post_id,
        (int) $course_product->get_id(),
        $current_user_id
    );
}

printf(
    /*html*/'<script>
    window.pc_data = {
        "nonce": "%1$s",
        "plugin_url": "%2$s",
        "pdf_watermark": {...},
        "linear_viewing": %6$s,
        "next_chapter_locked": %7$s
    }
    </script>',
    // ... existing params ...
    $is_linear_enabled ? 'true' : 'false',
    $next_chapter_locked ? 'true' : 'false'
);
```

**成功標準**：
- [ ] 鎖定章節 URL 存取 → 302 redirect 到正確章節 + `?linear_locked=1`
- [ ] 已解鎖章節正常顯示
- [ ] 管理員不受 redirect 影響
- [ ] `pc_data` 正確注入 `linear_viewing` 與 `next_chapter_locked`

#### 2.2 修改側邊欄渲染增加鎖定狀態

**檔案**：`inc/classes/Resources/Chapter/Utils/Utils.php` — `get_children_posts_html_uncached()`
**複雜度**：中 | **風險**：中 | **依賴**：步驟 1.1

修改 `get_children_posts_html_uncached()` 方法。在 `$context === 'classroom'` 時計算鎖定狀態。

**方案**：在方法開頭（depth=0 時）一次性計算整個課程的解鎖狀態，透過額外參數傳遞到遞迴子呼叫。

新增可選參數 `$lock_status`：

```php
public static function get_children_posts_html_uncached(
    int $post_id,
    array $children_posts = null,
    $depth = 0,
    $context = 'classroom',
    ?array $lock_status = null  // 新增：{locked_ids: int[], lock_messages: array<int, string>}
): string {
```

在 depth=0 且 context='classroom' 時初始化：

```php
if ($depth === 0 && $context === 'classroom' && $lock_status === null) {
    $course_id = $post_id;
    $user_id = get_current_user_id();
    if ($user_id && LinearViewing::is_enabled($course_id) && !LinearViewing::is_exempt($user_id)) {
        $status = LinearViewing::get_unlock_status($course_id, $user_id);
        $lock_status = [
            'locked_ids' => $status['locked_ids'],
            'lock_messages' => [], // lazy 計算
        ];
    }
}
```

在 `<li>` 的 sprintf 中，若 chapter 在 `locked_ids` 中：

- 增加 `data-locked="true"` 屬性
- 增加 `data-lock-message="..."` 屬性（透過 `LinearViewing::get_lock_message()`）
- 增加 CSS class `pc-chapter-locked`
- icon 區域替換為鎖頭 SVG（新增 `icon/lock` template）
- 標題 `<span>` 加上 `opacity-50` class

**鎖頭 SVG 圖示**：新增 `inc/templates/icon/lock.php`（新增），參考現有 `icon/video.php` 和 `icon/check.php` 的結構。

**成功標準**：
- [ ] 鎖定章節 `<li>` 帶 `data-locked="true"` + `data-lock-message`
- [ ] 鎖定章節顯示鎖頭圖示、透明度降低、提示文字
- [ ] 未鎖定章節與現有行為完全一致
- [ ] 未啟用 linear 的課程無 data-locked 屬性
- [ ] 管理員看到的章節全部無鎖定

#### 2.3 修改底部導航按鈕

**檔案**：`inc/templates/components/related-posts/prev-next.php`
**複雜度**：低 | **風險**：低 | **依賴**：步驟 1.1

修改「下一個」按鈕（line 42-58），增加鎖定判斷：

```php
if ($next_post) {
    $course_id = $course->get_id();
    $user_id = get_current_user_id();
    $is_next_locked = false;
    if ($user_id && LinearViewing::is_enabled($course_id) && !LinearViewing::is_exempt($user_id)) {
        $is_next_locked = LinearViewing::is_chapter_locked($next_post_id, $course_id, $user_id);
    }

    $next_label = esc_html__('Next', 'power-course');
    $disabled_class = $is_next_locked ? 'pc-btn-disabled pointer-events-none opacity-50' : '';
    $disabled_attr = $is_next_locked ? 'aria-disabled="true"' : '';
    $href = $is_next_locked ? 'javascript:void(0)' : esc_url(get_the_permalink($next_post->ID));

    // ... printf with $disabled_class, $disabled_attr, $href
    // 鎖定時在按鈕下方顯示提示
    if ($is_next_locked) {
        printf(
            '<p class="text-xs text-base-content/50 text-center mt-2">🔒 %s</p>',
            esc_html__('Complete this chapter to unlock the next one', 'power-course')
        );
    }
}
```

加上 `data-next-locked="true/false"` 讓前端 DOM 操作可即時更新。

**成功標準**：
- [ ] 下一章鎖定時按鈕灰色不可點擊
- [ ] 下一章解鎖後按鈕正常
- [ ] 前端完成章節後可透過 DOM 操作更新按鈕狀態

---

### 第三階段：前端互動（TypeScript）

> 此階段實作前端的即時解鎖、鎖定攔截、toast 提示、Ended 智慧判斷。

#### 3.1 新增 linearViewing.ts 前端模組

**檔案**：`inc/assets/src/events/linearViewing.ts`（新增）
**複雜度**：中 | **風險**：低 | **依賴**：第二階段

此模組負責：

**A. 鎖定章節點擊攔截**

```typescript
export function linearViewing() {
    // 確保 dialog 元素存在
    ensureLockDialog()

    // 攔截鎖定章節的點擊
    $(document).on('click', 'li[data-locked="true"]', function (e) {
        e.preventDefault()
        e.stopPropagation()
        const message = $(this).attr('data-lock-message') || ''
        showLockDialog(message)
    })

    // Toast 提示（URL redirect 後）
    handleLinearLockedToast()
}
```

**B. Dialog 實作**（使用 HTML5 `<dialog>`）

```typescript
function ensureLockDialog() {
    if ($('#pc-linear-lock-dialog').length > 0) return
    $('body').append(`
        <dialog id="pc-linear-lock-dialog" class="pc-dialog rounded-box p-6 max-w-sm">
            <h3 class="text-lg font-bold mb-4">${__('Chapter not yet unlocked', 'power-course')}</h3>
            <p id="pc-linear-lock-message" class="mb-4"></p>
            <form method="dialog">
                <button class="pc-btn pc-btn-primary w-full">${__('OK', 'power-course')}</button>
            </form>
        </dialog>
    `)
}

function showLockDialog(message: string) {
    $('#pc-linear-lock-message').text(message)
    const dialog = document.getElementById('pc-linear-lock-dialog') as HTMLDialogElement
    dialog?.showModal()
}
```

**C. Toast 提示**

```typescript
function handleLinearLockedToast() {
    const params = new URLSearchParams(window.location.search)
    if (params.get('linear_locked') !== '1') return

    // 建立 toast 元素
    const toast = $(`
        <div class="pc-toast pc-toast-info" role="alert">
            <span>${__('Please complete the previous chapters before viewing this content', 'power-course')}</span>
            <button class="pc-toast-close">✕</button>
        </div>
    `)
    $('body').prepend(toast)
    toast.find('.pc-toast-close').on('click', () => toast.remove())

    // 5 秒自動消失
    setTimeout(() => toast.fadeOut(300, () => toast.remove()), 5000)

    // 清除 URL 參數
    const url = new URL(window.location.href)
    url.searchParams.delete('linear_locked')
    window.history.replaceState({}, '', url.toString())
}
```

需要在現有的前端入口（如 `inc/assets/src/main.ts` 或 `inc/assets/src/classroom.ts`）中引入並呼叫 `linearViewing()`。

**成功標準**：
- [ ] 點擊鎖定章節彈出 dialog（指名前置章節）
- [ ] dialog 有「確定」按鈕關閉
- [ ] redirect 後顯示 toast + 自動清除 URL 參數
- [ ] toast 5 秒後自動消失

#### 3.2 修改 finishChapter.ts 增加 DOM 解鎖/鎖定操作

**檔案**：`inc/assets/src/events/finishChapter.ts`
**複雜度**：中 | **風險**：中（DOM 操作容易遺漏邊界情況） | **依賴**：步驟 3.1

在 `store.sub(finishChapterAtom, ...)` 回呼中（line 17-88），`isSuccess` 分支加入：

```typescript
if (isSuccess) {
    // ... 現有 icon 更新邏輯 ...

    // 線性觀看：即時更新鎖定/解鎖狀態
    const response = store.get(finishChapterAtom)
    const unlocked = response.unlocked_chapter_ids
    const locked = response.locked_chapter_ids

    if (unlocked !== null && unlocked !== undefined) {
        updateChapterLockStatus(unlocked, locked || [])
    }
}
```

新增輔助函式：

```typescript
function updateChapterLockStatus(unlockedIds: number[], lockedIds: number[]) {
    // 解鎖章節
    for (const id of unlockedIds) {
        const $li = $(`li[data-post-id="${id}"]`)
        if ($li.length === 0) continue
        $li.removeClass('pc-chapter-locked')
        $li.removeAttr('data-locked')
        $li.removeAttr('data-lock-message')
        $li.find('span').css('opacity', '')
        // icon 恢復：使用 API 回應的 icon_html（僅當前章節有）
        // 其他章節的 icon 保持鎖頭（下次頁面載入時 PHP 渲染正確 icon）
        // 或者：從 API 取得每個解鎖章節的 icon
    }

    // 鎖定章節（取消完成時）
    for (const id of lockedIds) {
        const $li = $(`li[data-post-id="${id}"]`)
        if ($li.length === 0) continue
        $li.addClass('pc-chapter-locked')
        $li.attr('data-locked', 'true')
        $li.find('span').css('opacity', '0.5')
        // 替換 icon 為鎖頭
        const $icon = $li.find('.pc-chapter-icon')
        if ($icon.length > 0) {
            $icon.html(LOCK_ICON_SVG)
        }
    }

    // 更新底部「下一個」按鈕
    updateNextButton()
}

function updateNextButton() {
    const $nextBtn = $('.pc-next-post')
    if ($nextBtn.length === 0) return
    const nextLocked = (window as any).pc_data?.next_chapter_locked
    if (nextLocked) {
        $nextBtn.addClass('pc-btn-disabled pointer-events-none opacity-50')
            .attr('aria-disabled', 'true')
            .attr('href', 'javascript:void(0)')
    } else {
        $nextBtn.removeClass('pc-btn-disabled pointer-events-none opacity-50')
            .removeAttr('aria-disabled')
        // 恢復原始 href — 需要從 data 屬性取得
    }
}
```

同時需要擴充 `finishChapterAtom` 的型別定義（在 `inc/assets/src/store.ts`），增加 `unlocked_chapter_ids` 和 `locked_chapter_ids` 欄位。

在 API 回呼（自動完成 line 120-139 和手動完成 line 184-205）中，從 `xhr.responseJSON.data` 取出新欄位並存入 store：

```typescript
const unlocked_chapter_ids = xhr?.responseJSON?.data?.unlocked_chapter_ids ?? null
const locked_chapter_ids = xhr?.responseJSON?.data?.locked_chapter_ids ?? null

store.set(finishChapterAtom, (prev) => ({
    ...prev,
    // ... existing fields ...
    unlocked_chapter_ids,
    locked_chapter_ids,
}))
```

**自動完成成功後更新 `pc_data.next_chapter_locked`**：

```typescript
if (is_this_chapter_finished && unlocked_chapter_ids) {
    // 下一章已在 unlocked 列表中
    const nextId = getNextChapterId() // 從 DOM 或 pc_data 取得
    if (nextId && unlocked_chapter_ids.includes(nextId)) {
        (window as any).pc_data.next_chapter_locked = false
    }
}
```

**成功標準**：
- [ ] 完成章節後，下一章即時解鎖（移除鎖頭、恢復透明度）
- [ ] 取消完成後，後續章節即時重新鎖定
- [ ] 底部「下一個」按鈕即時更新
- [ ] `pc_data.next_chapter_locked` 即時更新供 Ended.tsx 使用

#### 3.3 修改 Ended.tsx 實作智慧判斷

**檔案**：`js/src/App2/Ended.tsx`
**複雜度**：中 | **風險**：中（與 Player.tsx 時序耦合） | **依賴**：步驟 3.2

完整改寫 Ended 元件：

```tsx
import { PlayIcon } from '@vidstack/react/icons'
import { __, sprintf } from '@wordpress/i18n'
import React, { useState, useEffect } from 'react'

const COUNTDOWN = 5

type TEndedProps = {
    next_post_url: string
}

const Ended = ({ next_post_url }: TEndedProps) => {
    const [countdown, setCountdown] = useState(COUNTDOWN)
    const [nextLocked, setNextLocked] = useState<boolean>(
        () => !!(window as any).pc_data?.next_chapter_locked
    )
    const isLinearViewing = !!(window as any).pc_data?.linear_viewing

    // 監聽 pc_data.next_chapter_locked 變化（自動完成後更新）
    useEffect(() => {
        const checkLocked = () => {
            setNextLocked(!!(window as any).pc_data?.next_chapter_locked)
        }
        // 輪詢檢查（因為 pc_data 是 window 物件，非 reactive）
        const interval = setInterval(checkLocked, 500)
        return () => clearInterval(interval)
    }, [])

    // 倒數邏輯：僅在下一章未鎖定時啟動
    const shouldCountdown = !isLinearViewing || !nextLocked

    useEffect(() => {
        if (!shouldCountdown) return

        const interval = setInterval(() => {
            if (countdown > 0) {
                setCountdown(countdown - 1)
            }
        }, 1000)

        if (0 === countdown && next_post_url) {
            window.location.href = next_post_url
        }

        return () => clearInterval(interval)
    }, [countdown, shouldCountdown])

    if (!next_post_url) {
        return null
    }

    // 下一章鎖定：顯示引導提示
    if (isLinearViewing && nextLocked) {
        return (
            <div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
                <div className="text-white text-center px-4">
                    <div className="text-4xl mb-4">🔒</div>
                    <div className="text-base font-thin mb-2">
                        {__('Complete this chapter to unlock the next one', 'power-course')}
                    </div>
                    <div className="text-sm opacity-70">
                        {__('Click the "Mark as finished" button below', 'power-course')}
                    </div>
                </div>
            </div>
        )
    }

    // 正常倒數跳轉
    return (
        <div className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10">
            <div
                className="w-12 h-12 p-2 bg-white/70 rounded-full mb-8 relative cursor-pointer"
                onClick={() => { window.location.href = next_post_url }}
            >
                <PlayIcon />
                <div className="progress-circle absolute top-0 left-0" style={{ top: '-0.5rem', left: '-0.5rem', width: '4rem', height: '4rem' }}>
                    <svg className="w-full h-full">
                        <circle cx="32" cy="32" r="28" fill="none" stroke="#ffffff" strokeWidth="4" strokeLinecap="butt" strokeDasharray="176" strokeDashoffset="176" transform="rotate(-90,32,32)" style={{ animation: `circle-progress ${COUNTDOWN}s linear forwards` }}></circle>
                    </svg>
                </div>
            </div>
            <div className="text-white text-base font-thin">
                {sprintf(
                    /* translators: %d: 倒數秒數 */
                    __('Next chapter will auto-play in %d seconds', 'power-course'),
                    countdown
                )}
            </div>
        </div>
    )
}

export default Ended
```

**成功標準**：
- [ ] 未啟用 linear → 正常 5 秒倒數跳轉（現有行為不變）
- [ ] 啟用 linear + 下一章已解鎖 → 正常 5 秒倒數跳轉
- [ ] 啟用 linear + 下一章鎖定 → 顯示引導提示，無倒數，無跳轉
- [ ] 自動完成觸發後 → `next_chapter_locked` 更新為 false → Ended re-render 為倒數模式
- [ ] 硬編碼中文字串已修正為 i18n（`sprintf + __`）

#### 3.4 新增線性觀看 CSS 樣式

**檔案**：`inc/assets/src/styles/linear-viewing.css`（新增）
**複雜度**：低 | **風險**：低 | **依賴**：無

```css
/* 鎖定章節樣式 */
.pc-chapter-locked {
    cursor: not-allowed !important;
}
.pc-chapter-locked span {
    opacity: 0.5;
}
.pc-chapter-locked:hover {
    background-color: transparent !important;
}

/* 底部導航按鈕禁用 */
.pc-btn-disabled {
    pointer-events: none;
    opacity: 0.5;
    cursor: not-allowed;
}

/* Toast 提示 */
.pc-toast {
    position: fixed;
    top: 1rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 9999;
    padding: 0.75rem 1.5rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: pc-toast-slide-in 0.3s ease-out;
}
.pc-toast-info {
    background-color: #e8f4fd;
    color: #1a73e8;
    border: 1px solid #90caf9;
}
.pc-toast-close {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    opacity: 0.6;
}
.pc-toast-close:hover {
    opacity: 1;
}
@keyframes pc-toast-slide-in {
    from { transform: translateX(-50%) translateY(-1rem); opacity: 0; }
    to { transform: translateX(-50%) translateY(0); opacity: 1; }
}
```

此 CSS 需要在教室頁面載入。確認在 `Bootstrap.php` 或教室模板中引入。

---

### 第四階段：管理端 React UI

#### 4.1 新增課程設定開關

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseOther/index.tsx`
**複雜度**：低 | **風險**：低 | **依賴**：無（meta 儲存走現有 `handle_save_course_meta_data` 通用邏輯）

在 "Course Information" section 結束（line 276 `</div>` 之後），外部課程區塊之前，新增教室設定區塊：

```tsx
{/* 外部課程隱藏線性觀看設定 */}
{!isExternal && (
    <>
        <Heading>{__('Classroom Settings', 'power-course')}</Heading>
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-3 gap-6">
            <FiSwitch
                formItemProps={{
                    name: ['enable_linear_viewing'],
                    label: __('Enable sequential learning mode', 'power-course'),
                    tooltip: __(
                        'When enabled, students must complete chapters in order. The next chapter will only unlock after the current one is completed.',
                        'power-course'
                    ),
                }}
            />
        </div>
    </>
)}
```

meta key `enable_linear_viewing` 會透過現有的 `handle_save_course_meta_data()` 自動以 `$product->update_meta_data()` 儲存，無需後端額外處理。

**前端排序警告**：在排序成功回呼中，檢查 API 回應的 `warning` 欄位，若存在則顯示 Ant Design `message.warning()`。需確認排序功能的前端程式碼位置（搜尋 `chapters/sort` 的 API 呼叫處）。

**成功標準**：
- [ ] 開關在「其他設定」分頁 → 「教室設定」區域
- [ ] 切換後隨課程儲存正確寫入 meta
- [ ] 外部課程隱藏此開關
- [ ] 排序時若課程啟用 linear，顯示 warning toast

---

### 第五階段：E2E 測試

#### 5.1 管理端設定 E2E

**檔案**：`tests/e2e/01-admin/linear-viewing-setting.spec.ts`（新增）
**複雜度**：低 | **風險**：低 | **依賴**：第四階段

測試場景：
1. 管理員進入課程編輯 →「其他設定」→ 找到「啟用線性觀看」開關
2. 開關預設關閉
3. 開啟開關 → 儲存 → 重新載入 → 確認開關仍開啟
4. 關閉開關 → 儲存 → 重新載入 → 確認開關仍關閉
5. 外部課程不顯示此開關

#### 5.2 教室前台線性觀看 E2E

**檔案**：`tests/e2e/02-frontend/linear-viewing-classroom.spec.ts`（新增）
**複雜度**：高 | **風險**：中 | **依賴**：第一~四階段

測試場景：

**基本鎖定行為：**
1. 開啟 linear 的課程 → 學員進入教室 → 側邊欄第一章解鎖，其餘鎖定
2. 鎖定章節顯示鎖頭圖示 + 指名提示文字

**順序解鎖：**
3. 完成第一章 → 第二章即時解鎖（無頁面重載）
4. 側邊欄鎖頭圖示消失，透明度恢復

**鎖定章節點擊：**
5. 點擊鎖定章節 → dialog 彈出顯示正確訊息 → 點「確定」關閉

**URL 直接存取：**
6. 直接存取鎖定章節 URL → redirect 到正確章節 → toast 提示顯示

**取消完成重新鎖定：**
7. 取消第一章完成 → 第二章重新鎖定

**底部導航按鈕：**
8. 下一章鎖定 → 「下一個」按鈕灰色不可點擊

**管理員預覽：**
9. 管理員可自由存取所有章節（無鎖定）

**未啟用 linear 的課程：**
10. 未啟用課程 → 所有章節均可自由存取

---

## 測試策略

| 類型 | 檔案 | 覆蓋範圍 |
| --- | --- | --- |
| E2E（管理端） | `tests/e2e/01-admin/linear-viewing-setting.spec.ts` | 設定開關 CRUD |
| E2E（前台） | `tests/e2e/02-frontend/linear-viewing-classroom.spec.ts` | 完整教室互動流程 |
| 手動驗證 | — | 影片自動完成 + Ended 智慧判斷（需影片播放環境） |

**測試執行指令**：
```bash
pnpm run test:e2e:admin     # 管理端 E2E
pnpm run test:e2e:frontend  # 前台 E2E
```

**關鍵邊界情況（E2E 必覆蓋）**：
- 無完成紀錄 → 僅第一章解鎖
- 跳躍完成（自由模式遺留）→ 最遠進度模式
- 取消完成 → 重新鎖定
- 管理員預覽免除
- 未啟用 linear 不受影響

---

## 依賴項目

| 依賴 | 類型 | 說明 |
| --- | --- | --- |
| `get_flatten_post_ids()` | 現有方法 | 章節平攤順序，已有 cache |
| `AVLChapterMeta` | 現有類 | 章節完成紀錄 CRUD |
| `ChapterUtils::get_next_post_id()` | 現有方法 | 取得下一章 ID |
| `CourseUtils::get_course_progress()` | 現有方法 | 課程進度計算 |
| jQuery | 現有依賴 | 教室前端 DOM 操作 |
| `@wordpress/i18n` | 現有依賴 | 前端 i18n |
| Jotai (store) | 現有依賴 | finishChapterAtom 狀態管理 |
| 無新外部依賴 | — | 不需安裝任何新套件 |

---

## 限制條件

此計劃**不會**做的事：

1. **不建立獨立的 API 端點**（如 `GET /linear-viewing-status`）— 初始狀態由 PHP 渲染，更新由 toggle API 回傳
2. **不 React 化側邊欄** — 維持 PHP 渲染 + jQuery DOM 操作
3. **不支援管理員手動解鎖個別章節** — 需求文件明確列為「後續可評估」
4. **不支援「跳過特定章節」功能** — 不在此次範圍
5. **不修改 `get_flatten_post_ids()` 邏輯** — Q1 確認為 B（包含父章節），現有邏輯不變
6. **不處理並發安全**（如兩個 tab 同時完成/取消）— 現有 toggle API 已是 toggle 語義，Last Write Wins
7. **不新增自訂資料表** — 使用現有 `pc_avl_chaptermeta`（`finished_at`）+ WooCommerce postmeta（`enable_linear_viewing`）

---

## 成功標準

對照 Issue #205 驗收清單：

- [ ] 管理員可在課程「其他設定」分頁看到「啟用線性觀看」開關，預設關閉
- [ ] 開啟後，學員進入教室時只有第一個章節是解鎖狀態（無完成紀錄時）
- [ ] 已完成章節中最遠位置的下一個章節為解鎖狀態（最遠進度模式）
- [ ] 鎖定的章節在側邊欄顯示鎖定圖示和指名提示文字
- [ ] 學員點擊鎖定章節時，顯示友善提示訊息（dialog）而非錯誤頁面
- [ ] 學員透過 URL 直接存取鎖定章節時，自動導向當前應觀看章節並顯示 toast
- [ ] 學員完成章節後，下一個章節即時解鎖（不需重新載入頁面）
- [ ] 學員取消完成章節後，後續章節即時重新鎖定
- [ ] 影片播放達 95% 自動完成後，下一章節即時解鎖
- [ ] 所有章節（含父章節與子章節）平攤為一維順序
- [ ] 底部「下一個」按鈕在下一章鎖定時顯示但禁用
- [ ] 影片播完時，若下一章節被鎖定，不自動跳轉
- [ ] toggle API 驗證線性觀看規則，鎖定章節回傳 403
- [ ] 管理員預覽模式下，所有章節可自由存取
- [ ] 未啟用線性觀看的課程，行為完全一致
- [ ] 學員已有完成紀錄在功能啟用後仍有效
- [ ] Ended.tsx 硬編碼中文字串修正為 i18n

## 預估複雜度：中

核心演算法（最遠進度模式）為 O(n) 線性掃描，邏輯簡單。主要工作量在前後端整合點多（sidebar HTML、toggle API、Ended.tsx、prev-next、finishChapter.ts、toast/dialog）。無新資料表、無新 API 端點、無新外部依賴，風險可控。
