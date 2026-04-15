@frontend @event-driven @command
Feature: 紀錄章節續播秒數

  當學員觀看章節影片時，前端依據 currentTime 以 throttle 10 秒的節奏
  呼叫 POST /power-course/chapters/{id}/progress，將 last_position_seconds
  寫入 pc_chapter_progress 表（(user_id, chapter_id) 複合 unique index，upsert）。
  採 last_write_wins 策略：後寫者覆蓋；updated_at 由 server 寫入便於未來樂觀鎖。

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
    And 前端續播秒數 flush 節流為 10 秒
    And 最低可寫入秒數門檻 MIN_WRITE_SECONDS 為 5

  # ========== Q1：每 10 秒 throttle flush ==========

  Rule: Player 每 10 秒 throttle flush 一次，pause 與 beforeunload 立即 flush

    Example: 觀看 45 秒內 flush 4 次（10, 20, 30, 40）＋ pause flush 1 次（45）
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      And VidStack Player 正在播放章節 200 的影片
      When 影片連續播放 45 秒且中途按下暫停
      Then 前端應呼叫 POST /power-course/chapters/200/progress 共 5 次
      And 最後一次 payload 的 last_position_seconds 應為 45

    Example: 關閉分頁觸發 beforeunload 立即 flush
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放到第 23 秒時關閉分頁觸發 beforeunload
      Then 前端應以 navigator.sendBeacon 呼叫 POST /power-course/chapters/200/progress
      And payload 的 last_position_seconds 應為 23

  # ========== Q4：<5 秒不寫 ==========

  Rule: currentTime < 5 秒時不 flush，避免誤觸即離噪音

    Example: 播放 3 秒即離開不產生 API 呼叫
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放到第 3 秒時關閉頁面
      Then 前端不呼叫 POST /power-course/chapters/200/progress

    Example: 播放到第 5 秒時開始寫入
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放到第 5 秒
      Then 前端呼叫 POST /power-course/chapters/200/progress
      And payload 的 last_position_seconds 應為 5

  # ========== Q3：四捨五入到整數秒 ==========

  Rule: 秒數寫入前於 server 端四捨五入為整數

    Example: 前端傳 120.7 秒，DB 儲存為 121
      Given 用戶 "Alice" 在章節 200 無 last_position_seconds
      When 前端呼叫 POST /power-course/chapters/200/progress，last_position_seconds 為 120.7
      Then 操作成功
      And pc_chapter_progress 表中 (user_id=2, chapter_id=200) 的 last_position_seconds 應為 121

  # ========== Q5：僅 VidStack 可追蹤的類型才記錄 ==========

  Rule: 僅 bunny / youtube / vimeo 類型會寫入；code / none 一律不記錄

    Example: YouTube 影片寫入正常
      Given 用戶 "Alice" 在章節 202 無 last_position_seconds
      And VidStack Player 透過 YouTube provider 播放章節 202
      When 影片播放到第 60 秒
      Then 前端呼叫 POST /power-course/chapters/202/progress
      And payload 的 last_position_seconds 應為 60

    Example: code embed 章節不記錄
      Given 用戶 "Alice" 在章節 203 無 last_position_seconds
      When 學員進入章節 203 頁面
      Then 前端不呼叫 POST /power-course/chapters/203/progress

    Example: 無影片章節不記錄
      Given 用戶 "Alice" 在章節 201 無 last_position_seconds
      When 學員進入章節 201 頁面
      Then 前端不呼叫 POST /power-course/chapters/201/progress

  # ========== Q11：last_write_wins 並發策略 ==========

  Rule: 同一 (user_id, chapter_id) 並發寫入採後寫者覆蓋

    Example: 手機與桌機同時觀看以時間戳晚者為準
      Given 用戶 "Alice" 在章節 200 的 last_position_seconds 為 100，updated_at "2026-04-15 10:00:00"
      When 用戶 "Alice" 從桌機呼叫 POST /chapters/200/progress，last_position_seconds 為 150，server 時間 "2026-04-15 10:00:05"
      And 用戶 "Alice" 從手機呼叫 POST /chapters/200/progress，last_position_seconds 為 30，server 時間 "2026-04-15 10:00:07"
      Then pc_chapter_progress 表中 (user_id=2, chapter_id=200) 的 last_position_seconds 應為 30
      And updated_at 應為 "2026-04-15 10:00:07"

  # ========== Q13：upsert 行為 ==========

  Rule: (user_id, chapter_id) 複合 unique index，寫入採 upsert

    Example: 首次寫入建立新列
      Given pc_chapter_progress 表中無 (user_id=2, chapter_id=200) 資料
      When 用戶 "Alice" 呼叫 POST /chapters/200/progress，last_position_seconds 為 42
      Then pc_chapter_progress 表新增一列 (user_id=2, chapter_id=200, last_position_seconds=42)

    Example: 既有紀錄更新不產生重複列
      Given 用戶 "Alice" 在章節 200 的 last_position_seconds 為 60
      When 用戶 "Alice" 呼叫 POST /chapters/200/progress，last_position_seconds 為 120
      Then pc_chapter_progress 表中 (user_id=2, chapter_id=200) 應僅有一列
      And 該列 last_position_seconds 應為 120

    Example: 同時更新 course 層 last_chapter_id 指標
      Given 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 201
      When 用戶 "Alice" 呼叫 POST /chapters/200/progress，last_position_seconds 為 30
      Then pc_avl_coursemeta 表中 (post_id=100, user_id=2, meta_key="last_visit_info") 的 chapter_id 應為 200
      And last_visit_at 應更新為當下時間

  # ========== Q14：寫入權限 ==========

  Rule: 寫入必須登入 + 擁有該課程有效授權

    Example: 未登入呼叫回 403
      Given 訪客未登入
      When 匿名呼叫 POST /chapters/200/progress，last_position_seconds 為 30
      Then 操作失敗，HTTP 狀態碼 403

    Example: 已登入但未擁有課程授權回 403
      Given 用戶 "Bob" 未被加入課程 100
      When 用戶 "Bob" 呼叫 POST /chapters/200/progress，last_position_seconds 為 30
      Then 操作失敗，HTTP 狀態碼 403
      And pc_chapter_progress 表中不產生 (user_id=3, chapter_id=200) 資料

    Example: 課程已到期的學員回 403
      Given 用戶 "Alice" 在課程 100 的 expire_date 為 1609459200
      When 用戶 "Alice" 呼叫 POST /chapters/200/progress，last_position_seconds 為 30
      Then 操作失敗，HTTP 狀態碼 403

  # ========== Q6 / Q12：達到 95% 後仍繼續記錄 ==========

  Rule: 章節自動完成後秒數繼續記錄，不凍結

    Example: 95% 完成後秒數持續更新
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-04-15 10:00:00"
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 570
      When VidStack Player 繼續播放章節 200 到第 580 秒
      Then 前端呼叫 POST /power-course/chapters/200/progress
      And pc_chapter_progress 表中 (user_id=2, chapter_id=200) 的 last_position_seconds 應為 580
