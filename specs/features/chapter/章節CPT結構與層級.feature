@ignore @command @structure
Feature: 章節 CPT 結構與層級

  章節使用 WordPress Custom Post Type `pc_chapter` 儲存，支援巢狀階層與父子關係。

  **Code source:**
  - `inc/classes/Resources/Chapter/Core/CPT.php`
  - `inc/classes/Resources/Chapter/Model/Chapter.php`
  - `inc/classes/Resources/Chapter/Core/LifeCycle.php`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== CPT 註冊 ==========

  Rule: pc_chapter 為階層式（hierarchical）CPT，支援巢狀父子關係

    Example: 註冊參數
      When WordPress 執行 "init" action
      Then register_post_type('pc_chapter', ...) 以下列參數執行：
        | key                     | value |
        | public                  | true  |
        | hierarchical            | true  |
        | show_in_rest            | true  |
        | supports                | title, editor, thumbnail, custom-fields, author, page-attributes |
        | rest_controller_class   | WP_REST_Posts_Controller |
        | rewrite.slug            | classroom |
      And supports 包含 "page-attributes"，啟用 menu_order 排序

  Rule: pc_chapter 在正式環境（非 $is_local）隱藏 WP Admin UI

    Example: 正式環境
      Given Plugin::$is_local 為 false
      Then show_ui、show_in_menu、show_in_nav_menus、show_in_admin_bar 皆為 false
      And 章節僅能透過 Power Course React SPA 管理，不走 WP 原生編輯器

  # ========== 父子關係 ==========

  Rule: 章節的 post_parent 指向上層章節，最頂層章節的 post_parent 為課程商品 ID

    Example: 兩層結構
      Given 課程 100 下建立章節：
        | chapterId | post_parent | post_title |
        | 200       | 100         | 第一章     |
        | 201       | 200         | 1-1 小節   |
        | 202       | 200         | 1-2 小節   |
        | 203       | 100         | 第二章     |
      Then 章節 200 與 203 為頂層章節（post_parent = 100）
      And 章節 201、202 為章節 200 的子章節
      And Chapter Model 的 course_id 透過 Utils::get_course_id 遞迴向上尋找，結果均為 100

  Rule: 章節可無限巢狀（WordPress hierarchical CPT 無層數限制）

    Example: 三層結構
      Given 章節 201 下再建立章節 301（post_parent = 201）
      Then 章節 301 的 parent_course_id 仍為 100（遞迴向上）
      And Chapter 表現為合法的章節，可上傳影片、字幕、設定進度

  # ========== 排序（menu_order） ==========

  Rule: 章節列表按 menu_order ASC、ID ASC、date ASC 三階排序

    Example: 查詢章節列表
      Given 課程 100 下有章節：
        | chapterId | menu_order | date                |
        | 200       | 0          | 2026-04-01 10:00:00 |
        | 201       | 1          | 2026-04-02 10:00:00 |
        | 202       | 0          | 2026-04-03 10:00:00 |
      When 管理員呼叫 GET /chapters?post_parent=100
      Then 回應順序為 [200, 202, 201]
      And 200 與 202 的 menu_order 同為 0，依 ID 升序

  Rule: chapters/sort API 可同時更新巢狀位置（post_parent）與順序（menu_order）

    Example: 拖曳排序
      When 管理員拖曳章節 202 移至章節 200 之下，並在 201 之前
      And 呼叫 POST /chapters/sort，body 含 from_tree 與 to_tree
      Then ChapterUtils::sort_chapters 逐筆比對差異並 wp_update_post
      And 章節 202 的 post_parent 從 100 改為 200
      And 202 的 menu_order 調整為 0（201 之前）

  # ========== REST API 快取控制（Issue #216 Bug #1b）==========

  Rule: power-course namespace 的 REST API 必須回傳 nocache 標頭，避免被邊緣快取（LiteSpeed / WP Rocket / Cloudflare）

    Example: GET /chapters 帶 nocache 標頭
      When 管理員呼叫 GET /wp-json/power-course/chapters?post_parent=100
      Then 回應 HTTP header 包含 "Cache-Control: no-cache, must-revalidate, max-age=0, no-store"
      And 回應 HTTP header 包含 "Expires: Wed, 11 Jan 1984 05:00:00 GMT"
      And 此標頭由 nocache_headers() 於 REST 端點 callback 內注入
      And 同樣規則套用至 POST /chapters/sort、PUT /chapters/{id}、DELETE /chapters 等端點

  # ========== 刪除 ==========

  Rule: 刪除父章節時，WordPress 會遞迴刪除所有子章節（wp_trash_post 行為）

    Example: 刪除章節 200
      Given 章節 200 有子章節 201、202
      When 管理員呼叫 DELETE /chapters/{id} 或 DELETE /chapters（批次）
      Then wp_trash_post(200) 觸發 WordPress 遞迴 trash 子 posts
      And 章節 201、202 同步被 trash
      And 批次刪除程式中會先檢查 post status 為 trash 則視為成功（避免 wp_trash_post 對已 trash 的 post 回傳 false 誤判）

  # ========== 學員進度存儲 ==========

  Rule: 學員的章節進度寫入 pc_avl_chaptermeta（自訂表，非 postmeta）

    Example: 首次進入章節
      Given Alice 已被加入課程 100
      When Alice 進入章節 201 頁面
      Then power_course_visit_chapter action 觸發
      And pc_avl_chaptermeta(post_id=201, user_id=10, meta_key=first_visit_at) 寫入目前時間（若尚未存在）
      And StudentLog 寫入 log_type=chapter_enter

  Rule: 章節完成時間同樣儲存於 pc_avl_chaptermeta

    Example: 手動或自動完成章節
      When Alice 完成章節 201
      Then pc_avl_chaptermeta(post_id=201, user_id=10, meta_key=finished_at) 寫入完成時間
      And StudentLog 寫入 log_type=chapter_finish
      And 若整個課程進度達 100%，觸發 power_course_course_finished action

  # ========== Compatibility ==========

  Rule: 章節支援 Elementor 編輯器（透過 elementor_cpt_support filter）

    Example: Elementor 啟用時
      When option_elementor_cpt_support filter 執行
      Then pc_chapter 被加入支援的 CPT 清單
      And 章節編輯頁可使用 Elementor 視覺編輯

  Rule: 儲存章節時，若 editor=power-editor 會自動清除 Elementor meta

    Example: 從 Elementor 切換回 Power Editor
      Given 章節 201 曾使用 Elementor 編輯（_elementor_ 開頭的 post_meta 存在）
      When 管理員將 chapter.editor 改為 "power-editor" 並儲存
      Then LifeCycle::delete_elementor_data 執行
      And 所有 _elementor_ 開頭的 post_meta 被刪除
