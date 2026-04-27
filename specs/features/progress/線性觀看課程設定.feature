@ignore @command
Feature: 線性觀看課程設定

  管理員可以在每門課程的後台設定中，開啟或關閉「線性觀看」功能。
  設定儲存為 product meta `enable_linear_viewing`（`'yes'` / `'no'`），預設為 `'no'`。

  Background:
    Given 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
      | 101      | JS 進階課  | yes        | publish |

  # ========== 預設值 ==========

  Rule: 新課程預設關閉線性觀看

    Example: 新建課程的 enable_linear_viewing 為 'no'
      Given 課程 100 未設定 enable_linear_viewing meta
      When 系統讀取課程 100 的 enable_linear_viewing
      Then 值為 'no'（預設）

  # ========== 啟用線性觀看 ==========

  Rule: 管理員可啟用課程的線性觀看

    Example: 啟用線性觀看後 meta 存為 'yes'
      Given 用戶 "Admin" 具有 manage_woocommerce 權限
      When 管理員透過課程設定 API 更新課程 100，body 包含 enable_linear_viewing: 'yes'
      Then 課程 100 的 postmeta enable_linear_viewing 為 'yes'
      And API 回傳成功

  Rule: 管理員可關閉課程的線性觀看

    Example: 關閉線性觀看後 meta 存為 'no'
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      When 管理員透過課程設定 API 更新課程 100，body 包含 enable_linear_viewing: 'no'
      Then 課程 100 的 postmeta enable_linear_viewing 為 'no'

  # ========== 啟用/關閉對學員的影響 ==========

  Rule: 啟用後學員既有完成紀錄仍然有效

    Example: 學員在自由模式下完成 1-1 和 1-3，啟用線性觀看後紀錄保留
      Given 課程 100 有以下章節（按 menu_order 平攤順序）：
        | chapterId | post_title   |
        | 300       | 第一單元     |
        | 301       | 1-1 PHP 簡介 |
        | 302       | 1-2 變數型別 |
        | 303       | 1-3 流程控制 |
      And 用戶 "Alice" 在以下章節已完成：
        | chapterId |
        | 301       |
        | 303       |
      And 課程 100 的 enable_linear_viewing 為 'no'
      When 管理員啟用課程 100 的線性觀看
      Then 用戶 "Alice" 的完成紀錄不受影響
      And 以最遠進度模式（最遠到 303，位置 3）計算，解鎖範圍為 [300, 301, 302, 303, 400]

  Rule: 關閉後所有章節恢復自由存取

    Example: 關閉線性觀看後學員可自由存取
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      When 管理員關閉課程 100 的線性觀看
      Then 所有學員可自由存取課程 100 的所有章節
      And 行為與未啟用線性觀看時完全一致

  # ========== 章節排序變更警告 ==========

  Rule: 啟用線性觀看的課程在調整章節排序時顯示警告

    Example: 管理員調整已啟用線性觀看課程的排序
      Given 課程 100 的 enable_linear_viewing 為 'yes'
      When 管理員在課程 100 的後台拖曳調整章節排序
      Then 在儲存排序的 API 回應中包含警告訊息
      And 警告內容為「此課程已啟用線性觀看，調整排序將影響學員的章節解鎖狀態」

    Example: 未啟用線性觀看的課程調整排序無警告
      Given 課程 101 的 enable_linear_viewing 為 'no'
      When 管理員在課程 101 的後台拖曳調整章節排序
      Then API 回應中不包含線性觀看警告

  # ========== 外部課程不適用 ==========

  Rule: 外部課程（external type）不適用線性觀看

    Example: 外部課程無法設定線性觀看
      Given 系統中有以下課程：
        | courseId | name       | _is_course | status  | type     |
        | 200      | 外部課程   | yes        | publish | external |
      When 管理員嘗試設定課程 200 的 enable_linear_viewing 為 'yes'
      Then 設定不生效（外部課程無章節，功能無意義）
