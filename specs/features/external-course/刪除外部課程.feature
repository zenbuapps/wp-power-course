@ignore @command
Feature: 刪除外部課程

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | product_url                    |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 課程必須存在

    Example: 不存在的課程刪除失敗
      When 管理員 "Admin" 刪除外部課程 9999
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功刪除外部課程（移至回收桶）

    Example: 成功刪除外部課程
      When 管理員 "Admin" 刪除外部課程 200
      Then 操作成功
      And 課程 200 的 status 應為 "trash"

  Rule: 後置（狀態）- 刪除外部課程不影響站內課程

    Given 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  |
      | 100      | PHP 基礎課 | yes        | simple | publish |

    Example: 刪除外部課程後站內課程不受影響
      When 管理員 "Admin" 刪除外部課程 200
      Then 操作成功
      And 課程 100 的 status 應為 "publish"
