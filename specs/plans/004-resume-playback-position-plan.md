# 實作計劃：章節影片續播（Resume Playback Position, Issue #146）

> 規格主來源：`specs/open-issue/004-resume-playback-position.md`
> Clarify 紀錄：`specs/open-issue/clarify/2026-04-15-1030.md`
> 交接對象：`@zenbu-powers:tdd-coordinator`（後續以 TDD 流程實作）

## 概述

為學員提供章節影片續播體驗：每章節獨立記錄 `last_position_seconds`，課程層維護 `last_chapter_id` 指標，使「我的帳戶 / 課程詳情頁」的 CTA 能顯示「繼續觀看 / 重看 第 X 章 MM:SS」。採新增關聯表 `pc_chapter_progress` + upsert + last_write_wins 策略。

## 範圍模式：HOLD SCOPE

需求與決策均已透過 Q1–Q15 確定；本計畫僅專注防彈架構與邊界情況，預估影響 **15 個檔案（PHP 7 個 / TS 6 個 / Feature 新建 3 個 / E2E 新建 3 個）**。不擴張範圍，不回縮至更小 MVP。

## 需求重述

1. 前端 VidStack Player 以 10s throttle + pause/beforeunload flush 將 `last_position_seconds` POST 至後端。
2. 後端以 `pc_chapter_progress` 新表儲存 `(user_id, chapter_id)` unique 紀錄，同步更新 `pc_avl_coursemeta.last_visit_info.chapter_id` 作為 course 層指標。
3. 前端重新進入章節時讀取 `last_position_seconds` 並於 `canPlay` 事件 seek（±2s）。
4. 我的帳戶 / 課程詳情頁 CTA 依「未開始 / 進行中 / 已完成」顯示「開始上課 / 繼續觀看 第 X 章 MM:SS / 重看 第 X 章 MM:SS」。
5. 退課 / 站長重設 → 刪除該 (user, course) 全部 chapter_progress；課程到期 → 保留不刪。
6. 權限：需登入 + `CourseUtils::is_avl` 通過，否則 403。
7. 僅 `bunny / youtube / vimeo` 記錄；`code / none` 不寫入。
8. <5 秒不寫；秒數 server 端四捨五入為整數。
9. 章節已 95% / ended 後仍持續記錄（重看用）。

## 已知風險（Clarifier 提醒 + 新發現）

| # | 風險 | 緩解措施 |
|---|------|----------|
| R1 | Q11 多裝置時間戳同步：`wp_date()` 與 DB `NOW()` 時區差異可能造成 `updated_at` 錯亂 | 一律在 SQL 內使用 `NOW()`，PHP 層不再傳入時間戳；寫單元測試驗證 `updated_at` 為 server 時間 |
| R2 | Q13 索引升級：既有站點啟用時需跑 DDL migration，`pc_chapter_progress` 為新表不存在向下改表風險，但需確保 `AbstractTable::create_chapter_progress_table()` 在 `plugin.php` 與 `Compatibility` 兩處同步啟動 | 與既有 4 張表相同慣例；寫 Integration Test 驗證啟動後表存在、index 正確 |
| R3 | Q5 YouTube/Vimeo 精度：VidStack 對 YouTube/Vimeo postMessage 的 `currentTime` 更新頻率低於 HLS（可能 1–2s 一次） | 前端 throttle 以「timeupdate 事件」為訊號源，不做等距 timer；fallback 為 pause/beforeunload flush 確保最終一致 |
| R4 | Q4 server 靜默略過 <5s：API 回 200 但不寫 → 客戶端可能誤判為成功 | Response body 包含 `written: bool` 與 `last_position_seconds`（回讀 DB 值），讓前端可依此修正本地狀態；文件於 `api.yml` 明確標示 |
| R5 | sendBeacon 限制（`beforeunload`）：只能 POST + 無自訂 header | 使用 query-string 傳 `_wpnonce`（沿用 WP 內建 cookie 驗證 + `rest_cookie_check_errors` 機制）；確保 POST body 採 `application/x-www-form-urlencoded`（已於 api.yml 註記） |
| R6（新發現） | 現有 `save_last_visit_info` 掛在 `CHAPTER_ENTERED_ACTION`（進入章節時觸發，用 `wp_date()` 寫 `last_visit_at`），與新 POST `/progress` 並存時有兩條寫入 `last_visit_info.chapter_id` 的路徑 | 以 POST `/progress` 為主要寫入點；保留 `save_last_visit_info`（僅更新 chapter_id，新增「first-visit」語意），避免 Player 未 mount 時不到章節指標。測試需覆蓋「無影片章節進入後仍更新 last_visit_info」情境 |
| R7（新發現） | `pc_chapter_progress.course_id` 為 denormalized，若章節之後被轉換歸屬於另一個 course，可能造成退課清除遺漏 | 寫入時於 server 端由 `PostUtils::get_top_post_id($chapter_id)` 動態計算 course_id，而非信任前端 body；前端仍需帶 `course_id` 做 `is_avl` 權限檢查，但實際寫入欄位以 server 計算為準 |
| R8（新發現） | Playwright 無法真的等影片播 30 秒（CI 時長太長） | E2E 以「直接呼叫 POST /progress 模擬 Player flush」的策略取代實播；Player 本身的 throttle 邏輯改以前端 unit（手動驗證）+ Integration test（PHPUnit 覆蓋 API）雙管齊下 |

## 架構變更

### 新增檔案

| 路徑 | 說明 |
|------|------|
| `inc/classes/Resources/ChapterProgress/Core/Api.php` | REST API `/chapters/{id}/progress` GET/POST endpoint（ApiBase 派生） |
| `inc/classes/Resources/ChapterProgress/Core/Loader.php` | Resource Loader，掛入 `Resources\Loader` |
| `inc/classes/Resources/ChapterProgress/Service/Repository.php` | `$wpdb` 層 CRUD（upsert / find / delete_by_course_user） |
| `inc/classes/Resources/ChapterProgress/Service/Service.php` | 業務邏輯（權限檢查、video_type 白名單、四捨五入、<5s 略過、updated_at 邏輯、last_visit_info 同步） |
| `inc/classes/Resources/ChapterProgress/Model/ChapterProgress.php` | 資料模型（id / user_id / chapter_id / course_id / last_position_seconds / updated_at / created_at） |
| `js/src/App2/hooks/useChapterProgress.ts` | 前端 hook：初始 GET + throttle POST + pause/beforeunload flush（sendBeacon） |
| `tests/Integration/ChapterProgress/ChapterProgressApiTest.php` | API 端到端測試（權限 / upsert / <5s / 四捨五入 / code/none 不寫 / finished 後仍寫） |
| `tests/Integration/ChapterProgress/ChapterProgressRepositoryTest.php` | Repository 單元測試（upsert / delete_by_course_user） |
| `tests/Integration/ChapterProgress/RemoveStudentCleanupTest.php` | 退課清除測試（hook 觸發後 chapter_progress 清空） |
| `tests/e2e/02-frontend/015-resume-playback-basic.spec.ts` | E2E：首次觀看→離開→續播 |
| `tests/e2e/02-frontend/016-resume-playback-continue-cta.spec.ts` | E2E：我的帳戶 CTA 「繼續觀看 第 X 章 MM:SS」 |
| `tests/e2e/02-frontend/017-resume-playback-rewatch.spec.ts` | E2E：95% 完成後 CTA「重看」仍續播 |

### 修改檔案

| 路徑 | 具體改動 |
|------|----------|
| `plugin.php` | line 46-49：新增 `const CHAPTER_PROGRESS_TABLE_NAME = 'pc_chapter_progress';`；line 96-99：呼叫 `AbstractTable::create_chapter_progress_table()` |
| `inc/classes/AbstractTable.php` | 新增 `create_chapter_progress_table()`：id / user_id / chapter_id / course_id / last_position_seconds / updated_at / created_at + `UNIQUE KEY (user_id, chapter_id)` + `KEY (course_id)` + `KEY updated_at` |
| `inc/classes/Compatibility/Compatibility.php` | line 65 附近加入 `AbstractTable::create_chapter_progress_table()` 呼叫，相容既有站點升級 |
| `inc/classes/Resources/Loader.php` | 註冊 `ChapterProgress\Core\Loader` |
| `inc/classes/Resources/Course/LifeCycle.php` | `save_meta_remove_student`（line 405）新增呼叫 `ChapterProgress\Service\Repository::delete_by_course_user($user_id, $course_id)`（對應 Q9 退課清除） |
| `inc/classes/Utils/Course.php` | `get_course_progress()` 回傳結構新增 `last_position_seconds`、`last_chapter_id`（feature 「取得課程進度」要求） |
| `inc/classes/Resources/Chapter/Core/Api.php` | `post_chapters_with_id_callback`（line 201）保持不變；`toggle-finish-chapters`（line 261）回應內新增 `last_position_seconds`，供前端 CTA 刷新用 |
| `js/src/App2/Player.tsx` | 呼叫新 `useChapterProgress` hook：取初始 position → onCanPlay seek → onTimeUpdate throttle → onPause flush → useEffect beforeunload/visibilitychange flush |
| `js/src/pages/myaccount/courses/...`（CTA 元件，確切路徑 TBD by coder） | 依 `last_visit_info` + `chapter_progress[last_chapter_id]` 決定 CTA 文案 |
| `specs/features/progress/取得課程進度.feature` | 已由 clarifier 更新 |
| `specs/features/student/移除學員課程權限.feature` | 已由 clarifier 更新 |

## 資料流分析

### POST /chapters/{id}/progress（寫入）

```
[Client throttle 10s / pause / beforeunload]
       │
       ▼
[sendBeacon 或 fetch POST]  ──▶  [REST auth: is_user_logged_in + is_avl]
       │                                │
       ▼                                ▼
   [nonce 失敗]                    [權限失敗]
   → 403                           → 403
       │                                │
       ▼                                ▼
[Service::upsert]
       │
       ├─ chapter.video_type ∈ {code, none}? → 400
       ├─ last_position_seconds < 5?         → 200 (written=false)
       ├─ round() to int
       ├─ server 計算 course_id (PostUtils::get_top_post_id)
       ├─ $wpdb INSERT ... ON DUPLICATE KEY UPDATE (NOW())
       └─ 同步更新 pc_avl_coursemeta.last_visit_info (chapter_id / last_visit_at)
       │
       ▼
[Response 200]
  { code:"200", data:{ chapter_id, course_id, last_position_seconds, updated_at, written } }
```

Shadow paths：
- nil path：`chapter_id` 不存在 → 404
- empty path：`last_position_seconds` 缺失 → 400
- error path：`$wpdb->query` 失敗 → 500 + WC logger

### GET /chapters/{id}/progress（讀取）

```
[Client mount Player]
       │
       ▼
[REST auth: is_user_logged_in + is_avl]
       │
       ▼
[Service::find]
       │
       ├─ 無紀錄?    → last_position_seconds = 0, updated_at = null
       └─ 有紀錄    → 回傳整數秒
       │
       ▼
[Response 200] → 前端 canPlay → seek ±2s
```

### 退課清除（Q9）

```
Admin remove student
       │
       ▼
Course\LifeCycle::save_meta_remove_student (hook AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION)
       │
       ├─ delete_user_meta(avl_course_ids)
       ├─ AVLCourseMeta::delete(course_id, user_id)  ← last_visit_info 一併刪
       └─ NEW: ChapterProgress\Repository::delete_by_course_user($user_id, $course_id)
```

## 錯誤處理登記表

| 路徑 | 失敗原因 | 錯誤類型 | 處理 | 使用者可見 |
|------|----------|----------|------|------------|
| POST /progress | 未登入 | 403 | `WP_Error` | 靜默（背景請求） |
| POST /progress | 非該課程授權 | 403 | `WP_Error` | 靜默 |
| POST /progress | 課程到期 | 403 | `WP_Error`（`is_avl` 會檢查 expire） | 靜默 |
| POST /progress | `last_position_seconds` 缺漏 | 400 | `WP_Error` | 靜默 |
| POST /progress | `chapter_video_type` 為 code/none | 400 | `WP_Error` | 靜默（前端原本不該呼叫） |
| POST /progress | < 5 秒 | 200 + `written:false` | 無例外 | 文件描述 |
| POST /progress | `$wpdb->query` 失敗 | 500 | `WC::logger()` + `WP_Error` | 靜默 |
| GET /progress | 同上權限檢查 | 403 | `WP_Error` | 無 Player |
| beforeunload sendBeacon | 瀏覽器不支援 | N/A | fallback 為 `fetch({keepalive:true})` | 無 |
| seek 超出影片總長 | currentTime > duration | 容錯 | Player 自動 clamp 至 ended；後端不改 | 無 |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理 | 有測試 | 使用者可見 | 恢復路徑 |
|------------|----------|--------|--------|------------|----------|
| Repository::upsert 撞 unique index 同秒併發 | 極端 race condition | 是（ON DUPLICATE KEY UPDATE） | 是（並發 test） | 無 | 後寫者勝，updated_at 覆寫 |
| 前端 throttle timer 未在 unmount 清除 | 記憶體洩漏 | 需實作 useEffect cleanup | 是（review） | 無 | 離開頁面後 GC |
| YouTube provider 無 `currentTime` | postMessage 未返回值 | fallback 0，不寫入 | 是 | 無 | 下次 timeupdate 正常 |
| Player 未 mount 即 beforeunload | currentTime < 5 | 靜默略過 | 是 | 無 | 下次進入從頭 |
| is_avl 快取延遲 | 新授權後第一次 POST 仍 403 | 由 WP 原生 cache 行為 | 否（已知限制） | 靜默 | 下次 POST 成功 |

## 實作步驟（依依賴關係排序）

### 第一階段：資料層（後端，無依賴）

1. **定義 Plugin 常數**（檔案：`plugin.php` line 46-49）
   - 行動：新增 `const CHAPTER_PROGRESS_TABLE_NAME = 'pc_chapter_progress';`
   - 依賴：無
   - 風險：低

2. **新增 AbstractTable::create_chapter_progress_table**（檔案：`inc/classes/AbstractTable.php`）
   - 行動：新增 public static function，沿用既有 pattern（`dbDelta` + `WP::is_table_exists`）；DDL 內含 `UNIQUE KEY uq_chapter_progress_user_chapter (user_id, chapter_id)` + `KEY course_id` + `KEY updated_at`
   - 輸入：無；輸出：建立 `{prefix}pc_chapter_progress` 表
   - 驗證：Integration test `ChapterProgressRepositoryTest::test_table_exists_with_expected_indexes`
   - 依賴：步驟 1
   - 風險：低

3. **掛載到 plugin activation + Compatibility**（`plugin.php` line 96-99 / `Compatibility.php` line 65）
   - 行動：兩處各新增一行 `AbstractTable::create_chapter_progress_table();`
   - 依賴：步驟 2
   - 風險：低

4. **建立 Model**（`inc/classes/Resources/ChapterProgress/Model/ChapterProgress.php`）
   - 行動：`final class` + `declare(strict_types=1)`，屬性：`int $id`, `int $user_id`, `int $chapter_id`, `int $course_id`, `int $last_position_seconds`, `?string $updated_at`, `?string $created_at`
   - 依賴：無
   - 風險：低

### 第二階段：服務層（後端，依賴 Phase 1）

5. **Repository**（`inc/classes/Resources/ChapterProgress/Service/Repository.php`）
   - 方法：
     - `find(int $user_id, int $chapter_id): ?ChapterProgress`
     - `upsert(int $user_id, int $chapter_id, int $course_id, int $last_position_seconds): void`（使用 `$wpdb->query` + `$wpdb->prepare`，SQL `INSERT ... ON DUPLICATE KEY UPDATE last_position_seconds=VALUES(last_position_seconds), updated_at=NOW()`）
     - `delete_by_course_user(int $user_id, int $course_id): int`（回傳刪除列數）
   - 測試：`ChapterProgressRepositoryTest`
   - 依賴：步驟 4
   - 風險：中（SQL 注入風險 → 必須 `prepare`）

6. **Service（業務層）**（`inc/classes/Resources/ChapterProgress/Service/Service.php`）
   - 方法：
     - `get_progress(int $user_id, int $chapter_id): array` — 回 `{ chapter_id, course_id, last_position_seconds, updated_at }`，無紀錄時 `last_position_seconds=0`
     - `upsert_progress(int $user_id, int $chapter_id, float $raw_seconds): array` — 內含：
       - 取 `video_type`，白名單檢查 → 拋 `InvalidArgumentException`
       - `< MIN_WRITE_SECONDS (5)` → 回 `written:false`
       - `round()` 後呼叫 Repository::upsert
       - 同步更新 `pc_avl_coursemeta.last_visit_info`（沿用 `AVLCourseMeta::update`）
       - 回 `{ written:true, last_position_seconds, updated_at }`
     - `delete_all_for_user_in_course(int $user_id, int $course_id): void`
   - 常數：`MIN_WRITE_SECONDS = 5`、`ALLOWED_VIDEO_TYPES = ['bunny','youtube','vimeo']`
   - 依賴：步驟 5
   - 風險：中

### 第三階段：API 層（後端，依賴 Phase 2）

7. **新增 REST API**（`inc/classes/Resources/ChapterProgress/Core/Api.php`）
   - 繼承 `ApiBase` + `SingletonTrait`
   - `$namespace = 'power-course'`
   - `$apis`:
     - `['endpoint' => 'chapters/(?P<id>\d+)/progress', 'method' => 'get', 'permission_callback' => 'is_user_logged_in']`
     - `['endpoint' => 'chapters/(?P<id>\d+)/progress', 'method' => 'post', 'permission_callback' => 'is_user_logged_in']`
   - `get_chapters_with_id_progress_callback`：取 chapter → `PostUtils::get_top_post_id` → `CourseUtils::is_avl` → `Service::get_progress`
   - `post_chapters_with_id_progress_callback`：同權限檢查 → 從 body 取 `last_position_seconds`（float）+ `course_id`（僅用於快速 is_avl；server 仍會再算一次）→ `Service::upsert_progress`
   - 錯誤時回 `WP_Error( 'forbidden', '...', [ 'status' => 403 ] )`
   - 依賴：步驟 6
   - 風險：中（auth 是關鍵）

8. **Loader 註冊**（`inc/classes/Resources/ChapterProgress/Core/Loader.php` + `Resources/Loader.php`）
   - 新增 Loader instance() 呼叫 Api::instance()
   - 依賴：步驟 7
   - 風險：低

### 第四階段：hook 整合（後端，依賴 Phase 3）

9. **退課清除**（`inc/classes/Resources/Course/LifeCycle.php::save_meta_remove_student`）
   - 行動：於 `AVLCourseMeta::delete()` 呼叫之後，新增 `ChapterProgressService::delete_all_for_user_in_course($user_id, $course_id);`
   - 測試：`RemoveStudentCleanupTest`
   - 依賴：步驟 6
   - 風險：低

10. **get_course_progress 擴充**（`inc/classes/Utils/Course.php`）
    - 行動：response 新增 `last_position_seconds`（由 `last_chapter_id` + Service 查詢）、`last_chapter_id`（從 `last_visit_info.chapter_id`）
    - 依賴：步驟 6
    - 風險：低

### 第五階段：前端 Player（依賴 Phase 3 API）

11. **建立 `useChapterProgress` hook**（`js/src/App2/hooks/useChapterProgress.ts`）
    - signature：`useChapterProgress({ chapterId, courseId, videoType, isFinished })`
    - 職責：
      - 初始 GET → 回傳 `initialPosition`（0 時不 seek）
      - 暴露 `handleTimeUpdate(currentTime)` → throttle 10s flush
      - 暴露 `handlePause(currentTime)` / `handleEnded(currentTime)` → 立即 flush
      - 在 `useEffect` 中掛 `beforeunload` 與 `visibilitychange`，使用 `navigator.sendBeacon` POST
      - cleanup：移除 listener、cancel pending timer
    - 內部 helper：
      - `shouldTrack(videoType)` → `['bunny','youtube','vimeo'].includes(videoType)`
      - `flushPosition(seconds)` → 使用 TanStack Query mutation（前台 App2 不使用 Refine.dev，直接 fetch 可接受；與既有 `finishChapter.ts` 的 jQuery.ajax 一致，但新實作建議以 `fetch` + `keepalive`）
    - 依賴：步驟 7
    - 風險：中（throttle 與 cleanup 細節）

12. **Player.tsx 整合**（`js/src/App2/Player.tsx`）
    - 行動：
      - import `useChapterProgress`
      - `video_type` 由外部 props 傳入（`TPlayerProps` 新增 `video_type`, `initial_position_seconds` 可選但建議由 hook 自取）
      - 於 `<MediaPlayer>` 加 `onCanPlay`：若 `initialPosition > 0` 呼叫 `remote.seek(initialPosition)`（使用 `useMediaRemote`）
      - `onTimeUpdate` 串 `handleTimeUpdate(detail.currentTime)`（與既有 auto-finish 邏輯並存）
      - `onPause` 串 `handlePause`
      - `onEnded` 串 `handleEnded`
    - 依賴：步驟 11
    - 風險：中

13. **PHP Player template 傳入 video_type**（若 `TPlayerProps` 需要 `video_type` 則要 `inc/templates/` 對應模板的 dataset 同步；coder 確認路徑後更新）
    - 依賴：步驟 12
    - 風險：低

### 第六階段：前端 CTA（依賴 Phase 4）

14. **我的帳戶 / 課程詳情頁 CTA**（coder 於實作前先 grep 找出 `我的帳戶` / `繼續觀看` 既有元件，以現有檔案為準修改，不新建元件）
    - 行動：依 feature `繼續觀看課程CTA.feature` 決策表顯示文案；`MM:SS` 以 `Math.floor(seconds/60)` + `padStart(2,'0')` 格式化
    - 依賴：步驟 10（API response 提供資料）
    - 風險：低

### 第七階段：測試（依賴全部）

15. **PHPUnit Integration Tests**
    - `ChapterProgressRepositoryTest`：upsert 新建 / upsert 更新 / delete_by_course_user / unique index
    - `ChapterProgressApiTest`：所有 Gherkin rule 對照（權限、<5s、四捨五入、code/none 拒絕、finished 後仍寫、last_visit_info 同步）
    - `RemoveStudentCleanupTest`：觸發 `AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION` 後進度被刪
    - 依賴：Phase 1–4 完成
    - 風險：低

16. **Playwright E2E**
    - `015-resume-playback-basic.spec.ts`：fixture 建立已觀看 120s 的章節 → 進入 → 斷言 Player currentTime ≈ 120（±2）
    - `016-resume-playback-continue-cta.spec.ts`：fixture 設定 last_visit_info + chapter_progress → 進我的帳戶 → 斷言 CTA 文字「繼續觀看 第二章 03:45」
    - `017-resume-playback-rewatch.spec.ts`：fixture 設定 finished_at + last_position_seconds=570 → 進我的帳戶 → 斷言「重看 第一章 09:30」，點擊後 seek 到 570s
    - 共用 helper：`seedChapterProgress(page, { userId, chapterId, courseId, seconds, finished? })`，透過 wp-cli 或直接 $wpdb seeder
    - 依賴：Phase 5–6 完成
    - 風險：中（時序等待）

## 測試策略

- **紅燈階段（test-creator）**：先由 `@zenbu-powers:tdd-coordinator` 派 `test-creator` 寫步驟 15、16 的測試骨架（PHPUnit 測試類別先標 `markTestIncomplete`、Playwright spec 先寫 expect → 確保 fail）
- **綠燈階段**：
  - PHP 後端派 `@zenbu-powers:wordpress-master`（Phase 1–4、9–10）
  - 前端派 `@zenbu-powers:react-master`（Phase 5–6、11–14）
  - 兩者可並行
- **重構**：由對應 reviewer 執行
- **執行指令**：
  - `composer run test` → PHPUnit
  - `composer run phpstan` → level 9 驗證
  - `pnpm run lint:php`、`pnpm run lint:ts`
  - `pnpm run test:e2e:frontend` → 三條路徑

## 依賴圖（並行機會）

```
Phase 1 (plugin.php + AbstractTable + Compatibility + Model)
         │
         ▼
Phase 2 (Repository + Service)
         │
         ├──────────────────────────────┐
         ▼                              ▼
Phase 3 (REST API + Loader)      Phase 4 hook 整合
                                  (remove_student / get_course_progress)
         │                              │
         ├──────────────┬───────────────┘
         ▼              ▼
Phase 5 前端 Player   Phase 6 CTA
         │              │
         └──────┬───────┘
                ▼
Phase 7 測試（紅 → 綠 → 重構）
```

**平行機會：**
- Phase 3 與 Phase 4 可並行（均只依賴 Phase 2）
- Phase 5（Player）與 Phase 6（CTA）可並行（均依賴 Phase 3 + 4）
- Phase 7 的 PHPUnit 測試可與 Phase 5/6 前端開發並行（測試先行）

## 限制條件（本計畫不做）

- 進度條 UI（章節影片的秒數 bar）— Q10 延後
- 樂觀鎖衝突處理 — Q11 採 last_write_wins，只保留 `updated_at` 欄位供未來升級
- 多裝置「最遠點優先」策略 — 留待實作期評估（R1）
- `code / none` 章節的續播 — Q5 不支援
- 既有用戶秒數補算 / 歷史 log 推導 — Q7 從未播放視為 0
- 站長後台顯示秒數 — Q10 只顯示「上次觀看章節 + 最後活動時間」，不顯示秒數
- 前端 unit test — 專案無既有 unit test 框架（依 E2E + TS strict）

## 成功標準

- [ ] `pc_chapter_progress` 表建立於啟用時，既有站點升級亦建立
- [ ] `POST /chapters/{id}/progress` 在 Gherkin 9 個 Example 全綠（PHPUnit）
- [ ] `GET /chapters/{id}/progress` 在 Gherkin 6 個 Example 全綠
- [ ] 退課觸發後 `pc_chapter_progress` 與 `last_visit_info` 均清除
- [ ] VidStack Player 在 `bunny/youtube/vimeo` 皆能 seek 至 last_position_seconds（±2s）
- [ ] 我的帳戶 CTA 三種狀態（開始上課 / 繼續觀看 / 重看）文案正確
- [ ] 3 條 Playwright E2E 全綠
- [ ] `pnpm run lint:php` + `pnpm run lint:ts` + `composer run phpstan` 零錯誤

## 預估複雜度：中

— 共 15 檔案影響，大部分為既有 pattern 延伸（AbstractTable / ApiBase / Service-Repository）。唯一較新的是前端 `useChapterProgress` hook 與 sendBeacon 整合，但風險已於 R5 緩解。
