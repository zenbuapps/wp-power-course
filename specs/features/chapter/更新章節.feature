@ignore @command
Feature: 更新章節

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 有以下章節：
      | chapterId | post_title | post_parent | chapter_video_type | chapter_length |
      | 200       | 第一章     | 100         | bunny              | 600            |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 章節必須存在且 post_type 為 pc_chapter

    Example: 不存在的章節更新失敗
      When 管理員 "Admin" 更新章節 9999，參數如下：
        | post_title |
        | 新名稱     |
      Then 操作失敗，錯誤為「章節不存在」

  # ========== 前置（參數）==========

  Rule: 前置（參數）- id 不可為空

    Example: 未提供章節 ID 時更新失敗
      When 管理員 "Admin" 更新章節 ""，參數如下：
        | post_title |
        | 新名稱     |
      Then 操作失敗，錯誤訊息包含 "id"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 更新對應章節的 post 及 meta 資料

    Example: 成功更新章節標題和影片
      When 管理員 "Admin" 更新章節 200，參數如下：
        | post_title   | chapter_video_type | chapter_length | enable_comment |
        | 第一章（更新）| youtube            | 900            | no             |
      Then 操作成功
      And 章節 200 的 post_title 應為 "第一章（更新）"
      And 章節 200 的 chapter_video_type 應為 "youtube"
      And 章節 200 的 chapter_length 應為 900

  # ========== 後置（父子關係保留，Issue #216 Bug #2 修復）==========

  Rule: 後置（狀態）- 編輯子章節時 post_parent 必須保留，不得被重置為 0
    本規則修復 Issue #216 Bug #2：將章節拖入另一章節成為子章節後，
    再編輯該子章節（例如修改標題），儲存後 post_parent 不得從父章節 ID 變回 0。
    根因為 sort_chapters 用 raw SQL 寫入 post_parent 後未清除 object cache，
    導致 wp_insert_post_data filter 中的 wp_get_post_parent_id 讀到 stale 值。
    修復方式為在 sort_chapters 內呼叫 clean_post_cache（見「排序章節.feature」）。

    Example: 子章節編輯標題後 post_parent 持久存在
      Given 章節 201 已透過拖曳成為章節 200 的子章節（post_parent = 200）
      When 管理員 "Admin" 更新章節 201，參數如下：
        | post_title       |
        | 1-1 小節（更新） |
      Then 操作成功
      And 章節 201 的 post_title 應為 "1-1 小節（更新）"
      And 章節 201 的 post_parent 應為 200（不可被重置為 0）
      And 章節 201 仍位於章節 200 的巢狀結構之下

    Example: 三層結構編輯子節點後父子關係完整保留
      Given 課程 100 有以下三層章節：
        | chapterId | post_title | post_parent |
        | 200       | 第一章     | 100         |
        | 201       | 1-1 小節   | 200         |
        | 301       | 1-1-1 子節 | 201         |
      When 管理員 "Admin" 更新章節 301 的 post_title 為 "1-1-1 子節（編輯）"
      Then 操作成功
      And 章節 301 的 post_parent 應為 201
      And 章節 201 的 post_parent 應為 200
      And 整體巢狀結構完整保留

    Example: 排序後立刻編輯（不經 reload）父子關係仍正確
      Given 管理員 "Admin" 剛將章節 202 拖曳成為章節 200 的子節點
      And 排序 API 已回傳成功
      When 管理員 "Admin" 在同一個 session 內立刻編輯章節 202 的 post_title
      Then 操作成功
      And 章節 202 的 post_parent 應仍為 200
      And 不會因 object cache stale 而被重置為 0

  # ========== 後置（前端 UI，Issue #216 Q5）==========

  Rule: 後置（UI）- 編輯子章節儲存後須重新讀取章節列表，並自動展開父章節給予視覺確認

    Example: 編輯子章節後父章節自動展開
      Given 管理員開啟課程 100 編輯頁，章節列表已展開章節 200
      And 章節 201（post_parent = 200）位於章節 200 之下
      When 管理員 "Admin" 點擊章節 201、修改標題、按儲存
      Then 章節列表 invalidate 並重新讀取
      And 章節 200 在 SortableTree 中保持展開狀態（collapsed = false）
      And 使用者可立刻在原位置看到編輯後的章節 201

    Example: 編輯後若資料尚未到達 UI 不可閃爍消失
      Given 管理員 "Admin" 編輯子章節 201
      When 儲存 API 進行中
      Then 章節列表 loading 期間 SortableTree 區域 pointer-events 設為 none
      And 章節 201 不會從巢狀結構中暫時消失
