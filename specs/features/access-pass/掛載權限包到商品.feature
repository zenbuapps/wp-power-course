@ignore @command
Feature: 掛載權限包到商品

  # 1 商品掛 1 包（1:1）：商品端用 product meta access_pass_id 記錄掛載關係。
  # 同一商品可同時設定「逐課綁定 bind_courses_data」與「權限包 access_pass_id」——兩者並存、效果並集（不互斥）。
  # 商品類型不限：一次性商品（simple）與訂閱商品（subscription）皆可掛載。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  |
      | 100      | HTML 入門課 | yes        | publish |
    And 系統中有以下商品：
      | productId | name        | type         | status  | regular_price |
      | 500       | 全站通行證  | simple       | publish | 1999          |
      | 510       | 月費暢看    | subscription | publish | 299           |
    And 系統中有以下課程權限包：
      | passId | name         | scope_type | limit_mode         | status   |
      | 300    | 全站課程權限 | all        | permanent          | active   |
      | 301    | 訂閱全站權限 | all        | follow_subscription| active   |
      | 302    | 舊版權限     | all        | permanent          | disabled |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 被掛載的商品必須存在

    Example: 掛載到不存在的商品時操作失敗
      When 管理員 "Admin" 將課程權限包 300 掛載到商品 9999
      Then 操作失敗

  Rule: 前置（狀態）- 被掛載的權限包必須存在

    Example: 掛載不存在的權限包時操作失敗
      When 管理員 "Admin" 將課程權限包 9999 掛載到商品 500
      Then 操作失敗

  Rule: 前置（狀態）- 被掛載的權限包必須為啟用中（active）

    Example: 掛載已停用的權限包時操作失敗
      When 管理員 "Admin" 將課程權限包 302 掛載到商品 500
      Then 操作失敗

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 成功將權限包掛載到一次性商品，寫入 access_pass_id meta

    Example: 成功掛載權限包到一次性商品
      When 管理員 "Admin" 將課程權限包 300 掛載到商品 500
      Then 操作成功
      And 商品 500 的 access_pass_id meta 應為 300

  Rule: 後置（狀態）- 成功將權限包掛載到訂閱商品

    Example: 成功掛載跟隨訂閱權限包到訂閱商品
      When 管理員 "Admin" 將課程權限包 301 掛載到商品 510
      Then 操作成功
      And 商品 510 的 access_pass_id meta 應為 301

  Rule: 後置（狀態）- 1 商品只掛 1 包，重新掛載會覆蓋既有掛載

    Example: 重新掛載權限包覆蓋舊的掛載
      Given 商品 500 的 access_pass_id meta 為 301
      When 管理員 "Admin" 將課程權限包 300 掛載到商品 500
      Then 操作成功
      And 商品 500 的 access_pass_id meta 應為 300

  Rule: 後置（狀態）- 權限包與逐課綁定並存，兩者皆保留

    Example: 商品同時設定逐課綁定與權限包
      Given 商品 500 已設定 bind_courses_data 綁定課程 100
      When 管理員 "Admin" 將課程權限包 300 掛載到商品 500
      Then 操作成功
      And 商品 500 的 access_pass_id meta 應為 300
      And 商品 500 的 bind_courses_data 仍綁定課程 100
