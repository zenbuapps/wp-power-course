@ignore @ui @frontend
Feature: 課程學員列表批次切換顯示全部課程

  作為 課程編輯頁的管理員
  我希望 透過欄位表頭的單一 Switch 一次切換「所有學員 row 是否展開全部已授權課程」
  以便 不需逐一切換 row 內的 switch，快速從「只看本課程」與「綜覽全部課程」兩種視角切換

  Background:
    Given 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | React 進階 | yes        | publish |
    And 系統中有以下用戶：
      | userId | name  | email           | role       |
      | 2      | Alice | alice@test.com  | subscriber |
      | 3      | Bob   | bob@test.com    | subscriber |
    And 用戶 "Alice" 已被加入課程 100，expire_date 0
    And 用戶 "Alice" 已被加入課程 101，expire_date 0
    And 用戶 "Bob" 已被加入課程 100，expire_date 0
    And 管理員 "Admin" 進入課程編輯頁 "課程學員" TAB，URL 為 "/wp-admin/admin.php?page=power-course#/courses/edit/100"

  # ========== 初始狀態 ==========

  Rule: 初始進入頁面時，欄位表頭的 Switch 預設關閉（OFF）

    Example: 預設只顯示本課程
      Then 表格欄位 "Granted courses" 的表頭應顯示 "Show all" 文字與 Switch 元件
      And 該 Switch 的狀態為 OFF
      And 用戶 "Alice" 的 row 在 "Granted courses" 欄位中應僅顯示課程 100 "PHP 基礎課"
      And 用戶 "Alice" 的 row 在 "Granted courses" 欄位中應**不**顯示課程 101 "React 進階"
      And 用戶 "Bob" 的 row 在 "Granted courses" 欄位中應僅顯示課程 100 "PHP 基礎課"

  Rule: 個別 row 內不再渲染獨立 Switch

    Example: 整個表格中只有一個 "Show all" Switch
      Then 表格 DOM 中 `role="switch"` 元素數量應為 1
      And 該 Switch 位於 "Granted courses" 欄位的表頭（th）內

  # ========== 切換行為 ==========

  Rule: 切換 Switch 為 ON 時，全部 user row 同步展開「全部已授權課程」

    Example: 開啟 Switch 後，所有 user 都展開
      When 管理員點擊 "Granted courses" 表頭的 "Show all" Switch
      Then 該 Switch 的狀態為 ON
      And 用戶 "Alice" 的 row 在 "Granted courses" 欄位中應同時顯示課程 100 與課程 101
      And 用戶 "Bob" 的 row 在 "Granted courses" 欄位中應僅顯示課程 100（因為 Bob 本來就只被授權課程 100）

  Rule: 再次切換 Switch 為 OFF 時，全部 user row 收回「只看本課程」

    Example: 關閉 Switch 後回到預設
      Given 管理員已點擊 "Show all" Switch 開啟為 ON
      When 管理員再次點擊 "Show all" Switch
      Then 該 Switch 的狀態為 OFF
      And 用戶 "Alice" 的 row 在 "Granted courses" 欄位中應僅顯示課程 100
      And 用戶 "Bob" 的 row 在 "Granted courses" 欄位中應僅顯示課程 100

  # ========== 狀態持久性 ==========

  Rule: Switch 狀態僅本次頁面停留有效，不寫入持久性儲存

    Example: 重新整理後 Switch 重置為 OFF
      Given 管理員已點擊 "Show all" Switch 開啟為 ON
      When 管理員重新整理頁面
      Then 該 Switch 的狀態為 OFF

    Example: 切換到別的 TAB 再回來，Switch 重置為 OFF
      Given 管理員已點擊 "Show all" Switch 開啟為 ON
      When 管理員切換到其他 TAB（例如「課程內容」）
      And 管理員再切回 "課程學員" TAB
      Then 該 Switch 的狀態為 OFF

    Example: 切換到別堂課程的 Edit 頁，Switch 重置為 OFF
      Given 管理員已點擊 "Show all" Switch 開啟為 ON
      When 管理員導航到 "/wp-admin/admin.php?page=power-course#/courses/edit/101"
      And 管理員點擊 "課程學員" TAB
      Then 該 Switch 的狀態為 OFF

  # ========== UI 規格 ==========

  Rule: Switch 應顯示為 small size，前置 "Show all" 文字並提供 Tooltip

    Example: 滑鼠 hover 顯示 Tooltip
      When 管理員將滑鼠移到 "Show all" 文字或 Switch 上
      Then 應顯示 Tooltip "Show all courses granted to the user"

    Example: Switch 視覺尺寸為 small
      Then "Show all" Switch 元件應套用 antd Switch 的 `size="small"` 樣式

  # ========== 跨頁面影響範圍 ==========

  Rule: `/admin/students` 全局學員管理頁的 "Granted courses" 欄位**不顯示** Switch

    Example: 全局學員頁無 Switch 控制
      Given 管理員 "Admin" 進入頁面 "/wp-admin/admin.php?page=power-course#/students"
      Then 表格欄位 "Granted courses" 的表頭應**不**顯示 Switch
      And 用戶 "Alice" 的 row 在 "Granted courses" 欄位中應同時顯示課程 100 與課程 101（永遠展開全部）

  Rule: `/teachers/edit/{teacher_id}` Learning TAB 行為完全不變

    Example: Teacher 頁 Learning TAB 永遠展開全部
      Given 用戶 "Alice" 也是講師（meta `is_teacher = yes`）
      And 管理員 "Admin" 進入頁面 "/wp-admin/admin.php?page=power-course#/teachers/edit/2"
      When 管理員點擊 "Learning" TAB
      Then 該 TAB 應展開顯示用戶 Alice 全部的 avl_courses（課程 100 與課程 101）
      And 該 TAB 中**不**應出現 "Show all" Switch
