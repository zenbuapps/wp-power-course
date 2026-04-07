@ignore @command
Feature: 上傳媒體檔案

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必須提供檔案

    Example: 未提供檔案時上傳失敗
      When 管理員 "Admin" 上傳媒體檔案，參數如下：
        | file |
        |      |
      Then 操作失敗，錯誤為「必須提供檔案」

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 檔案上傳到 WordPress 媒體庫（POST /power-course/upload，依循 WordPress 預設 upload_mimes，不額外限制格式）

    Example: 成功上傳圖片
      When 管理員 "Admin" 上傳媒體檔案 "course-cover.jpg"
      Then 操作成功
      And 回應中應包含 attachment_id（正整數）
      And 回應中應包含 url（媒體檔案 URL）

    Example: 成功上傳影片（上傳到 WordPress 本地媒體庫）
      When 管理員 "Admin" 上傳媒體檔案 "lesson-video.mp4"
      Then 操作成功
      And 回應中應包含 attachment_id

    Example: 成功上傳 PDF 文件
      When 管理員 "Admin" 上傳媒體檔案 "handout.pdf"
      Then 操作成功
      And 回應中應包含 attachment_id

  Rule: 後置（狀態）- 支援 upload_only 模式（僅上傳不建立 attachment）

    Example: upload_only 模式僅回傳檔案路徑
      When 管理員 "Admin" 上傳媒體檔案 "data.csv"，upload_only 為 true
      Then 操作成功
      And 回應中應包含 url（檔案路徑）
      And 回應中不應包含 attachment_id
