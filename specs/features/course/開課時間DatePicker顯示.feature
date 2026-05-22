@ignore @command
Feature: 開課時間 DatePicker 顯示行為

  課程編輯頁 (Courses Edit Admin SPA) 的 DatePicker 欄位（開課時間、上架時間等）
  在課程未設定該日期時，必須顯示 placeholder（空白），不可顯示 "Invalid date" 字樣
  也不需使用者按 ✕ 清除才能輸入。
  對應 Issue #222。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |

  # ========== 後端 API 契約 ==========

  Rule: 後端 - course_schedule meta 為 falsy 值時 API 應回傳 null

    Example: meta 不存在 (新建課程預設) - API 回 null
      Given 系統中有以下課程：
        | courseId | name        | _is_course | status  |
        | 301      | 新課程預設  | yes        | publish |
      And 課程 301 的 course_schedule meta 不存在
      When 管理員 "Admin" 取得課程 301 的詳情
      Then 操作成功
      And 回應的 course_schedule 應為 null

    Example: meta 為空字串 - API 回 null
      Given 系統中有以下課程：
        | courseId | name             | _is_course | status  | course_schedule |
        | 302      | 開課時間空字串   | yes        | publish |                 |
      When 管理員 "Admin" 取得課程 302 的詳情
      Then 操作成功
      And 回應的 course_schedule 應為 null

    Example: meta 為字串 "0" - API 回 null
      Given 系統中有以下課程：
        | courseId | name           | _is_course | status  |
        | 303      | 開課時間為零   | yes        | publish |
      And 課程 303 的 wp_postmeta 中 "course_schedule" 的 meta_value 為 "0"
      When 管理員 "Admin" 取得課程 303 的詳情
      Then 操作成功
      And 回應的 course_schedule 應為 null

    Example: meta 為合法 timestamp - API 回 integer
      Given 系統中有以下課程：
        | courseId | name        | _is_course | status  | course_schedule |
        | 304      | 已排程開課  | yes        | publish | 1735689600      |
      When 管理員 "Admin" 取得課程 304 的詳情
      Then 操作成功
      And 回應的 course_schedule 應為 1735689600

  # ========== 前端 utility 行為（parseDatePickerValue） ==========

  Rule: 前端 - parseDatePickerValue 對 falsy 輸入回傳 undefined

    Example: 輸入 null - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到 null
      Then 應回傳 undefined

    Example: 輸入 undefined - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到 undefined
      Then 應回傳 undefined

    Example: 輸入空字串 "" - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到空字串
      Then 應回傳 undefined

    Example: 輸入數字 0 - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到數字 0
      Then 應回傳 undefined

    Example: 輸入字串 "0" - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到字串 "0"
      Then 應回傳 undefined

  Rule: 前端 - parseDatePickerValue 對合法 timestamp 回傳 valid dayjs

    Example: 輸入 10 位數秒級 timestamp - 回 valid dayjs
      Given 前端 utility "parseDatePickerValue" 收到數字 1735689600
      Then 應回傳 dayjs 物件
      And 該 dayjs 物件的 isValid() 應為 true
      And 該 dayjs 物件 toISOString() 應為 "2025-01-01T00:00:00.000Z"

    Example: 輸入 13 位數毫秒級 timestamp - 回 valid dayjs
      Given 前端 utility "parseDatePickerValue" 收到數字 1735689600000
      Then 應回傳 dayjs 物件
      And 該 dayjs 物件的 isValid() 應為 true

  Rule: 前端 - parseDatePickerValue 對無法解析的輸入回傳 undefined（不可回 Invalid Date）

    Example: 輸入非法字串 "not-a-date" - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到字串 "not-a-date"
      Then 應回傳 undefined

    Example: 輸入物件 {} - 回 undefined
      Given 前端 utility "parseDatePickerValue" 收到物件 {}
      Then 應回傳 undefined

  # ========== UI 行為（E2E） ==========

  Rule: UI - 新建課程進入編輯頁，DatePicker 顯示 placeholder 不顯示 Invalid date

    Example: 新建課程「開課時間」DatePicker 顯示 placeholder
      Given 管理員 "Admin" 已建立全新課程 (course_schedule 未設定)
      When 管理員 "Admin" 進入該課程編輯頁的「課程訂價」頁籤
      Then 「開課時間」DatePicker input 的 value 屬性應為空字串
      And 「開課時間」DatePicker 不應顯示文字 "Invalid date"
      And 「開課時間」DatePicker 應顯示 placeholder 文字 "Select date"

    Example: 新建課程「上架時間」DatePicker 顯示 placeholder
      Given 管理員 "Admin" 已建立全新課程 (date_created 為標準 WP 預設)
      When 管理員 "Admin" 進入該課程編輯頁的「其他」頁籤
      Then 「上架時間」DatePicker 不應顯示文字 "Invalid date"

    Example: 設過開課時間的課程仍正常顯示日期
      Given 管理員 "Admin" 已建立全新課程
      And 該課程 course_schedule 為 1735689600
      When 管理員 "Admin" 進入該課程編輯頁的「課程訂價」頁籤
      Then 「開課時間」DatePicker input 的 value 屬性應為 "2025-01-01 08:00"
      And 「開課時間」DatePicker 不應顯示文字 "Invalid date"

    Example: 清空開課時間後儲存 - 重新進入仍顯示空 placeholder
      Given 系統中有以下課程：
        | courseId | name             | _is_course | status  | course_schedule |
        | 305      | 已排程後清空     | yes        | publish | 1735689600      |
      And 管理員 "Admin" 已進入課程 305 編輯頁的「課程訂價」頁籤
      When 管理員 "Admin" 點選「開課時間」DatePicker 的 ✕ 清除按鈕
      And 管理員 "Admin" 儲存課程
      Then 操作成功
      And 課程 305 的 wp_postmeta 中 "course_schedule" 的 meta_value 應為 ""
      When 管理員 "Admin" 重新整理頁面並進入「課程訂價」頁籤
      Then 「開課時間」DatePicker input 的 value 屬性應為空字串
      And 「開課時間」DatePicker 不應顯示文字 "Invalid date"

  # ========== Regression 守衛（針對 Issue #222 主場景） ==========

  Rule: Regression - 新建課程不應因「開課時間」顯示 Invalid date 而阻塞使用者輸入

    Example: 不需點 ✕ 即可直接選擇日期
      Given 管理員 "Admin" 已建立全新課程
      When 管理員 "Admin" 進入該課程編輯頁的「課程訂價」頁籤
      And 管理員 "Admin" 點擊「開課時間」DatePicker 觸發日曆面板
      Then 日曆面板應正常開啟
      And 「開課時間」input 此時應為焦點 (focused) 且 value 為空字串
      # 不需要先按 ✕ 清掉 "Invalid date" 才能輸入
