@ignore @command
Feature: 排序章節

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | menu_order |
      | 200       | 第一章     | 100         | 1          |
      | 201       | 第二章     | 100         | 2          |
      | 202       | 第三章     | 100         | 3          |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 所有章節 ID 必須存在

    Example: 包含不存在的章節 ID 時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 200       | 1          |
        | 9999      | 2          |
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- chapters 不可為空陣列

    Example: 未提供排序資料時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
      Then 操作失敗，錯誤訊息包含 "chapters"

  Rule: 前置（參數）- 每項必須包含有效的 id 與 menu_order

    Example: 缺少 menu_order 時操作失敗
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 200       |            |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 批量更新所有章節的 menu_order

    Example: 成功重新排序章節
      When 管理員 "Admin" 排序章節，參數如下：
        | chapterId | menu_order |
        | 202       | 1          |
        | 200       | 2          |
        | 201       | 3          |
      Then 操作成功
      And 章節 202 的 menu_order 應為 1
      And 章節 200 的 menu_order 應為 2
      And 章節 201 的 menu_order 應為 3

  # ========== 後置（Cache 失效，Issue #216 Bug #2 根因修復）==========

  Rule: 後置（Cache）- raw SQL 更新後必須對每個被異動的章節呼叫 clean_post_cache，避免 WP object cache 殘留 stale 值
    本規則修復 Issue #216 Bug #2：sort_chapters 用 raw $wpdb->query() 寫入 post_parent，
    若不主動清除 object cache，後續 wp_get_post_parent_id() 會回傳更新前的舊值（0），
    導致 wp_insert_post_data filter 在編輯子章節時誤判 parent 不存在而清空 post_parent。

    Example: 排序後立刻讀取 parent 必須拿到最新值
      Given 章節 202 的 post_parent 原本為 100（頂層）
      When 管理員 "Admin" 將章節 202 拖曳成為章節 200 的子章節（post_parent 改為 200）
      Then 操作成功
      And ChapterUtils::sort_chapters 對所有被異動的 post_id 呼叫 clean_post_cache
      And 立刻呼叫 wp_get_post_parent_id(202) 必須回傳 200（不是 stale 的 100）
      And clean_post_cache action 觸發，下游外掛掛勾可正常收到通知

    Example: 大量章節分批排序時每一批都要清除 cache
      Given 課程 100 下有 60 個章節（超過 batch_size = 50）
      When 管理員 "Admin" 對全部 60 個章節重新排序
      Then 每一個 batch 完成 UPDATE 後即執行 clean_post_cache
      And TRANSACTION COMMIT 後所有 60 個章節的 cache 都已清除

  # ========== 後置（REST 標頭，Issue #216 Bug #1b）==========

  Rule: 後置（REST）- power-course REST API 必須回傳 no-store 標頭，避免被 LiteSpeed / Cloudflare 等邊緣快取
    本規則修復 Issue #216 Bug #1b：排序成功後 refetch chapters 仍可能拿到 LiteSpeed 邊緣快取的舊資料。

    Example: 章節列表 API 回應帶 nocache 標頭
      When 管理員 "Admin" 呼叫 GET /wp-json/power-course/chapters
      Then HTTP response header 包含 "Cache-Control: no-cache, must-revalidate, max-age=0, no-store"
      And LiteSpeed Cache 與 WP Rocket 等外掛不會快取此回應

    Example: 排序 API 回應帶 nocache 標頭
      When 管理員 "Admin" 呼叫 POST /wp-json/power-course/chapters/sort
      Then HTTP response header 包含 "Cache-Control: no-store"

  # ========== 後置（巢狀層數覆蓋，Issue #216 Q7）==========

  Rule: 後置（巢狀）- 排序與 cache 失效須覆蓋全部 5 層巢狀（MAX_DEPTH = 5）

    Example: 三層結構排序後 cache 一致
      Given 課程 100 有以下三層章節結構：
        | chapterId | post_title | post_parent |
        | 200       | 第一章     | 100         |
        | 201       | 1-1 小節   | 200         |
        | 301       | 1-1-1 子節 | 201         |
      When 管理員 "Admin" 將章節 301 拖曳到章節 200 之下（成為章節 200 的直接子節點）
      Then 操作成功
      And 章節 301 的 post_parent 應為 200
      And clean_post_cache(301) 已被呼叫
      And wp_get_post_parent_id(301) 回傳 200

    Example: 五層結構（達 MAX_DEPTH 上限）排序合法
      Given 課程 100 有 5 層巢狀章節（depth 0 到 depth 4）
      When 管理員 "Admin" 在第 5 層內重新排序
      Then 操作成功
      And SortableTree 的 sortableRule 不會誤判超過深度

    Example: 嘗試拖曳超過 MAX_DEPTH 的操作被拒絕
      Given 課程 100 有 5 層巢狀章節
      When 管理員 "Admin" 嘗試將節點拖曳成第 6 層
      Then 操作失敗，前端顯示錯誤訊息 "Exceeded max depth, operation failed"
      And 不發送 POST /chapters/sort 請求

  # ========== 後置（前端 UI）==========

  Rule: 後置（UI）- 排序成功後 SortableTree 需以最新 React state 重新初始化（Issue #216 Bug #1a）
    避免 @ant-design/pro-editor 的 SortableTree 內部 state 與 React state 脫節，
    導致下次拖曳用舊 from_tree 計算 diff 而失效。

    Example: 排序成功後 SortableTree 重新 mount
      Given 管理員開啟課程編輯頁的章節 tab
      When 管理員拖曳章節並儲存成功
      Then 前端使用 <SortableTree key={treeVersion}> 並遞增 treeVersion
      And SortableTree unmount 後 remount，從最新 React state 重新初始化內部 state
      And 下次拖曳的 from_tree 反映實際資料庫狀態

  Rule: 後置（UI）- 排序失敗時章節順序自動還原為拖曳前位置（Issue #216 Q4）

    Example: 後端 transaction rollback 時前端還原
      Given 管理員拖曳章節 202 成為章節 200 的子節點
      When POST /chapters/sort 回傳錯誤（如資料庫鎖定 / 權限不足）
      Then 前端顯示紅色錯誤通知 "Failed to save sort order"
      And 章節列表視覺位置還原為拖曳前的 originTree
      And 不保留拖曳後的視覺位置（避免使用者誤以為已成功）
