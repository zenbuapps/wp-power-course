@ignore @command
Feature: 透過彈窗挑選用戶加入課程

  # 場景：管理員在課程編輯頁「課程學員」tab 上方，透過升級後的 UserTable（mode='course-exclude'）
  #       批次挑選尚未加入本課程的用戶，並可選擇性設定到期日，一鍵加入。
  # 後端 API：沿用 POST /power-course/v2/courses/add-students（不變）
  # 前端元件：@/components/user/UserTable（新增 mode prop）
  # Issue：#190
  # 澄清紀錄：specs/clarify/2026-04-21-1747-issue190.md
  # 對應 Command feature（後端）：features/student/新增學員到課程.feature（既有，不動）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email           |
      | 1      | Admin | admin@test.com  |
      | 2      | Alice | alice@test.com  |
      | 3      | Bob   | bob@test.com    |
      | 4      | Carol | carol@test.com  |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 用戶 "Alice" 已開通課程 100（avl_course_ids 包含 100）
    And 管理員 "Admin" 正在瀏覽課程 100 的編輯頁的「課程學員」tab

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- UserTable 在 course-exclude 模式下，列表僅顯示尚未加入本課程的用戶

    Example: 已加入本課程的用戶不應出現在上方挑選表格
      When 管理員 "Admin" 查看課程 100 編輯頁的 UserTable（mode='course-exclude'）
      Then 上方挑選表格不應包含用戶 "Alice"
      And 上方挑選表格應包含用戶 "Bob" 與用戶 "Carol"

    Example: 已加入課程的用戶被移除課程權限後應重新出現在上方挑選表格
      Given 管理員 "Admin" 已透過下方 StudentTable 將用戶 "Alice" 從課程 100 移除
      When 上方 UserTable 觸發 refetch
      Then 上方挑選表格應包含用戶 "Alice"

  Rule: 前置（狀態）- courseId 尚未解析時，上方挑選表格不應發出查詢

    Example: courseId 為空時不應發出查詢
      Given useParsed() 回傳的 id 為 undefined
      When UserTable 以 mode='course-exclude' 掛載
      Then 不應發出 students resource 的 list 查詢

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 未勾選任何用戶時，加入按鈕必須處於 disabled 狀態

    Example: 零勾選時加入按鈕 disabled
      Given 管理員 "Admin" 已載入 UserTable（mode='course-exclude'）
      And 管理員 "Admin" 未勾選任何 row
      Then 按鈕 "Add students to this course" 應為 disabled

    Example: 勾選 1 位用戶後按鈕應顯示動態人數並可點擊
      Given 管理員 "Admin" 已載入 UserTable（mode='course-exclude'）
      When 管理員 "Admin" 勾選用戶 "Bob"
      Then 按鈕 label 應為 "Add 1 students to this course"
      And 按鈕應為 enabled

  Rule: 前置（參數）- DatePicker 不可選擇今天以前的日期

    Example: 今天以前的日期應被 disabledDate 禁用
      Given 管理員 "Admin" 開啟 DatePicker
      Then 今天之前的日期（`current < dayjs().startOf('day')`）應為 disabled 狀態

  Rule: 前置（參數）- DatePicker 未選擇時，送出的 expire_date 必須為 0（永久）

    Example: 未選日期時送出 expire_date = 0
      Given 管理員 "Admin" 勾選用戶 "Bob"
      And 管理員 "Admin" 未選擇 DatePicker
      When 管理員 "Admin" 點擊加入按鈕
      Then 發送至 POST /courses/add-students 的 payload.expire_date 應為 0

  Rule: 前置（參數）- DatePicker 選擇日期時，送出的 expire_date 必須為該日期的 unix timestamp

    Example: 選擇未來日期時送出對應 unix timestamp
      Given 管理員 "Admin" 勾選用戶 "Bob"
      And 管理員 "Admin" 選擇 DatePicker 為 "2027-01-01 00:00"
      When 管理員 "Admin" 點擊加入按鈕
      Then 發送至 POST /courses/add-students 的 payload.expire_date 應為 "2027-01-01 00:00" 的 unix timestamp

  Rule: 前置（參數）- 批次加入必須對所有勾選的 user_ids 套用同一個 expire_date

    Example: 勾選多位用戶時所有人套用相同到期日
      Given 管理員 "Admin" 勾選用戶 "Bob" 與 "Carol"
      And 管理員 "Admin" 選擇 DatePicker 為 "2027-01-01 00:00"
      When 管理員 "Admin" 點擊加入按鈕
      Then 發送至 POST /courses/add-students 的 payload 應為：
        | 欄位         | 值                          |
        | user_ids     | ["2", "3"]                  |
        | course_ids   | ["100"]                     |
        | expire_date  | 1893456000                  |

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 加入成功後應清空已選清單與 DatePicker

    Example: 成功加入後 UI 狀態回到初始
      Given 管理員 "Admin" 勾選用戶 "Bob"
      And 管理員 "Admin" 選擇 DatePicker 為 "2027-01-01 00:00"
      When 管理員 "Admin" 點擊加入按鈕
      And API 回傳成功
      Then selectedUserIdsAtom 應為空陣列
      And DatePicker 值應為 undefined
      And 加入按鈕應回到 disabled 狀態（因為無勾選）

  Rule: 後置（狀態）- 加入成功後應 invalidate students resource list

    Example: 成功加入後上下兩表同步 refetch
      Given 管理員 "Admin" 勾選用戶 "Bob"
      When 管理員 "Admin" 點擊加入按鈕
      And API 回傳成功
      Then 應呼叫 invalidate({ resource: 'students', dataProviderName: 'power-course', invalidates: ['list'] })
      And 上方 UserTable（course-exclude）應 refetch，用戶 "Bob" 應從列表消失
      And 下方 StudentTable 應 refetch，用戶 "Bob" 應出現在列表

  Rule: 後置（狀態）- 加入成功後應顯示成功訊息

    Example: 成功加入後顯示 message.success
      Given 管理員 "Admin" 勾選用戶 "Bob"
      When 管理員 "Admin" 點擊加入按鈕
      And API 回傳成功
      Then 應顯示 message.success 內容為 "Students added successfully"
      And message key 應為 "add-students"

  Rule: 後置（狀態）- 加入失敗後不應清空已選清單與 DatePicker

    Example: 失敗時保留勾選與 DatePicker 以便重試
      Given 管理員 "Admin" 勾選用戶 "Bob" 與 "Carol"
      And 管理員 "Admin" 選擇 DatePicker 為 "2027-01-01 00:00"
      When 管理員 "Admin" 點擊加入按鈕
      And API 回傳失敗
      Then 應顯示 message.error 內容為 "Failed to add students to course"
      And selectedUserIdsAtom 應仍為 ["2", "3"]
      And DatePicker 值應仍為 "2027-01-01 00:00"
      And 加入按鈕應仍為 enabled

  # ========== 模式切換驗證（不回歸既有 global 行為）==========

  Rule: 後置（狀態）- UserTable mode='global' 的行為應與本次改動前完全一致

    Example: global 模式下 resource 與 permanent filter 維持原樣
      Given 管理員 "Admin" 瀏覽 /admin/students 全局學員管理頁
      When UserTable 以 mode='global' 掛載（或省略 mode prop）
      Then UserTable 使用的 resource 應為 "users"
      And permanent filter 應為 [{ meta_keys: ['is_teacher', 'avl_courses'] }]
      And 加入按鈕與到期日 DatePicker 不應出現

    Example: global 模式下 canGrantCourseAccess=true 時既有管理功能應完整顯示
      Given 管理員 "Admin" 瀏覽 /admin/students 全局學員管理頁
      When UserTable 以 mode='global' 且 canGrantCourseAccess=true 掛載
      Then GrantCourseAccess 按鈕應顯示
      And 批次「更新觀看到期日」區塊應顯示
      And CSV 匯出按鈕應顯示
      And HistoryDrawer 應可被觸發
