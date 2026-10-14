@command
Feature: 上傳作業附件

  學員在繳交前先上傳附件取得 attachment_id，再於「繳交作業」時帶入（兩段式上傳）。
  檔案存於受保護目錄，不提供公開 URL，只能經「下載作業附件」驗權後取得。

  業務依據：zenbu-site-2.0 `POST me/course-assignments/upload` + `media.service.ts::uploadAttachment`
  （只驗登入，不指定作業、不驗報名；上限 50MB；MIME 白名單）
  偏離（Q4 使用者決策）：參考實作存 Bunny Storage 並回傳公開 URL；Power Course 存受保護目錄且不回傳 URL

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role       |
      | 2      | Alice | alice@test.com | subscriber |
      | 3      | Bob   | bob@test.com   | subscriber |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 使用者必須已登入

    Example: 未登入訪客上傳附件時操作失敗
      When 未登入訪客上傳作業附件：
        | original_name | mime_type | file_size |
        | 作業截圖.png  | image/png | 20480     |
      Then 操作失敗，錯誤為「必須登入」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 必須提供檔案

    Example: 未附檔案時操作失敗
      When 用戶 "Alice" 上傳作業附件但未附檔案
      Then 操作失敗，錯誤為「請選擇要上傳的檔案」

  Rule: 前置（參數）- 檔案大小不可超過 50MB

    Example: 檔案超過 50MB 一個位元組時操作失敗
      When 用戶 "Alice" 上傳作業附件：
        | original_name | mime_type       | file_size |
        | 期末報告.pdf  | application/pdf | 52428801  |
      Then 操作失敗，錯誤為「檔案過大（上限 50MB）」

  Rule: 前置（參數）- 檔案類型必須為圖片（jpeg / png / webp / gif）、PDF、Word、Excel、PowerPoint、純文字、CSV 或 ZIP

    Scenario Outline: 上傳 <mime_type> 時操作失敗
      When 用戶 "Alice" 上傳作業附件：
        | original_name   | mime_type   | file_size |
        | <original_name> | <mime_type> | 1024      |
      Then 操作失敗，錯誤為「不支援的檔案類型：<mime_type>」

      Examples:
        | original_name | mime_type                |
        | 示範影片.mp4  | video/mp4                |
        | setup.exe     | application/x-msdownload |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 白名單內且不超過 50MB 的檔案皆可上傳，不需指定作業也不檢查課程觀看權

    Scenario Outline: 未加入任何課程的 Bob 上傳 <original_name>
      When 用戶 "Bob" 上傳作業附件：
        | original_name   | mime_type   | file_size   |
        | <original_name> | <mime_type> | <file_size> |
      Then 操作成功

      Examples:
        | original_name | mime_type                                                          | file_size |
        | 作業截圖.png  | image/png                                                          | 20480     |
        | 期末報告.pdf  | application/pdf                                                    | 52428800  |
        | 成績表.xlsx   | application/vnd.openxmlformats-officedocument.spreadsheetml.sheet | 8192      |
        | 程式碼.zip    | application/zip                                                    | 1024      |

  Rule: 後置（狀態）- 建立附件紀錄並把檔案以隨機檔名存入受保護目錄

    Example: 上傳圖片後寫入附件紀錄
      When 用戶 "Alice" 上傳作業附件：
        | original_name | mime_type | file_size |
        | 作業截圖.png  | image/png | 20480     |
      Then 操作成功
      And 新建作業附件應為：
        | user_id | original_name | mime_type | file_size |
        | 2       | 作業截圖.png  | image/png | 20480     |
      And 新建作業附件的實體檔案應存放於受保護目錄且檔名不等於原始檔名

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳附件 id、檔名、MIME 類型與大小，不回傳檔案 URL

    Example: 上傳成功後回應不含 url
      When 用戶 "Alice" 上傳作業附件：
        | original_name | mime_type | file_size |
        | 作業截圖.png  | image/png | 20480     |
      Then 操作成功
      And 回應應包含：
        | file_name    | mime_type | file_size |
        | 作業截圖.png | image/png | 20480     |
      And 回應不應包含 url 欄位
