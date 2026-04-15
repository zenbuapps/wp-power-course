@ignore @command
Feature: 更新商品綁定課程期限

  管理員可以對商品已綁定的課程，修改觀看期限設定（不改變綁定關係本身），
  例如把原本「固定 30 天」改為「無期限」或「指定到期日」。

  **Code source:** `inc/classes/Api/Product.php::post_products_update_bound_courses_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下商品：
      | productId | name    | _is_course |
      | 400       | 加購包  | no         |
    And 系統中有以下課程：
      | courseId | name         | _is_course |
      | 100      | PHP 基礎課   | yes        |
    And 商品 400 綁定了課程 [100]，期限為 limit_type=fixed、limit_value=30、limit_unit=day

  # ========== 前置（參數）==========

  Rule: 前置（參數）- product_ids / course_ids / limit_type 三者皆為必填

    Example: 缺少 limit_type 時失敗
      When 管理員 "Admin" 呼叫 POST /products/update-bound-courses，參數如下：
        | product_ids | course_ids |
        | [400]       | [100]      |
      Then 操作失敗，錯誤為「limit_type 為必填」

  Rule: 前置（參數）- limit_type=fixed 時 limit_value 與 limit_unit 不可為空

    Example: fixed 模式未提供 limit_value
      When 管理員 "Admin" 呼叫 POST /products/update-bound-courses，參數如下：
        | product_ids | course_ids | limit_type |
        | [400]       | [100]      | fixed      |
      Then 操作失敗，錯誤為「limit_type 為 fixed 時 limit_value 不可為空」

  # ========== Happy Path ==========

  Rule: 成功更新商品綁定課程的期限

    Example: 固定 30 天改為無限期
      When 管理員 "Admin" 呼叫 POST /products/update-bound-courses，參數如下：
        | product_ids | course_ids | limit_type |
        | [400]       | [100]      | unlimited  |
      Then 操作成功
      And 回應 code 為 "success"
      And 回應 message 為 "修改成功"
      And 商品 400 的 bind_courses_data 中，課程 100 的 limit_type 為 "unlimited"
      And 商品 400 的 bind_course_ids meta 保持為 [100]（綁定關係不變）

    Example: 固定 30 天改為指定到期日
      When 管理員 "Admin" 呼叫 POST /products/update-bound-courses，參數如下：
        | product_ids | course_ids | limit_type | limit_value | limit_unit |
        | [400]       | [100]      | assigned   | 1767225599  | timestamp  |
      Then 商品 400 的 bind_courses_data 中，課程 100 的 limit_type 為 "assigned"、limit_value 為 1767225599

  # ========== 不追溯已購買 ==========

  Rule: 更新期限不影響已購買學員的 pc_avl_coursemeta

    Example: Alice 已購買並開通
      Given Alice (userId=10) 已購買商品 400 並獲得課程 100 存取權
      And Alice 的 pc_avl_coursemeta(course_id=100, user_id=10).expire_date = 1714118340（2024-04-26）
      When 管理員將商品 400 的課程 100 期限改為 unlimited
      Then 操作成功
      And Alice 的 pc_avl_coursemeta.expire_date 仍為 1714118340（未被覆寫）
      And 僅影響未來購買商品 400 的新學員
