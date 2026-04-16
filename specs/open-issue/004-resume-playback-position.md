# 章節影片續播（Resume Playback Position）

## Idea

目前學員離開章節頁後再次進入，影片一律從頭開始播放，體驗不佳。希望在「課程頁 / 章節頁」加入「上次觀看到 MM:SS」的續播能力：

- 學員播放過的影片，系統記錄目前播放秒數（`last_position_seconds`）。
- 學員下次進入該章節時，播放器自動跳到上次的秒數繼續播放。
- 「我的帳戶 → 我的課程」清單、課程詳情頁的 CTA 按鈕需要支援「繼續觀看 第 X 章 MM:SS」。
- 章節已自動完成（95% / ended）後，續播秒數仍保留，CTA 改為「重看 第 X 章 MM:SS」，仍可自動續播。

---

## 澄清結果（Q1–Q15 一頁摘要）

| # | 項目 | 最終決策 |
|---|------|---------|
| 1 | 記錄秒數的時機 | B — 前端每 10 秒 throttle 呼叫一次 API（含 pause / beforeunload flush） |
| 2 | 是否區分裝置 / 瀏覽器 | A — 不區分，single source of truth per (user, chapter) |
| 3 | 續播誤差容忍 | B — 儲存時四捨五入到整數秒；前端 seek 容忍 ±2 秒 |
| 4 | 0 秒 / <5 秒的寫入策略 | C — <5 秒不寫，≥5 秒才開始寫（避免誤觸即離噪音） |
| 5 | 寫入作用範圍 | B — 僅 Bunny HLS + YouTube / Vimeo（VidStack 可追蹤進度的類型）；`code` embed 與 `none` 不記錄 |
| 6 | 記錄在 currentTime 達到 95% 後 | B — 繼續記錄（未來要支援「重看」從離開點續播） |
| 7 | 跨章節瀏覽時的續播依據 | A — 以 chapter_progress.last_position_seconds 為準，course 層的 last_chapter_id 僅作為「繼續觀看」指標 |
| 8 | CTA「繼續觀看」的文案 | B — 未開始：`開始上課`；進行中：`繼續觀看 第 X 章 MM:SS`；已完成：`重看 第 X 章 MM:SS` |
| 9 | 退課 / 被移除課程後資料處理 | A — 隨 `power_course_after_remove_student_from_course` 觸發時刪除該 (user, course) 所有 chapter_progress 與 last_visit_info |
| 10 | 是否顯示總進度（對應秒數條） | B — 本輪只做離開點記錄；進度條 UI 維持現狀，後續需求再追加 |
| 11 | 並發寫入衝突策略 | B — last_write_wins（後寫者覆蓋）；搭配 server 側 updated_at 便於未來切換至樂觀鎖 |
| 12 | 達到 95%/ended 後秒數的顯示與行為 | B — 保留 last_position_seconds；CTA 改為「重看 第 X 章 MM:SS」；仍自動續播到該秒數 |
| 13 | 資料表設計 | C — 新增 `pc_chapter_progress` 表：`(user_id, chapter_id)` 複合 unique index + upsert；course 層維護 `last_chapter_id` 指標（沿用 `pc_avl_coursemeta.last_visit_info`） |
| 14 | 寫入 API 的權限 | B — 需登入 + `CourseUtils::is_avl($course_id, $user_id)` 通過；否則 403 |
| 15 | 測試策略 | B — Playwright E2E 核心 3 條路徑（首次觀看→離開→續播 / 95% 完成後重看 / 退課清除） |

---

## 規格相關檔案

| 類型 | 路徑 |
|------|------|
| 澄清紀錄 | `specs/open-issue/clarify/2026-04-15-<HHMM>.md` |
| Feature（新） | `specs/features/progress/紀錄章節續播秒數.feature` |
| Feature（新） | `specs/features/progress/續播至上次觀看秒數.feature` |
| Feature（新） | `specs/features/course/繼續觀看課程CTA.feature` |
| Feature（更新） | `specs/features/progress/取得課程進度.feature` — response 加 `last_position_seconds` |
| Feature（更新） | `specs/features/student/移除學員課程權限.feature` — 加退課清除 chapter_progress 規則 |
| API（更新） | `specs/api/api.yml` — POST /chapters/{id}/progress 加 body `last_position_seconds`；GET /chapters/{id}/progress response 加 `last_position_seconds` |
| Entity（更新） | `specs/entity/erm.dbml` — 新增 `pc_chapter_progress` table，沿用 `pc_avl_coursemeta.last_visit_info` 作為 course 層指標 |

---

## 涉及的現有程式碼

| 檔案 | 說明 |
|------|------|
| `inc/classes/Resources/Chapter/Core/LifeCycle.php` | 既有 `save_last_visit_info()` 方法，續播秒數寫入可在同一個 hook 樹上擴充 |
| `inc/classes/Resources/Chapter/Core/Api.php` | 既有 toggle-finish API（line 330「已標示為完成」），新增 `post_chapters_with_id_progress_callback` 接收續播秒數 |
| `inc/classes/Utils/Course.php` | `get_course_progress()` 負責課程層指標；需同時回傳 `last_position_seconds`、`last_chapter_id` |
| `js/src/components/Player/...`（VidStack Player 組件） | 新增 `timeupdate` throttle 10s flush、`pause` / `beforeunload` flush、初始化時 seek 到 `last_position_seconds` |
| `js/src/pages/myaccount/courses/...` | 我的帳戶課程列表 CTA 顯示「繼續觀看 第 X 章 MM:SS / 重看 第 X 章 MM:SS」 |

---

## 技術依賴

- VidStack Player（已在用）— `useMediaState('currentTime')` + `onTimeUpdate`
- YouTube / Vimeo 透過 VidStack provider，postMessage API 由 VidStack 封裝
- TanStack Query v4（前端 POST /progress mutation）
- 不引入新 library

---

## 驗收標準（Acceptance Criteria）

1. 學員觀看 Bunny HLS 影片 30 秒後重新整理，影片自動從第 30 秒（±2 秒）續播。
2. 學員在章節 A 看到第 120 秒離開進入章節 B，返回章節 A 時從第 120 秒續播；章節 B 單獨維護自己的 last_position_seconds。
3. 章節 95% 自動完成後，保留 last_position_seconds；CTA 顯示「重看 第 1 章 09:30」，點擊仍自動續播到該秒數。
4. 管理員從課程移除學員，該學員所有章節 progress 與 course last_visit_info 立即刪除。
5. 未登入 / 未擁有該課程授權的用戶呼叫 POST /chapters/{id}/progress 一律回 403。
6. Playwright E2E 3 條核心路徑全綠。

---

## 風險與待決議

- Q11 last_write_wins：多裝置（手機 + 桌機）同時觀看時，後寫者覆蓋；是否需要加「使用者意圖」信號（例如僅在 currentTime 前進時覆蓋）留待實作階段評估。
- Q5 YouTube / Vimeo：VidStack 透過 postMessage 取得 currentTime，不同 provider 的更新頻率與精度差異需要 Player 層 adapter 驗證。
- Q13 索引：`(user_id, chapter_id)` 複合 unique index 在 wp_插件啟動時建表，需要升級腳本相容既有站點。
