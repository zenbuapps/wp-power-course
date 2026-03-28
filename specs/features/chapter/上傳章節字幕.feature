@ignore @command
Feature: 上傳章節字幕

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

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 章節必須存在

    Example: 不存在的章節上傳字幕失敗
      When 管理員 "Admin" 為章節 9999 上傳字幕，參數如下：
        | file           | srclang |
        | subtitle.srt   | zh-TW   |
      Then 操作失敗，錯誤為「章節不存在」

  Rule: 前置（狀態）- 同語言字幕不可重複上傳（需先刪除再上傳）

    Example: 重複上傳相同語言字幕失敗
      Given 章節 200 已有以下字幕：
        | srclang | label | attachment_id |
        | zh-TW   | 繁體中文 | 301          |
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file               | srclang |
        | new-subtitle.srt   | zh-TW   |
      Then 操作失敗，錯誤為「該語言字幕已存在，請先刪除再上傳」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必須提供字幕檔案

    Example: 未提供檔案時上傳失敗
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file | srclang |
        |      | zh-TW   |
      Then 操作失敗，錯誤為「必須提供字幕檔案」

  Rule: 前置（參數）- 必須指定語言代碼（srclang）

    Example: 未提供語言代碼時上傳失敗
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file         | srclang |
        | subtitle.srt |         |
      Then 操作失敗，錯誤為「必須指定字幕語言」

  Rule: 前置（參數）- 僅接受 .srt 和 .vtt 格式

    Example: 上傳不支援的格式失敗
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file          | srclang |
        | subtitle.txt  | zh-TW   |
      Then 操作失敗，錯誤為「僅支援 .srt 和 .vtt 格式」

  Rule: 前置（參數）- srclang 必須為有效的 BCP-47 語言代碼

    Example: 無效的語言代碼上傳失敗
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file         | srclang |
        | subtitle.srt | zzz     |
      Then 操作失敗，錯誤為「無效的語言代碼」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- SRT 檔案自動轉換為 WebVTT 後儲存到 WordPress 媒體庫

    Example: 成功上傳 SRT 字幕（自動轉換為 WebVTT）
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file         | srclang |
        | subtitle.srt | zh-TW   |
      Then 操作成功
      And 章節 200 的字幕列表應包含：
        | srclang | label    | format |
        | zh-TW   | 繁體中文 | vtt    |
      And 回應中應包含 attachment_id（正整數）
      And 回應中應包含 url（.vtt 檔案 URL）

  Rule: 後置（狀態）- WebVTT 檔案直接儲存到 WordPress 媒體庫

    Example: 成功上傳 WebVTT 字幕
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file         | srclang |
        | subtitle.vtt | en      |
      Then 操作成功
      And 章節 200 的字幕列表應包含：
        | srclang | label   | format |
        | en      | English | vtt    |
      And 回應中應包含 attachment_id（正整數）
      And 回應中應包含 url（.vtt 檔案 URL）

  Rule: 後置（狀態）- 支援同一章節上傳多語言字幕

    Example: 成功為同一章節上傳第二語言字幕
      Given 章節 200 已有以下字幕：
        | srclang | label    | attachment_id |
        | zh-TW   | 繁體中文 | 301          |
      When 管理員 "Admin" 為章節 200 上傳字幕，參數如下：
        | file            | srclang |
        | subtitle-en.vtt | en      |
      Then 操作成功
      And 章節 200 的字幕列表應包含：
        | srclang | label    |
        | zh-TW   | 繁體中文 |
        | en      | English  |
