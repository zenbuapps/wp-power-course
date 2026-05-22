@ignore @query
Feature: 課程學員 Tab — Filter 篩選器

  Issue #227 — 課程編輯頁的「學員」Tab 上方新增 Filter 篩選器：
  1. 關鍵字搜尋（ID / 帳號 / Email / 顯示名稱 / billing 姓名）
  2. 課程進度查詢（運算子 + 百分比數值）

  頁面位置：`/admin.php?page=power-course#/courses/edit/{course_id}` → 學員 Tab
  API：GET /power-course/v2/students（已存在，本 Issue 擴充 progress_operator + progress_value 參數）

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email             | role          | billing_last_name | billing_first_name |
      | 1      | Admin   | admin@test.com    | administrator |                   |                    |
      | 2      | Alice   | alice@test.com    | subscriber    | 劉                | 小明               |
      | 3      | Bob     | bob@test.com      | subscriber    |                   |                    |
      | 4      | Charlie | charlie@test.com  | subscriber    | 陳                | 大華               |
      | 5      | Diana   | diana@test.com    | subscriber    |                   |                    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  |
      | 100      | PHP 基礎課 | yes        | publish |
    And 課程 100 共有 10 個章節
    And 用戶 "Alice"  已被加入課程 100，已完成 10 個章節（進度 100%）
    And 用戶 "Bob"    已被加入課程 100，已完成 5 個章節（進度 50%）
    And 用戶 "Charlie" 已被加入課程 100，已完成 2 個章節（進度 20%）
    And 用戶 "Diana"  已被加入課程 100，已完成 0 個章節（進度 0%）

  # ============================================================
  # 前置（參數）— progress_operator 與 progress_value 必須成對
  # ============================================================

  Rule: 前置（參數）- progress_operator 與 progress_value 必須同時提供

    Example: 只提供 operator 未提供 value 時操作失敗
      When 管理員 "Admin" 查詢課程 100 學員列表，參數如下：
        | meta_value | progress_operator |
        | 100        | <                 |
      Then 操作失敗，錯誤為「progress_operator 與 progress_value 必須同時提供」

    Example: 只提供 value 未提供 operator 時操作失敗
      When 管理員 "Admin" 查詢課程 100 學員列表，參數如下：
        | meta_value | progress_value |
        | 100        | 30             |
      Then 操作失敗，錯誤為「progress_operator 與 progress_value 必須同時提供」

  Rule: 前置（參數）- progress_operator 只允許特定運算子

    Example: 不合法運算子被拒絕
      When 管理員 "Admin" 查詢課程 100 學員列表，參數如下：
        | meta_value | progress_operator | progress_value |
        | 100        | LIKE              | 50             |
      Then 操作失敗，錯誤為「progress_operator 只能是 =、!=、<、<=、>、>=」

    Example: 6 種合法運算子皆被接受
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "<="，progress_value = 50
      Then 操作成功

  Rule: 前置（參數）- progress_value 必須是 0 到 100 之間的整數

    Example: 負數值被拒絕
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = ">"，progress_value = -10
      Then 操作失敗，錯誤為「progress_value 必須是 0 到 100 之間的整數」

    Example: 超過 100 的值被拒絕
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "<"，progress_value = 150
      Then 操作失敗，錯誤為「progress_value 必須是 0 到 100 之間的整數」

  # ============================================================
  # 後置（回應）— 進度篩選邏輯
  # ============================================================

  Rule: 後置（回應）- 支援「進度 < N%」篩選（落後學員）

    Example: 查詢進度 <30% 的學員
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "<"，progress_value = 30
      Then 操作成功
      And 回應學員數量為 2
      And 回應中應包含用戶 "Charlie"
      And 回應中應包含用戶 "Diana"

  Rule: 後置（回應）- 支援「進度 > N%」篩選（領先學員）

    Example: 查詢進度 >50% 的學員
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = ">"，progress_value = 50
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

  Rule: 後置（回應）- 支援「進度 = N%」精確匹配

    Example: 查詢進度 = 100% 的學員（已完成）
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "="，progress_value = 100
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

    Example: 查詢進度 = 0% 的學員（未開始）
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "="，progress_value = 0
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Diana"

  Rule: 後置（回應）- 支援「進度 >= N%」與「<= N%」邊界包含

    Example: 進度 >= 50% 包含等於 50% 的學員
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = ">="，progress_value = 50
      Then 操作成功
      And 回應學員數量為 2
      And 回應中應包含用戶 "Alice"
      And 回應中應包含用戶 "Bob"

    Example: 進度 <= 20% 包含等於 20% 的學員
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "<="，progress_value = 20
      Then 操作成功
      And 回應學員數量為 2
      And 回應中應包含用戶 "Charlie"
      And 回應中應包含用戶 "Diana"

  Rule: 後置（回應）- 支援「進度 != N%」排除

    Example: 進度 != 100% 排除已完成的學員
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "!="，progress_value = 100
      Then 操作成功
      And 回應學員數量為 3
      And 回應中應不包含用戶 "Alice"

  # ============================================================
  # 後置（回應）— 關鍵字搜尋（與 UserTable 一致：id + login + email + display_name + billing 姓名）
  # ============================================================

  Rule: 後置（回應）- 單一 search 欄位同時比對 id / 帳號 / Email / 顯示名稱 / billing 姓名

    Example: 以 Email 子字串搜尋
      When 管理員 "Admin" 查詢課程 100 學員列表，search = "alice"
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

    Example: 以 billing 姓名搜尋
      When 管理員 "Admin" 查詢課程 100 學員列表，search = "劉小明"
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

    Example: 以用戶 ID 搜尋
      When 管理員 "Admin" 查詢課程 100 學員列表，search = "3"
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Bob"

    Example: 以顯示名稱搜尋
      When 管理員 "Admin" 查詢課程 100 學員列表，search = "Bob"
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Bob"

  # ============================================================
  # 後置（回應）— 多條件 AND 組合
  # ============================================================

  Rule: 後置（回應）- search 與 progress 篩選並用時為 AND 邏輯

    Example: 搜尋名稱「a」且進度 = 100%
      When 管理員 "Admin" 查詢課程 100 學員列表，參數如下：
        | meta_value | search | progress_operator | progress_value |
        | 100        | a      | =                 | 100            |
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Alice"

    Example: 搜尋名稱「a」且進度 < 30% — 兩條件都需符合
      When 管理員 "Admin" 查詢課程 100 學員列表，參數如下：
        | meta_value | search | progress_operator | progress_value |
        | 100        | a      | <                 | 30             |
      Then 操作成功
      And 回應學員數量為 1
      And 回應中應包含用戶 "Diana"

  # ============================================================
  # 後置（回應）— 篩選後分頁正確
  # ============================================================

  Rule: 後置（回應）- 篩選後 pagination 的 total 應為篩選結果數量，非全體學員數

    Example: 篩選後 total 反映篩選結果筆數
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = "<"，progress_value = 30，posts_per_page = 1，paged = 1
      Then 操作成功
      And 回應學員數量為 1（當頁）
      And 回應 pagination.total 為 2
      And 回應 pagination.total_pages 為 2

  # ============================================================
  # 後置（回應）— 無符合條件時回空列表
  # ============================================================

  Rule: 後置（回應）- 篩選無符合學員時回傳空列表（非 404）

    Example: 進度 > 99% 但無 100% 學員時回傳空
      Given 用戶 "Alice" 從課程 100 移除權限
      When 管理員 "Admin" 查詢課程 100 學員列表，progress_operator = ">"，progress_value = 99
      Then 操作成功
      And 回應學員數量為 0
      And 回應 pagination.total 為 0
