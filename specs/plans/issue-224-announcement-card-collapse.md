# Issue #224 實作計畫 — 課程銷售頁公告卡片內文折疊

> 規格來源：
> - Clarify：`specs/clarify/2026-05-14-issue224-announcement-card-collapse.md`
> - Feature：`specs/features/announcement/銷售頁公告卡片內文折疊.feature`
> - 用戶確認決策：`A A A A A A B`
>
> 範圍模式：**HOLD SCOPE**（Issue 範圍清楚、預估 ≤ 6 個檔案變動、無架構決策）

---

## 1. 需求重述（planner 視角）

當課程銷售頁的公告卡片內文**渲染後超過 N 行視覺行**（預設 N=3）時，
卡片應折疊只顯示前 N 行並附「展開全文 / 收合」切換按鈕；
≤ N 行時直接完整顯示，**不渲染**任何折疊控制元件。

- 「N 行」判斷依據：CSS `line-clamp` + JS `scrollHeight > clientHeight + 1`（容差 1px）
- 預設折疊（不是預設展開）
- 按鈕沿用既有 `expandable.php` 視覺：漸層遮罩 fade-out + 按鈕置中下方
- 行數可透過 `apply_filters('pc_announcement_collapse_lines', 3)` 客製
- 僅套用於 `inc/templates/pages/course-product/announcement.php`，不影響其他位置
- 切換按鈕文字沿用既有 i18n msgid `Expand content` / `Collapse`（已存在於 `manual.json` 與 `.pot`）

## 2. 與既有功能的層級區隔

| 層級 | 處理對象 | 行為 | Feature |
|------|---------|------|---------|
| 多則之間 | 公告卡片 1 與 卡片 2 之間 | 手風琴（最新展開、其餘折疊） | `銷售頁公告區塊顯示.feature` |
| **單則卡片內** | 卡片 1 自己的長內文 | **line-clamp 折疊**（本 Issue） | `銷售頁公告卡片內文折疊.feature` |

兩者可同時生效：例如最新一則公告手風琴展開，但其內文若 > 3 行仍會被內部折疊。
> ℹ️ 目前 `announcement.php` 並未實作多則之間的手風琴行為（template 直接 foreach 渲染所有 `pc-alert`），那是另一個獨立議題；本 Issue **不處理**多則之間的手風琴。

---

## 3. 風險識別與已驗證的現況

### 3.1 已驗證的現況

| 項目 | 狀態 |
|------|------|
| `inc/templates/pages/course-product/announcement.php` | 目前 foreach 直接渲染，無折疊邏輯 |
| `inc/assets/src/events/` Bootstrap 註冊模式 | `main.ts` → import from `./events` → 在 `$(document).ready` 內呼叫，沿用即可 |
| `inc/assets/src/events/index.ts` re-export 模式 | 沿用即可，新增 `export * from './announcementToggle'` |
| `vite.config-for-wp.ts` 的 `@wordpress/i18n` shim | **已設定**（line 60-62），新 TS 檔可直接 `import { __ } from '@wordpress/i18n'` |
| i18n msgid `Expand content` / `Collapse` | **已存在**於 `languages/power-course.pot` 與 `scripts/i18n-translations/manual.json`，**不需新增任何字串** |
| Tailwind / DaisyUI utility classes 在前台 PHP 模板可用 | 既有 `announcement.php` 已使用 `bg-gradient-to-t from-base-100` 等，沿用即可 |
| `tailwindcss ^3.4` 已安裝 | 但**本專案無 `tailwind.config.js`**，無自訂 line-clamp safelist；建議用 inline CSS 或 `<style>` 區塊處理「行數動態」需求 |
| 既有 `pc-toggle-content` 元件 | 用**固定 px 高度**判斷，**不適合**本 Issue 的「行數視覺判定」，**不重用**，獨立成新元件以避免衝突 |
| 既有 `toggleContent.ts` | 同上，不影響本 Issue；保持原樣 |
| 既有 E2E `001-course-product-page-render.spec.ts` | 不涉及公告，本 Issue 新增獨立 spec 檔 |
| `tests/Integration/Announcement/` | 已有 query / CRUD test 樣板可參考（含 `insert_announcement` helper） |

### 3.2 風險登記表

| 風險 ID | 風險 | 影響 | 緩解 |
|---------|------|------|------|
| R1 | **FOUC（折疊判定前的閃爍）**：PHP SSR 階段無法得知文字行數，若 JS 未跑、button 已顯示，使用者會看到「短公告也出現展開按鈕又消失」的閃爍 | 中 | PHP 預設輸出 `pc-announcement-toggle` 容器時加 `hidden` 屬性 / `style="display:none"`；JS 偵測超出後才 `.removeAttribute('hidden')` |
| R2 | **CSS line-clamp 在 Safari ≤ 14 / Firefox ≤ 67 行為差異** | 低 | `-webkit-line-clamp` + `display: -webkit-box` 是公認跨瀏覽器寫法，現代瀏覽器（含 Safari 6+ / Firefox 68+）全部支援 |
| R3 | **長段落 wrap 後行數受視窗寬度影響**：手機端可能 5 行、桌面端只 3 行 | 中 | JS 監聽 `window.resize`（debounced 200ms）重新量測；折疊狀態保留不變，但「是否顯示按鈕」要重評 |
| R4 | **客製 N 行透過 `apply_filters` 後 N=10、內文僅 5 行**：此時 scrollHeight === clientHeight，JS 不顯示按鈕 ✓ | 低 | 已在 feature spec Example「客製為 10 行」涵蓋，JS 用統一邏輯即可 |
| R5 | **HTML 富文本含 `<br>` / 列表 / 超長連結**：line-clamp 對混合 inline / block 元素的處理不完全標準化 | 低 | `wpautop(wp_kses_post(...))` 保證內容被 `<p>` 包裹；line-clamp 限制最外層 box 高度，內部 inline 內容仍被 box 行數截斷 |
| R6 | **wp_kses_post 過濾後留下的 `<br>` 在 `display: -webkit-box` 容器內可能被忽略** | 低 | 為內文容器外多包一層真正的「line-clamp wrapper」，wpautop 的 `<p>` 元素保持在 wrapper 內，避免 `-webkit-box` 直接吃 raw 段落 |
| R7 | **`apply_filters` 回傳非整數或負數** | 低 | PHP 端用 `max(1, (int) apply_filters(...))` 強制收斂為正整數 |
| R8 | **PHPCS / PHPStan 對新增的 echo 輸出與 callback 嚴格檢查** | 低 | 沿用既有 `printf` / `esc_html__` / `esc_attr` 慣例；新增的 button 用 `<button type="button">` 而非 `<div>`（無障礙要求） |
| R9 | **`-webkit-line-clamp` 與 `aria-expanded="true"` 切換時需保留 transition** | 低 | 不做 CSS transition（line-clamp 切換本身不支援平滑過渡），只在 click handler 立即切換 class；漸層遮罩用 `opacity` transition 即可 |

### 3.3 GAP 與 ASM（已澄清）

無未澄清項目；用戶已透過 `A A A A A A B` 確認所有 7 個情境問題。

---

## 4. 資料流與錯誤處理登記表

### 4.1 資料流

```
PHP（SSR）
  └─ announcement.php
       ├─ 取得 $collapse_lines = max(1, (int) apply_filters('pc_announcement_collapse_lines', 3));
       ├─ 渲染 <style> 區塊定義 .pc-announcement-content / .pc-announcement-toggle CSS
       └─ foreach $announcements:
            ├─ 內文容器 <div class="pc-announcement-content"
            │       style="--pc-collapse-lines: {N};"
            │       id="pc-announcement-content-{id}"
            │       aria-expanded="false"
            │       data-collapse-lines="{N}">
            │     {wpautop(wp_kses_post($content))}
            │   </div>
            └─ 切換容器 <div class="pc-announcement-toggle ..." hidden
                     data-target="pc-announcement-content-{id}">
                  <button type="button"
                          aria-controls="pc-announcement-content-{id}"
                          aria-expanded="false">
                    {__('Expand content', 'power-course')}
                  </button>
                </div>

瀏覽器 DOM ready（jQuery $(document).ready）
  └─ announcementToggle()
       ├─ $('.pc-announcement-content').each
       │    ├─ measure: scrollHeight > clientHeight + 1
       │    ├─ 若 NO  → 把對應 .pc-announcement-toggle 從 DOM 移除（節省版面 + 無障礙乾淨）
       │    └─ 若 YES → 對應 toggle.removeAttribute('hidden')，bind click handler
       │
       ├─ click handler (delegated)：
       │    ├─ 讀 button 旁 content 的 aria-expanded
       │    ├─ toggle aria-expanded（content + button）
       │    ├─ 更新按鈕文字（Expand content ↔ Collapse）
       │    └─ 切換 toggle 容器 .is-expanded class（CSS 控制漸層遮罩淡出）
       │
       └─ window.resize（debounced 200ms）
            └─ 重跑 each 與顯示/隱藏邏輯（但保留 aria-expanded 狀態）
```

### 4.2 錯誤處理登記表

| 場景 | 行為 |
|------|------|
| PHP `$announcements` 為空 | 既有邏輯 `return`，本 Issue 無變動 |
| PHP `apply_filters` 回傳非整數 | `max(1, (int) ...)` 收斂為 ≥ 1 |
| JS 載入失敗 / 未執行 | 內文預設套 `line-clamp`，使用者看到折疊狀態的 3 行 + **不會看到** toggle 按鈕（因預設 `hidden`）→ 退化為「永遠折疊」但仍可讀，可接受 |
| JS 跑完但內文 ≤ N 行 | 移除 toggle 容器 + 移除 line-clamp 限制（在 content 上補 `aria-expanded="true"` 或加 `.is-short` class 去除 box-orient）→ 完整顯示無按鈕 |
| `window.resize` 後從 ≤N 變 >N（手機端） | 重跑 each，若 toggle 已被移除則沒救（已不在 DOM），可接受：刷新頁面恢復；若用 `hidden` 而非 remove 則可恢復 → **改採 `hidden` 而非 `remove`**（折衷取響應式可用性） |

> **修正決策**：採用 `hidden` toggle 而非完全 `remove`，以支援響應式行為（R3）。

---

## 5. 詳細實作步驟

實作順序遵循「**先紅燈測試 → 再實作**」的 TDD 紀律，由 `@zenbu-powers:tdd-coordinator` 統籌 spawn 下游 agent。

### 5.1 檔案異動清單（總計：3 個檔案修改 + 2 個新增）

| # | 動作 | 檔案路徑 | 預估行數 | 負責 agent |
|---|------|---------|---------|-----------|
| 1 | **新增** | `tests/e2e/02-frontend/019-announcement-card-collapse.spec.ts` | ~150 | `test-creator` |
| 2 | **新增** | `tests/Integration/Announcement/AnnouncementCollapseFilterTest.php` | ~80 | `test-creator` |
| 3 | **修改** | `inc/templates/pages/course-product/announcement.php` | +60 / -8 | `wordpress-master` |
| 4 | **新增** | `inc/assets/src/events/announcementToggle.ts` | ~80 | （在 PHP 改完後）`wordpress-master` 或 react-master（檔案在前台 vanilla TS，由 wordpress-master 負責 — 與 `toggleContent.ts` 同層） |
| 5 | **修改** | `inc/assets/src/events/index.ts` | +1 行 | 同上 |
| 6 | **修改** | `inc/assets/src/main.ts` | +2 行（import + 呼叫） | 同上 |

> 📌 i18n 檔案無需異動（`Expand content` / `Collapse` 已存在於 `.pot` / `manual.json`）。
> 📌 若有手動跑 `pnpm run i18n:build` 仍應跑一次確認 diff 乾淨；新功能未引入新 msgid，預期 `.po` / `.mo` / `.json` 無變動。

### 5.2 階段 R（Red）— 撰寫測試先行（先寫先掛紅燈）

#### R-1. E2E spec：`tests/e2e/02-frontend/019-announcement-card-collapse.spec.ts`

**測試對象**：銷售頁公告卡片折疊互動。

**測試前置**：
- 透過 `tests/e2e/helpers/api-client.ts` 新增 helper `createAnnouncement(courseId, { title, content })`，呼叫 `power-course/v2 announcements` POST endpoint（若不存在則用 `pcPost` + 直接 `wp_insert_post` via WP REST `/wp/v2/pc_announcement`）
  - ⚠️ 先查 `inc/classes/Resources/Announcement/Core/Api.php` 是否有 public CRUD endpoint；若無則直接用 `wp_insert_post` 透過 WP REST 走 admin auth
- 沿用 `ensureFrontendTestData()` 拿 `courseUrl`
- 在 `test.beforeAll` 階段建立至少 3 則測試公告：
  - 短公告（1 段 1 行）
  - 中公告（剛好 3 行：`<p>L1<br>L2<br>L3</p>`）
  - 長公告（5 行：`<p>L1<br>L2<br>L3<br>L4<br>L5</p>`）

**測試案例**（對應 `銷售頁公告卡片內文折疊.feature`）：

```ts
test.describe('課程銷售頁公告卡片內文折疊', () => {
  test('短公告 (1 行) 應完整顯示且無切換按鈕', ...)
  test('剛好 3 行公告應完整顯示且無切換按鈕', ...)
  test('5 行公告應折疊只顯示前 3 行', ...)
  test('5 行公告應顯示「Expand content」按鈕', ...)
  test('5 行公告的內文容器 aria-expanded 應為 "false"', ...)
  test('點擊展開後 aria-expanded 應為 "true" 且按鈕文字變為 "Collapse"', ...)
  test('點擊收合後恢復折疊狀態', ...)
  test('多則公告各自獨立的展開/收合狀態', ...)
  test('鍵盤 Tab 可聚焦切換按鈕並以 Enter 觸發', ...)
})
```

**SELECTOR 補強**：在 `tests/e2e/fixtures/test-data.ts` 的 `SELECTORS` 新增 `announcement` 區塊：
```ts
announcement: {
  section: '#pc-announcement-section',
  list: '.pc-announcement-list',
  card: '.pc-alert[data-announcement-id]',
  content: '.pc-announcement-content',
  toggle: '.pc-announcement-toggle',
  toggleButton: '.pc-announcement-toggle button',
}
```

#### R-2. Integration test：`tests/Integration/Announcement/AnnouncementCollapseFilterTest.php`

**測試對象**：`pc_announcement_collapse_lines` filter 行為（無法在 E2E 觸發，因需 hook PHP filter）。

**測試案例**：
- `test_預設行數為3`：不掛 filter，render 銷售頁取得 HTML，驗證內文容器有 `--pc-collapse-lines: 3` 或 `data-collapse-lines="3"`
- `test_filter客製為5行生效`：掛 `add_filter('pc_announcement_collapse_lines', fn() => 5)` 後 render，驗證輸出 `5`
- `test_filter回傳負數收斂為1`：掛 `add_filter(..., fn() => -10)` 後驗證輸出 `1`
- `test_filter回傳非整數型別收斂為整數`：掛 `add_filter(..., fn() => '3.7')` 後驗證輸出 `3`

**Render 方式**：
- 沿用 `tests/Integration/Announcement/AnnouncementQueryTest.php` 的 `insert_announcement()` 模式建立公告
- 用 `ob_start(); load_template('announcement.php', ['product' => $product]); $html = ob_get_clean();` 取得渲染輸出
- 用簡單字串匹配或 `DOMDocument` 驗證 `data-collapse-lines` 屬性
- ⚠️ 注意 `Plugin::load_template` 的 args 傳遞方式，需檢查實際 signature

### 5.3 階段 G（Green）— 實作程式碼讓測試通過

#### G-1. 修改 `inc/templates/pages/course-product/announcement.php`

**完整改寫後的結構**：

```php
<?php
/**
 * Course Product > Announcement Section
 *
 * Issue #224：公告內文超過 N 行（預設 3）時自動折疊並提供「展開全文 / 收合」切換。
 */

use J7\PowerCourse\Resources\Announcement\Service\Query as AnnouncementQuery;

$default_args = [ 'product' => $GLOBALS['course'] ?? null ];
$args         = wp_parse_args($args, $default_args);
[ 'product' => $product ] = $args;

if (! ( $product instanceof \WC_Product )) {
    return;
}

$course_id = (int) $product->get_id();
$user_id   = (int) \get_current_user_id();

/** @var array<int, array<string, mixed>> $announcements */
$announcements = AnnouncementQuery::list_public($course_id, $user_id);

if (empty($announcements)) {
    return;
}

/**
 * 公告卡片內文折疊行數
 *
 * @since Issue #224
 * @param int $lines 預設 3 行。≥ 1 的整數；非整數會被收斂。
 */
$collapse_lines = max( 1, (int) apply_filters( 'pc_announcement_collapse_lines', 3 ) );

echo '<section id="pc-announcement-section" class="mb-8">';
printf(
    '<h3 class="pc-title mb-8 text-xl font-normal text-base-content">%s</h3>',
    esc_html__('Announcements', 'power-course')
);

/* 折疊樣式（inline 隨 template 輸出，避免依賴未必存在的 tailwind line-clamp safelist） */
echo '<style>
.pc-announcement-content {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: var(--pc-collapse-lines, 3);
    overflow: hidden;
}
.pc-announcement-content[aria-expanded="true"] {
    display: block;
    -webkit-line-clamp: unset;
    overflow: visible;
}
.pc-announcement-toggle {
    /* 漸層遮罩 + 按鈕，由 PHP 輸出 utility classes */
}
.pc-announcement-toggle.is-expanded .pc-announcement-toggle__mask { opacity: 0; }
</style>';

echo '<div class="pc-announcement-list flex flex-col gap-2">';
foreach ($announcements as $announcement) {
    $announcement_id   = (int) ( $announcement['id'] ?? 0 );
    $post_title        = (string) ( $announcement['post_title'] ?? '' );
    $post_content      = (string) ( $announcement['post_content'] ?? '' );
    $post_date_display = isset($announcement['post_date'])
        ? \wp_date(\get_option('date_format'), strtotime( (string) $announcement['post_date']))
        : '';
    $content_dom_id    = sprintf('pc-announcement-content-%d', $announcement_id);

    printf(
        /* html */
        '
<div role="alert" class="pc-alert" data-announcement-id="%1$d">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-info h-6 w-6 shrink-0 self-start mt-0">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <div class="flex flex-col gap-0 w-full">
        <span class="font-semibold text-base">%2$s</span>
        <div
            id="%6$s"
            class="pc-announcement-content text-sm leading-7 relative"
            style="--pc-collapse-lines: %7$d;"
            data-collapse-lines="%7$d"
            aria-expanded="false"
        >%4$s</div>
        <div class="pc-announcement-toggle w-full relative cursor-pointer text-sm text-primary flex justify-center items-center font-semibold" hidden data-target="%6$s">
            <div class="pc-announcement-toggle__mask absolute -top-10 left-0 w-full h-10 bg-gradient-to-t from-base-100 pointer-events-none transition-opacity"></div>
            <button type="button" class="pc-announcement-toggle__btn relative py-1 px-2 cursor-pointer bg-transparent border-0" aria-controls="%6$s" aria-expanded="false">%5$s</button>
        </div>
        <time class="text-xs text-base-content/60 whitespace-nowrap self-end mt-0">%3$s</time>
    </div>
</div>',
        (int) $announcement_id,
        esc_html($post_title),
        esc_html($post_date_display),
        \wpautop(wp_kses_post($post_content)),
        esc_html__('Expand content', 'power-course'),
        esc_attr($content_dom_id),
        (int) $collapse_lines,
    );
}
echo '</div>';
echo '</section>';
```

**修改要點**：
- `$collapse_lines` 從 `apply_filters` 取得並收斂
- 內文容器加 `class="pc-announcement-content"` + `style="--pc-collapse-lines:N"` + `aria-expanded="false"` + 唯一 `id`
- 內文之後新增 `.pc-announcement-toggle` 容器，預設 `hidden`，含漸層遮罩 + button
- button 用 `<button type="button">` 而非 `<div>`（無障礙、Q7 的鍵盤可及性 Example）
- button 文字用 `esc_html__('Expand content', 'power-course')`（PHP 端 SSR fallback）
- 所有 placeholder 用編號（`%1$d`, `%2$s` 等），符合 i18n.rule.md
- inline `<style>` 區塊集中折疊樣式，避免依賴 Tailwind safelist

#### G-2. 新增 `inc/assets/src/events/announcementToggle.ts`

```ts
import { __ } from '@wordpress/i18n'
import $ from 'jquery'
import { debounce } from 'lodash-es'

/**
 * 課程銷售頁公告卡片內文折疊（Issue #224）
 *
 * 邏輯：
 * 1. DOM ready 後遍歷 .pc-announcement-content，量測 scrollHeight > clientHeight + 1
 * 2. 超出者 → 顯示對應 .pc-announcement-toggle（移除 hidden）
 * 3. 未超出者 → 對 content 補 aria-expanded="true" 讓 CSS 移除 line-clamp，toggle 保持 hidden
 * 4. 點擊 button → toggle aria-expanded、更新按鈕文字、切換 .is-expanded class
 * 5. window.resize（debounced 200ms）→ 重評（避免轉向後行數變化）
 */
export const announcementToggle = () => {
    const SELECTOR_CONTENT = '.pc-announcement-content'
    const SELECTOR_TOGGLE  = '.pc-announcement-toggle'
    const SELECTOR_BTN     = '.pc-announcement-toggle__btn'

    const reevaluate = () => {
        $(SELECTOR_CONTENT).each(function () {
            const content = this as HTMLElement
            const targetId = content.id
            const toggle = document.querySelector<HTMLElement>(
                `${SELECTOR_TOGGLE}[data-target="${targetId}"]`,
            )
            if (!toggle) return

            // 暫時移除 aria-expanded 影響量測 → 強制以折疊狀態 measure
            const wasExpanded = content.getAttribute('aria-expanded') === 'true'
            if (wasExpanded) content.setAttribute('aria-expanded', 'false')

            const overflows = content.scrollHeight > content.clientHeight + 1

            if (wasExpanded && overflows) {
                // 還原使用者展開狀態
                content.setAttribute('aria-expanded', 'true')
            }

            if (overflows) {
                toggle.removeAttribute('hidden')
            } else {
                toggle.setAttribute('hidden', '')
                // 不超出時，徹底展開讓內文可見（CSS 預設仍套 line-clamp 但用 aria-expanded=true 覆寫）
                content.setAttribute('aria-expanded', 'true')
            }
        })
    }

    const onToggleClick = (e: JQuery.ClickEvent) => {
        e.preventDefault()
        const $btn = $(e.currentTarget)
        const $toggle = $btn.closest(SELECTOR_TOGGLE)
        const targetId = $toggle.data('target') as string
        const $content = $(`#${targetId}`)
        if (!$content.length) return

        const isExpanded = $content.attr('aria-expanded') === 'true'
        const next = !isExpanded
        $content.attr('aria-expanded', next ? 'true' : 'false')
        $btn.attr('aria-expanded', next ? 'true' : 'false')
        $btn.text(
            next
                ? __('Collapse', 'power-course')
                : __('Expand content', 'power-course'),
        )
        $toggle.toggleClass('is-expanded', next)
    }

    // 使用事件委派，避免 toggle 容器後續被 reevaluate 顯示/隱藏導致 handler 失效
    $(document).off('click.pcAnnouncement', SELECTOR_BTN)
    $(document).on('click.pcAnnouncement', SELECTOR_BTN, onToggleClick)

    reevaluate()
    $(window).on('resize.pcAnnouncement', debounce(reevaluate, 200))
}
```

**實作要點**：
- 使用 `@wordpress/i18n` 的 `__` 取得翻譯文字（透過 shim 與 PHP 共用 i18n store）
- jQuery 風格 + lodash debounce（與 `coursesProduct.ts` 一致）
- 事件委派 (`$(document).on('click', SELECTOR, ...)`)，handler 不會因 reevaluate 失效
- 量測前先強制設 `aria-expanded="false"` 避免 false negative（容器已展開 → scrollHeight === clientHeight）
- ≤ N 行的情境：補 `aria-expanded="true"` 讓 CSS 移除 line-clamp（display: block，正常文字流）
- `resize` 監聽器用 `.pcAnnouncement` namespace 避免汙染

#### G-3. 修改 `inc/assets/src/events/index.ts`

```ts
export * from './finishChapter'
export * from './dynamicWidth'
export * from './tabs'
export * from './coursesProduct'
export * from './toggleContent'
export * from './countdown'
export * from './comment'
export * from './cart'
export * from './HlsSupport'
export * from './watermarkPDF'
export * from './linearViewing'
export * from './announcementToggle' // Issue #224
```

#### G-4. 修改 `inc/assets/src/main.ts`

```ts
import {
    finishChapter,
    dynamicWidth,
    tabs,
    coursesProduct,
    toggleContent,
    countdown,
    CommentApp,
    cart,
    HlsSupport,
    watermarkPDF,
    linearViewing,
    announcementToggle, // Issue #224
} from './events'

// ... 在 $(document).ready 內 ...
toggleContent()
countdown()
HlsSupport()
announcementToggle() // Issue #224：公告卡片內文折疊
```

### 5.4 階段 V（驗證）— Lint / 測試 / i18n / 手動瀏覽器驗證

執行順序（由 tdd-coordinator 接力 spawn 對應 agent）：

1. **`pnpm run lint:php`**（phpcbf + phpcs + phpstan level 9）→ 必須通過
2. **`pnpm run lint:ts`**（ESLint + Prettier auto-fix）→ 必須通過
3. **`pnpm run build:wp`**（Vite build 前台 bundle）→ 必須產生 `inc/assets/dist/index.js`
4. **`composer run test -- --filter=AnnouncementCollapseFilterTest`** → 4 個測試全綠
5. **`pnpm run i18n:build`** → 確認 `git diff languages/` 沒有預期外的字串新增（不應有；msgid 全部沿用）
6. **`pnpm run test:e2e:frontend -- 019-announcement-card-collapse`** → 全綠
7. **playwright-cli 手動瀏覽器驗證**（透過 `https://local-turbo.powerhouse.tw`）：
   - 登入後台建立一則 5 行公告，前台觀察折疊 + 展開行為
   - 截圖回報 PR

### 5.5 階段 R（Refactor，僅在測試全綠後執行）

- 觀察 PHP 端 inline `<style>` 是否該獨立成 `inc/assets/src/styles/announcement.css`（同 `trial-videos-swiper.css` 模式）—— 但這會增加 Vite entry，HOLD SCOPE 模式下**不做**，留 TODO 註解
- 觀察 button 文字切換能否抽成共用 utility（如 `js/src/shims/wordpress-i18n.ts` 旁加 helper）—— 同上，**不做**
- 移除 R/G 過程中可能殘留的 console.log / 註解雜訊

---

## 6. 測試策略

### 6.1 Integration Test（PHPUnit + WP_UnitTestCase）

**為何用 IT 而非單純 E2E**：
- `apply_filters('pc_announcement_collapse_lines', N)` 的 N 收斂行為（負數→1、字串→整數、預設 3）無法在 E2E 中觸發（需 hook PHP filter）
- IT 速度快、可窮舉邊界情境
- 沿用既有 `tests/Integration/Announcement/` 目錄與 `insert_announcement()` helper

**覆蓋範圍**：
- ✅ 預設 N=3
- ✅ Filter 客製 N=5
- ✅ Filter 回傳負數 → 收斂 1
- ✅ Filter 回傳非整數型別 → 收斂整數

**不覆蓋**：DOM 互動、視覺折疊（屬於 E2E 範疇）

### 6.2 E2E Test（Playwright）

**為何用 E2E**：
- 折疊邏輯的核心是「**渲染後**的視覺行數判定」，這只有真實瀏覽器能驗證
- 點擊互動、`aria-expanded` 切換、按鈕文字變化、鍵盤可及性是 E2E 強項

**覆蓋範圍**（對應 `銷售頁公告卡片內文折疊.feature` 的 Rule）：
- ✅ 短公告（≤ 3 行）完整顯示無按鈕
- ✅ 長公告（> 3 行）折疊 + 顯示按鈕
- ✅ 點擊展開 → `aria-expanded="true"` + 按鈕文字 = "Collapse"
- ✅ 點擊收合 → `aria-expanded="false"` + 按鈕文字 = "Expand content"
- ✅ 多則公告獨立狀態
- ✅ 鍵盤 Tab + Enter 可觸發切換

**不覆蓋**：
- `apply_filters` 客製行為（已在 IT 覆蓋）
- 響應式行數變化（手機端 4 行、桌面 3 行）— 屬 P2 邊界，留作後續加強

### 6.3 手動瀏覽器驗證

使用 `playwright-cli` skill 於 `https://local-turbo.powerhouse.tw`：
1. 後台建立一則 5 行 + 一則 1 行公告，掛在同一課程
2. 前台 visit 銷售頁
3. 截圖：(a) 1 行公告完整顯示無按鈕；(b) 5 行公告折疊 + 漸層遮罩 + Expand content 按鈕
4. 點擊展開後再截圖，附在 PR description

---

## 7. 依賴關係與實作順序

```
[R-1] E2E spec 紅燈（test-creator）
    │
    └─ 同時可並行：
[R-2] Integration test 紅燈（test-creator）
    │
    ▼
[G-1] 修改 announcement.php（wordpress-master）─→ 讓 IT 大部分綠
    │
    ▼
[G-2] 新增 announcementToggle.ts（wordpress-master）─→ JS 互動完成
    │
    ├─ [G-3] 修改 index.ts（同 PR 內必須一起）
    └─ [G-4] 修改 main.ts（同 PR 內必須一起）
    │
    ▼
[V-1] lint:php / lint:ts
[V-2] build:wp（產 bundle）
[V-3] composer run test
[V-4] pnpm run i18n:build（diff 確認）
[V-5] pnpm run test:e2e:frontend
[V-6] playwright-cli 手動驗證
    │
    ▼
[R] Refactor pass（必要時）
```

> ✅ R-1 與 R-2 可並行（不同檔案、無依賴），但建議 R-2 先跑（PHP IT 較容易先看出 template render 是否正確）。

---

## 8. PR 自我檢查清單（交給 tdd-coordinator 接力時用）

- [ ] `announcement.php` 內文容器有 `class="pc-announcement-content"` + `aria-expanded="false"` + 唯一 `id`
- [ ] `.pc-announcement-toggle` 容器預設 `hidden`，JS 偵測後才顯示
- [ ] button 是 `<button type="button">` 而非 `<div>`（無障礙 + Q7 鍵盤可及性 Example）
- [ ] PHP 端輸出文字用 `esc_html__('Expand content', 'power-course')`，未引入新 msgid
- [ ] `apply_filters('pc_announcement_collapse_lines', 3)` 回傳值經 `max(1, (int) ...)` 收斂
- [ ] JS 端 import `__` 來自 `@wordpress/i18n`（會走 shim 到 `window.wp.i18n`）
- [ ] JS 端事件用 delegate（`$(document).on('click', SELECTOR, ...)`）
- [ ] JS 端 `window.resize` 用 lodash `debounce` 包裹
- [ ] `inc/assets/src/events/index.ts` 與 `main.ts` 都已加新 module 並呼叫
- [ ] `pnpm run lint:php`、`pnpm run lint:ts` 全綠
- [ ] `pnpm run build:wp` 成功且 `inc/assets/dist/index.js` 包含 `announcementToggle`
- [ ] `composer run test --filter=AnnouncementCollapseFilterTest` 全綠
- [ ] `pnpm run test:e2e:frontend -- 019-announcement-card-collapse` 全綠
- [ ] `pnpm run i18n:build` 後 `git diff languages/` 無預期外字串新增
- [ ] playwright-cli 手動驗證短公告 / 長公告兩個截圖已附 PR

---

## 9. 不在本 Issue 範圍（明示 defer）

- ❌ 多則公告之間的手風琴（最新展開、其餘折疊）— 由 `銷售頁公告區塊顯示.feature` 規範，但目前 template 尚未實作，與本 Issue 解耦
- ❌ 教室頁面的公告區塊（如有）
- ❌ Email 通知 / 後台預覽的折疊
- ❌ 後台設定 UI 讓站長 GUI 調整行數（已用 `apply_filters` 替代）
- ❌ 響應式行數動態調整（手機端 / 桌面端不同行數）— 屬 P2，目前統一 `apply_filters` 一次決定

---

## 10. 交接 tdd-coordinator

**TDD 藍圖摘要**：

1. **Red phase**（並行）
   - spawn `test-creator` 寫 `019-announcement-card-collapse.spec.ts` + 在 `fixtures/test-data.ts` 補 `SELECTORS.announcement` + 在 `helpers/api-client.ts` 補 `createAnnouncement()` helper
   - spawn `test-creator` 寫 `AnnouncementCollapseFilterTest.php`
   - 跑兩組測試確認全紅 ✅

2. **Green phase**（序列）
   - spawn `wordpress-master` 改 `announcement.php` → 跑 IT 應全綠
   - spawn `wordpress-master` 新增 `announcementToggle.ts` + 改 `events/index.ts` + 改 `main.ts` → 跑 E2E 應全綠
   - 跑 `pnpm run lint:php` / `lint:ts` / `build:wp` 三道驗證

3. **Refactor phase**
   - 不主動 spawn reviewer（依規則自動驗收由 Stop hook → acceptance-evaluator 把關）
   - 若想做安全敏感審查，opt-in `@zenbu-powers:wordpress-reviewer`（本 Issue 無資料庫寫入、無 nonce 場景，風險低）

4. **doc-updater**
   - 本 Issue 變更不影響 CLAUDE.md / rules（i18n / wordpress / react / e2e 規範皆完全沿用），**不需 spawn**
   - 若有變動 SELECTORS 對外曝光，可考慮在 e2e-testing.rule.md 補一條，但屬可選

5. **finishing-branch**
   - 本任務在 `issue/224` 分支上，commit 完所有變動後跑 finishing-a-development-branch skill
   - 選擇開 PR，PR 模板自動帶 `Closes #224`
