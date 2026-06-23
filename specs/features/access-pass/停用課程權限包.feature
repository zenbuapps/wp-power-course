@ignore @command
Feature: 停用課程權限包

  # 停用（disable）≠ 刪除（delete）：
  # 停用後權限包不可再掛載到新商品，但「已購用戶的觀看權限保留」。
  # 對照「刪除課程權限包.feature」：刪除才會真正收回已購用戶權限。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role          |
      | 1      | Admin   | admin@test.com   | administrator |
      | 2      | Student | student@test.com | subscriber    |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
    And 系統中有以下商品：
      | productId | name        | type   | status  | regular_price |
      | 500       | HTML 系列包 | simple | publish | 999           |
    And 系統中有以下課程權限包：
      | passId | name         | scope_type | limit_mode | status | term_ids |
      | 300    | 全站課程權限 | all        | permanent  | active |          |
      | 301    | HTML 權限    | category   | permanent  | active | [10]     |
    And 商品 500 掛載了課程權限包 301

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 被停用的權限包必須存在

    Example: 停用不存在的權限包時操作失敗
      When 管理員 "Admin" 停用課程權限包 9999
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 停用後權限包狀態變為 disabled

    Example: 成功停用權限包
      When 管理員 "Admin" 停用課程權限包 300
      Then 操作成功
      And 權限包 300 的 status 應為 "disabled"

  Rule: 後置（狀態）- 停用後已購用戶的觀看權限仍保留

    Example: 停用權限包後已購學員仍可觀看範圍內課程
      Given 學員 "Student" 已透過商品 500 取得課程權限包 301
      When 管理員 "Admin" 停用課程權限包 301
      Then 操作成功
      And 學員 "Student" 對課程 100 的觀看權限應為「可觀看」

  Rule: 後置（狀態）- 停用後權限包不可再掛載到新商品

    Example: 停用的權限包無法掛載到新商品
      Given 系統中有以下商品：
        | productId | name      | type   | status  | regular_price |
        | 501       | 新商品 G  | simple | publish | 599           |
      And 權限包 300 已停用
      When 管理員 "Admin" 將課程權限包 300 掛載到商品 501
      Then 操作失敗
