@command @query
Feature: 多影片試看

  課程銷售頁的「課程試看」從單一影片擴展為多影片：
  Admin 可在課程編輯頁新增 1~6 部試看影片；前台在 1 部時直接顯示（與舊行為一致）、
  2~6 部時以 Swiper 輪播顯示（左右箭頭 + 分頁點，無 autoplay）。
  舊資料（單一 trial_video postmeta）採 lazy migration —— 讀取時動態包成陣列；
  儲存時統一寫入 trial_videos（JSON 陣列），同時刪除舊的 trial_video meta。
  對應 Issue #10。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          |
      | 1      | Admin   | admin@test.com    | administrator |
      | 2      | Student | student@test.com  | subscriber    |
    And 系統中有以下課程：
      | courseId | name           | _is_course | type   | status  |
      | 109      | PHP 基礎課     | yes        | simple | publish |
      | 110      | 訂閱制 SaaS    | yes        | simple | publish |
      | 111      | 外部 Hahow 課  | yes        | external | publish |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 多影片試看支援的 product type 含 simple / subscription / external

    Example: 站內 simple 課程支援多影片試看
      Given 課程 109 的 trial_videos 為：
        | type   | id       |
        | bunny  | bunny-1  |
        | youtube| yt-001   |
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 Footer 顯示「課程試看」標題
      And 頁面渲染 Swiper 輪播容器，包含 2 張投影片

    Example: 外部 external 課程同樣支援多影片試看
      Given 課程 111 的 trial_videos 為：
        | type   | id      |
        | bunny  | b-2     |
        | vimeo  | vm-001  |
      When 學員 "Student" 瀏覽課程銷售頁 111
      Then 頁面 Footer 顯示「課程試看」標題
      And 頁面渲染 Swiper 輪播容器，包含 2 張投影片

  # ========== 前置（參數）==========

  Rule: 前置（參數）- trial_videos 必須為陣列，最多 6 筆

    Example: 新增 6 部試看影片成功
      Given 課程 109 的 trial_videos 為空陣列
      When 管理員 "Admin" 更新課程 109，trial_videos 共 6 筆
      Then 操作成功
      And 課程 109 的 trial_videos 應有 6 筆

    Example: 新增 7 部試看影片失敗
      Given 課程 109 的 trial_videos 為空陣列
      When 管理員 "Admin" 更新課程 109，trial_videos 共 7 筆
      Then 操作失敗，HTTP 狀態為 400
      And 錯誤訊息包含「最多 6 部」

    Example: trial_videos 非陣列被拒絕
      When 管理員 "Admin" 更新課程 109，trial_videos 為單一物件 {"type":"bunny","id":"x"}
      Then 操作失敗，HTTP 狀態為 400

  Rule: 前置（參數）- trial_videos 中每一筆必須是合法的 VideoObject

    Example: 缺少 type 欄位的影片被拒絕
      When 管理員 "Admin" 更新課程 109，trial_videos 為：
        | id     |
        | xxx-1  |
      Then 操作失敗，HTTP 狀態為 400

    Example: type 為 none 的項目視為空，自動過濾
      When 管理員 "Admin" 更新課程 109，trial_videos 為：
        | type   | id    |
        | bunny  | b-1   |
        | none   |       |
      Then 操作成功
      And 課程 109 的 trial_videos 應有 1 筆
      And 課程 109 的 trial_videos[0].type 應為 "bunny"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 儲存 trial_videos 時，postmeta `trial_videos` 存為 JSON 陣列

    Example: 儲存 3 部試看影片
      Given 課程 109 的 trial_videos 為空陣列
      When 管理員 "Admin" 更新課程 109，trial_videos 為：
        | type    | id       |
        | bunny   | b-1      |
        | youtube | yt-001   |
        | vimeo   | vm-001   |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "trial_videos" 的 meta_value 應為 JSON 陣列且長度為 3
      And 課程 109 的 trial_videos[0].id 應為 "b-1"
      And 課程 109 的 trial_videos[1].id 應為 "yt-001"
      And 課程 109 的 trial_videos[2].id 應為 "vm-001"

  Rule: 後置（狀態）- 儲存 trial_videos 時，舊的單一 `trial_video` postmeta 必須被刪除

    Example: 升級舊課程：寫入 trial_videos 同時清除 trial_video
      Given 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 為 '{"type":"bunny","id":"old-1"}'
      And 課程 109 的 wp_postmeta 中無 "trial_videos" 欄位
      When 管理員 "Admin" 更新課程 109，trial_videos 為：
        | type   | id    |
        | bunny  | new-1 |
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "trial_videos" 的 meta_value 應為 JSON 陣列且長度為 1
      And 課程 109 的 wp_postmeta 中不應存在 "trial_video" 欄位

  Rule: 後置（狀態）- 全部清空時，trial_videos 存為空陣列且 trial_video meta 被刪除

    Example: 清空所有試看影片
      Given 課程 109 的 trial_videos 為 1 筆 bunny 影片
      When 管理員 "Admin" 更新課程 109，trial_videos 為空陣列
      Then 操作成功
      And 課程 109 的 wp_postmeta 中 "trial_videos" 的 meta_value 應為 "[]"
      And 課程 109 的 wp_postmeta 中不應存在 "trial_video" 欄位

  # ========== 向下相容（讀取）==========

  Rule: 向下相容 - 讀取時若僅有舊 `trial_video`，自動轉為 `trial_videos: [trial_video]`

    Example: 舊課程 GET 回傳轉換後的陣列
      Given 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 為 '{"type":"bunny","id":"legacy-1"}'
      And 課程 109 的 wp_postmeta 中無 "trial_videos" 欄位
      When 管理員 "Admin" 取得課程 109 詳情
      Then 操作成功
      And 回應 trial_videos 應為長度 1 的陣列
      And 回應 trial_videos[0].type 應為 "bunny"
      And 回應 trial_videos[0].id 應為 "legacy-1"

    Example: 舊 trial_video.type 為 none 視為無試看影片
      Given 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 為 '{"type":"none","id":""}'
      And 課程 109 的 wp_postmeta 中無 "trial_videos" 欄位
      When 管理員 "Admin" 取得課程 109 詳情
      Then 操作成功
      And 回應 trial_videos 應為空陣列

    Example: 同時存在新舊 meta 時，trial_videos 優先
      Given 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 為 '{"type":"bunny","id":"legacy-1"}'
      And 課程 109 的 wp_postmeta 中 "trial_videos" 的 meta_value 為 '[{"type":"youtube","id":"new-1"}]'
      When 管理員 "Admin" 取得課程 109 詳情
      Then 回應 trial_videos 應為長度 1 的陣列
      And 回應 trial_videos[0].id 應為 "new-1"

  # ========== 前台渲染 ==========

  Rule: 前台渲染 - 0 部試看影片時，整個「課程試看」區塊不渲染

    Example: 無試看影片
      Given 課程 109 的 trial_videos 為空陣列
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 Footer 不應出現「課程試看」標題
      And 頁面不應渲染試看影片容器

  Rule: 前台渲染 - 1 部試看影片時，直接渲染影片，不載入 Swiper

    Example: 單部影片直接顯示
      Given 課程 109 的 trial_videos 為 1 筆 bunny 影片
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 Footer 顯示「課程試看」標題
      And 頁面渲染單一 .video-player 元素
      And 頁面不應載入 Swiper CSS / JS
      And 頁面不應出現分頁點 (.swiper-pagination)
      And 頁面不應出現左右箭頭 (.swiper-button-prev / .swiper-button-next)

  Rule: 前台渲染 - 2~6 部試看影片時，以 Swiper 輪播渲染

    Example: 3 部影片以 Swiper 渲染
      Given 課程 109 的 trial_videos 為 3 筆影片（bunny + youtube + vimeo）
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 Footer 顯示「課程試看」標題
      And 頁面渲染 .swiper 容器，內含 3 張 .swiper-slide
      And 頁面顯示分頁點，共 3 個 (.swiper-pagination-bullet)
      And 頁面顯示左右箭頭 (.swiper-button-prev / .swiper-button-next)
      And 頁面載入 Swiper CSS 與 JS（條件式 enqueue）
      And Swiper 初始化參數 autoplay 應為 false

    Example: Swiper 切換 slide 時前一部影片自動暫停
      Given 課程 109 的 trial_videos 為 2 筆 bunny 影片
      And 學員正在播放第 1 張 slide 的影片
      When 學員點擊右箭頭切換至第 2 張 slide
      Then 第 1 張 slide 的影片應自動暫停
      And 第 2 張 slide 的影片進入 ready 狀態（不自動播放）

  Rule: 前台渲染 - Swiper CSS/JS 採條件式載入（僅 trial_videos 數量 ≥ 2 時）

    Example: 1 部影片不載入 Swiper
      Given 課程 109 的 trial_videos 為 1 筆影片
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 HTML 不應包含 swiper.min.css 的 <link> 標籤
      And 頁面 HTML 不應包含 swiper bundle JS 的 <script> 標籤

    Example: 2 部影片才載入 Swiper
      Given 課程 109 的 trial_videos 為 2 筆影片
      When 學員 "Student" 瀏覽課程銷售頁 109
      Then 頁面 HTML 應包含 swiper CSS 的 <link> 標籤
      And 頁面 HTML 應包含 swiper JS 的 <script> 標籤

  # ========== 後台管理介面 ==========

  Rule: 後台管理 - 課程編輯頁的試看影片區塊以 Form.List 呈現，支援新增/刪除/拖拉排序

    Example: 開啟舊課程編輯頁，舊 trial_video 顯示為列表第一筆
      Given 課程 109 的 wp_postmeta 中 "trial_video" 的 meta_value 為 '{"type":"bunny","id":"legacy-1"}'
      And 課程 109 的 wp_postmeta 中無 "trial_videos" 欄位
      When 管理員 "Admin" 開啟課程 109 的編輯頁
      Then 「課程試看影片」區塊顯示 1 筆影片
      And 第 1 筆影片的 id 為 "legacy-1"

    Example: 已有 6 部影片時新增按鈕 disabled
      Given 課程 109 的 trial_videos 為 6 筆影片
      When 管理員 "Admin" 開啟課程 109 的編輯頁
      Then 「新增試看影片」按鈕應為 disabled 狀態
      And 按鈕旁顯示提示文字「最多可新增 6 部」

    Example: 拖拉排序後儲存，順序應持久化
      Given 課程 109 的 trial_videos 為：
        | type    | id    |
        | bunny   | a     |
        | youtube | b     |
        | vimeo   | c     |
      When 管理員 "Admin" 將第 1 筆拖至第 3 位後儲存
      Then 操作成功
      And 課程 109 的 trial_videos[0].id 應為 "b"
      And 課程 109 的 trial_videos[1].id 應為 "c"
      And 課程 109 的 trial_videos[2].id 應為 "a"
