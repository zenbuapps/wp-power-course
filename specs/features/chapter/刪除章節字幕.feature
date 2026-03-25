@ignore @command
Feature: 刪除章節字幕

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | chapter_video_type | chapter_video_id         |
      | 200       | 第一章     | 100         | bunny              | abc-123-def-456          |
    And 章節 200 已有以下字幕：
      | srclang | label    | attachment_id |
      | zh-TW   | 繁體中文 | 301          |
      | en      | English  | 302          |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 章節必須存在

    Example: 不存在的章節刪除字幕失敗
      When 管理員 "Admin" 刪除章節 9999 的字幕，語言為 "zh-TW"
      Then 操作失敗，錯誤為「章節不存在」

  Rule: 前置（狀態）- 指定語言的字幕必須存在

    Example: 刪除不存在的語言字幕失敗
      When 管理員 "Admin" 刪除章節 200 的字幕，語言為 "ja"
      Then 操作失敗，錯誤為「該語言字幕不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必須指定語言代碼

    Example: 未指定語言代碼時刪除失敗
      When 管理員 "Admin" 刪除章節 200 的字幕，語言為 ""
      Then 操作失敗，錯誤訊息包含 "srclang"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 刪除指定語言的字幕及 WordPress 媒體庫附件

    Example: 成功刪除指定語言字幕
      When 管理員 "Admin" 刪除章節 200 的字幕，語言為 "zh-TW"
      Then 操作成功
      And 章節 200 的字幕列表應包含：
        | srclang | label   |
        | en      | English |
      And 章節 200 的字幕列表不應包含 srclang 為 "zh-TW" 的項目
      And WordPress 媒體庫中 attachment_id 301 應已刪除

  Rule: 後置（狀態）- 刪除最後一筆字幕後字幕列表為空

    Example: 刪除唯一字幕後列表為空
      Given 章節 200 已有以下字幕：
        | srclang | label    | attachment_id |
        | zh-TW   | 繁體中文 | 301          |
      When 管理員 "Admin" 刪除章節 200 的字幕，語言為 "zh-TW"
      Then 操作成功
      And 章節 200 的字幕列表應為空
