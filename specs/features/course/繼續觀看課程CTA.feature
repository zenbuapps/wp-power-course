@frontend @query
Feature: 繼續觀看課程 CTA

  學員在「我的帳戶 → 我的課程」或課程詳情頁看到的主要 CTA 按鈕，
  依據課程層 last_chapter_id（pc_avl_coursemeta.last_visit_info.chapter_id）
  與該章節的 last_position_seconds 顯示不同文案：
  - 未開始：「開始上課」
  - 進行中：「繼續觀看 第 X 章 MM:SS」
  - 章節已完成但仍有秒數：「重看 第 X 章 MM:SS」
  點擊後導向該章節，Player 初始化時自動續播至該秒數。

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
      | 201       | 第二章     | 100         | bunny              |
      | 202       | 第三章     | 100         | bunny              |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0

  # ========== Q1 / Q8：未開始 ==========

  Rule: 未曾觀看任何章節時，CTA 顯示「開始上課」

    Example: 首次進入課程
      Given 用戶 "Alice" 在課程 100 無 last_visit_info
      And 用戶 "Alice" 在課程 100 的所有章節皆無 last_position_seconds
      When 用戶 "Alice" 進入「我的帳戶 → 我的課程」頁面
      Then 課程 100 的 CTA 按鈕文字應為「開始上課」
      And 點擊後導向課程 100 的第一個章節（chapterId = 200）

  # ========== Q8：進行中 ==========

  Rule: 有進行中的章節時，CTA 顯示「繼續觀看 第 X 章 MM:SS」

    Example: 停在第 2 章 03:45
      Given 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 201
      And 用戶 "Alice" 在章節 201 的 last_position_seconds 為 225
      And 用戶 "Alice" 在章節 201 無 finished_at
      When 用戶 "Alice" 進入「我的帳戶 → 我的課程」頁面
      Then 課程 100 的 CTA 按鈕文字應為「繼續觀看 第二章 03:45」
      And 點擊後導向章節 201
      And VidStack Player seek 到第 225 秒（±2 秒）

    Example: 秒數小於 60 時顯示 00:MM 格式
      Given 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 200
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 8
      And 用戶 "Alice" 在章節 200 無 finished_at
      When 用戶 "Alice" 進入課程 100 詳情頁
      Then CTA 按鈕文字應為「繼續觀看 第一章 00:08」

  # ========== Q12：已完成 → 重看 ==========

  Rule: 停留章節已 finished_at 但仍保留 last_position_seconds 時，CTA 顯示「重看 第 X 章 MM:SS」

    Example: 第 1 章 95% 完成後 CTA 改為重看
      Given 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 200
      And 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-04-15 10:00:00"
      And 用戶 "Alice" 在章節 200 的 last_position_seconds 為 570
      When 用戶 "Alice" 進入「我的帳戶 → 我的課程」頁面
      Then 課程 100 的 CTA 按鈕文字應為「重看 第一章 09:30」
      And 點擊後導向章節 200
      And VidStack Player seek 到第 570 秒（±2 秒）

    Example: 第 1 章 95% 完成但無秒數紀錄時，仍能重看從頭
      Given 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 200
      And 用戶 "Alice" 在章節 200 的 finished_at 為 "2026-04-15 10:00:00"
      And 用戶 "Alice" 在章節 200 無 last_position_seconds
      When 用戶 "Alice" 進入課程 100 詳情頁
      Then CTA 按鈕文字應為「重看 第一章 00:00」
      And 點擊後 VidStack Player 從 0 秒開始播放

  # ========== 全部章節完成 ==========

  Rule: 課程 progress 達 100% 時，CTA 仍採最後造訪章節規則（重看）

    Example: 整個課程都完成
      Given 用戶 "Alice" 在課程 100 所有章節的 finished_at 皆已設定
      And 用戶 "Alice" 在課程 100 的 last_visit_info.chapter_id 為 202
      And 用戶 "Alice" 在章節 202 的 last_position_seconds 為 300
      When 用戶 "Alice" 進入「我的帳戶 → 我的課程」頁面
      Then 課程 100 的進度標示為 100%
      And CTA 按鈕文字應為「重看 第三章 05:00」
