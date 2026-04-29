# 實作計畫：課程多影片試看（Issue #10）

## 概述

擴充課程銷售頁「課程試看」從單一影片到最多 6 部影片：

- 後端 postmeta 由 `trial_video`（單一 `VideoObject`）改為 `trial_videos`（`VideoObject[]`，最多 6 筆）。
- 讀取走 lazy migration —— 若僅有舊 `trial_video` 自動轉為 `[trial_video]`；儲存時統一寫 `trial_videos` 並刪除舊 `trial_video` meta。
- 後台課程編輯頁的試看影片區塊以 Ant Design `Form.List` 呈現，支援 1~6 筆、新增、刪除、拖拉排序。
- 前台銷售頁：0 部不渲染、1 部直接顯示（與既有行為一致、不載 Swiper）、2~6 部以 Swiper 輪播渲染（pagination + navigation arrows、無 autoplay、切換時前一部影片自動暫停）。
- Swiper CSS/JS 採條件式 enqueue —— 只在 `count(trial_videos) >= 2` 時掛載，避免影響其他頁面效能。
- API 維持向下相容：`GET /courses/{id}` 同時回 `trial_videos`（新）與 `trial_video`（DEPRECATED，回 `trial_videos[0]` fallback）。

來源：`specs/features/course/多影片試看.feature`、`specs/api/api.yml`、`specs/entity/erm.dbml`、`specs/ui/課程銷售頁.md`、Issue #10 clarifier 決議（Q1~Q7 全 A）。

## 範圍模式：HOLD SCOPE

**預估影響檔案**：後端 4 + 前端 6 + 前台 vanilla TS 2~3 + 測試 3 + i18n 1，共 ~16 檔。

不在範圍內：

- 試看影片個別字幕（`pc_subtitles_trial_video` 仍依舊以單一 slot 設計，本計畫不擴充為「每部影片各自字幕」，理由見「未處理項」段落）。
- MCP server tools 不需修改（目前 `inc/classes/Api/Mcp/Tools/` grep 無 `trial_video` 直接引用，透過 Course API 間接讀寫即可）。
- 一次性 migration script（採 lazy migration，無需）。

## 需求重述（驗收角度）

對應 `specs/features/course/多影片試看.feature` 的 Rule：

1. **前置（狀態）** — simple / subscription / external 三種 product type 皆支援 `trial_videos`。
2. **前置（參數）**：
   - `trial_videos` 必須是陣列、長度 0~6；超過 6 筆 → HTTP 400「最多 6 部」。
   - 非陣列輸入（如單一物件）→ HTTP 400。
   - 每筆必須是合法 `VideoObject`（必含 `type` 字段）；缺欄位 → HTTP 400。
   - `type === 'none'` 的項目視為空白，自動過濾，不寫入。
3. **後置（狀態）**：
   - `trial_videos` 寫入 `wp_postmeta` 為 JSON 陣列字串。
   - 寫入時若舊 `trial_video` meta 存在則一併刪除（lazy migration 完成點）。
   - 全部清空時 `trial_videos` 寫入 `[]`，舊 `trial_video` 也被刪除。
4. **向下相容（讀取）**：
   - 僅有舊 `trial_video` 時 → 讀取回傳 `trial_videos: [trial_video]`。
   - 舊 `trial_video.type === 'none'` → 視為無試看影片，回 `trial_videos: []`。
   - 同時存在新舊 meta → `trial_videos` 優先。
5. **前台渲染**：
   - 0 部 → 不渲染區塊。
   - 1 部 → 直接渲染單一 `.video-player`，不出現 `.swiper-pagination` / `.swiper-button-prev` / `.swiper-button-next`，且 HTML 不含 swiper CSS/JS。
   - 2~6 部 → 渲染 `.swiper` + N 個 `.swiper-slide`、N 個 pagination bullet、左右箭頭；`autoplay: false`；切換時前一部自動暫停。
6. **後台管理**：
   - 舊 `trial_video` 開啟編輯頁時自動顯示為列表第 1 筆。
   - 已 6 筆時「新增試看影片」disabled，旁顯示「最多可新增 6 部」。
   - 拖拉排序後儲存，順序持久化於 `trial_videos` 陣列。

## 已知風險（來自程式碼研究）

| 風險 | 緩解措施 |
| --- | --- |
| 前端 axios 對 `[]` 序列化會送出 `[]`，但對 `undefined` 會省略 key —— 必須確保前端永遠送 `trial_videos` 陣列（哪怕空陣列），讓「全部清空」場景能觸發後端清空語義 | 比照 Issue #203 的 `CLEARABLE_FIELDS` 機制：`Edit/index.tsx` 的 `CLEARABLE_FIELDS` 把 `trial_video` 替換為 `trial_videos`；當值為 `undefined`/`null`/`NaN` 時 normalize 為 `[]` |
| `WP::sanitize_text_field_deep` 會把陣列內的物件遞迴轉成字串。若把 `trial_videos` 留在 `body_params` 走 sanitize，每個 VideoObject 會被破壞 | 在 `Api/Course.php::separator()` 的 `$skip_keys` 加入 `'trial_videos'`，讓它不被 sanitize（與既有 `feature_video`、`trial_video` 同樣模式） |
| `WP::separator()` 會把不在 product property 中的 key 歸到 `meta_data`。`trial_videos` 為自訂 meta，符合此分流，不需特殊處理 | 跑 PHPUnit 驗證 `meta_data['trial_videos']` 確實被分到 meta 流程 |
| WordPress meta API：`update_meta_data($key, [])` 與 `update_meta_data($key, '[]')` 的差異 —— 直接傳陣列會被 WordPress 序列化為 PHP serialize（`a:0:{}`），而非 JSON `"[]"`。spec 要求 meta_value 為 JSON 字串 `"[]"` | 寫入時用 `wp_json_encode($trial_videos)` 顯式 JSON 編碼後再 `update_meta_data`；讀取時 `json_decode` 還原為陣列 |
| Subtitle 服務 `Subtitle::VALID_VIDEO_SLOTS` 包含 `'trial_video'`，意味著舊的 `pc_subtitles_trial_video` meta 仍在資料庫中。本計畫不擴充字幕到多影片 | `trial_video` slot 在 Subtitle 中先「保留現狀」—— 仍為單一 slot，但前端 `VideoInput` 在 `trial_videos` Form.List 中只渲染影片本體，不掛 SubtitleManager。後續另開 issue 處理「每部試看影片獨立字幕」 |
| `j7-easy-email`、`Bunny.tsx`、`Vimeo.tsx`、`Youtube.tsx` 等元件中 `'trial_video'` 字面量是用於 SubtitleManager 的 video_slot 判斷 → Form.List 內呼叫 VideoInput 不傳 trial_video slot，避免渲染 SubtitleManager（本期不支援多影片字幕） | 透過 `<VideoInput>` 新增 prop `hideSubtitle?: boolean`，在 trial_videos Form.List 內傳 `hideSubtitle={true}` 跳過字幕區塊；不變動既有 trial_video slot 邏輯 |
| 前端 Swiper 需安裝 `swiper` npm 套件 —— 評估 bundle size：Swiper 11 約 ~140KB minified、~40KB gzipped。前台已是 vanilla TS bundle（`inc/assets/dist/index.js`），可拆分新 entry point | 新增獨立 entry `inc/assets/src/trial-videos-swiper.ts`，由 PHP 條件 enqueue 而非進主 bundle |
| Swiper 觸控事件可能與 VidStack / iframe 播放器手勢衝突（如 seek bar 拖拉、YouTube 點擊播放） | 設定 Swiper `touchStartPreventDefault: false` + 適當 `noSwipingClass` 處理 player 內部互動；逐型驗證 |
| 影片 player 暫停 API 跨 type 不一致：VidStack 走 `<media-player>` 的 `paused = true`、YouTube iframe 需要 postMessage `{event:'command',func:'pauseVideo'}`、Vimeo 需要 postMessage `{method:'pause'}`、code 類型不可控 | 在 `trial-videos-swiper.ts` 實作 `pauseAllExcept(activeIndex)` 的 type 分流；code 類型不處理（admin 自負風險） |
| `VidStack` 跨 slide 內可能有多個 player 實例同時 mount（Swiper 預設不 lazy mount），可能在 mobile 流量上爆增 | 啟用 Swiper `lazy` + 對 VidStack 設 `preload="none"` |
| i18n：新字串「Add trial video」、「Maximum 6 trial videos」、「Drag to reorder」等需走 `power-course` text domain，msgid 一律英文，`scripts/i18n-translations/manual.json` 補繁中翻譯 | 全列表納入 i18n checklist，跑 `pnpm run i18n:build` 與 commit `.pot/.po/.mo/.json` |
| 既有 E2E `tests/e2e/03-integration/012-course-empty-fields-api.spec.ts:248-253` 仍以 `trial_video` 字串為 meta key 驗證 | 改寫為 `trial_videos`，並新增驗證舊 meta 已被刪除的 assertion |
| `Edit/index.tsx::CLEARABLE_FIELDS` 包含 `'trial_video'`；本期把鍵改為 `'trial_videos'`，但仍要支援「使用者把所有 Form.List item 移除後送出」→ axios 送 `[]` 而非 `undefined` | normalize 邏輯擴充：對 `trial_videos`，`undefined` → `[]`（不是 `''`），與其他 string 類欄位的清空語義不同 |

## 架構變更

### 後端（PHP）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/classes/Api/Course.php` | 修改 | (1) `format_course_with_extra_records` L301-310 區段：新增 `trial_videos` key，採 lazy migration 邏輯（讀取 `trial_videos` postmeta；若空則檢查舊 `trial_video`、type≠none 時包成 `[trial_video]`、否則回 `[]`）；保留 `trial_video` 為 deprecated 欄位（回 `trial_videos[0]` 或舊值 fallback）。 (2) `separator()` L540-546 的 `$skip_keys` 加入 `'trial_videos'`，避免 `WP::sanitize_text_field_deep` 破壞陣列內物件結構。 (3) `handle_save_course_meta_data()` 新增 `trial_videos` 專用驗證/儲存分支：型別檢查（必為陣列）、長度檢查（≤6）、過濾 `type === 'none'` 的項目、每筆 VideoObject 結構驗證（必含 `type` + `id`）、最終以 `wp_json_encode` 寫 `trial_videos` postmeta、`delete_meta_data('trial_video')` 清舊 meta；驗證失敗回 `WP_Error` HTTP 400。 |
| `inc/templates/pages/course-product/footer/index.php` | 修改 | 讀取邏輯改為 `trial_videos`（含 lazy migration fallback 從 `trial_video`）；分流：0 部 return、1 部沿用既有 `Plugin::load_template('video', ...)`、2~6 部呼叫新模板 `trial-videos-swiper.php`（傳 `videos` 陣列）。 |
| `inc/templates/components/video/trial-videos-swiper.php` | 新增 | Swiper 容器模板：渲染 `.swiper` + N 個 `.swiper-slide`（每個 slide 內呼叫 `Plugin::load_template('video', ...)`，傳 `video_info` 與 `video_slot='trial_video'`），加上 `.swiper-pagination` 與 `.swiper-button-prev` / `.swiper-button-next`。同時呼叫 `J7\PowerCourse\Templates\Ajax::enqueue_swiper_assets()`（見下行）。 |
| `inc/classes/Templates/Ajax.php` | 修改 | 新增 public static `enqueue_swiper_assets()`：條件 enqueue Swiper CSS/JS（從 `inc/assets/dist/swiper-trial-videos.{css,js}`）+ `wp_set_script_translations` + `inject_locale_data_to_handle`，並 enqueue 新的 vanilla TS bundle entry。Handle 命名 `power-course-trial-videos-swiper`。 |
| `vite.config-for-wp.ts` | 修改 | 新增 entry point `inc/assets/src/trial-videos-swiper.ts`；確認 `resolve.alias` 已含 `@wordpress/i18n` shim（見 `.claude/rules/i18n.rule.md` 「前台 bundle 漏掛 shim」段落）。 |

### 前端 - React Admin SPA（TypeScript）

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `js/src/components/formItem/VideoInput/types/index.ts` | 修改 | 維持 `TVideoSlot` 不變（仍為 `'chapter_video' \| 'feature_video' \| 'trial_video'`）。新增 `TTrialVideos = TVideo[]`。 |
| `js/src/components/formItem/VideoInput/index.tsx` | 修改 | 新增可選 prop `hideSubtitle?: boolean`，傳遞給子元件（`Bunny`、`Vimeo`、`Youtube`），用於決定是否渲染 `<SubtitleManager>`。 |
| `js/src/components/formItem/VideoInput/Bunny.tsx` / `Vimeo.tsx` / `Youtube.tsx` | 修改 | 接收 `hideSubtitle` prop；當 `hideSubtitle === true` 時不渲染 `SubtitleManager`（本期 trial_videos 多影片模式不支援字幕）。Bunny/Vimeo/Youtube 既有的 `'trial_video'` 字面量是 SubtitleManager 的 slot，邏輯不動。 |
| `js/src/components/formItem/TrialVideosList/index.tsx` | 新增 | Form.List 包裝元件：以 Ant Design `<Form.List name={['trial_videos']}>` 呈現；每筆內含 `<VideoInput name={[..., field.name]} hideSubtitle />` + 拖拉 handle + 刪除按鈕；底部「新增試看影片」按鈕（`fields.length >= 6` 時 disabled，旁顯示「最多可新增 6 部」）。拖拉排序使用 `@dnd-kit/core` 或 antd `<DragSortTable>` 不適用 `Form.List`，建議使用 `react-dnd` 或自訂以 `react-sortable-hoc` 慣例（與專案既有的 `SortableChapters` 一致）—— 確認 `js/src/components/course/SortableChapters` 使用何種 lib 後對齊。 |
| `js/src/components/formItem/index.ts` | 修改 | 匯出新增的 `TrialVideosList`。 |
| `js/src/pages/admin/Courses/Edit/tabs/CourseDescription/index.tsx` | 修改 | 把 L240-243 的單一 `<VideoInput name={['trial_video']} />` 區塊替換為 `<TrialVideosList />`。 |
| `js/src/pages/admin/Courses/Edit/index.tsx` | 修改 | (1) `CLEARABLE_FIELDS` 把 `'trial_video'` 替換為 `'trial_videos'`；(2) `handleOnFinish` 的 normalize loop 對 `trial_videos` 特化：`undefined`/`null`/`NaN` → `[]`（不是 `''`）。 |
| `js/src/pages/admin/Courses/List/types/index.ts` | 修改 | 新增 `trial_videos: TVideo[]`；保留 `trial_video?: TVideo`（DEPRECATED，給向下相容讀取用，新程式碼不再寫入）。 |
| `js/src/pages/admin/Courses/Edit/hooks/useParseData.ts` (待確認檔名) | 視需要修改 | 若 lazy migration 邏輯在 GET 時就統一回 `trial_videos`，則前端 hooks 不需特殊處理；只在 `trial_videos` 為 `undefined`（極舊資料）時補預設 `[]`。 |

### 前端 - 前台 vanilla TS

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `inc/assets/src/trial-videos-swiper.ts` | 新增 | 主要邏輯：(1) `import Swiper, { Navigation, Pagination } from 'swiper'` + `import 'swiper/css'` + `import 'swiper/css/navigation'` + `import 'swiper/css/pagination'`；(2) DOM ready 時掃描 `[data-pc-trial-videos-swiper]` 容器，初始化 Swiper（`autoplay: false`、`navigation: true`、`pagination: { clickable: true }`、`lazy: true`）；(3) 監聽 `slideChange` event，呼叫 `pauseAllExcept(activeIndex)` —— 對每個非 active slide 內的 player 各自暫停（VidStack: `<media-player>.paused = true`；YouTube iframe: postMessage `{event:'command',func:'pauseVideo'}`；Vimeo iframe: postMessage `{method:'pause'}`；code: skip）。 |
| `inc/assets/src/styles/trial-videos-swiper.css` | 新增（視需要） | 微調 Swiper 樣式（與專案 Tailwind 風格對齊）。可選；若 swiper 預設樣式已可用則略。 |
| `package.json` | 修改 | 新增依賴 `swiper`（pin major version；建議 `^11.x`）。 |
| `scripts/i18n-make-pot.mjs` | 視需要修改 | 確認 `JS_GLOBS` 已包含 `inc/assets/src/**`（已包含，無需動）。 |

### 規格與翻譯

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `scripts/i18n-translations/manual.json` | 修改 | 新增以下 msgid → 繁中映射： `Add trial video` → 「新增試看影片」、`Maximum %d trial videos` → 「最多可新增 %d 部」、`Drag to reorder` → 「拖拉排序」、`Trial Videos` → 「課程試看影片」、`Remove this trial video` → 「移除此試看影片」、`At most %d trial videos can be added` → 「最多 %d 部」、`trial_videos must be an array` → 「trial_videos 必須為陣列」（PHP 端錯誤訊息英文 msgid 對映繁中譯文）。 |
| `specs/plans/issue-10-multi-trial-videos.md` | 新增（本檔） | 實作計畫。 |

### 測試

| 檔案 | 變更類型 | 說明 |
| --- | --- | --- |
| `tests/Integration/Course/CourseTrialVideosTest.php` | 新增 | PHPUnit Integration 測試，覆蓋多影片試看的後端契約：<br>- `test_新增 6 部 trial_videos 成功`：POST `/courses/{id}` 帶 6 筆 → 200，DB `trial_videos` postmeta 為 JSON 長度 6。<br>- `test_新增 7 部 trial_videos 失敗`：POST 帶 7 筆 → 400「最多 6 部」，DB 不變。<br>- `test_trial_videos 非陣列被拒絕`：POST `trial_videos: {type:'bunny'}` → 400。<br>- `test_缺少 type 欄位的影片被拒絕`：每筆 VideoObject 必有 `type`，否則 400。<br>- `test_type 為 none 的項目自動過濾`：傳 `[{type:'bunny',id:'b1'},{type:'none'}]` → 200，DB 只有 1 筆。<br>- `test_寫入 trial_videos 同時刪除舊 trial_video meta`：先設 `trial_video`，更新 `trial_videos` → 舊 meta 不存在。<br>- `test_全部清空 trial_videos 寫入空陣列`：傳 `[]` → DB `trial_videos` 為 `"[]"`，舊 `trial_video` 也被刪除。<br>- `test_向下相容讀取舊 trial_video`：DB 僅有 `trial_video` → GET 回傳 `trial_videos: [trial_video]`。<br>- `test_舊 trial_video.type=none 視為空`：DB `trial_video={type:'none'}` → GET 回 `trial_videos: []`。<br>- `test_新舊 meta 同時存在 trial_videos 優先`：DB 同時有 → GET 回 `trial_videos`。<br>- `test_simple/subscription/external 三型 product 皆支援 trial_videos`。 |
| `tests/e2e/03-integration/012-course-empty-fields-api.spec.ts` | 修改 | L240-253 的 `trial_video` 清空測試改為 `trial_videos`：<br>- 既有 test 改名 `test_清空 trial_videos 後 meta 應為空陣列`，更新 assertion 為 `trial_videos === '[]'`，並新增驗證 `trial_video` meta 已不存在。 |
| `tests/e2e/02-frontend/0XX-course-trial-videos-swiper.spec.ts` | 新增 | Playwright 前台 E2E：<br>- `test_0 部 trial_videos：頁面不渲染試看區塊`<br>- `test_1 部 trial_videos：直接顯示影片，HTML 無 swiper-pagination/swiper-button-prev/-next`<br>- `test_3 部 trial_videos：渲染 .swiper、3 個 .swiper-slide、3 個 pagination bullet、左右箭頭`<br>- `test_2 部 trial_videos：點擊右箭頭切換時前一個 video player 應為 paused 狀態`<br>- `test_1 部頁面 HTML 不含 swiper CSS/JS`，`test_2 部頁面 HTML 含 swiper CSS/JS`（驗證條件式 enqueue） |
| `tests/e2e/01-admin/course-edit-trial-videos.spec.ts` | 新增 | Playwright admin E2E：<br>- `test_開啟舊課程編輯頁，舊 trial_video 顯示為列表第 1 筆`<br>- `test_新增 6 部 trial_videos 後新增按鈕 disabled`<br>- `test_拖拉第 1 筆到第 3 位後儲存，順序持久化` |

## 資料流分析

### 資料流 1：使用者透過 Admin 新增/編輯多部試看影片（Write Path）

```
USER ACTION              FORM.LIST VALUES                NORMALIZE                AXIOS SERIALIZE              PHP SEPARATOR              HANDLE_SAVE_COURSE_META_DATA
──────────               ────────────────                ─────────                ────────────────             ─────────────              ──────────────────────────────
新增 3 部影片        →   {trial_videos:[v1,v2,v3]}   →  passthrough         →   JSON {trial_videos:[..]} →   skip_keys 含 'trial_videos' →   驗證 array、≤6、每筆有 type
                                                                                                              不被 sanitize 破壞              filter type=none → 留 3 筆
                                                                                                                                              wp_json_encode → meta 'trial_videos'
                                                                                                                                              delete_meta_data('trial_video')

刪除全部影片        →    {trial_videos: []}            CLEARABLE_FIELDS：       JSON {trial_videos:[]}     →   skip_keys 含 'trial_videos'  →  驗證 array OK、長度 0
                         （或 undefined）              undefined → []                                                                          wp_json_encode([]) = '[]' 寫入 meta
                                                                                                                                              delete_meta_data('trial_video')

未動 trial_videos   →    {其他欄位}                   passthrough             不送 trial_videos key          $meta_data 不含                   foreach 不執行 trial_videos 分支
                                                                                                            'trial_videos'                  DB 保持原狀（合約）

送 7 筆 (Form.List 上限應已擋；測試用 raw API)         JSON {trial_videos:[...7]} →  meta_data 進到分支    →  return new WP_Error 400「最多 6 部」
```

### 資料流 2：學員瀏覽課程銷售頁（Read Path）

```
HTTP GET /course/{slug}                       footer/index.php                              前台 vanilla TS / Swiper
─────────────────────                          ───────────────                                ──────────────────────
WP 渲染 single-product             →           讀 trial_videos postmeta                  →    [if count>=2]：載入 swiper bundle
                                               若空 → 讀 trial_video lazy 包裝                Swiper 初始化、addEventListener slideChange
                                                                                              切換時呼叫 pauseAllExcept(activeIndex)
                                               count == 0 → return（不渲染）
                                               count == 1 → 直接 load_template('video',...) 與既有行為一致；不 enqueue swiper
                                               count >= 2 → load trial-videos-swiper.php （內部 enqueue swiper assets）
                                                            並逐 slide load_template('video',...)
```

### 資料流 3：API GET /courses/{id}（Admin 編輯頁讀取，含 lazy migration）

```
GET /power-course/v2/courses/{id}              format_course_with_extra_records             前端 useForm initial values
─────────────────────────────                  ────────────────────────────                  ──────────────────────────
WP_REST 入口                              →    讀 trial_videos postmeta                →     trial_videos: TVideo[] = response.trial_videos
                                               若 trial_videos 存在 → 回該值
                                               否則讀 trial_video：
                                                 type === 'none' or 不存在 → 回 []
                                                 否則 → 回 [trial_video]

                                               trial_video（DEPRECATED）回傳：
                                                 trial_videos[0] ?? 舊 trial_video ?? {type:none}
                                                                                               <Form.List name={['trial_videos']}> 渲染列表
```

## 失敗模式登記表

| 觸發點 | 場景 | 預期行為 | 對應驗收 |
| --- | --- | --- | --- |
| API 寫入 | `trial_videos` 為單一物件 | HTTP 400 | feature L60-62 |
| API 寫入 | `trial_videos` 長度 7 | HTTP 400「最多 6 部」 | feature L54-58 |
| API 寫入 | `trial_videos[i]` 缺 `type` | HTTP 400 | feature L66-70 |
| API 寫入 | `trial_videos[i].type === 'none'` | 200，過濾後寫入 | feature L72-79 |
| API 寫入 | 其他課程資料儲存失敗（如 `set_sale_price` throw） | trial_videos 不寫入（與舊行為一致） | 沿用既有 transactional 行為 |
| API 讀取 | `trial_videos` postmeta 為非 JSON 字串（手動改壞） | json_decode → null → fallback 空陣列 | 對齊既有「異常資料 graceful 」原則 |
| 前台渲染 | `trial_videos` postmeta 為非陣列（手動改壞） | 嘗試 lazy migration；若仍非陣列則不渲染區塊 | feature L148-154 |
| 前台 Swiper | swiper bundle 載入失敗 | fallback：顯示第 1 部影片即可（容器仍渲染但無左右箭頭） | 加上 try/catch + log 到 console |
| Form.List | 使用者拖拉時觸發 React state 警告 | drag handler 內保證 `field.key` 唯一 | E2E 驗證無 console error |

## 錯誤處理登記表

| 錯誤類型 | 處理位置 | 處理方式 |
| --- | --- | --- |
| 後端：`trial_videos` 非陣列 | `Course.php::handle_save_course_meta_data` | `return new WP_Error('trial_videos_invalid', __('trial_videos must be an array', 'power-course'), ['status' => 400])` |
| 後端：超過 6 筆 | 同上 | `return new WP_Error('trial_videos_too_many', sprintf(__('At most %d trial videos can be added', 'power-course'), 6), ['status' => 400])` |
| 後端：缺 `type` | 同上 | `return new WP_Error('trial_videos_invalid_item', __('Each trial video must contain "type" field', 'power-course'), ['status' => 400])` |
| 前台：swiper 初始化異常 | `inc/assets/src/trial-videos-swiper.ts` | `try/catch`，console.error 不噴 user 看到的錯誤；fallback 隱藏 navigation arrows |
| 前台：影片暫停 API 失敗（如 iframe sandboxed） | `pauseAllExcept` | 包 try/catch 個別處理，繼續處理其他 slide |
| 前端 Form.List：拖拉狀態不一致 | `TrialVideosList` | 拖拉結束 → `move(from, to)` 走 antd Form.List API，避免直接改 fields 陣列 |

## 實作順序（依依賴關係）

> **注意**：此計畫**只規劃**，不實作。執行階段交給 tdd-coordinator。以下順序為建議的紅燈→綠燈→重構分批。

### Phase 0 — 環境與依賴
1. 新增 `swiper` 至 `package.json`、跑 `pnpm install`。
2. 確認 `vite.config-for-wp.ts` 的 `@wordpress/i18n` shim alias 已設置（依 i18n.rule.md「前台 bundle 漏掛 shim」段落自檢）。
3. 新增 entry point `inc/assets/src/trial-videos-swiper.ts` 至 `vite.config-for-wp.ts`。

### Phase 1 — 後端契約（API + 儲存）
1. **紅燈**：先寫 `tests/Integration/Course/CourseTrialVideosTest.php` 全部 11 個 test method（驗證、寫入、清空、向下相容、三 product type），跑 PHPUnit 應全 fail。
2. **綠燈**：
   - `Api/Course.php::format_course_with_extra_records` 加 `trial_videos` lazy migration 讀取邏輯；`trial_video` 保留為 deprecated 並回 `trial_videos[0]` fallback。
   - `Api/Course.php::separator()` `$skip_keys` 加 `'trial_videos'`。
   - `Api/Course.php::handle_save_course_meta_data` 加 `trial_videos` 驗證/儲存分支：陣列檢查、長度檢查、過濾 type=none、每筆必有 type、wp_json_encode 寫入、清舊 trial_video meta。
   - 跑 PHPUnit 應全 pass。
3. **重構**：把 `trial_videos` 驗證邏輯抽成 private method（如 `validate_and_filter_trial_videos`），在 PR review 前提升可讀性。

### Phase 2 — 前台渲染（PHP 模板 + Swiper bundle）
1. **紅燈**：寫 `tests/e2e/02-frontend/0XX-course-trial-videos-swiper.spec.ts` 5 個場景（0/1/2/3/4 部 + 暫停切換），跑 Playwright 應全 fail（畫面尚未實作）。
2. **綠燈**：
   - 新增 `inc/templates/components/video/trial-videos-swiper.php`：渲染 swiper 容器與 N 個 slide。
   - 修改 `inc/templates/pages/course-product/footer/index.php`：依 count 分流（0/1/2~6）。
   - `inc/classes/Templates/Ajax.php` 新增 `enqueue_swiper_assets()` 並在 swiper 模板中條件呼叫。
   - 實作 `inc/assets/src/trial-videos-swiper.ts`：Swiper init + slideChange pauseAllExcept。
   - 跑 `pnpm run build:wp`、Playwright 應全 pass。
3. **重構**：把跨 player 暫停邏輯抽成 `pauseVidstack`、`pauseYoutube`、`pauseVimeo` 三個 helper。

### Phase 3 — 後台管理 UI（React Form.List）
1. **紅燈**：寫 `tests/e2e/01-admin/course-edit-trial-videos.spec.ts` 3 個場景，跑 Playwright 應全 fail。
2. **綠燈**：
   - `js/src/components/formItem/VideoInput/index.tsx` 加 `hideSubtitle` prop。
   - `Bunny.tsx` / `Vimeo.tsx` / `Youtube.tsx` 接收 `hideSubtitle`，控制 SubtitleManager 渲染。
   - 新增 `js/src/components/formItem/TrialVideosList/index.tsx`（Form.List + 拖拉 + 上限 disabled）。
   - 對齊 `js/src/components/course/SortableChapters` 使用的拖拉 lib（先讀 SortableChapters 確認 lib，本計畫未列出名稱）。
   - 修改 `Edit/tabs/CourseDescription/index.tsx` 替換為 `TrialVideosList`。
   - 修改 `Edit/index.tsx::CLEARABLE_FIELDS` 與 `handleOnFinish` normalize：`trial_videos` 用 `[]` 取代 `''`。
   - 修改 `Courses/List/types/index.ts` 加 `trial_videos: TVideo[]`。
   - 跑 Playwright 應全 pass。
3. **重構**：拖拉 handle 樣式統一、刪除確認 popconfirm 一致化。

### Phase 4 — i18n 與既有測試更新
1. 把所有新增 msgid 加到 `scripts/i18n-translations/manual.json`。
2. 跑 `pnpm run i18n:build`，確認 `.pot/.po/.mo/.json` 都有新字串、`未翻譯` 計數沒上升。
3. 修改 `tests/e2e/03-integration/012-course-empty-fields-api.spec.ts` L240-253：`trial_video` → `trial_videos`，並新增驗證舊 meta 已被刪除。
4. 跑 `pnpm run lint:php`、`pnpm run lint:ts`、`composer run phpstan`、Playwright 整套應全綠。

### Phase 5 — 規格與文件對齊
1. 確認 `specs/api/api.yml`、`specs/entity/erm.dbml`、`specs/ui/課程銷售頁.md`、`specs/features/course/多影片試看.feature` 已對齊（clarifier 已處理，本期只需驗證一致性）。
2. 不寫 CHANGELOG（專案慣例由 release script 處理）。

## 風險評估與注意事項

| 風險等級 | 項目 | 緩解 |
| --- | --- | --- |
| **高** | Swiper bundle 與 i18n shim 衝突 —— 新 entry 漏掛 alias 會讓 swiper 內呼叫的 `__()`（若有）讀不到翻譯 | Phase 0 步驟 2 強制檢查 `vite.config-for-wp.ts::resolve.alias`；新增 PR 審查項 |
| **高** | 前台多 player mount 造成 mobile 流量爆增 | Swiper `lazy: true` + VidStack `preload="none"`；Phase 2 重構時加 lazy mount |
| **中** | 拖拉 lib 不一致 —— 既有 `SortableChapters` 使用何種 lib 需先確認 | Phase 3 步驟 2 第 4 點先讀 `SortableChapters` 對齊；若使用 `react-sortable-hoc`（已 deprecated），考慮跟 SortableChapters 一致避免新依賴，但在 PR 註記未來統一遷移計畫 |
| **中** | Subtitle slot 仍為單一 `trial_video` —— 多影片試看暫不支援字幕 | UX 註記：`hideSubtitle={true}` 在 TrialVideosList 內；issue 後續再開另一張（如「多試看影片各自字幕」） |
| **中** | 既有 admin 端透過 wc-admin classic editor 編輯課程仍會直接寫 `trial_video` postmeta（非透過 power-course API） | 本期不處理 classic editor 路徑；lazy migration 確保兩條寫入路徑共存。在計畫中註記為 known limitation |
| **低** | code 類型影片無法跨 type 暫停 | 不處理；admin 自負風險。記入 README 與註解 |
| **低** | bundle size：Swiper ~40KB gzipped | 條件 enqueue 讓 0/1 部影片頁面不受影響；2~6 部頁面才付出此成本，可接受 |

### 未處理項（明確 out-of-scope）

1. **多試看影片各自字幕**：本期 `Subtitle` 服務維持單一 `trial_video` slot；TrialVideosList 內傳 `hideSubtitle={true}`。後續另開 issue 討論「`pc_subtitles_trial_videos[i]`」資料模型。
2. **MCP server tools 擴充**：grep 確認目前 `inc/classes/Api/Mcp/Tools/` 無直接讀寫 `trial_video`，僅透過 Course API 間接操作；Course API 改變後 MCP 自動相容。若有 tool 顯式回傳 `trial_video` schema，則需另開 issue 補 `trial_videos`。
3. **Classic Editor 端的試看影片設定**：仍使用單一 `trial_video` postmeta（如 wc-admin product editor 的 metabox）；本期專注 power-course React Admin SPA。
4. **一次性 migration script**：採 lazy migration，不處理。若未來需強制統一可另起 maintenance task（如 `wp pc migrate:trial-videos`）。

## 測試策略

### PHP Integration Test (PHPUnit)

- **覆蓋率目標**：`tests/Integration/Course/CourseTrialVideosTest.php` 覆蓋 feature 文件中 11 個 Example 場景的後端契約（不含前台渲染）。
- **執行命令**：`composer run test`（既有 wp-env Docker 化測試環境）。
- **斷言重點**：
  - HTTP 狀態碼（200 / 400）。
  - DB postmeta key/value（`trial_videos` 為 JSON 字串 + 舊 `trial_video` 不存在）。
  - GET response shape（`trial_videos: TVideo[]` + `trial_video` deprecated fallback）。

### Frontend E2E (Playwright)

- **`tests/e2e/02-frontend/`**：前台銷售頁渲染 5 個場景（0/1/2/3 部 + 暫停切換）。
- **`tests/e2e/01-admin/`**：後台 Form.List 操作 3 個場景（舊資料相容 + 上限 + 拖拉）。
- **`tests/e2e/03-integration/`**：修改既有 `012-course-empty-fields-api.spec.ts` 的 trial_video 清空測試為 trial_videos。
- **執行命令**：`pnpm run test:e2e:frontend` / `:admin` / `:integration`。
- **資料準備**：透過 `helpers/api-client.ts` 直接 POST `/courses/{id}` 設定 `trial_videos`，避免 UI 操作降低測試穩定性。

### 品質檢查

- `pnpm run lint:php`：PHPCS + PHPStan level 9 必通過。
- `pnpm run lint:ts`：ESLint + Prettier 必通過。
- `pnpm run i18n:build` 後 `git diff languages/`：四個檔案都有預期 diff，無未翻譯字串新增。
- `pnpm run build:wp`：前台 vanilla TS bundle 需編譯成功（含新 entry）。

## 交付清單（PR Description 模板）

```
## Summary
- 課程試看支持多影片（最多 6 部）
- 後端 trial_videos postmeta 取代 trial_video（lazy migration 向下相容）
- 前台 Swiper 條件式輪播（1 部直顯、2~6 部輪播）
- 後台 Form.List 拖拉排序

## Test plan
- [ ] PHPUnit `tests/Integration/Course/CourseTrialVideosTest.php` 全綠
- [ ] Playwright `02-frontend/0XX-course-trial-videos-swiper.spec.ts` 全綠
- [ ] Playwright `01-admin/course-edit-trial-videos.spec.ts` 全綠
- [ ] Playwright `03-integration/012-course-empty-fields-api.spec.ts` 全綠（trial_video → trial_videos）
- [ ] `pnpm run lint:php` 通過
- [ ] `pnpm run lint:ts` 通過
- [ ] `pnpm run i18n:build` 已執行，languages/ 四檔都已 commit
- [ ] 手動驗證：本地站 https://local-turbo.powerhouse.tw 課程銷售頁 0/1/3 部影片渲染
- [ ] 手動驗證：mobile viewport 觸控滑動正常
```
