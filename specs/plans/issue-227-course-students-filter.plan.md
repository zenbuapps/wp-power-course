# 實作計劃：課程學員 Tab — Filter 篩選器 (Issue #227)

## 概述

於「課程編輯頁 → 學員 Tab」上方新增 Filter 篩選器，提供「關鍵字搜尋」與「課程進度查詢」兩種條件（運算子 + 0-100 整數百分比），協助站長快速找到落後 / 領先 / 已完成 / 未開始的學員，並讓「匯出學員 CSV」依當前篩選結果輸出。

對應規格：

- `specs/features/student/課程學員Filter篩選.feature`
- `specs/ui/課程學員Tab-Filter.md`
- `specs/api/api.yml`（`GET /students` 已加入 `progress_operator` / `progress_value` 參數）

## 範圍模式：HOLD SCOPE

clarifier session 已將需求收斂為 8 個明確決策（**A B C A A A A B**），且 `.feature` / `ui.md` / `api.yml` 均已寫入 `./specs`。預估影響 **9 個生產檔案 + 2 個測試新檔 + i18n 翻譯與 build 產出**。範圍不再擴張。

## 需求重述

1. 課程編輯頁學員 Tab 上方加 `<Card title="篩選">` 區，視覺對齊既有 `UserTable/Filter`
2. 欄位 1：關鍵字搜尋（單一 Input），對應 `?search=`，後端比對 ID / login / email / display_name / billing / first_name / last_name（reuse 既有 `Query::search_field=default` 行為）
3. 欄位 2：課程進度（雙欄位）—— `Select` 運算子（=, !=, <, <=, >, >=）+ `InputNumber` 0-100 整數，對應 `?progress_operator=` / `?progress_value=`；兩欄位必須同時有值才會送出
4. FilterTags 顯示已套用條件（chip 點 ✕ 可移除）
5. 「篩選 / 重設」按鈕對齊 UserTable Filter
6. 匯出學員 CSV 帶入當前 Filter 參數（search / progress_operator / progress_value）
7. 切 Tab 保留 Filter 狀態（依靠 Refine `useTable` React state；重整頁面才清空）
8. 後端在 `Query::__construct()` 中以 SQL HAVING 子句套用進度篩選，pagination 的 total 反映篩選後筆數
9. 參數驗證：
   - `progress_operator` 與 `progress_value` 必須成對 → 400「progress_operator 與 progress_value 必須同時提供」
   - `progress_operator` 白名單 → 400「progress_operator 只能是 =、!=、<、<=、>、>=」
   - `progress_value` 0-100 整數 → 400「progress_value 必須是 0 到 100 之間的整數」
10. 課程無章節（total_chapters = 0）時所有學員進度視為 0%，避免除以零

## 已確認的設計決策

| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1 | 搜尋欄位範圍 | **A** — 跟 UserTable 一致，單一輸入框比對 ID / login / Email / display_name / billing 姓名 |
| Q2 | 進度篩選 UI | **B** — 運算子 Select + 數值 InputNumber 雙欄位（=, !=, <, <=, >, >= × 0–100） |
| Q3 | 課程進度欄位 | **C** — 不加進 Table 欄位，只做 Filter |
| Q4 | 視覺風格 | **A** — 完全參考 UserTable Filter：`<Card>` + `<FilterTags />` + 「篩選 / 重設」 |
| Q5 | 匯出行為 | **A** — 匯出套用 Filter |
| Q6 | 狀態保留 | **A** — 保留條件（Refine useTable React state），重整頁面才清空 |
| Q7 | 後端篩選實作 | **A** — SQL 層子查詢計算進度並以 HAVING 套用 |
| Q8 | 批次操作交集 | **B** — 當前頁全選（不在此 Issue 處理跨頁全選） |

## 已知風險（來自程式碼研究）

| 風險 | 嚴重度 | 緩解措施 |
| --- | --- | --- |
| `Query.php` 既有 SQL 直接字串拼接（`"%{$search_value}%"`），新增 progress filter 必須避免引入新的 injection 風險 | 高 | progress_operator 走白名單 + match 後直接寫入字面值；progress_value 以 `intval()` 強轉為整數；課程 ID（`meta_value`）原本就是 `$wpdb->prepare()` 參數，沿用 |
| `Query::get_pagination()` 目前以 `$this->where` 計算 total，未含 JOIN 與 HAVING；進度篩選若用 HAVING 必須讓 pagination SQL 也帶上同樣的 JOIN + HAVING，否則 `total` 會回全體學員數而非篩選後筆數（破壞 spec 中「pagination.total 為 2」的驗證點） | 高 | 將「計算進度」的 subquery 抽成可共用的 SQL fragment（user_id 子查詢或 derived table），同時用於 main query 與 count query；count 改為 `SELECT COUNT(DISTINCT u.ID) FROM (...) sub` 包裹 |
| `pc_avl_chaptermeta` 是 EAV 表，同 `(post_id, user_id, meta_key='finished_at')` 可能出現多筆 row（spec 注釋有提到 LinearViewing 用 `DISTINCT post_id`）；若直接 COUNT 進度會被重複計算成 >100% | 高 | 進度子查詢用 `COUNT(DISTINCT cm.post_id)` 而非 `COUNT(*)`；上限 clamp 為 100（與 `CourseUtils::get_course_progress()` 一致：`min(100, ...)`） |
| `CourseUtils::get_course_progress()` 回傳 `round(progress, 1)`（含小數一位）；SQL 端若用 `INT(...)` 截斷會跟 UI 顯示不一致 | 中 | progress filter 採整數百分比語意：SQL 用 `ROUND( finished*100/total, 1 )` 計算後與 `progress_value`（整數）比較；spec 中 50% / 20% / 100% / 0% 均為整數，邊界明確 |
| 進度 = 0% 的學員可能完全沒有 `pc_avl_chaptermeta` row（從未進入任何章節）；LEFT JOIN 後 `COUNT` 為 0 是正確結果 | 中 | 子查詢使用 LEFT JOIN 並把 NULL 視為 0；spec Background 中 Diana 即「已加入課程但完成 0 章節」，驗證此情境 |
| 「課程無章節」（`get_flatten_post_ids` 空陣列）時除以零 | 中 | 子查詢前先在 PHP 端取得 `$chapter_ids = ChapterUtils::get_flatten_post_ids($course_id)`，空陣列時直接視為「所有學員進度 0%」並用 `progress_operator + progress_value` 在 PHP 端短路（=0 / <=0 / <N 全通過、>0 / =100 全不通過） |
| 既有 `Query::__construct()` throw `Exception` 而非回 400 JSON；新增驗證若沿用 throw 會導致非預期 500 | 高 | 新驗證在 Api.php callback 中前置做完並 `return new WP_Error(...)` 回 400（或在 Query constructor 內 throw 而 callback try/catch 包成 400），與規格中「操作失敗，錯誤為...」對齊 |
| FilterTags 對 `progress_operator` / `progress_value` 兩個 key 各顯示一個 chip 會很怪（應該合併成「進度 < 30%」一個 chip） | 中 | 在 `StudentTable/utils/index.tsx` 自訂 `keyLabelMapper`，並對 `progress_value` chip 客製顯示為「進度 {operator} {value}%」；或在 Filter 元件內 hook `onValuesChange` 將兩欄位整合進一個 synthetic field（推薦前者，較不破壞既有 useTable contract） |
| 翻譯 pipeline：新增 7 個 English msgid（含 sprintf placeholder），必須 commit `manual.json` + 跑 `pnpm run i18n:build` 後一併 commit 4 個 languages/ 檔 | 低 | 在前端步驟 5 明列所有新 msgid 與繁中翻譯；CI 會掃 .pot diff |
| ExportCSV 既有實作中 `Query` 用 `posts_per_page = -1` 全撈 + PHP 端 batch_process；加入 search / progress filter 不會破壞既有行為，但 constructor signature 變更必須同步更新 callback 與既有測試 | 中 | `ExportCSV` constructor 增加可選參數 `string $search = ''`、`?string $progress_operator = null`、`?int $progress_value = null`，預設為原行為（空白），保留向下相容 |
| Refine `useTable` 的 `setFilters` 同時更新多個 field 時可能觸發兩次 refetch | 低 | 在「重設」按鈕用 `form.resetFields()` 後一次 `form.submit()`，符合既有 UserTable Filter 模式 |

## 架構變更

### 後端（PHP）— 5 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 1 | `inc/classes/Resources/Student/Core/Api.php` | 修改 | `get_students_callback()` 解析 `progress_operator` / `progress_value` 並傳入 `Query`；新增三條 400 驗證；`get_students_export_with_id_callback()` 解析同樣參數並傳入 `ExportCSV` constructor |
| 2 | `inc/classes/Resources/Student/Service/Query.php` | 修改 | constructor 增加 progress filter SQL 子查詢；`get_pagination()` 改為包含同樣 JOIN+HAVING；保留既有公開 API（`user_ids` / `get_pagination` / `get_users` / `get` static），signature 不破壞 |
| 3 | `inc/classes/Resources/Student/Service/ExportCSV.php` | 修改 | constructor 增加 `$search` / `$progress_operator` / `$progress_value` 三個 optional 參數，傳入內部 `Query` |
| 4 | `inc/classes/Resources/Student/Service/Progress.php` | 不修改 | 進度計算邏輯抽到 Query SQL，不必動 |
| 5 | `inc/classes/Utils/Course.php` | 不修改 | `get_course_progress()` 是 PHP 端逐筆計算，SQL 端有獨立公式（spec 已說明須保證結果一致） |

### 前端（TypeScript / React）— 3 個檔案

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 6 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/Filter/index.tsx` | **新增** | Filter 元件本體，含關鍵字 Input + 運算子 Select + 數值 InputNumber + 篩選/重設按鈕；對齊既有 `js/src/components/user/UserTable/Filter/index.tsx` 結構 |
| 7 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/utils/index.tsx` | **新增** | 提供 FilterTags 用的 `keyLabelMapper`（含 `progress_operator` / `progress_value` 合併顯示為「進度 < 30%」chip） |
| 8 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/index.tsx` | 修改 | (a) 引入 Filter 並包入 `<Card title="篩選">`；(b) `useTable` 加 `onSearch` handler；(c) `handleExport` 從 `searchFormProps.form` 取出當前值並組進 URL；(d) 拆 `searchFormProps`、`filters` 並顯示 `<FilterTags />` |

### i18n — 1 個翻譯來源 + 4 個 build 產出

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 9 | `scripts/i18n-translations/manual.json` | 修改 | 新增 7 條繁中翻譯（見「i18n 新增字串清單」） |
| 10 | `languages/power-course.pot` / `power-course-zh_TW.po` / `power-course-zh_TW.mo` / `power-course-zh_TW.json` | 自動產出 | 由 `pnpm run i18n:build` 一次產生，commit 時連同程式碼一起進版本控制 |

### 測試 — 2 個新檔

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 11 | `tests/Integration/Student/CourseStudentsFilterTest.php` | **新增** | PHPUnit Integration Test，覆蓋 `.feature` 全部 Rule（11 個 Example） |
| 12 | `tests/e2e/01-admin/course-students-filter.spec.ts` | **新增** | Playwright E2E，覆蓋學員 Tab Filter UI 流程 + 匯出按鈕帶參數 |

## 資料流分析

### 流程 1 — Filter 提交 → Refine `useTable` refetch → 顯示結果

```
[Filter Form]
  search: "alice"
  progress_operator: "<"
  progress_value: 30
        │
        ▼ form.submit()
[Refine useTable.setFilters]
        │
        ▼ HTTP GET /power-course/v2/students
[Api::get_students_callback]
        │
        ├── 驗證 progress_operator / progress_value 成對 ──▶ 400
        ├── 驗證 progress_operator ∈ {=, !=, <, <=, >, >=} ──▶ 400
        ├── 驗證 progress_value ∈ [0, 100] (int) ──────────▶ 400
        │
        ▼
[Query::__construct]
  SELECT u.ID FROM wp_users u
    INNER JOIN wp_usermeta um ON ... AND um.meta_key='avl_course_ids' AND um.meta_value='100'
    LEFT JOIN wp_usermeta um_fn/um_ln/um_bfn/um_bln (search 為 name/default 時)
    LEFT JOIN (SELECT user_id, ROUND(COUNT(DISTINCT post_id) * 100 / total, 1) AS progress
               FROM wp_pc_avl_chaptermeta
               WHERE meta_key='finished_at' AND post_id IN (chapter_ids)
               GROUP BY user_id) p ON p.user_id = u.ID
  WHERE ... AND (search clauses) AND COALESCE(p.progress, 0) < 30
  ORDER BY um.umeta_id DESC LIMIT 20 OFFSET 0
        │
        ▼
[user_ids 陣列] ──▶ User::to_array('list', meta_keys) ──▶ JSON response
        │            X-WP-Total / X-WP-TotalPages headers
        ▼
[Refine useTable] ──▶ Table 渲染篩選後學員 + 第 1 頁
```

### Shadow paths（每階段）

```
INPUT (search/progress params)
  │
  ▼
[nil?]    → search/progress 皆空：不附加 search clause、不附加 progress HAVING
[empty?]  → progress_value=0 且 operator='='：合法，回 Diana（spec Example）
[invalid?] → operator 非白名單 / value 超界 / 只給一邊：400

VALIDATION (Query::__construct)
  │
  ▼
[meta_value 空] → throw Exception → callback catch 包成 400「meta_value cannot be empty」
[course chapters 為空] → total_chapters=0 → progress 視為 0 → 套用條件後可能回空 list（非 500）

TRANSFORM (SQL 計算)
  │
  ▼
[user_id 在 chaptermeta 完全無 row] → LEFT JOIN 後 progress 為 NULL → COALESCE(p.progress,0)=0 → 進度視為 0%
[post_id 不在 chapter_ids 中] → 子查詢 WHERE 已濾掉，不影響
[finished_at 多筆 row] → COUNT(DISTINCT post_id) 去重 → 正確
[total_chapters=0] → PHP 端短路，不進 SQL

PERSIST (無寫入)
  │
  ▼
[N/A] — 僅查詢

OUTPUT (response)
  │
  ▼
[empty result] → 回空 array + X-WP-Total=0 + X-WP-TotalPages=0（非 404）
[stale cache] → REST callback 第一行 `\nocache_headers()` 已注入（wordpress.rule.md 要求）
```

### 流程 2 — 匯出 CSV（套用 Filter）

```
[StudentTable handleExport]
  讀 searchFormProps.form.getFieldsValue() → { search, progress_operator, progress_value }
        │
        ▼
[URLSearchParams]
  _wpnonce=NONCE
  ? &search=alice
  ? &progress_operator=%3C  (URL encode)
  ? &progress_value=30
        │
        ▼ window.open(`${apiUrl}/students/export/${courseId}?${qs}`)
[Api::get_students_export_with_id_callback]
        │
        ▼ extract_export_params (擴充)
[ExportCSV::__construct($course_id, $search, $progress_operator, $progress_value)]
        │
        ▼ 內部 Query(posts_per_page=-1, search=..., progress_operator=..., progress_value=...)
[get_rows()] ──▶ ExportCSVBase::export() ──▶ CSV download
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| `GET /students?progress_operator=<` 只給一邊 | 參數驗證失敗 | 400 ValidationError | callback 前置檢查回 `WP_Error('progress_pair_required', '...', ['status'=>400])` | ✅ 顯示為 toast/message |
| `GET /students?progress_operator=LIKE` | operator 非白名單 | 400 ValidationError | callback 前置 → 400 | ✅ |
| `GET /students?progress_value=150` | value 超界 / 非整數 | 400 ValidationError | callback 前置（含 `intval` 後 0..100 範圍檢查）→ 400 | ✅ |
| `GET /students?meta_value=` | course_id 缺失 | Exception | 既有：throw → callback try/catch → 400 | ✅ |
| 課程無章節 + progress_operator='>' value=0 | total_chapters=0 → 套用後空 list | 200 + empty array | PHP 短路：直接回 user_ids=[] + total=0 | ✅ 顯示「無符合學員」 |
| `pc_avl_chaptermeta` 結構異常（meta_key 名變更） | SQL 結果為 0 | 200 + 全員 0% | progress 0%，等同 spec 中 Diana case | ⚠️ 邏輯誤差但不會 500 |
| Refine 端 setFilters 失敗 | 網路錯誤 / 5xx | 500 / network | useTable 內建 error handler，顯示 message.error | ✅ Refine 預設行為 |
| Filter 元件只填運算子或只填數值 | 表單驗證 | inline 錯誤 | `Form.Item` 加 `dependencies` + `validator`，前端攔截，不送出 | ✅ inline 紅字 |
| 「重設」按鈕 | N/A | 無錯誤 | `form.resetFields()` + `form.submit()` | ⚠️ Table 重新 fetch |
| Export 失敗 | 後端 throw | 500 | 既有 try/catch logger（沿用），瀏覽器收到 500 顯示「Failed to export」 | ✅ 既有訊息 |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| `Api::get_students_callback` 收到非白名單 operator | 400 + 訊息 | ✅ | ✅ Integration | ✅ | 修正參數重試 |
| `Query::__construct` 課程無章節 | 短路回空 | ✅ | ✅ Integration (新增 edge case) | ✅ | N/A，預期行為 |
| `Query` LEFT JOIN derived table 包覆 COUNT 時被資料庫優化器忽略 | total 不正確 | ✅ | ✅ Integration（pagination.total 驗證） | ❌ | 修 SQL |
| `pc_avl_chaptermeta` 同章節多筆 finished_at row | progress >100% | ✅ `COUNT(DISTINCT post_id)` + `min(100, ...)` clamp | ✅ Integration（補造重複 row 測試） | ❌ | clamp 處理 |
| FilterTags 對 `progress_operator` / `progress_value` 兩 key 各自顯示 chip | UI 出現兩個 chip 看起來怪 | ✅ 合併到 `progress_value` 的 mapper 同時顯示 operator 與 value | ✅ E2E（截圖驗證 chip 文字） | ✅ | mapper 修正 |
| `useTable` filters 與 permanent filters 順序不對導致 meta_value 被覆蓋 | 篩選不到任何學員 | ✅ permanent filters 優先，dynamic 走 `onSearch` 回傳 | ✅ E2E（先 Filter 再切 Tab） | ✅ | 既有 Refine 機制 |
| Export 帶 progress filter 後 batch_process 超時 | 大型課程匯出失敗 | ⚠️ 既有問題不在此 Issue 範圍 | ❌ | ✅ 500 | 視情況後續 issue |
| Filter 兩欄位都有值但欄位驗證漏判 | 422 / 500 | ✅ Form.Item dependencies 雙向驗證 + 後端 400 雙重把關 | ✅ E2E 試「只填運算子點篩選」 | ✅ | inline 錯誤 |
| `Query::get_pagination()` count SQL 與 main SQL 條件不一致 | total 對不上實際筆數 | ✅ 抽 SQL fragment 共用 | ✅ Integration（spec posts_per_page=1 案例） | ❌ | 修 SQL |
| 重整頁面後 Filter 清空但 URL 仍指向 #/courses/edit/100 | 預期行為（A 方案） | ✅ | ⚠️ 非測試重點 | ⚠️ | 無需恢復 |

## 實作步驟

### 第一階段 — 後端 SQL 子查詢與參數驗證（核心）

**TDD 優先順序：紅燈先寫 Integration Test，再實作 Query SQL。**

1. **新增 Integration Test 骨架**（檔案：`tests/Integration/Student/CourseStudentsFilterTest.php`）
   - 行動：依 `課程學員Filter篩選.feature` 11 個 Example 寫 11 個 `test_*` 方法
   - Background fixture：建 1 個 course（10 章節）+ 5 個 user（Admin, Alice, Bob, Charlie, Diana），用 `factory()` + `AVLChapterMeta::add(post_id, user_id, 'finished_at', time())` 製造進度
   - 直接呼叫 `Api::instance()->get_students_callback($request)` 與 `Query` constructor，斷言 user_ids / pagination / 錯誤碼
   - 依賴：無
   - 風險：低（測試先行）

2. **`Query::__construct()` 加 progress filter SQL**（檔案：`inc/classes/Resources/Student/Service/Query.php`）
   - 行動：
     - 新 `progress_operator` / `progress_value` 兩個 args
     - 在 PHP 端先 `$chapter_ids = ChapterUtils::get_flatten_post_ids($course_id)`；空 → 短路（依 operator+value 決定回全 user_ids 還是空）
     - 在主 SQL 增加 LEFT JOIN derived table：`(SELECT user_id, ROUND(COUNT(DISTINCT post_id) * 100 / {N}, 1) AS progress FROM {wpdb->prefix}pc_avl_chaptermeta WHERE meta_key='finished_at' AND post_id IN (chapter_ids 字面值) GROUP BY user_id) p ON p.user_id = u.ID`
     - WHERE 子句加 `COALESCE(p.progress, 0) {operator} {value}`
     - 抽出 `private function build_progress_clause(): array { return ['join' => ..., 'where' => ...] }` 共用給 main + count
   - 抽出 `private function get_chapter_ids_for_progress(): array<int>`（接 `meta_value` course_id → 章節 ids）
   - 原因：規格要求 SQL 層篩選 + pagination 正確
   - 依賴：步驟 1
   - 風險：中（SQL 語法 + DISTINCT + clamp）

3. **`Query::get_pagination()` 改為 sub-query 計算 total**（檔案：同上）
   - 行動：count SQL 改為包含同樣的 progress JOIN + WHERE，用 `SELECT COUNT(DISTINCT u.ID) FROM ... <where with progress>`
   - 原因：spec 要求 `pagination.total` 反映篩選後筆數
   - 依賴：步驟 2
   - 風險：中

4. **`Api::get_students_callback()` 加參數驗證 + 傳遞**（檔案：`inc/classes/Resources/Student/Core/Api.php`）
   - 行動：
     - callback 第一行加 `\nocache_headers()`（wordpress.rule.md 要求；既有 Api.php 中此 callback 沒有，順手補）
     - sanitize params 之後檢查 `progress_operator` / `progress_value`：
       - 兩者只有一個 → 回 `new WP_REST_Response(['code'=>'progress_pair_required','message'=>__('progress_operator 與 progress_value 必須同時提供','power-course')],400)`（spec 用繁中錯誤訊息但 msgid 仍為英文 — 見「i18n 新增字串」）
       - operator 不在 `['=','!=','<','<=','>','>=']` → 400 + 對應 msgid
       - value 不在 0..100 整數 → 400 + 對應 msgid
     - 通過後將兩參數寫入 `$rest_params` 傳入 `Query`
     - 對 `Query::__construct` 的 `Exception` 用 try/catch 包成 400（meta_value 空的情境）
   - 原因：spec 三條 Rule
   - 依賴：步驟 2
   - 風險：中

5. **跑 Integration Test 確認綠燈**
   - 行動：`vendor/bin/phpunit --filter CourseStudentsFilterTest`
   - 依賴：步驟 1-4
   - 風險：低

### 第二階段 — 匯出 CSV 同步擴充

6. **`ExportCSV::__construct` 加 optional 篩選參數**（檔案：`inc/classes/Resources/Student/Service/ExportCSV.php`）
   - 行動：
     - 新 constructor 簽名：`__construct(int $course_id, string $search = '', ?string $progress_operator = null, ?int $progress_value = null)`
     - `get_rows()` 內 `new Query([...])` 傳入這四個參數
     - 既有呼叫端（`Api::get_students_export_with_id_callback`）仍可只傳 `$course_id`，向下相容
   - 原因：spec Q5=A 匯出套用 Filter
   - 依賴：步驟 2-3
   - 風險：低

7. **`Api::get_students_export_with_id_callback()` 解析 query string**（檔案：`Api.php`）
   - 行動：
     - callback 第一行 `\nocache_headers()`
     - 從 `$request->get_query_params()` 取 `search` / `progress_operator` / `progress_value`，sanitize（白名單檢查 + intval clamp）
     - 不合法時直接忽略該參數（匯出不阻擋，但記 log）；或同樣回 400（**推薦同樣 400**，與 list endpoint 行為一致，前端只會送合法參數）
     - 傳給 `new ExportCSV($course_id, $search, $progress_operator, $progress_value)`
   - 原因：匯出帶 Filter
   - 依賴：步驟 6
   - 風險：中

### 第三階段 — 前端 Filter UI

8. **新增 `StudentTable/Filter/index.tsx`**（檔案：見「架構變更 #6」）
   - 行動：仿 `js/src/components/user/UserTable/Filter/index.tsx`，含：
     - `<Form.Item name="search" label="Keyword search">` → `<Input.Search allowClear>`
     - `<Form.Item label="Progress">` 內用 `<Space.Compact>` 放 `<Form.Item name="progress_operator">` `<Select>` + `<Form.Item name="progress_value">` `<InputNumber min={0} max={100} precision={0} addonAfter="%">`
     - 雙欄位 `Form.Item.dependencies`：當其中一個有值另一個必填，否則顯示 inline 錯誤
     - 「篩選」`<Button type="primary" htmlType="submit" icon={<SearchOutlined />}>` + 「重設」`<Button onClick={form.resetFields then form.submit}>`
   - export `TStudentFilterValues = { search?: string; progress_operator?: '=' | '!=' | '<' | '<=' | '>' | '>='; progress_value?: number }`
   - 依賴：無
   - 風險：低

9. **新增 `StudentTable/utils/index.tsx`**（檔案：見「架構變更 #7」）
   - 行動：實作 `keyLabelMapper`：
     - `'search'` → `__('Keyword search', 'power-course')`
     - `'progress_value'` → 讀同表單的 `progress_operator` 拼成 `sprintf(__('Progress %1$s %2$d%%', 'power-course'), operator, value)`（合併兩欄位為一個 chip）
     - `'progress_operator'` → 回 `''`（hidden，避免重複 chip）
   - 注意：FilterTags 簽名 `(key) => string`，無法取得 form value；改在 mapper 內回傳特殊 sentinel，或在 `StudentTable/index.tsx` 用 `useWatch` 取兩值後客製 chip 渲染。**最簡解**：在 `keyLabelMapper` 用 closure 接 `form` 並讀 `form.getFieldValue('progress_operator')`
   - 依賴：步驟 8
   - 風險：中（FilterTags API 細節需驗證）

10. **修改 `StudentTable/index.tsx`**（檔案：見「架構變更 #8」）
    - 行動：
      - 引入 `Filter` 與 `keyLabelMapper`、`FilterTags`、`Card`
      - `useTable` 加 `onSearch: (values: TStudentFilterValues) => filters[]`（轉成 Refine filter 陣列：`field='search'/'progress_operator'/'progress_value'`、`operator='eq'`、`value=...`）
      - 解構 `useTable` 回傳：`{ searchFormProps, tableProps, filters }`
      - 把當前 `<div className="mb-4 flex justify-between gap-4">` 操作列上方插入：
        ```tsx
        <Card title={__('Filter', 'power-course')} variant="borderless" className="mb-4">
          <Filter formProps={searchFormProps} />
          <FilterTags form={searchFormProps.form as FormInstance} keyLabelMapper={keyLabelMapper(searchFormProps.form)} />
        </Card>
        ```
      - `handleExport` 改為：
        ```ts
        const handleExport = () => {
          const values = (searchFormProps.form as FormInstance<TStudentFilterValues>).getFieldsValue()
          const params = new URLSearchParams({ _wpnonce: NONCE })
          if (values?.search) params.append('search', values.search)
          if (values?.progress_operator && values?.progress_value !== undefined && values?.progress_value !== null) {
            params.append('progress_operator', values.progress_operator)
            params.append('progress_value', String(values.progress_value))
          }
          window.open(`${apiUrl}/students/export/${courseId}?${params.toString()}`, '_blank')
        }
        ```
    - 依賴：步驟 8-9
    - 風險：中

### 第四階段 — i18n 與 E2E 測試

11. **補繁中翻譯到 `manual.json`**（檔案：`scripts/i18n-translations/manual.json`）
    - 新增以下 7 條（msgid 一律英文，msgstr_zh_TW 繁中）：
      - `'Keyword search'` → 既有：「關鍵字搜尋」
      - `'Progress'` → 「課程進度」
      - `'Operator'` → 「運算子」
      - `'0-100'` → 「0-100」
      - `'Both operator and value are required'` → 「運算子與數值必須同時提供」
      - `'Progress %1$s %2$d%%'`（FilterTags chip）→ 「進度 %1$s %2$d%%」
      - `'progress_operator 與 progress_value 必須同時提供'`（後端錯誤訊息）→ 「progress_operator 與 progress_value 必須同時提供」（注意 spec 規格訊息是繁中 — 這條 msgid 與 msgstr 都用繁中是 OK 的，因為是錯誤碼訊息）— **改：msgid 用英文「`Both progress_operator and progress_value must be provided`」，msgstr 用繁中**
      - 同理：`'progress_operator must be one of =, !=, <, <=, >, >='`、`'progress_value must be an integer between 0 and 100'`
      - 運算子 Select 6 個 label：`Equal to` / `Not equal to` / `Less than` / `Less or equal` / `Greater than` / `Greater or equal`
    - 依賴：步驟 4 + 8
    - 風險：低

12. **跑 `pnpm run i18n:build`**（確認 `.pot` / `.po` / `.mo` / `.json` 四檔都更新）
    - 依賴：步驟 11
    - 風險：低

13. **新增 E2E `tests/e2e/01-admin/course-students-filter.spec.ts`**
    - 行動：
      - 用 `api-client` helper 建 1 course + 4 user，預先呼叫 add-students + 模擬章節完成（透過 toggle-finish API 或 raw DB insert）
      - 登入 admin → 進入課程編輯頁 → 切到學員 Tab
      - 在 Filter Card 輸入「alice」+ Select 「<」+ InputNumber 30 → 點「篩選」
      - 斷言 Table 只顯示 Diana（與 spec Example「搜尋名稱『a』且進度 < 30%」一致）
      - 驗證 FilterTags 顯示「關鍵字: alice」「進度 < 30%」
      - 點「重設」→ 斷言所有學員回到 Table
      - 點「匯出學員 CSV」→ 攔截 `window.open`，驗證 URL 含 `progress_operator=%3C&progress_value=30`
      - 切到「章節」Tab 再切回 → Filter 條件仍存在
    - 依賴：步驟 1-10
    - 風險：中（E2E 環境前置資料）

### 第五階段 — 品質檢查與交付

14. **跑全套 lint + test**
    - `pnpm run lint:php`
    - `pnpm run lint:ts`
    - `pnpm run build`（確認 TS 編譯通過）
    - `composer run phpstan`（level 9）
    - `composer run test -- --filter Student`
    - `pnpm run test:e2e:admin -- course-students-filter`

15. **手動 smoke test 於 `local-turbo.powerhouse.tw`**
    - 依 spec 互動流程逐步操作（見 `ui/課程學員Tab-Filter.md` 第 30-43 行）
    - 截圖：Filter Card 預設展開、FilterTags 顯示「進度 < 30%」chip、匯出後 CSV 內容只含 Diana

## 測試策略

### Integration Test（PHPUnit + WP_UnitTestCase）

檔案：`tests/Integration/Student/CourseStudentsFilterTest.php`

對應 `.feature` 的 11 個 Example，每個各一個 test method：

| Rule | Example | 測試方法 |
| --- | --- | --- |
| 參數成對 | 只給 operator | `test_progress_operator_alone_returns_400` |
| 參數成對 | 只給 value | `test_progress_value_alone_returns_400` |
| 白名單 | LIKE 拒絕 | `test_invalid_operator_returns_400` |
| 白名單 | 6 種合法皆接受 | `test_all_valid_operators_accepted`（dataProvider 跑 6 次） |
| 範圍 | -10 拒絕 | `test_negative_value_returns_400` |
| 範圍 | 150 拒絕 | `test_value_over_100_returns_400` |
| < N% | 進度 <30% | `test_filter_lt_30_returns_charlie_and_diana` |
| > N% | 進度 >50% | `test_filter_gt_50_returns_alice` |
| = N% | 進度 =100% | `test_filter_eq_100_returns_alice` |
| = N% | 進度 =0% | `test_filter_eq_0_returns_diana` |
| >= / <= | 進度 >=50%、<=20% | `test_filter_gte_50` + `test_filter_lte_20` |
| != | !=100% | `test_filter_not_eq_100_excludes_alice` |
| search 多欄位 | email / billing / id / display_name | 4 個 test_search_by_* |
| AND 組合 | search + progress | `test_search_and_progress_combined` |
| pagination | total 反映篩選結果 | `test_pagination_total_after_filter` |
| 空結果 | total=0 | `test_no_match_returns_empty_list` |
| 邊界 | 課程 0 章節 + progress=0 + op='=' | `test_zero_chapters_all_users_progress_zero`（新增 edge case） |
| 邊界 | chaptermeta 重複 finished_at row | `test_duplicate_finished_at_clamped_to_100`（新增 edge case） |

### E2E Test（Playwright）

檔案：`tests/e2e/01-admin/course-students-filter.spec.ts`

| 場景 | 斷言 |
| --- | --- |
| Filter 預設展開 | `<Card title="篩選">` 可見 |
| 輸入關鍵字 + 進度 → 篩選 | Table rows count 符合預期 + URL 帶 query string |
| FilterTags chip | 文字「進度 < 30%」可見、點 ✕ 移除 |
| 重設按鈕 | 表單清空 + Table 回原始 |
| 匯出帶 Filter | `window.open` URL 含 `progress_operator=%3C&progress_value=30` |
| 切 Tab 保留狀態 | Q6=A 驗證 |
| 只填運算子點篩選 | inline 錯誤 + 不送 request |
| 重整頁面清空 | Q6=A 第二段驗證 |

### 測試執行指令

```bash
# Integration
composer run test -- --filter CourseStudentsFilterTest

# E2E (admin only)
pnpm run test:e2e:admin -- course-students-filter

# 全套品質
pnpm run lint:php
pnpm run lint:ts
composer run phpstan
```

### 關鍵邊界情況

- 課程 0 章節（`get_flatten_post_ids` 回 []）→ 所有學員進度 0%
- 進度 = 0%（從未完成任何章節，可能完全無 chaptermeta row）
- 進度 = 100%（完成所有章節）+ chaptermeta 重複 row（不能算 >100%）
- progress_value = 0（邊界整數）+ progress_value = 100（另一邊界）
- search 含 SQL meta-character（`%` `_`）— 既有 Query 已直接拼接，不在本 Issue 範圍但需注意
- 「重設」按鈕同時清空 search + progress 兩欄
- 跨 20 筆分頁 + Filter 套用 → 第 2 頁正確
- 翻譯 fallback：英文 locale 站台 msgid 為 fallback 仍可讀

## i18n 新增字串清單

**前端（msgid 一律英文）：**

| msgid | msgstr_zh_TW | 使用位置 |
| --- | --- | --- |
| `Keyword search` | 關鍵字搜尋 | Filter Form label（已存在於 UserTable，reuse） |
| `Progress` | 課程進度 | Filter Form label |
| `Operator` | 運算子 | Select placeholder |
| `0-100` | 0-100 | InputNumber placeholder |
| `Equal to` | 等於 | Select option |
| `Not equal to` | 不等於 | Select option |
| `Less than` | 小於 | Select option |
| `Less or equal` | 小於或等於 | Select option |
| `Greater than` | 大於 | Select option |
| `Greater or equal` | 大於或等於 | Select option |
| `Both operator and value are required` | 運算子與數值必須同時提供 | Form.Item validator |
| `Progress %1$s %2$d%%` | 進度 %1$s %2$d%% | FilterTags chip（translators: 1=運算子, 2=百分比） |

**後端錯誤訊息：**

| msgid | msgstr_zh_TW |
| --- | --- |
| `Both progress_operator and progress_value must be provided` | progress_operator 與 progress_value 必須同時提供 |
| `progress_operator must be one of =, !=, <, <=, >, >=` | progress_operator 只能是 =、!=、<、<=、>、>= |
| `progress_value must be an integer between 0 and 100` | progress_value 必須是 0 到 100 之間的整數 |

**注意**：spec `.feature` Then 子句寫的「錯誤為『progress_operator 與 progress_value 必須同時提供』」是繁中閱讀，Integration Test 斷言時應改為比對 msgid 英文（或比對 response 中的 `code`），避免 locale 切換造成測試 fragile。

## 依賴項目

無新增 PHP/JS 依賴。所有用到的工具皆已在 `composer.json` / `package.json`：

- `J7\WpUtils\Classes\WP::sanitize_text_field_deep`
- `J7\Powerhouse\Domains\User\Model\User::to_array`
- `ChapterUtils::get_flatten_post_ids` / `CourseUtils::get_course_progress`
- Ant Design `Form`, `Input`, `Select`, `InputNumber`, `Space.Compact`, `Button`, `Card`
- `antd-toolkit/refine`: `FilterTags`
- `@refinedev/antd`: `useTable`
- `@wordpress/i18n`: `__`, `sprintf`

## 風險與緩解措施

- **高**：`Query::get_pagination()` 必須同步含 progress JOIN+WHERE → 抽 SQL fragment 共用；加 `test_pagination_total_after_filter` 保護
- **高**：SQL injection（progress_operator） → 嚴格白名單 match 後寫入字面值，progress_value 強制 `intval()`
- **高**：`pc_avl_chaptermeta` 重複 row → `COUNT(DISTINCT post_id)` + `min(100, ...)` clamp，加 `test_duplicate_finished_at_clamped_to_100`
- **中**：FilterTags chip 合併 progress_operator + progress_value → `keyLabelMapper` 接 form instance 讀取兩值合併渲染
- **中**：課程 0 章節除以零 → PHP 端短路（chapter_ids 為空時不進 SQL）
- **中**：既有 `Query::__construct` throw Exception 與新增 400 驗證的錯誤路徑統一 → Api callback 統一 try/catch 包成 WP_REST_Response
- **低**：i18n pipeline 漏跑 → CI 會 fail `.pot` diff；PR 必含 4 個 `languages/` 檔
- **低**：Refine setFilters 兩次 refetch → 「重設」用單一 form.submit 收斂

## 錯誤處理策略

採用「**前置驗證 + 短路 + 結構化 400 回應**」三層：

1. **前置驗證**（Api.php callback）：白名單檢查 + 範圍檢查 + 配對檢查 → 失敗回 400 JSON（含 `code` + `message`）
2. **短路**（Query constructor）：課程無章節時直接判定進度 0%，不進 SQL
3. **結構化錯誤**：所有 400 帶 spec 對齊的 `code`：
   - `progress_pair_required`
   - `progress_operator_invalid`
   - `progress_value_invalid`
   - 既有 `students_course_id_required`（meta_value 空，沿用既有訊息）

前端依 `code` 做不同訊息顯示；測試斷言 `code` 而非 `message`，避免 locale fragile。

## 限制條件

**此計劃不會做的事：**

- ❌ 不在 StudentTable 加「課程進度」欄位（Q3=C 明確排除）
- ❌ 不做跨頁全選批次操作（Q8=B 明確排除，跨頁全選留下版 issue）
- ❌ 不寫進度到 cache 欄位 / 不動 `pc_avl_coursemeta` schema（Q7=A 排除 cache 方案）
- ❌ 不把 Filter 條件寫入 URL query string（Q6=A 排除 C 選項）
- ❌ 不重寫 `Query` class（保持 backward compat，僅擴充 constructor args + 新 private method）
- ❌ 不修改全域 `/students/export-all`（本 Issue 只涉及 course-scoped 匯出 `/students/export/{id}`）
- ❌ 不修改 `Query::search_field` 行為（仍走 default 多欄位比對；spec Q1=A 已對齊）
- ❌ 不引入新的 npm / composer 依賴
- ❌ 不重構既有 `Query.php` SQL 拼接安全性問題（既有 `"{$search_value}"` 直接 interpolate 雖有風險，但不在 #227 範圍，留下次重構 issue）

## 成功標準

- [ ] `composer run test -- --filter CourseStudentsFilterTest` 全綠（18+ 個 test methods）
- [ ] `pnpm run test:e2e:admin -- course-students-filter` 全綠
- [ ] `pnpm run lint:php` 與 `composer run phpstan` 全過
- [ ] `pnpm run lint:ts` 與 `pnpm run build` 全過
- [ ] `pnpm run i18n:build` 跑完後 `git diff languages/` 顯示新 msgid 已進 .pot/.po/.mo/.json
- [ ] 手動驗證 `local-turbo.powerhouse.tw` 課程編輯頁學員 Tab：
  - [ ] Filter Card 預設展開
  - [ ] 「進度 < 30%」filter 篩出落後學員
  - [ ] FilterTags chip 文字「進度 < 30%」正確
  - [ ] 「匯出學員 CSV」帶 Filter 參數
  - [ ] 切 Tab 後 Filter 條件仍保留
  - [ ] 重整頁面後 Filter 清空
- [ ] 對應 `.feature` 的 11 個 Example 全部對應到測試斷言
- [ ] 不破壞既有 `StudentTable` 操作（DatePicker / 更新到期日 / 移除權限 / 加其他課程）

## 預估複雜度：中

- 後端 SQL 子查詢與 pagination 同步是主要技術風險（高）
- 前端 UI 大部分 reuse UserTable Filter 模式（低）
- i18n 流程已熟悉（低）
- 測試覆蓋面積大但模式重複（中）

---

## 交接 Note 給 tdd-coordinator

執行順序：

1. **TDD Red**：先建 `CourseStudentsFilterTest.php` 11 個 test method 骨架（含 Background fixture），全部紅燈
2. **TDD Green 1**：實作 `Api::get_students_callback` 三條驗證 → 6 個驗證類 test 綠
3. **TDD Green 2**：實作 `Query::__construct` progress SQL + `get_pagination` 同步 → 進度篩選類 test 綠
4. **TDD Green 3**：實作 `ExportCSV` + `Api::get_students_export_with_id_callback` 擴充 → 匯出類 test 綠（如有）
5. **TDD Refactor**：抽 `Query::build_progress_clause()` SQL fragment、清除 phpcs warnings
6. **前端**：用 `react-master` 派 Filter 元件 + utils + StudentTable 改造（步驟 8-10）
7. **i18n**：補 `manual.json` + 跑 `pnpm run i18n:build`，commit 4 個 languages 檔
8. **E2E**：寫 Playwright spec
9. **驗收**：跑全套品質檢查 + 手動 smoke test

每 phase 完成跑對應測試確認綠燈再進下一 phase。
