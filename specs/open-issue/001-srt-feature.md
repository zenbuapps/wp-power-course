# Bunny Stream API 作為影片源時可以上傳 SubRip (SRT) files and Video Text Track (WebVTT) 字幕檔

## Idea

我希望用戶在課程後台 /wp-admin/admin.php?page=power-course

使用 VideoInput， 選擇 Bunny Stream API 作為影片源時
可以多一個上傳 SubRip (SRT) files and Video Text Track (WebVTT) 字幕檔的選項
讓用戶從電腦上傳字幕檔

---

## 澄清結果

| # | 項目 | 決定 |
|---|------|------|
| 1 | 字幕儲存位置 | 上傳到 WordPress 媒體庫，前台播放時由 WordPress 提供字幕檔 |
| 2 | 多語言支援 | 支援多語言，用戶可為同一影片上傳多份不同語言的字幕 |
| 3 | 語言指定方式 | 下拉選單選擇語言（預設常見語言列表，如 zh-TW、en、ja 等） |
| 4 | 字幕管理 | 支援刪除與替換（列出已上傳的字幕，可個別刪除或重新上傳覆蓋） |
| 5 | 檔案格式 | 兩種都接受上傳（SRT、WebVTT），後端自動將 SRT 轉為 WebVTT 儲存，前台統一用 WebVTT |
| 6 | 前台字幕切換 | 使用 Vidstack 內建的字幕切換 UI，自動列出所有可用語言 |
| 7 | 預設顯示行為 | 預設關閉，學員需手動從播放器 UI 開啟 |
| 8 | 上傳預覽 | 不需要，用戶上傳後直接到前台播放器確認效果 |

---

## 規格相關檔案

| 類型 | 路徑 |
|------|------|
| 澄清紀錄 | `specs/open-issue/clarify/2026-03-13-2012.md` |
| Activity（更新） | `specs/activities/課程上架流程.activity` — 新增 DECISION:2b |
| Feature | `specs/features/chapter/上傳章節字幕.feature` |
| Feature | `specs/features/chapter/刪除章節字幕.feature` |
| API（更新） | `specs/api/api.yml` — 新增 Subtitles tag + 3 endpoints + SubtitleTrack schema |
| Entity（更新） | `specs/entity/erm.dbml` — chapters table 新增 chapter_subtitles JSON 欄位 |

---

## 涉及的現有程式碼

| 檔案 | 說明 |
|------|------|
| `js/src/components/formItem/VideoInput/index.tsx` | VideoInput 主組件（影片源選擇器） |
| `js/src/components/formItem/VideoInput/Bunny.tsx` | Bunny 影片選擇組件（需新增字幕上傳 UI） |
| `inc/classes/Api/Upload.php` | 檔案上傳 API（需擴展支援字幕檔） |
| `inc/templates/components/video/vidstack/index.php` | 前台 Vidstack 播放器模板（需加入 `<track>` 標籤） |
| `inc/classes/Resources/Chapter/Core/Api.php` | 章節 API（字幕元數據隨章節儲存） |
