@frontend @query
Feature: 續播至上次觀看秒數

  當學員重新進入已觀看過的章節頁時，VidStack Player 初始化後
  自動 seek 到 pc_chapter_progress.last_position_seconds，
  允許 ±2 秒誤差，並從該秒數繼續播放。支援 Bunny HLS / YouTube / Vimeo。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          |
      | 2      | Alice | alice@test.com |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | chapter_video_type |
      | 200       | 第一章     | 100         | bunny              |
      | 201       | 第二章     | 100         | none               |
      | 202       | 第三章     | 100         | youtube            |
      | 203       | 第四章     | 100         | code               |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 章節 200 的影片總長為 600 秒
    And 前端續播誤差容忍 SEEK_TOLERANCE_SECONDS 為 2

  # ========== Q3 / Q7：讀取 chapter_progress 續播 ==========

  Rule: 讀取 pc_chapter_progress.last_position_seconds 進行 seek

    Example: 首次進入章節無紀錄時從頭播放
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      When 用戶 "Alice" 進入章節 200 頁面
      Then VidStack Player 初始 currentTime 應為 0
      And 影片從頭播放

    Example: 有續播紀錄時 seek 到離開點
      Given 用戶 "Alice" 在章節 200 的 last_position_seconds 為 120
      When 用戶 "Alice" 進入章節 200 頁面
      Then VidStack Player 於 canPlay 事件觸發後 seek 到 currentTime = 120（±2 秒）
      And 影片從第 120 秒繼續播放

  # ========== Q7：跨章節獨立維護 ==========

  Rule: 每個章節各自維護 last_position_seconds，互不影響

    Example: 章節 A 與章節 C 各自續播
      Given 用戶 "Alice" 在章節 200 的 last_position_seconds 為 90
      And 用戶 "Alice" 在章節 202 的 last_position_seconds 為 150
      When 用戶 "Alice" 進入章節 200 頁面
      Then VidStack Player seek 到第 90 秒
      When 用戶 "Alice" 切換到章節 202 頁面
      Then VidStack Player seek 到第 150 秒（±2 秒）

  # ========== Q5：影片類型支援 ==========

  Rule: Bunny HLS / YouTube / Vimeo 皆可續播；code 與 none 不適用

    Example: YouTube 透過 VidStack provider 續播
      Given 用戶 "Alice" 在章節 202 的 last_position_seconds 為 45
      When 用戶 "Alice" 進入章節 202 頁面
      Then VidStack YouTube provider 於 canPlay 事件後 seek 到第 45 秒（±2 秒）

    Example: 無影片章節不觸發 seek
      Given 用戶 "Alice" 在章節 201 無影片
      When 用戶 "Alice" 進入章節 201 頁面
      Then 頁面無 VidStack Player 實例
      And 不產生任何 seek 行為

    Example: code embed 章節不適用
      Given 用戶 "Alice" 在章節 203 的 chapter_video.type 為 "code"
      When 用戶 "Alice" 進入章節 203 頁面
      Then 頁面使用獨立 template 渲染，非 VidStack
      And 不產生任何 seek 行為

  # ========== Q12：達 95% / ended 後仍可續播 ==========

  Rule: 章節已完成後，重新進入仍以 last_position_seconds 續播（重看）

    Example: 已完成章節重看自動跳至離開點
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-04-15 10:00:00"
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 580
      When 用戶 "Alice" 從 CTA 點擊「重看 第 1 章 09:40」進入章節 200
      Then VidStack Player seek 到第 580 秒（±2 秒）
      And 影片從第 580 秒繼續播放

    Example: 已完成且秒數接近片尾仍正常續播
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-04-15 10:00:00"
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 598
      When 用戶 "Alice" 進入章節 200 頁面
      Then VidStack Player seek 到第 598 秒（±2 秒）
      And 影片從第 598 秒繼續播放直到 ended
