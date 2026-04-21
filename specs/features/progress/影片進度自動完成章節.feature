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

  # ========== Ended 倒數跳轉的時序（Issue #206 修正，post-test 2026-04-20 調整） ==========

  Rule: 自動完成 API 呼叫為 fire-and-forget；Ended 倒數遮罩必須提供重看本章的出口，不強制跳下一章

    # Post-test 2026-04-20：Q1 由 C 調整為 B，僅保留「重看本章」出口。
    # 原因：VidStack 在 ended 狀態下「取消自動跳轉」造成播放按鈕消失（BUG 2-1）
    # 與拖拉進度條觸發 progress API 把進度條 seek 回片尾（BUG 2-2）。
    # 已刪除：「取消自動跳轉」與「onSeeking 中止倒數」Examples。
    # 保留：首次觀看回歸、重看本章（新）、最後一章無遮罩。

    Example: 95% 觸發 API 後影片播放到 ended，倒數期間 API 已完成
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      And 章節 200 的 next_post_url 為 "/chapter/201"
      When 影片播放進度達到 95%，自動完成 API 已發出
      And 影片播放到 100%，Ended 遮罩顯示倒數「5」秒
      Then API 呼叫為 fire-and-forget，伺服器端已處理完成
      And Ended 遮罩包含「重看本章」按鈕

    Example: 首次觀看自然播完後 5 秒倒數結束自動跳下一章（Q10 回歸保護）
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And 用戶 "Alice" 在章節 200 無 last_position_seconds
      And VidStack Player 正在播放章節 200 的影片
      And 章節 200 的 next_post_url 為 "/chapter/201"
      When 影片自然播放到 ended
      Then Ended 遮罩顯示倒數「5」秒
      When 5 秒倒數結束，用戶未點任何按鈕
      Then 頁面執行 window.location.href = "/chapter/201"
      And 下一頁載入時 DB 狀態已正確更新

    Example: Ended 倒數期間按「重看本章」停留在當前章節並從 0 重播
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      And 章節 200 的 next_post_url 為 "/chapter/201"
      When 影片播放到 ended，Ended 遮罩顯示倒數「5」秒
      And 用戶 "Alice" 點擊「重看本章」按鈕
      Then Ended 遮罩消失
      And VidStack Player 的 currentTime 回到 0（±2 秒）
      And 影片播放狀態為 playing
      And 頁面 URL 維持為章節 200，未跳轉到 "/chapter/201"
      And 即使 setInterval 殘留一次執行，isCancelledRef.current 為 true 守衛 window.location.href 不被呼叫

    Example: 最後一章（無 next_post_url）播放到 ended 不顯示遮罩
      Given 用戶 "Alice" 在章節 299 無 finished_at
      And 章節 299 為課程 100 的最後一章，next_post_url 為空字串
      And VidStack Player 正在播放章節 299 的影片
      When 影片播放到 ended
      Then Ended 遮罩不顯示
      And 不啟動 5 秒倒數
      And 頁面維持在章節 299

  # ========== 手動完成按鈕回歸 ==========

  Rule: 手動完成按鈕功能維持正常

    Example: 手動點擊「標示為已完成」按鈕仍正常運作
      Given 用戶 "Alice" 在章節 200 無 finished_at
      When 學員手動點擊「標示為已完成」按鈕
      Then 呼叫 toggle-finish API
      And 彈出成功對話框（非靜默模式）
      And 章節標記為已完成
      And UI 狀態正確更新

  # ========== duration 邊界 ==========

  Rule: VidStack 尚未回報 duration 時不觸發自動完成

    Example: duration 為 0 時不計算百分比
      Given 用戶 "Alice" 在章節 200 無 finished_at
      And VidStack Player 正在播放章節 200 的影片
      And VidStack 尚未透過 onDurationChange 回報影片時長（duration = 0）
      When 影片觸發 onTimeUpdate 事件（currentTime = 100）
      Then 不計算播放百分比
      And 不 dispatch "pc:auto-finish-chapter" 事件

  # ========== code 類型影片 ==========

  Rule: code 類型影片不渲染 VidStack，不適用自動完成

    Example: code embed 影片不觸發自動完成
      Given 用戶 "Alice" 在章節 203 無 finished_at
      And 章節 203 的 chapter_video.type 為 "code"
      When 學員進入章節 203 頁面
      Then 頁面使用獨立 template 渲染影片，非 VidStack
      And 自動完成邏輯不觸發
      And 章節完成仍依賴手動點擊按鈕
