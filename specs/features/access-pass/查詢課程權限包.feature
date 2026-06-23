@ignore @query
Feature: 查詢課程權限包

  # 後台列表查詢：回傳所有權限包供管理頁呈現。
  # 回應需含「已掛載商品數」與「已影響（已購）用戶數」，供刪除前影響提示與列表展示使用。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下商品：
      | productId | name        | type   | status  | regular_price |
      | 500       | 全站通行證  | simple | publish | 1999          |
      | 501       | HTML 系列包 | simple | publish | 999           |
    And 系統中有以下課程權限包：
      | passId | name         | scope_type | limit_mode | status   | term_ids |
      | 300    | 全站課程權限 | all        | permanent  | active   |          |
      | 301    | HTML 權限    | category   | permanent  | active   | [10]     |
      | 302    | 舊版權限     | all        | permanent  | disabled |          |
    And 商品 500 掛載了課程權限包 300
    And 商品 501 掛載了課程權限包 301

  # ========== 後置（回應）==========

  Rule: 後置（回應）- 回傳所有權限包清單，含狀態與範圍類型

    Example: 查詢權限包列表
      When 管理員 "Admin" 查詢課程權限包列表
      Then 操作成功
      And 查詢結果應包含：
        | passId | name         | scope_type | status   |
        | 300    | 全站課程權限 | all        | active   |
        | 301    | HTML 權限    | category   | active   |
        | 302    | 舊版權限     | all        | disabled |

  Rule: 後置（回應）- 回傳每個權限包已掛載的商品數

    Example: 查詢結果含已掛載商品數
      When 管理員 "Admin" 查詢課程權限包列表
      Then 操作成功
      And 權限包 300 的已掛載商品數應為 1
      And 權限包 301 的已掛載商品數應為 1

  Rule: 後置（回應）- 可依狀態篩選權限包

    Example: 僅查詢啟用中的權限包
      When 管理員 "Admin" 查詢課程權限包列表，狀態篩選為 "active"
      Then 操作成功
      And 查詢結果應包含：
        | passId | name         |
        | 300    | 全站課程權限 |
        | 301    | HTML 權限    |
      And 查詢結果不應包含權限包 302
