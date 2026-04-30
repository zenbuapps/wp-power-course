@ignore @command @structure
Feature: 公告 CPT 結構

  公告使用 WordPress Custom Post Type `pc_announcement` 儲存。
  每則公告綁定到一門課程（post_parent 指向課程商品 ID），內容以 Power Editor 編輯。
  發佈起始時間使用 post_date（搭配 post_status=future 自動轉 publish），發佈結束時間以 post_meta 儲存。

  **設計決策（Issue #6）：**
  - 採用 CPT 而非自訂資料表，重用 Power Editor 整合與 wp_cron 排程
  - post_status='future' 自動轉 'publish'（WordPress 內建排程）
  - 結束時間以 meta `end_at` 儲存，前台查詢以 meta_query 過濾
  - 公告軟刪除（trash）支援還原

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== CPT 註冊 ==========

  Rule: pc_announcement 為非階層式（hierarchical=false）CPT

    Example: 註冊參數
      When WordPress 執行 "init" action
      Then register_post_type('pc_announcement', ...) 以下列參數執行：
        | key                     | value |
        | public                  | false |
        | hierarchical            | false |
        | show_in_rest            | true  |
        | supports                | title, editor, custom-fields, author |
        | rest_controller_class   | WP_REST_Posts_Controller |
      And supports 不包含 "page-attributes"（公告不需要 menu_order，依時間排序）

  Rule: pc_announcement 在正式環境（非 $is_local）隱藏 WP Admin UI

    Example: 正式環境
      Given Plugin::$is_local 為 false
      Then show_ui、show_in_menu、show_in_nav_menus、show_in_admin_bar 皆為 false
      And 公告僅能透過 Power Course React SPA 管理，不走 WP 原生編輯器

  # ========== 父子關係 ==========

  Rule: 公告的 post_parent 指向所屬課程的商品 ID

    Example: 建立公告時 post_parent 必須是課程
      Given 課程 100 下建立公告：
        | announcementId | post_parent | post_title           |
        | 300            | 100         | 雙十一限時五折優惠！ |
      Then 公告 300 的 post_parent 應為 100
      And 公告 300 的 parent_course_id meta 應為 100

  # ========== 發佈時間（post_date） ==========

  Rule: 發佈起始時間以 post_date 表示，狀態以 post_status 表示

    Example: 立即發佈的公告
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | post_parent | post_status | post_date           |
        | 立即公告   | 100         | publish     | 2026-04-29 10:00:00 |
      Then 公告的 post_status 應為 "publish"
      And 公告的 post_date 應為 "2026-04-29 10:00:00"

    Example: 排程發佈的公告（post_status=future）
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | post_parent | post_status | post_date           |
        | 排程公告   | 100         | future      | 2026-11-03 09:00:00 |
      Then 公告的 post_status 應為 "future"
      And WordPress 會於 post_date 到期時自動將狀態改為 "publish"（透過 wp_cron）

  # ========== 結束時間（post_meta: end_at） ==========

  Rule: 發佈結束時間以 post_meta 'end_at' 儲存（10 位 Unix timestamp）

    Example: 設定結束時間
      Given 公告 300 已建立
      When 管理員 "Admin" 更新公告 300 的 end_at 為 1762876800
      Then 公告 300 的 end_at meta 應為 1762876800

    Example: 不設結束時間（永久顯示）
      When 管理員 "Admin" 建立公告，參數如下：
        | post_title | post_parent | post_status | end_at |
        | 永久公告   | 100         | publish     |        |
      Then 公告的 end_at meta 應為空字串或 0
      And 公告永久顯示（無結束時間限制）

  # ========== 可見性（post_meta: visibility） ==========

  Rule: 可見性以 post_meta 'visibility' 儲存，值為 'public' 或 'enrolled'

    Example: 公開可見
      When 管理員 "Admin" 建立公告，visibility 設為 "public"
      Then 公告的 visibility meta 應為 "public"
      And 所有訪客（含未登入、未購）皆可看到此公告

    Example: 僅已購學員可見
      When 管理員 "Admin" 建立公告，visibility 設為 "enrolled"
      Then 公告的 visibility meta 應為 "enrolled"
      And 僅已被加入該課程的學員可看到此公告

    Example: 預設值為 public
      When 管理員 "Admin" 建立公告但未指定 visibility
      Then 公告的 visibility meta 應為 "public"

  # ========== 排序（依 post_date 由新到舊） ==========

  Rule: 公告依 post_date 由新到舊排序（DESC）

    Example: 多則公告排序
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_date           |
        | 300            | 公告 A     | 2026-04-01 10:00:00 |
        | 301            | 公告 B     | 2026-04-15 10:00:00 |
        | 302            | 公告 C     | 2026-04-20 10:00:00 |
      When 管理員呼叫 GET /announcements?parent_course_id=100
      Then 回應順序為 [302, 301, 300]
