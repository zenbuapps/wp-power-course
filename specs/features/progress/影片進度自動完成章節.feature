@frontend @event-driven
Feature: 影片進度自動完成章節

  當學員觀看章節影片的播放進度達到 95% 門檻（或影片播放到 ended），
  前端自動觸發 Custom DOM Event（pc:auto-finish-chapter），
  由 finishChapter.ts 監聽並呼叫現有的 toggle-finish API，
  以靜默模式（不彈出對話框）將章節標記為已完成。

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
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 章節 200 的影片總長為 600 秒
    And 自動完成門檻值 FINISH_THRESHOLD 為 0.95

  # ========== 核心：播放進度達到門檻 ==========

  Rule: 當影片播放進度達到 95% 門檻且章節尚未完成時，自動標記章節為已完成

    Example: Happy Path — 學員觀看 Bunny HLS 影片超過 95%
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放進度達到 95%（currentTime = 570, duration = 600）
      Then Player.tsx dispatch 自訂事件 "pc:auto-finish-chapter"，detail 包含 { chapterId: 200, courseId: 100 }
      And finishChapter.ts 收到事件後，呼叫 POST /power-course/toggle-finish-chapters/200
      And 章節列表中「第一章」的圖示更新為已完成
      And 進度條數值更新
      And 「標示為已完成」按鈕切換為「標示為未完成」狀態
      And 不彈出完成對話框（靜默完成）

  # ========== 防重複觸發 ==========

  Rule: 章節已完成時，播放進度超過門檻不重複觸發 API

    Example: 已完成的章節重新觀看不觸發
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放進度達到 95%
      Then 不 dispatch "pc:auto-finish-chapter" 事件
      And 不呼叫 toggle-finish API
      And 頁面狀態保持不變

    Example: 同一次觀看中 95% 和 ended 事件不重複呼叫 API
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放進度達到 95%，觸發自動完成
      And 影片繼續播放到 100% 觸發 ended 事件
      Then 僅呼叫一次 toggle-finish API（第二次被 hasAutoFinished flag 擋下）

  # ========== Ended 保底 ==========

  Rule: 影片播放到 ended 事件時，也觸發自動完成作為保底

    Example: 影片自然播放到結束觸發自動完成
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      When 影片自然播放到結束，觸發 onEnded 事件
      Then Player.tsx dispatch 自訂事件 "pc:auto-finish-chapter"
      And finishChapter.ts 呼叫 toggle-finish API
      And 章節標記為已完成（靜默模式）

  # ========== 拖動進度條 ==========

  Rule: 拖動進度條跳到 95% 以後也觸發自動完成

    Example: 學員拖動進度條直接跳到 96%
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      When 學員將進度條拖動到 96% 的位置（currentTime = 576, duration = 600）
      Then Player.tsx dispatch 自訂事件 "pc:auto-finish-chapter"
      And finishChapter.ts 呼叫 toggle-finish API
      And 章節標記為已完成（靜默模式）

  # ========== 無影片章節 ==========

  Rule: 無影片的章節不受自動完成影響

    Example: 純文字章節不觸發自動完成
      Given 用戶 "Alice" 在章節 201 無 finished_at
      And 章節 201 的 chapter_video.type 為 "none"
      When 學員進入章節 201 頁面
      Then VidStack Player 不存在，自動完成邏輯不觸發
      And 章節完成仍依賴手動點擊按鈕

  # ========== 手動取消後重新觸發 ==========

  Rule: 手動取消完成後重新觀看超過 95% 可再次自動完成

    Example: 手動標示為未完成後，再次觀看觸發自動完成
      Given 用戶 "Alice" 在章節 200 的 finished_at 為 "2025-06-01 10:00:00"
      When 學員手動點擊「標示為未完成」按鈕
      Then 章節 200 變為未完成狀態
      And finishChapter.ts 重置 hasAutoFinished flag
      When 學員繼續播放影片，播放進度再次超過 95%
      Then Player.tsx dispatch 自訂事件 "pc:auto-finish-chapter"
      And finishChapter.ts 呼叫 toggle-finish API
      And 章節重新標記為已完成

  # ========== API 失敗靜默處理 ==========

  Rule: 自動完成 API 呼叫失敗時靜默處理

    Example: 網路問題導致 API 呼叫失敗
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      And 網路連線中斷
      When 影片播放進度達到 95%
      Then Player.tsx dispatch 自訂事件 "pc:auto-finish-chapter"
      And finishChapter.ts 呼叫 toggle-finish API
      And API 呼叫失敗，console.warn 記錄錯誤
      And 不彈出錯誤對話框
      And 學員可之後手動點擊按鈕完成

  # ========== YouTube / Vimeo 相容性 ==========

  Rule: YouTube 和 Vimeo 影片若 VidStack 可追蹤進度則適用，否則降級為手動完成

    Example: YouTube 影片適用自動完成（若 VidStack 可追蹤進度）
      Given 用戶 "Alice" 在章節 202 無 finished_at
      And 章節 202 的 chapter_video.type 為 "youtube"
      And VidStack Player 正在播放章節 202 的影片
      When VidStack 的 onTimeUpdate 事件回報播放進度達到 95%
      Then 自動完成邏輯正常觸發

  # ========== Ended 倒數跳轉的時序 ==========

  Rule: 自動完成 API 呼叫為 fire-and-forget，不影響 Ended 倒數跳轉

    Example: 95% 觸發 API 後影片播放到 ended 仍正常跳轉
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      When 影片播放進度達到 95%，自動完成 API 已發出
      And 影片播放到 100%，Ended 倒數 5 秒開始
      And 5 秒後頁面跳轉到下一章節
      Then API 呼叫為 fire-and-forget，伺服器端已處理完成
      And 下一頁載入時 DB 狀態已正確更新

  # ========== 手動完成按鈕回歸 ==========

  Rule: 手動完成按鈕功能維持正常

    Example: 手動點擊「標示為已完成」按鈕仍正常運作
      Given 用戶 "Alice" 在章節 200 無 finished_at
      When 學員手動點擊「標示為已完成」按鈕
      Then 呼叫 toggle-finish API
      And 彈出成功對話框（非靜默模式）
      And 章節標記為已完成
      And UI 狀態正確更新
