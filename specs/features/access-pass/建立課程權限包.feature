@ignore @command
Feature: 建立課程權限包

  # 課程權限包（Access Pass）= 新 CPT pc_access_pass
  # 站長定義一次權限範圍並命名，再掛到一個或多個商品。
  # 範圍：all（全站）/ category（分類標籤聯集，含子分類）/ specific（固定課程清單）
  # 期限：permanent（永久）/ follow_subscription（跟隨訂閱）/ limited（限時 N 天）

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
      | 101      | HTML 進階課 | yes        | publish |
      | 200      | PHP 基礎課  | yes        | publish |
    And 系統中有以下課程分類：
      | termId | name | taxonomy    | parent |
      | 10     | HTML | product_cat | 0      |
      | 11     | 前端 | product_cat | 10     |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- name 不可為空

    Example: 未提供權限包名稱時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name | scope_type | limit_mode |
        |      | all        | permanent  |
      Then 操作失敗，錯誤訊息包含 "name"

  Rule: 前置（參數）- scope_type 必須為 all、category、specific 三者之一

    Scenario Outline: scope_type 為 <scope_type> 時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name     | scope_type   | limit_mode |
        | 測試權限 | <scope_type> | permanent  |
      Then 操作失敗

      Examples:
        | scope_type |
        |            |
        | invalid    |

  Rule: 前置（參數）- limit_mode 必須為 permanent、follow_subscription、limited 三者之一

    Example: limit_mode 不合法時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name     | scope_type | limit_mode |
        | 測試權限 | all        | invalid    |
      Then 操作失敗

  Rule: 前置（參數）- scope_type 為 category 時，term_ids 至少需指定一個分類或標籤

    Example: category 範圍未指定任何 term 時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name           | scope_type | limit_mode | term_ids |
        | HTML 系列權限  | category   | permanent  |          |
      Then 操作失敗

  Rule: 前置（參數）- scope_type 為 specific 時，course_ids 至少需指定一門課程

    Example: specific 範圍未指定任何課程時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name         | scope_type | limit_mode | course_ids |
        | 特選包       | specific   | permanent  |            |
      Then 操作失敗

  Rule: 前置（參數）- limit_mode 為 limited 時，limit_value 必須為正整數且 limit_unit 必填

    Example: limited 模式未提供 limit_value 時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name       | scope_type | limit_mode | limit_unit |
        | 限時 30 天 | all        | limited    | day        |
      Then 操作失敗

    Example: limited 模式 limit_value 為 0 時建立失敗
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name       | scope_type | limit_mode | limit_value | limit_unit |
        | 限時權限   | all        | limited    | 0           | day        |
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功建立全站範圍永久權限包

    Example: 成功建立全站永久權限包
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name         | scope_type | limit_mode |
        | 全站課程權限 | all        | permanent  |
      Then 操作成功
      And 新建權限包的 scope_type 應為 "all"
      And 新建權限包的 limit_mode 應為 "permanent"
      And 新建權限包的 status 應為 "active"

  Rule: 後置（狀態）- 成功建立分類標籤範圍權限包，term_ids 取聯集

    Example: 成功建立 HTML 分類權限包
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name              | scope_type | limit_mode |
        | HTML 入門課程權限 | category   | permanent  |
      And 權限包範圍包含以下分類標籤：
        | term_id | taxonomy    |
        | 10      | product_cat |
      Then 操作成功
      And 新建權限包的 scope_type 應為 "category"
      And 新建權限包的 term_ids 應為 [10]

  Rule: 後置（狀態）- 成功建立特定課程範圍權限包，course_ids 為固定清單

    Example: 成功建立特定課程權限包
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name                  | scope_type | limit_mode |
        | HTML 進階特別課程權限 | specific   | permanent  |
      And 權限包範圍包含以下課程：
        | course_id |
        | 100       |
        | 101       |
      Then 操作成功
      And 新建權限包的 scope_type 應為 "specific"
      And 新建權限包的 course_ids 應為 [100, 101]

  Rule: 後置（狀態）- 成功建立限時 N 天權限包，記錄 limit_value 與 limit_unit

    Example: 成功建立限時 30 天權限包
      When 管理員 "Admin" 建立課程權限包，參數如下：
        | name       | scope_type | limit_mode | limit_value | limit_unit |
        | 限時 30 天 | all        | limited    | 30          | day        |
      Then 操作成功
      And 新建權限包的 limit_mode 應為 "limited"
      And 新建權限包的 limit_value 應為 30
      And 新建權限包的 limit_unit 應為 "day"
