@ignore @constraint
Feature: 外部課程功能隔離

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下外部課程：
      | courseId | name             | _is_course | type     | status  | product_url                    |
      | 200      | Python 資料科學  | yes        | external | publish | https://hahow.in/courses/12345 |
    And 系統中有以下站內課程：
      | courseId | name       | _is_course | type   | status  |
      | 100      | PHP 基礎課 | yes        | simple | publish |

  # ========== 營收報表隔離 ==========

  Rule: 外部課程不出現在營收報表中

    Example: 營收報表不包含外部課程
      When 管理員 "Admin" 查詢營收報表
      Then 報表的課程篩選選項不應包含 "Python 資料科學"
      And 報表的課程篩選選項應包含 "PHP 基礎課"

  # ========== 學員管理隔離 ==========

  Rule: 外部課程不出現在學員管理的課程篩選中

    Example: 學員管理的課程篩選不包含外部課程
      When 管理員 "Admin" 查詢學員列表的課程篩選選項
      Then 篩選選項不應包含 "Python 資料科學"
      And 篩選選項應包含 "PHP 基礎課"

  Rule: 外部課程不可新增學員

    Example: 嘗試為外部課程新增學員時失敗
      When 管理員 "Admin" 嘗試為課程 200 新增學員 userId 20
      Then 操作失敗

  # ========== 自動郵件隔離 ==========

  Rule: 外部課程不可被選為自動郵件的目標課程

    Example: 郵件模板的課程選擇器不包含外部課程
      When 管理員 "Admin" 查詢可選的郵件觸發目標課程
      Then 選項不應包含 "Python 資料科學"（courseId: 200）
      And 選項應包含 "PHP 基礎課"（courseId: 100）

  Rule: 嘗試建立以外部課程為目標的郵件模板時失敗

    Example: 為外部課程建立郵件模板時被阻擋
      When 管理員 "Admin" 建立郵件模板，目標課程為 200
      Then 操作失敗

  # ========== 自動授權隔離 ==========

  Rule: 外部課程不可被加入自動授權課程清單

    Example: 設定自動授權課程時排除外部課程
      When 管理員 "Admin" 將課程 200 加入自動授權課程清單
      Then 操作失敗

    Example: 自動授權課程的選擇器不包含外部課程
      When 管理員 "Admin" 查詢可設定為自動授權的課程
      Then 選項不應包含 "Python 資料科學"（courseId: 200）

  # ========== 章節管理隔離 ==========

  Rule: 外部課程不可新增章節

    Example: 嘗試為外部課程建立章節時失敗
      When 管理員 "Admin" 為課程 200 建立章節，參數如下：
        | name       |
        | 第一章     |
      Then 操作失敗

  # ========== 銷售方案隔離 ==========

  Rule: 外部課程不可建立銷售方案

    Example: 嘗試為外部課程建立銷售方案時失敗
      When 管理員 "Admin" 為課程 200 建立銷售方案
      Then 操作失敗

  # ========== 課程列表查詢 ==========

  Rule: 課程列表查詢可混合包含站內與外部課程

    Example: 預設查詢包含所有類型課程
      When 管理員 "Admin" 查詢課程列表
      Then 回應中應包含課程 "PHP 基礎課"
      And 回應中應包含課程 "Python 資料科學"

  Rule: 課程列表查詢可依類型篩選

    Example: 僅查詢外部課程
      When 管理員 "Admin" 查詢課程列表，參數如下：
        | type     |
        | external |
      Then 回應中應包含課程 "Python 資料科學"
      And 回應中不應包含課程 "PHP 基礎課"
