@ignore @command
Feature: 刪除課程權限包

  # 刪除（delete）= 真正收回已購用戶的觀看權限。
  # API 需要 confirm 旗標作為二次確認；UI 在確認前顯示「將影響 N 位已購用戶」（見 specs/ui/課程權限包管理頁.md）。
  # 刪除權限包不可動到「單獨購買」或「逐課綁定」寫入 avl_course_ids 的課程權限（OR 疊加，互不影響）。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role          |
      | 1      | Admin   | admin@test.com   | administrator |
      | 2      | Student | student@test.com | subscriber    |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
      | 200      | PHP 基礎課  | yes        | publish |
    And 系統中有以下課程權限包：
      | passId | name      | scope_type | limit_mode | status | term_ids |
      | 301    | HTML 權限 | category   | permanent  | active | [10]     |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 被刪除的權限包必須存在

    Example: 刪除不存在的權限包時操作失敗
      When 管理員 "Admin" 刪除課程權限包 9999，確認旗標為 true
      Then 操作失敗

  # ========== 前置（參數）==========

  Rule: 前置（參數）- 刪除需帶二次確認旗標

    Example: 未帶確認旗標刪除時操作失敗
      When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 false
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 確認後成功刪除權限包

    Example: 帶確認旗標成功刪除權限包
      When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
      Then 操作成功
      And 權限包 301 應不存在

  Rule: 後置（狀態）- 刪除後已購用戶失去該權限包涵蓋的觀看權

    Example: 刪除權限包後已購學員失去範圍內課程觀看權
      Given 學員 "Student" 已透過權限包 301 取得課程 100 的觀看權限
      When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
      Then 操作成功
      And 學員 "Student" 對課程 100 的觀看權限應為「不可觀看」

  Rule: 後置（狀態）- 刪除權限包不影響使用者單獨購買的課程權限

    Example: 刪除分類權限包後，單獨購買的課程仍可觀看
      Given 學員 "Student" 已單獨購買課程 200（寫入 avl_course_ids）
      And 學員 "Student" 已透過權限包 301 取得課程 100 的觀看權限
      When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
      Then 操作成功
      And 學員 "Student" 對課程 200 的觀看權限應為「可觀看」
