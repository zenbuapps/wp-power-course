@command
Feature: 中文章節 slug 儲存穩定性

  章節使用中文名稱時，slug（post_name）在多次儲存後必須保持不變。
  修復目標：Chapter API 的 sanitize_text_field_deep() 不應破壞 post_name 中的中文字元。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |

  # ========== 核心場景：中文 slug 多次儲存穩定性 ==========

  Rule: 中文 slug 在多次儲存後不應變更

    Example: 中文章節名稱首次建立後，連續儲存 3 次 slug 不變
      Given 課程 100 有以下章節：
        | chapterId | post_title | post_parent | post_name      |
        | 200       | 新章節     | 100         | %e6%96%b0%e7%ab%a0%e7%af%80 |
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 600            |
      Then 操作成功
      And 章節 200 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80"
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 900            |
      Then 操作成功
      And 章節 200 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80"
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 1200           |
      Then 操作成功
      And 章節 200 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80"

    Example: 同名章節帶後綴編號的 slug 在多次儲存後不變
      Given 課程 100 有以下章節：
        | chapterId | post_title | post_parent | post_name      |
        | 200       | 新章節     | 100         | %e6%96%b0%e7%ab%a0%e7%af%80   |
        | 201       | 新章節     | 100         | %e6%96%b0%e7%ab%a0%e7%af%80-2 |
      When 管理員 "Admin" 更新章節 201，參數如下：
        | chapter_length |
        | 600            |
      Then 操作成功
      And 章節 201 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80-2"
      When 管理員 "Admin" 更新章節 201，參數如下：
        | chapter_length |
        | 900            |
      Then 操作成功
      And 章節 201 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80-2"

  # ========== 邊界場景：slug 欄位未送出時不應變更 ==========

  Rule: 未送出 slug 欄位時，既有 slug 不應被覆寫

    Example: 只更新標題不送 slug，既有中文 slug 不變
      Given 課程 100 有以下章節：
        | chapterId | post_title | post_parent | post_name      |
        | 200       | 新章節     | 100         | %e6%96%b0%e7%ab%a0%e7%af%80 |
      When 管理員 "Admin" 更新章節 200，參數如下：
        | post_title |
        | 進階章節   |
      Then 操作成功
      And 章節 200 的 post_name 應為 "%e6%96%b0%e7%ab%a0%e7%af%80"

  # ========== 對照場景：英文 slug 不受影響 ==========

  Rule: 英文 slug 行為不受修復影響

    Example: 英文章節名稱多次儲存 slug 不變
      Given 課程 100 有以下章節：
        | chapterId | post_title   | post_parent | post_name    |
        | 200       | Introduction | 100         | introduction |
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 600            |
      Then 操作成功
      And 章節 200 的 post_name 應為 "introduction"
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 900            |
      Then 操作成功
      And 章節 200 的 post_name 應為 "introduction"

  # ========== 顯式修改 slug 場景 ==========

  Rule: 管理員可以顯式修改 slug，修改後的值在後續儲存中保持穩定

    Example: 管理員修改中文 slug 後，後續儲存不會再次破壞
      Given 課程 100 有以下章節：
        | chapterId | post_title | post_parent | post_name |
        | 200       | 新章節     | 100         | 2-3       |
      When 管理員 "Admin" 更新章節 200，參數如下：
        | slug                                   |
        | %e6%96%b0%e7%ab%a0%e7%af%80            |
      Then 操作成功
      And 章節 200 的 post_name 應包含 "%e6%96%b0%e7%ab%a0%e7%af%80"
      When 管理員 "Admin" 更新章節 200，參數如下：
        | chapter_length |
        | 600            |
      Then 操作成功
      And 章節 200 的 post_name 應包含 "%e6%96%b0%e7%ab%a0%e7%af%80"
