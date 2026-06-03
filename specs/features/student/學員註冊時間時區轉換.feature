@ignore @query @timezone
Feature: 學員註冊時間時區轉換（Issue #233）

  作為站長，
  我希望後台學員列表、學員明細、匯出 CSV，以及 MCP 工具輸出的「註冊於 / user_registered」時間，
  一律以 WordPress 設定的時區呈現，而非資料庫儲存的 UTC 原值，
  以免與同畫面其他已轉時區的時間欄位（學習紀錄時間軸、訂單時間軸）互相矛盾。

  # 背景說明：
  # - wp_users.user_registered 由 WP 核心以 UTC 寫入（wp_insert_user → gmdate）。
  # - 修正前各輸出點直接吐 (string) $user->user_registered（UTC 原值）。
  # - 修正後一律經 get_date_from_gmt() 轉成 WP 設定時區，格式維持 Y-m-d H:i:s（不套站台日期格式）。
  # - 受影響輸出點（共 6 處）：
  #   1. 學員列表 API get_students_callback（前台列表，資料源 Powerhouse to_array('list') 後處理）
  #   2. Query::get（單一學員，餵 MCP StudentGetTool）       inc/.../Student/Service/Query.php:368
  #   3. 單一課程匯出 CSV                                    inc/.../Student/Service/ExportCSV.php:122
  #   4. 全域匯出學員 CSV                                    inc/.../Student/Service/ExportAllCSV.php:161
  #   5. MCP 學員列表工具 StudentListTool                   inc/.../Mcp/Tools/Student/StudentListTool.php:180
  #   6. MCP 學員匯出 CSV 工具 StudentExportCsvTool          inc/.../Mcp/Tools/Student/StudentExportCsvTool.php:193

  Background:
    Given WordPress 時區設定為 "Asia/Taipei"（gmt_offset = 8）
    And 主機 PHP date.timezone 為 "UTC"
    And 系統中有以下用戶（user_registered 為資料庫儲存的 UTC 原值）：
      | userId | name  | email          | role       | user_registered     |
      | 1      | Admin | admin@test.com | administrator | 2026-01-01 00:00:00 |
      | 2      | Alice | alice@test.com | subscriber | 2026-05-17 13:07:33 |
      | 3      | Bob   | bob@test.com   | subscriber | 2026-05-17 18:30:00 |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 0

  # ========== 共通轉換規則 ==========

  Rule: 後置（回應）- user_registered 一律以 WP 設定時區輸出（僅換時區，格式不變）

    Example: 同日時段 — UTC 13:07:33 → 台灣時間 21:07:33
      When 任一輸出點輸出 "Alice" 的 user_registered
      Then 輸出值應為 "2026-05-17 21:07:33"

    Example: 跨日時段 — UTC 18:30:00 → 台灣時間隔日 02:30:00
      When 任一輸出點輸出 "Bob" 的 user_registered
      Then 輸出值應為 "2026-05-18 02:30:00"

    Example: 輸出格式維持 Y-m-d H:i:s（不套用站台日期/時間格式設定）
      Given WordPress 後台日期格式設為 "F j, Y"、時間格式設為 "g:i a"
      When 任一輸出點輸出 "Alice" 的 user_registered
      Then 輸出值應仍為 "2026-05-17 21:07:33"

  Rule: 後置（回應）- 空值 / 異常註冊時間原樣保留，不得轉成 1970 epoch

    Example: user_registered 為空字串時輸出空字串
      Given 系統中有以下用戶：
        | userId | name | email        | role       | user_registered |
        | 4      | Zoe  | zoe@test.com | subscriber |                 |
      When 任一輸出點輸出 "Zoe" 的 user_registered
      Then 輸出值應為 ""
      And 輸出值不應為 "1970-01-01 08:00:00"

    Example: user_registered 為 "0000-00-00 00:00:00" 時原樣保留
      Given 系統中有以下用戶：
        | userId | name | email        | role       | user_registered     |
        | 5      | Ivy  | ivy@test.com | subscriber | 0000-00-00 00:00:00 |
      When 任一輸出點輸出 "Ivy" 的 user_registered
      Then 輸出值不應為 "1970-01-01 08:00:00"

  # ========== 各輸出點 ==========

  Rule: 後置（回應）- 學員列表 API（get_students_callback）回應 user_registered 為站台時區

    # 資料源為 Powerhouse User::to_array('list')，回傳 UTC 原值；
    # PowerCourse 端於 get_students_callback 取得結果後做時區後處理。
    Example: 查詢課程 100 學員列表，註冊時間為站台時區
      When 管理員 "Admin" 查詢學員列表，參數如下：
        | meta_value | posts_per_page | paged |
        | 100        | 20             | 1     |
      Then 操作成功
      And 回應中 "Alice" 的 user_registered 應為 "2026-05-17 21:07:33"
      And 回應中 "Bob" 的 user_registered 應為 "2026-05-18 02:30:00"

  Rule: 後置（回應）- 不得 double-shift（Powerhouse to_array 未重複轉換，PowerCourse 僅轉一次）

    # 防回歸護欄：列表後處理假設 to_array('list') 回傳的是 UTC 原值。
    # 若 Powerhouse 已自行轉時區，再轉一次會變 +16 小時（2026-05-18 05:07:33）。
    Example: 列表後處理對 Alice 僅 +8 小時，而非 +16
      When 學員列表 API 對 Powerhouse 回傳的 user_registered "2026-05-17 13:07:33" 做時區後處理
      Then 後處理結果應為 "2026-05-17 21:07:33"
      And 後處理結果不應為 "2026-05-18 05:07:33"

  Rule: 後置（回應）- 單一學員查詢（Query::get，餵 MCP StudentGetTool）user_registered 為站台時區

    Example: 查詢學員 #2 明細，註冊時間為站台時區
      When 透過 MCP StudentGetTool 查詢學員 2
      Then 操作成功
      And 回應 user_registered 應為 "2026-05-17 21:07:33"

  Rule: 後置（回應）- 單一課程匯出 CSV 的 user_registered 為站台時區

    Example: 匯出課程 100 學員 CSV，註冊時間為站台時區
      When 管理員 "Admin" 匯出課程 100 的學員 CSV
      Then 操作成功
      And CSV 中 "Alice" 的 user_registered 應為 "2026-05-17 21:07:33"
      And CSV 中 "Bob" 的 user_registered 應為 "2026-05-18 02:30:00"
      And CSV 的「註冊於」欄位標頭維持原樣（不額外加註時區）

  Rule: 後置（回應）- 全域匯出學員 CSV 的 user_registered 為站台時區

    Example: 全域匯出學員 CSV，註冊時間為站台時區
      When 管理員 "Admin" 全域匯出學員 CSV
      Then 操作成功
      And CSV 中 "Alice" 的 user_registered 應為 "2026-05-17 21:07:33"

  Rule: 後置（回應）- MCP 學員列表工具（StudentListTool）user_registered 為站台時區

    Example: 透過 MCP StudentListTool 取得學員列表
      When 透過 MCP StudentListTool 查詢課程 100 的學員列表
      Then 操作成功
      And 回應中 "Alice" 的 user_registered 應為 "2026-05-17 21:07:33"

  Rule: 後置（回應）- MCP 學員匯出工具（StudentExportCsvTool）user_registered 為站台時區

    Example: 透過 MCP StudentExportCsvTool 匯出學員 CSV
      When 透過 MCP StudentExportCsvTool 匯出課程 100 的學員 CSV
      Then 操作成功
      And 匯出資料中 "Alice" 的 user_registered 應為 "2026-05-17 21:07:33"
