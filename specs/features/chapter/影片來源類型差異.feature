@ignore @frontend @structure
Feature: 影片來源類型差異

  章節 / 課程特色影片 / 試看影片支援 5 種來源類型，各自走不同的 template 與播放器：

  | type              | 模板                                     | 播放器          | 支援字幕 | 支援自動完成 |
  |-------------------|------------------------------------------|-----------------|---------|-------------|
  | bunny-stream-api  | components/video/vidstack/index.php      | Vidstack (HLS)  | ✅      | ✅          |
  | youtube           | components/video/vidstack/index.php      | Vidstack (YouTube) | ⚠️ 有限制 | ⚠️ 視 Vidstack 行為 |
  | vimeo             | components/video/vidstack/index.php      | Vidstack (Vimeo)   | ⚠️ 有限制 | ⚠️ 視 Vidstack 行為 |
  | code              | components/video/code/index.php          | 自訂 iframe embed | ❌ | ❌ |
  | none              | 不渲染                                   | —               | —       | —           |

  **Code source:**
  - `inc/templates/components/video/index.php`：路由器，依 video_info.type 分派
  - `inc/templates/components/video/vidstack/index.php`：Vidstack 入口（支援 bunny / youtube / vimeo）
  - `inc/templates/components/video/code/index.php`：code 類型專屬模板
  - `js/src/App2/Player.tsx`：Vidstack React 元件，負責 95% 自動完成邏輯

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role     |
      | 10     | Alice | customer |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 路由決策 ==========

  Rule: video/index.php 依 video_info.type 決定載入的模板

    Example: none 類型不渲染任何東西
      Given 章節 200 的 chapter_video 為 { type: "none", id: "", meta: [] }
      When 模板 components/video/index.php 執行
      Then 直接 return，不渲染任何 HTML
      And 頁面不顯示播放器

    Example: code 類型走 components/video/code
      Given 章節 200 的 chapter_video 為 { type: "code", id: "<iframe ...></iframe>" }
      When 模板 components/video/index.php 執行
      Then 載入 video/code 模板
      And code 模板直接 echo 出 iframe HTML（需信任 Admin 輸入）

    Example: 其他 type 走 components/video/vidstack
      Given 章節 200 的 chapter_video.type 為 "bunny-stream-api"
      When 模板 components/video/index.php 執行
      Then 載入 video/vidstack 模板
      And 模板負責組 src 與渲染 React 元件掛載點

  # ========== Vidstack src 組合 ==========

  Rule: Vidstack 透過 src 字串前綴決定播放器類型（內建邏輯）

    Example: YouTube
      Given 章節 200 的 chapter_video 為 { type: "youtube", id: "dQw4w9WgXcQ" }
      When vidstack 模板執行
      Then src 為 "youtube/dQw4w9WgXcQ"
      And Vidstack MediaPlayer 自動渲染 YouTube iframe embed

    Example: Vimeo
      Given 章節 200 的 chapter_video 為 { type: "vimeo", id: "123456789" }
      Then src 為 "vimeo/123456789"
      And Vidstack MediaPlayer 自動渲染 Vimeo player

    Example: Bunny HLS
      Given 章節 200 的 chapter_video 為 { type: "bunny-stream-api", id: "abc-111-222-333" }
      And PowerhouseSettings.bunny_cdn_hostname 為 "vz-abc123.b-cdn.net"
      Then src 為 "https://vz-abc123.b-cdn.net/abc-111-222-333/playlist.m3u8"
      And Vidstack MediaPlayer 以 HLS.js 播放

  Rule: 缺少 bunny_cdn_hostname 時 Bunny 影片 fallback 到 404 template

    Example: 未設定 hostname
      Given PowerhouseSettings.bunny_cdn_hostname 為空
      And 章節 200 的 chapter_video.type 為 "bunny-stream-api"
      When vidstack 模板執行
      Then 載入 video/404 模板
      And 顯示 "缺少 video_id | src ，請聯絡老師"

  # ========== 自動完成支援矩陣 ==========

  Rule: 自動完成 (95% 門檻) 僅適用於 Vidstack 渲染的影片

    Example: Bunny 影片
      Given 章節 200 的 chapter_video.type 為 "bunny-stream-api"
      When Alice 播放到 95% 進度
      Then Player.tsx 的 onTimeUpdate 觸發 dispatchAutoFinishEvent
      And pc:auto-finish-chapter custom event 被 dispatch
      And finishChapter.ts 呼叫 toggle-finish API

    Example: code 類型不支援自動完成
      Given 章節 201 的 chapter_video.type 為 "code"
      Then code 模板不渲染 Vidstack，僅渲染 raw iframe HTML
      And 沒有 onTimeUpdate / onEnded 事件可監聽
      And Alice 必須手動點擊「標示為已完成」按鈕

  Rule: YouTube / Vimeo 的自動完成取決於 Vidstack 是否能從 iframe 取得 currentTime

    Example: YouTube 可追蹤
      Given 章節 202 的 chapter_video.type 為 "youtube"
      And Vidstack 透過 YouTube iframe API 可取得 currentTime
      When Alice 播放到 95% 進度
      Then 自動完成正常運作
      And 與 Bunny 行為一致

    Example: 降級為手動完成
      Given Vidstack 無法從 YouTube / Vimeo 取得正確的 currentTime（如網路問題、iframe postMessage 失敗）
      Then onTimeUpdate 的 detail.currentTime 為 0 或 duration 為 0
      And 自動完成邏輯 early-return（duration <= 0 時）
      And Alice 必須手動點擊「標示為已完成」按鈕

  # ========== 字幕支援矩陣 ==========

  Rule: 字幕（VTT Track）僅能掛在 Vidstack MediaPlayer 上

    Example: Bunny HLS + 字幕
      Given 章節 200 的 chapter_video.type 為 "bunny-stream-api"
      And 章節 200 已上傳 zh-TW 字幕
      When Alice 播放影片
      Then Vidstack <Track> 元素載入 /wp-content/uploads/subtitle-zh-TW.vtt
      And Alice 可在播放器控制列選擇字幕

    Example: code 類型不支援字幕
      Given 章節 201 的 chapter_video.type 為 "code"
      Then 即使後端 postmeta 中有 pc_subtitles_chapter_video，前台也無法顯示
      And 字幕功能僅對 Vidstack 播放的影片有效
