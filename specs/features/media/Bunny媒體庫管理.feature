@ignore @frontend @ui-only
Feature: Bunny 媒體庫管理

  管理員在 WP Admin 側邊欄 "Bunny 媒體庫" 可瀏覽、上傳、搜尋 Bunny Stream 上的影片，
  並在課程 / 章節編輯時選擇既有影片作為影片來源。

  **架構說明（來自 js/src/pages/admin/BunnyMediaLibraryPage/index.tsx）:**
  - 頁面本身只是 `<MediaLibrary>` 元件的 thin wrapper，來源為 `antd-toolkit/refine`
  - Bunny Stream 的實際 API（列表、上傳、刪除、快照）由 **Powerhouse / Bunny Stream CDN SDK** 提供，不在 power-course REST namespace 內
  - Power Course 前端與後端的互動僅限於：
    1. 讀取 `bunny_cdn_hostname` 設定（由 `PowerhouseSettings::instance()->bunny_cdn_hostname` 提供）
    2. 影片播放時以 `https://{bunny_cdn_hostname}/{video_id}/playlist.m3u8` 組成 HLS src

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And PowerhouseSettings 中 bunny_cdn_hostname 已設定為 "vz-abc123.b-cdn.net"
    And Bunny Stream 帳號中有以下影片：
      | guid                                   | title          | status | length |
      | abc-111-111-111                        | 課程介紹       | 4      | 120    |
      | def-222-222-222                        | 第一章影片     | 4      | 600    |
      | ghi-333-333-333                        | 處理中影片     | 2      | null   |

  # ========== 進入頁面 ==========

  Rule: 管理員可從左側選單進入 Bunny 媒體庫頁面

    Example: 點擊選單項目
      Given 管理員 "Admin" 已登入 WP Admin
      When 管理員點擊側邊選單 "Bunny 媒體庫"
      Then 頁面路由切換到 /bunny-media-library
      And 頁面渲染 <MediaLibrary> 元件
      And limit prop 為 undefined（無選擇上限，允許多選）

  # ========== 瀏覽影片列表 ==========

  Rule: 管理員可以看到所有已上傳到 Bunny 的影片（含處理中）

    Example: 列表顯示三筆影片
      When 管理員進入 Bunny 媒體庫頁面
      Then 列表渲染 3 筆影片：
        | title       | status   |
        | 課程介紹    | Finished |
        | 第一章影片  | Finished |
        | 處理中影片  | Processing |
      And 每張卡片顯示 thumbnail、標題、長度、狀態
      And status 不為 4 (Finished) 的影片有視覺標示（處理中、轉碼中、錯誤）

  # ========== 選擇影片 ==========

  Rule: 管理員選擇影片後，selectedItems state 更新

    Example: 多選兩張影片
      Given 管理員進入 Bunny 媒體庫頁面
      When 管理員點擊 "課程介紹" 卡片
      And 管理員點擊 "第一章影片" 卡片
      Then selectedItems state 為 [ TBunnyVideo("abc-111..."), TBunnyVideo("def-222...") ]
      And 兩張卡片顯示選中狀態

  # ========== 在課程編輯頁中選擇 ==========

  Rule: 在課程/章節編輯頁的 VideoInput 中可選擇 Bunny 影片作為來源

    Example: 章節編輯選擇 Bunny 影片
      Given 管理員正在編輯章節 200
      And 章節 200 尚未設定影片
      When 管理員在 VideoInput 元件中切換到 "Bunny Stream" 類型
      And 點擊 "從 Bunny 媒體庫選擇"
      Then 彈出 Bunny 媒體庫選擇對話框
      When 管理員選擇影片 "第一章影片"（guid = def-222-222-222）
      And 點擊確認
      Then 章節 200 的 chapter_video meta 更新為：
        | field | value                |
        | type  | bunny-stream-api     |
        | id    | def-222-222-222      |
      And 前台播放時 src 組為 "https://vz-abc123.b-cdn.net/def-222-222-222/playlist.m3u8"

  # ========== 字幕整合 ==========

  Rule: Bunny 影片選擇完後，字幕仍走 Power Course 的 /posts/{id}/subtitles/{videoSlot} API

    Example: 章節設定 Bunny 影片後上傳字幕
      Given 章節 200 的 chapter_video.type = "bunny-stream-api"、id = "def-222-222-222"
      When 管理員透過 /posts/200/subtitles/chapter_video 上傳字幕 subtitle-zh-TW.srt
      Then 字幕轉為 WebVTT 後存入 WordPress 媒體庫（不存到 Bunny Stream）
      And 字幕記錄寫入 pc_subtitles_chapter_video postmeta
      And Vidstack Player 播放時同時載入 Bunny HLS 與 WordPress 媒體庫的字幕

  # ========== 缺失 bunny_cdn_hostname ==========

  Rule: 未設定 bunny_cdn_hostname 時，前台播放 Bunny 影片會載入 404 template

    Example: hostname 未設
      Given PowerhouseSettings.bunny_cdn_hostname 為空字串
      And 章節 200 的 chapter_video.type = "bunny-stream-api"、id = "def-222-222-222"
      When 學員進入章節 200 頁面
      Then Vidstack component 無法組出 src
      And 載入 video/404 template，顯示 "缺少 video_id | src ，請聯絡老師"
      And 不渲染 Vidstack Player
