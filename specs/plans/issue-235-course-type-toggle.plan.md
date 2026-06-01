# 實作計劃：內外部課程切換、名稱備註 (Issue #235)

## 概述

讓「站內課程 ↔ 外部平台課程」於課程編輯頁可雙向切換（過去建立後型別鎖死，只能刪除重建），並把全域「外部課程」字面改為「外部平台課程」，避免與「外訓」混淆。資料保留語意：切換不刪除原章節、學員授權、Bundle 關聯、`product_url` / `button_text`，僅 UI 隱藏。

對應規格：

- `specs/features/external-course/切換課程類型.feature`
- `specs/features/external-course/課程類型輔助說明文字.feature`
- `specs/activities/課程類型切換流程.activity`
- `specs/api/api.yml` — `POST /courses/{id}` 已新增 `type` + `confirm_type_change` 兩個欄位

## 範圍模式：HOLD SCOPE

clarifier session 已收斂為 7 個明確決策（**C D C C B D C**），`.feature` / `.activity` / `api.yml` 都已寫入 `./specs`。預估影響：

- 後端 **3 個生產檔**（`inc/classes/Api/Course.php`、`inc/classes/Utils/Course.php` 小增、`inc/templates/components/card/pricing.php` 文案）
- 前端 **6 個生產檔**（`Courses/Edit/index.tsx`、`Courses/List/Table/index.tsx`、新增 `Courses/Edit/components/TypeSwitcher/` 目錄 3 檔、`utils/constants.ts` 可選微調、type 定義）
- i18n **1 個對照表**（`scripts/i18n-translations/manual.json`）+ pipeline 產出 4 個 languages/ 檔
- 測試 **3 個新檔**（PHP 整合測試 1、E2E admin 1、existing E2E 修改 1）

範圍不再擴張；外部課程 UI 隔離邏輯（CourseDescription / CoursePrice / CourseOther 內的 `isExternal` 條件）已存在，無需重寫，型別切換成功後 record refetch 即可自然生效。

## 已確認的設計決策（Issue #235 clarify Q1–Q7）

| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1 | Segmented 控制項位置 | **C** — 頁面標題列旁（脫離 Tab，跨 Tab 全域可見），使用小型 `<Segmented>` |
| Q2 | 站內→外部時資料處理 | **D** — 全部保留隱藏，對話框列出影響清單（學員數 / 章節數 / Bundle 數） |
| Q3 | 外部→站內時 `product_url` / `button_text` | **C** — 保留 meta，僅 UI 隱藏，切回外部時自動帶回 |
| Q4 | 確認後送出時機 | **C** — 立刻送 API + 全頁 loading + 失敗回滾 UI |
| Q5 | 名稱字面 | **B** — 「外部課程」→「外部平台課程」（msgid `External platform course`） |
| Q6 | 說明文字呈現 | **D** — 下拉選單用 description（永遠可見）、編輯頁用 Tooltip（節省版面） |
| Q7 | API 設計 | **C** — 既有 `POST /courses/{id}` 擴充 + `confirm_type_change=true` 旗標 |

## 已知風險（來自程式碼研究）

| 風險 | 嚴重度 | 緩解措施 |
| --- | --- | --- |
| **WC_Product 實例切換的正確姿勢** —— `wc_get_product($id)` 由 product_type taxonomy 決定回傳 class（Simple / External），不能直接「把 Simple 物件 cast 成 External」 | 高 | 切換流程：(1) `wp_set_object_terms($id, $target_term, 'product_type')` 改 taxonomy；(2) `wc_delete_product_transients($id)`；(3) 由新 class 重新實例化 `new \WC_Product_External($id)` / `new \WC_Product_Simple($id)`，再依需要 `set_props` / 立刻 `save()`。對齊 WooCommerce 內部 `WC_Product_Factory::get_classname_from_product_type` 行為 |
| **外部→站內時 `product_url` 在 wp_postmeta 保留** —— `WC_Product_External` 的 `_product_url` 與 `_button_text` 是 extra_data props，儲存於 `wp_postmeta` 表，但 `WC_Product_Simple` 不會讀也不會寫這兩個 meta | 高 | 切換到 Simple 時**不主動刪 `_product_url` / `_button_text` meta**（僅 taxonomy 變更）；切回 External 時直接重新 `new WC_Product_External($id)` 即可從 postmeta 撈回原值，無需特殊處理 |
| **`handle_save_course_meta_data` 內 `is_external` 是由 `$product instanceof WC_Product_External` 推導** —— 若切換流程沒有重 fetch product 實例，後續資料寫入會走錯分支（例如把外部欄位的 meta 設到 Simple 上） | 高 | 切換邏輯抽到 `handle_save_course_data` / `handle_save_course_meta_data` **之前**執行；切換完成後重新 `wc_get_product($id)` 取得正確 class 的實例，再進入後續儲存流程；同一 callback 內單一 request 完成 type switch + 其他欄位 save |
| **`confirm_type_change=false` 時必須完全忽略 type 欄位**（向下相容） | 高 | 在 `separator()` 解析時：若 `confirm_type_change !== true`，把 `type` 從 `$body_params` 內 `unset` 掉再進 `WP::separator()`；既有測試 `test_更新外部課程`、`test_更新站內課程` 不會受影響 |
| **`type` 非 simple/external 時要回 400 而非 silent ignore** | 中 | callback 開頭做 whitelist 驗證：`if ($confirm_type_change && !in_array($type, ['simple','external'], true)) return new WP_Error('invalid_type', ..., ['status' => 400])` |
| **影響清單需要 student_count** —— 現有 `format_course_records` 沒有此欄位，但 Segmented 的 confirm dialog 需要即時顯示 | 中 | 在 `format_course_records` 加 `student_count`（`SELECT COUNT(DISTINCT user_id) FROM {prefix}pc_avl_coursemeta WHERE post_id = %d`，用 `$wpdb->prepare()`）；GET /courses/{id} 回應一起帶；frontend 從 record 取，無需額外 API call |
| **影響清單章節數**：spec Background 表示「5 個章節」對應 `post_type=chapter`，包含子章節 | 中 | 新增 `chapter_count` 欄位 = 課程下所有 `post_type=chapter` 文章（含巢狀子章節）數量；用既有 `ChapterUtils::get_flatten_post_ids($course_id)` 計算長度，避免另外寫 SQL |
| **影響清單 bundle 數** | 低 | reuse 既有 `bundle_ids`：`bundle_ids.length` 即可，前端不需新欄位 |
| **`bundle_ids` 取得方式是 `Helper::get_bundle_products($id, true)`，回傳 ID 陣列** | 低 | 直接 `bundle_ids.length` |
| **type_change_skipped no-op 邏輯** —— spec 要求 `type` 等於當前類型時不報錯但回 `type_change_skipped: true` | 中 | 切換前比對 `$product->get_type() === $target_type`，相同則 set 一個 local flag `$type_change_skipped = true`、跳過 taxonomy 切換；callback 在 response data 中加入此 flag |
| **i18n msgid 命名統一性** —— 規格要求 msgid `External platform course`、`In-site course`，但現有程式已用 `External course` / `Internal course`；要全域替換 | 中 | 新 msgid: `External platform course`、`In-site course`；舊 msgid `External course`、`Internal course`、`Internal product` 不從程式碼移除，但全部呼叫點都改用新 msgid。`manual.json` 新增對應 entry（繁中：外部平台課程 / 站內課程）。`inc/templates/components/card/pricing.php:101` 的 `External course` 也一併改 |
| **type=external 時 isExternal 條件還依賴 form 的 `is_external` watch（適用新增模式）** —— 編輯模式現在 record.type 切換後 isExternal 不會跟著更新（會被 form watch 蓋過？） | 中 | 切換 API 成功後呼叫 `query.refetch()` 重新拉 record，記得讓 `record?.type` 成為唯一決策依據；不要再從 form 讀 `is_external` 來做 hide tabs 判斷（編輯模式下 form 沒有 is_external 欄位）；watchIsExternal 僅在 record 不存在（新增模式）時被讀取 |
| **無權限驗證** —— spec 沒明說，但 type change 是不可逆（class 切換）操作；既有 ApiBase 預設 `manage_woocommerce` capability check | 低 | 沿用既有 `permission_callback`，不另加；測試覆蓋角色為 administrator |
| **REST callback 必須有 `nocache_headers()`** —— 規範要求 | 低 | `post_courses_with_id_callback` 既有實作未呼叫 nocache（這是既有缺漏），本 PR 順手補一行；不破壞既有行為 |
| **i18n pipeline** —— 新增 ~12 個英文 msgid（含 sprintf placeholder），必須 commit `manual.json` + 跑 `pnpm run i18n:build` 後一併 commit 4 個 languages/ 檔 | 低 | 在 i18n 步驟明列所有新 msgid 與繁中翻譯；CI 會 diff `.pot` |
| **Modal 元件選擇** —— Ant Design `Modal.confirm()` 還是宣告式 `<Modal>`？影響清單需要 dynamic content | 低 | 用宣告式 `<Modal>`，狀態由 `useState` 控制；可直接渲染 `Descriptions` / `List` 列出影響清單 |

## 資料流分析

### Happy Path：站內 → 外部

1. 使用者點 Segmented 「外部平台課程」
2. 前端：依 `record.student_count` / `record.chapter_count` / `record.bundle_ids.length` 組影響清單 → 顯示 `<Modal>`
3. 使用者點「確認切換」→ `setLoading(true)` + 發送 `POST /courses/{id}` body `{ type: 'external', confirm_type_change: true }`
4. 後端 `post_courses_with_id_callback`：
   - `separator()` 解析 body，識別 `confirm_type_change === true`
   - **若無變更**（current type == target type）：略過 taxonomy 切換，回應 `{ ..., type_change_skipped: true }`
   - **若需切換**：whitelist 驗證 `type` ∈ {simple, external}；不通過回 `WP_Error(400)`
   - 切換流程：
     1. `wp_set_object_terms($id, $term, 'product_type')`
     2. `wc_delete_product_transients($id)`
     3. `$product = new \WC_Product_External($id)`（或 Simple）
   - 後續若 body 還帶其他欄位（如 name），照常走 `handle_save_course_data` / `handle_save_course_meta_data` 處理
   - 回應 200 + 完整 `CourseDetail`
5. 前端：成功 → `query.refetch()` 拉新 record → `isExternal` 由 `record.type` 重新決定 → Tabs 自動隱藏/顯示 → `setLoading(false)` + `message.success`
6. 失敗（4xx/5xx）→ `setLoading(false)` + Segmented 回滾 + `message.error`

### Happy Path：外部 → 站內

同上流程，但：
- 確認對話框內容改為「外部連結 URL 與 CTA 按鈕文字將被隱藏但不刪除（切回外部平台課程時自動帶回原值）」
- 後端切換時不主動刪 `_product_url` / `_button_text` meta（WC_Product_Simple 自動忽略）
- 切回外部時 `new WC_Product_External($id)` 自動撈回原 meta，前端 record 帶回原值

### 邊界與錯誤情境

| 情境 | 預期行為 |
| --- | --- |
| 同類型送 confirm_type_change | 200 + `type_change_skipped: true`，product 不變 |
| `type` 非 simple/external | 400 `{ code: 'invalid_type_change' }` |
| 未帶 confirm_type_change（一般 update） | type 欄位被忽略，其他欄位照常更新（向下相容） |
| API 500（DB 異常 / `wp_set_object_terms` fail） | 前端 Segmented 回滾為原類型、loading 消失、message.error |
| 使用者點「取消」 | 不發 API、Segmented 維持原狀 |
| 切換期間 user 點其他 UI | 全頁 loading 遮罩，所有互動 disable |

## 實作步驟

### Step 1 — 後端：擴充 `format_course_records` 加 `student_count` / `chapter_count`

**檔案**：`inc/classes/Api/Course.php`

在 `format_course_records` 內（約 280 行附近）新增：

```php
global $wpdb;
$student_count = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}" . Plugin::COURSE_TABLE_NAME . " WHERE post_id = %d",
        $product->get_id()
    )
);

$chapter_count = count( ChapterUtils::get_flatten_post_ids( $product->get_id() ) );
```

把 `'student_count' => $student_count` 與 `'chapter_count' => $chapter_count` 加進 `$extra_array`。

**為什麼**：dialog 的影響清單需要這兩個欄位；放在 `format_course_records`（GET 詳情用）而非 `format_course_base_records`（list 用），避免列表查詢做大量 SQL。

### Step 2 — 後端：擴充 `post_courses_with_id_callback` 處理 confirm_type_change

**檔案**：`inc/classes/Api/Course.php`

- 在 `separator()` 內取出 `$body_params['confirm_type_change']` 與 `$body_params['type']`，從 body params 移除（不進入 meta_data flow）
- 加一個 private method `handle_type_change( \WC_Product &$product, ?string $target_type, bool $confirm ): array|\WP_Error`：
  - `if (! $confirm) return [ 'skipped' => true ];`
  - 驗證 `$target_type ∈ ['simple', 'external']`，否則回 `WP_Error('invalid_type_change', ..., ['status' => 400])`
  - 比對 `$product->get_type() === $target_type` → `return [ 'skipped' => true ];`
  - `wp_set_object_terms( $product->get_id(), $target_type, 'product_type' )`
  - `wc_delete_product_transients( $product->get_id() )`
  - **重新實例化**：`$product = ('external' === $target_type) ? new \WC_Product_External( $product->get_id() ) : new \WC_Product_Simple( $product->get_id() );`
  - `return [ 'skipped' => false ];`
- `post_courses_with_id_callback` 流程改為：
  1. `\nocache_headers();`
  2. `separator()` 拿到 `[$product, $data, $meta_data, $type_change_args]`
  3. `$type_change_result = $this->handle_type_change( $product, ... )`；若 `WP_Error` 直接 return
  4. `handle_save_course_data` / `handle_save_course_meta_data` 照舊
  5. response data 增加 `type_change_skipped` → 若 `$type_change_result['skipped']` 則為 `true`，否則為 `false`

**為什麼**：依 Q7 決策擴充既有端點而非新增；旗標讓 API 明確分辨「一般更新」與「類型切換」；no-op 與 invalid type 都有明確 contract。

### Step 3 — 後端：文案調整 `External course` → `External platform course`

**檔案**：

1. `inc/templates/components/card/pricing.php:101` —— `\esc_attr__( 'External course', 'power-course' )` 改 `\esc_attr__( 'External platform course', 'power-course' )`

**為什麼**：避免「外訓」誤解；msgid 一律英文（i18n rule）。

### Step 4 — 前端：新增 TypeSwitcher 元件

**新增目錄**：`js/src/pages/admin/Courses/Edit/components/TypeSwitcher/`

包含：

- `index.tsx` — 主元件，受控 Segmented + InfoCircle Tooltip
- `ConfirmModal.tsx` — 切換確認對話框（站內↔外部兩種文案 + 影響清單 + 全頁 loading）
- `hooks.ts` — `useTypeChange` hook（封裝 `useCustomMutation` 呼叫 `POST /courses/{id}`）

**index.tsx 介面**：

```tsx
type TTypeSwitcherProps = {
  recordType: 'simple' | 'external'
  studentCount: number
  chapterCount: number
  bundleCount: number
  onSuccess: () => void  // refetch record
}
```

- `<Segmented>` 兩個選項，label 用 `__('In-site course', 'power-course')` / `__('External platform course', 'power-course')`
- 右側 `<Tooltip>` 包 `<InfoCircleOutlined>`，內容含兩種類型差異說明（明確排除「外訓」字樣）
- 點 Segmented 變更 → 開 ConfirmModal
- 切換成功 → `onSuccess()` （由父層呼叫 `query.refetch()`）

**ConfirmModal.tsx**：

- 用 Ant Design 宣告式 `<Modal>`，由 state 控制
- `okButtonProps={{ loading: isMutating }}`，`<Modal>` 自帶遮罩
- 對話框內容 conditional：
  - 站內→外部時：`<Descriptions>` 列出 student/chapter/bundle 三項；若 bundleCount > 0 額外加上 `<Alert>` 風險提示
  - 外部→站內時：說明 product_url / button_text 將被保留隱藏 + 需自行設定章節銷售方案
- 兩按鈕：「取消」/「確認切換」

**hooks.ts — useTypeChange**：

- 用 Refine 的 `useCustomMutation` 而非 `useUpdate`（精細控制 success / error）
- 呼叫 `POST ${apiUrl}/courses/${id}` body `{ type, confirm_type_change: true }`
- 對外回傳 `{ mutate, isLoading }`

**為什麼**：抽元件方便測試 + 重用；用宣告式 Modal 而非 `Modal.confirm` 是為了 dynamic content + 影響清單表格。

### Step 5 — 前端：Edit 主頁掛入 TypeSwitcher

**檔案**：`js/src/pages/admin/Courses/Edit/index.tsx`

- 在 `<Edit title={...}>` 的 `title` prop 加入 `<TypeSwitcher>`：

```tsx
title={
  <div className="flex items-center gap-4">
    <span>{record?.name} <span className="text-gray-400 text-xs">#{record?.id}</span></span>
    {record?.id && (
      <TypeSwitcher
        recordType={record.type as 'simple' | 'external'}
        studentCount={record.student_count ?? 0}
        chapterCount={record.chapter_count ?? 0}
        bundleCount={(record.bundle_ids ?? []).length}
        onSuccess={() => query?.refetch()}
      />
    )}
  </div>
}
```

- 確保 `isExternal` 僅從 `record?.type === 'external'` 推導（不再依賴 form `is_external` 欄位於編輯模式）；新增模式（`record` 為 undefined）才用 form watch
- `EXTERNAL_HIDDEN_TABS` 邏輯不變

**為什麼**：Q1 決策 — Segmented 跨 Tab 全域可見；title 區是 Refine `<Edit>` 內最持久的位置。

### Step 6 — 前端：List/Table 下拉選單加 description

**檔案**：`js/src/pages/admin/Courses/List/Table/index.tsx`

`createMenuItems` 改寫：

```tsx
const createMenuItems: MenuProps['items'] = [
  {
    key: 'internal',
    label: (
      <div>
        <div>{__('In-site course', 'power-course')}</div>
        <div className="text-xs text-gray-400">
          {__('Courses watched directly on this site, with chapter/video/student management.', 'power-course')}
        </div>
      </div>
    ),
    onClick: createInternalCourse,
  },
  {
    key: 'external',
    label: (
      <div>
        <div>{__('External platform course', 'power-course')}</div>
        <div className="text-xs text-gray-400">
          {__('Redirects to courses on other platforms (e.g., Hahow, Udemy); only the sales page is shown on this site.', 'power-course')}
        </div>
      </div>
    ),
    onClick: createExternalCourse,
  },
]
```

也順手把 `createExternalCourse` 內 `__('New external course', 'power-course')` 改 `__('New external platform course', 'power-course')`，前台展示新術語對齊。

**為什麼**：Q6 決策 — 下拉選單用 description（永遠可見），新手管理員一眼能判斷；Q5 — 字面改新詞。

### Step 7 — 前端：型別定義更新

**檔案**：`js/src/pages/admin/Courses/List/types/index.ts`（或 `TCourseRecord` 所在處）

- `TCourseRecord` 加：
  - `student_count?: number`
  - `chapter_count?: number`
  - `type_change_skipped?: boolean`（response data 用）
- 確認 `type` 已是 `'simple' | 'external' | ...`

**為什麼**：TypeScript strict 必須 declare 才能編譯。

### Step 8 — i18n：補對照表 + 跑 pipeline

**檔案**：`scripts/i18n-translations/manual.json`

新增 entries（msgid 全英文；context 指向第一個使用點）：

| msgid | msgstr_zh_TW |
| --- | --- |
| `External platform course` | `外部平台課程` |
| `In-site course` | `站內課程` |
| `Courses watched directly on this site, with chapter/video/student management.` | `在本站直接觀看的課程，包含章節、影片與學員管理` |
| `Redirects to courses on other platforms (e.g., Hahow, Udemy); only the sales page is shown on this site.` | `導向其他平台的課程（如 Hahow、Udemy），僅在本站展示銷售頁` |
| `Switch to external platform course` | `切換為外部平台課程` |
| `Switch to in-site course` | `切換為站內課程` |
| `Confirm switch` | `確認切換` |
| `Authorized students` | `已授權學員` |
| `Chapters` | `章節數`（沿用既有翻譯；確認術語表已有則跳過） |
| `Linked bundles` | `綁定銷售方案` |
| `After switching, the above data will be hidden (not deleted) and restored when you switch back.` | `切換後上述資料將被隱藏（不會刪除），可隨時切回站內課程恢復` |
| `This course is linked by %d bundle(s); the front-end display of bundles may be inconsistent after switching to external platform course. Please review.` | `此課程被 %d 個銷售方案綁定，切換為外部平台課程後，前台銷售方案展示行為可能不一致，建議檢查` |
| `External Link URL and CTA button text will be hidden but not deleted (auto-restored when switching back).` | `外部連結 URL 與 CTA 按鈕文字將被隱藏但不刪除（切回外部平台課程時自動帶回原值）` |
| `After switching to in-site course, you will need to configure chapters, bundles, etc. yourself.` | `切換為站內課程後需自行設定章節、銷售方案等內容` |
| `Switched to external platform course successfully` | `已切換為外部平台課程` |
| `Switched to in-site course successfully` | `已切換為站內課程` |
| `Failed to switch course type. Please try again later.` | `切換失敗，請稍後再試` |
| `Course type` | `課程類型` |
| `In-site course: courses watched directly on this site, with chapter/video/student management.` | `站內課程：在本站直接觀看的課程，包含章節、影片與學員管理` |
| `External platform course: redirects to courses on other platforms (e.g., Hahow, Udemy); only the sales page is shown on this site.` | `外部平台課程：導向其他平台的課程（如 Hahow、Udemy），僅在本站展示銷售頁` |
| `New external platform course` | `新外部平台課程` |

**注意**：`Chapters` 已存在於 `js/src/pages/admin/Courses/Edit/index.tsx:184` 的 tab label，請確認既有翻譯一致。

**指令**：

```bash
pnpm run i18n:build  # pot → merge → mo → json
```

**Commit**：包含 `manual.json` + `languages/power-course.pot` + `power-course-zh_TW.po` + `.mo` + `.json` 五個檔。

**為什麼**：i18n rule —— msgid 全英文 + 翻譯走 manual.json + 不手改 .po。

### Step 9 — PHP 整合測試

**新檔**：`tests/Integration/Course/CourseTypeChangeTest.php`

仿 `CourseTrialVideosTest` 透過 `WP_REST_Request` + `$this->api->post_courses_with_id_callback($request)` 呼叫 callback，覆蓋：

| 測試案例 | 預期 |
| --- | --- |
| `test_未帶confirm_type_change時忽略type欄位` | `type=external` 但無 flag → product type 維持 simple；其他欄位（如 name）正常更新 |
| `test_帶confirm_type_change_simple切external_class切換成功` | product_type taxonomy 為 external、`wc_get_product()` 為 `WC_Product_External` |
| `test_帶confirm_type_change_external切simple_class切換成功` | 同上反向 |
| `test_外部切站內後product_url_meta保留` | `get_post_meta($id, '_product_url', true)` 與 `_button_text` 仍存在 |
| `test_切回外部時product_url自動帶回` | 切 simple 再切 external，`WC_Product_External::get_product_url()` 回原值 |
| `test_切換站內課程後章節資料保留` | 預先建 3 個 chapter post（post_parent=course）→ 切外部後 `get_children()` 仍為 3 |
| `test_切換站內課程後學員授權保留` | 預先 INSERT pc_avl_coursemeta 一筆 → 切外部後 SELECT 仍存在 |
| `test_同類型切換為no-op` | `type=simple` + flag + 原本就是 simple → response 帶 `type_change_skipped: true`、taxonomy 不變 |
| `test_type非合法值回400` | `type=bundle` + flag → `WP_Error` status=400、message 包含「simple」「external」 |
| `test_format_course_records_含student_count_chapter_count` | GET 詳情 response 包含 `student_count: 3`、`chapter_count: 2` |

**為什麼**：對應 `.feature` Rule，每個 Example 一個測試方法；命名繁中遵循專案慣例。

### Step 10 — E2E 測試（admin）

**新檔**：`tests/e2e/01-admin/course-type-toggle.spec.ts`

覆蓋：

| 案例 | 步驟 |
| --- | --- |
| `站內課程編輯頁顯示 Segmented，預設選中站內` | 建立站內課程 → 進編輯頁 → 確認 Segmented 存在且選中正確 |
| `點 Segmented 切外部彈出確認對話框` | 點 Segmented → 看到 Modal 含影響清單 |
| `取消對話框不送 API` | 點取消 → Modal 關閉、Segmented 維持站內 |
| `確認切換後 tabs 即時更新` | 點確認 → 等 loading 結束 → Bundles/Chapters/Students/Analytics tab 消失 → CourseDescription tab 內出現外部連結欄位 |
| `外部切站內後外部連結欄位隱藏但 DB 保留` | 切回站內 → 外部欄位消失；再切外部 → 欄位帶回原值 |
| `下拉選單顯示 description 文字` | 課程列表頁開「新增課程」下拉 → 兩個選項各自顯示一行灰色 description |
| `Segmented 旁 Tooltip hover 顯示說明` | hover InfoCircle → 看到含「站內課程」「外部平台課程」與排除「外訓」字樣的 tooltip |

**修改檔**：`tests/e2e/01-admin/api-course-crud.spec.ts` —— 加一段 API 直測：

```ts
test('POST /courses/{id} with confirm_type_change=true switches WC_Product class', async () => {
  // 1. 建 simple 課程
  // 2. POST { type: 'external', confirm_type_change: true }
  // 3. GET /courses/{id} → type === 'external'
  // 4. POST { type: 'simple', confirm_type_change: true }
  // 5. GET → type === 'simple'
})

test('POST /courses/{id} without confirm_type_change ignores type field', async () => {
  // 1. simple 課程
  // 2. POST { type: 'external' }（no flag）
  // 3. GET → type 仍 simple
})
```

**為什麼**：E2E 驗證瀏覽器層的 UI 行為（loading / Tab 顯示）；API spec 驗證契約層；分工避免重覆 cost。

### Step 11 — 既有 E2E 文案斷言修補

**檔案**：`tests/e2e/01-admin/course-create.spec.ts` 與 `course-list.spec.ts`（如果有對 `External course` / `Internal course` 字串做 assert）

- 把 assert 字串改為 `External platform course` / `In-site course`（或 zh_TW 對應）
- `pnpm run test:e2e:admin -- --grep="course-create|course-list"` 跑通過

**為什麼**：文案改動會導致現有 assert 失敗；先掃既有 assert 一次性更新。

### Step 12 — Lint / Format / 文件

```bash
pnpm run lint:php   # phpcbf + phpcs + phpstan level 9
pnpm run lint:ts    # ESLint
pnpm run format     # Prettier
```

修任何 lint error，特別注意：
- PHPStan level 9：`format_course_records` 的回傳型別已是 `array<string, mixed>`，新增欄位不需動 phpdoc
- ESLint `react-hooks/exhaustive-deps`：TypeSwitcher 內 `useEffect` 依賴需完整
- Prettier：tab indent / no semicolons

**文件**：本 plan 本身已寫入 `specs/plans/`；無需動 `CLAUDE.md`（型別切換為新功能，不影響架構決策）

## 受影響的檔案清單

### 生產程式碼

1. `inc/classes/Api/Course.php` — `format_course_records` 加 student_count / chapter_count；`separator` 抽 confirm_type_change；`post_courses_with_id_callback` 加 nocache_headers + type_change 流程；新 private method `handle_type_change`
2. `inc/templates/components/card/pricing.php` — `External course` → `External platform course`
3. `js/src/pages/admin/Courses/Edit/index.tsx` — 引入 TypeSwitcher、整合 title prop、`isExternal` 只信 `record.type`
4. `js/src/pages/admin/Courses/Edit/components/TypeSwitcher/index.tsx` — 新增（Segmented + Tooltip）
5. `js/src/pages/admin/Courses/Edit/components/TypeSwitcher/ConfirmModal.tsx` — 新增（Modal + Descriptions + Alert）
6. `js/src/pages/admin/Courses/Edit/components/TypeSwitcher/hooks.ts` — 新增（useTypeChange）
7. `js/src/pages/admin/Courses/List/Table/index.tsx` — 下拉 description + 新 msgid
8. `js/src/pages/admin/Courses/List/types/index.ts`（或 TCourseRecord 定義處） — 加欄位

### i18n

9. `scripts/i18n-translations/manual.json` — 新增 ~20 條 entry
10. `languages/power-course.pot` — pipeline 產出
11. `languages/power-course-zh_TW.po` / `.mo` / `.json` — pipeline 產出

### 測試

12. `tests/Integration/Course/CourseTypeChangeTest.php` — 新增（10 個測試案例）
13. `tests/e2e/01-admin/course-type-toggle.spec.ts` — 新增（7 個 e2e scenario）
14. `tests/e2e/01-admin/api-course-crud.spec.ts` — 修改（加 2 個 API 案例）
15. `tests/e2e/01-admin/course-create.spec.ts` / `course-list.spec.ts` — 修改文案 assert（如有）

### API 規格

16. `specs/api/api.yml` — **已更新**（clarifier 已寫入 confirm_type_change + type 欄位），無需再動

## 實作順序

依以下順序執行可最大化測試覆蓋並降低偵錯成本：

1. **Step 1**（後端：format_course_records 加欄位）— 基礎；先讓 GET 詳情包含新欄位
2. **Step 2**（後端：post_courses_with_id_callback 擴充 confirm_type_change）— 核心後端 contract
3. **Step 3**（後端：pricing.php 文案）— 順手，獨立
4. **Step 9**（PHP 整合測試）— 先寫測試保護後端；可用 TDD 模式回頭調 Step 1-2
5. **Step 7**（前端型別定義）— 解開 TS 編譯
6. **Step 4**（前端：新元件 TypeSwitcher）— 獨立可單元測試
7. **Step 5**（前端：Edit 整合 TypeSwitcher）
8. **Step 6**（前端：List 下拉 description）
9. **Step 8**（i18n）— 等所有 msgid 確定後一次性跑 pipeline
10. **Step 10**（E2E admin 新檔）
11. **Step 11**（既有 E2E 文案修補）
12. **Step 12**（lint / format / 跑全套測試）

## 測試策略總覽

| 層級 | 工具 | 覆蓋內容 |
| --- | --- | --- |
| PHP 整合測試 | PHPUnit (`composer run test`) | `handle_type_change` 邏輯、confirm_type_change 旗標契約、no-op、invalid type、資料保留語意、format_course_records 新欄位 |
| E2E 瀏覽器 | Playwright (`pnpm run test:e2e:admin`) | Segmented 顯示與切換、Modal 影響清單、loading 遮罩、Tab 隱藏/顯示、Tooltip hover、下拉 description |
| E2E API | Playwright (`pnpm run test:e2e:admin`) | REST API 切換 contract（與 PHPUnit 平行驗證；確保 HTTP 層也通） |
| 型別檢查 | TypeScript strict (`pnpm run build`) | TCourseRecord 與 TypeSwitcher props 對齊 |
| 靜態分析 | PHPStan level 9 | 新邏輯無型別漏洞 |
| Lint | PHPCS / ESLint / Prettier | 遵循專案 coding style |

## i18n 驗收標準（對應 `課程類型輔助說明文字.feature`）

- `languages/power-course.pot` 包含 `External platform course` 與 `In-site course`（與 spec Rule 一致）
- `power-course-zh_TW.po` `msgstr` 為「外部平台課程」「站內課程」
- 程式碼裡**任何**呼叫 `__('External course', 'power-course')` 都已改為 `__('External platform course', 'power-course')`（搜尋確認）
- pricing.php 的 `\esc_attr__( 'External course', ... )` 已改為新 msgid

## 開發者交接補充

- TDD coordinator 接手後，建議先跑 Step 9（PHP 整合測試）以 Red 階段鎖定後端 contract；接著 Step 1-2 進 Green
- 前端 TypeSwitcher 元件 props 介面已固定，TDD coordinator 可指派 react-master 並列開工
- `nocache_headers()` 補進 `post_courses_with_id_callback` 是「順手修」，本身不在 Issue #235 範圍，但符合 `wordpress.rule.md` 規範，建議一起 commit
- 切換的 product class 操作對 WooCommerce 內部 transient / object cache 都已透過 `wc_delete_product_transients()` 處理；測試環境（PHPUnit）若有 object cache plugin 可能需要額外 `wp_cache_flush()`
- E2E 瀏覽器測試需 Vite dev server 提供前端（`pnpm run dev` 或 build artifact）

## 完成條件（Definition of Done）

- ✅ 所有 14 個 spec Example 都有對應測試通過（PHPUnit + Playwright）
- ✅ `pnpm run lint:php` / `pnpm run lint:ts` / PHPStan level 9 全綠
- ✅ `pnpm run i18n:build` 跑完後 `.pot` / `.po` / `.mo` / `.json` 都有新字串，`[build-zhtw-po] 未翻譯` 數量未增加
- ✅ 既有 E2E 測試（admin / frontend / integration）全綠 — 沒有因文案改動而漏修
- ✅ 手動驗證：本機開兩個 tab（一個站內、一個外部），各自切換成功並驗證資料保留語意
- ✅ Issue #235 提到的兩個業務需求（可切換 + 名稱備註）都已在 UI 上可見

