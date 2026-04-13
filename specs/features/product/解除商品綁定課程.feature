@ignore @command
Feature: 解除商品綁定課程

  管理員可將某個課程從「商品課程權限綁定」中移除，而不需要完全刪除商品。
  與 `綁定課程到商品.feature` 互為逆操作。

  **Code source:** `inc/classes/Api/Product.php::post_products_unbind_courses_callback`

  Background:
    Given 系統中有以下用戶：
      | userId | name  | role          |
      | 1      | Admin | administrator |
    And 系統中有以下商品：
      | productId | name   | type   | _is_course |
      | 400       | 加購包 | simple | no         |
    And 系統中有以下課程：
      | courseId | name         | _is_course |
      | 100      | PHP 基礎課   | yes        |
      | 101      | Laravel 課程 | yes        |
    And 商品 400 綁定了課程 [100, 101]
    And 商品 400 的 bind_course_ids meta 為 [100, 101]
    And 商品 400 的 bind_courses_data meta 包含兩筆 BindCourseData (100, 101)

  # ========== 前置（參數）==========

  Rule: 前置（參數）- product_ids 與 course_ids 皆為必填

    Example: 缺少 course_ids 時失敗
      When 管理員 "Admin" 呼叫 POST /products/unbind-courses，參數如下：
        | product_ids |
        | [400]       |
      Then 操作失敗，錯誤為「course_ids 為必填」

  # ========== Happy Path ==========

  Rule: 從商品解除指定課程的綁定

    Example: 從商品 400 解除課程 100
      When 管理員 "Admin" 呼叫 POST /products/unbind-courses，參數如下：
        | product_ids | course_ids |
        | [400]       | [100]      |
      Then 操作成功
      And 回應 code 為 "success"
      And 回應 message 為 "解除綁定成功"
      And 回應 data.success_ids 為 [400]
      And 商品 400 的 bind_course_ids meta 變為 [101]
      And 商品 400 的 bind_courses_data 只剩下課程 101 的一筆資料

  Rule: 批次解除多個商品與多個課程的綁定

    Example: 同時解除兩個課程
      When 管理員 "Admin" 呼叫 POST /products/unbind-courses，參數如下：
        | product_ids | course_ids |
        | [400]       | [100, 101] |
      Then 操作成功
      And 商品 400 的 bind_course_ids meta 為空陣列
      And 商品 400 的 bind_courses_data 為空陣列

  # ========== 解除後不影響已購買的學員 ==========

  Rule: 解除綁定不追溯已購買的學員權限

    Example: 已購買學員仍保有課程存取權
      Given Alice (userId=10) 已購買商品 400 並獲得課程 100 的存取權
      And Alice 的 pc_avl_coursemeta(course_id=100, user_id=10) 存在
      When 管理員 "Admin" 從商品 400 解除課程 100 的綁定
      Then 操作成功
      And Alice 的 pc_avl_coursemeta 保持不變
      And Alice 仍可正常觀看課程 100

  # ========== 部分失敗 ==========

  Rule: 批次中部分商品更新失敗時，會被記錄到 failed_ids

    Example: 其中一筆 meta 更新失敗
      Given 商品 401 為特殊設定，update_meta_array 會回傳 WP_Error
      When 管理員 "Admin" 呼叫 POST /products/unbind-courses，參數如下：
        | product_ids | course_ids |
        | [400, 401]  | [100]      |
      Then 回應 data.success_ids 為 [400]
      And 回應 data.failed_ids 為 [401]
