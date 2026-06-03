@ignore @command
Feature: 設定信件允許重複寄送

  每個郵件模板（pe_email）新增「允許重複寄送」開關（post_meta `allow_repeat_send`），
  讓站長決定該封信是否允許對同一位學員重複寄送。
  - 開（true，預設）：寄送階段無視 `mark_as_sent`，只要觸發條件達成就寄。
  - 關（false）：寄送階段依 `mark_as_sent` 判斷，同一報名期間內每位學員只收一次。
  缺值（升級前既有模板）一律視為 true，以一併修復「撤銷後重購收不到信」的既有問題。

  Background:
    Given 系統中有以下用戶：
      | userId | name  | email          | role          |
      | 1      | Admin | admin@test.com | administrator |
    And 系統中有以下郵件模板：
      | emailId | post_title   | trigger_at     | post_status |
      | 500     | 課程開通通知 | course_granted | publish     |

  # ========== 前置（參數）==========

  Rule: 前置（參數）- allow_repeat_send 為布林開關，值以 'yes' / 'no' 儲存

    Example: 開關設為開時，post_meta 寫入 'yes'
      When 管理員 "Admin" 更新郵件模板 500，allow_repeat_send 設為「開」
      Then 操作成功
      And 郵件模板 500 的 post_meta "allow_repeat_send" 為 "yes"

    Example: 開關設為關時，post_meta 寫入 'no'
      When 管理員 "Admin" 更新郵件模板 500，allow_repeat_send 設為「關」
      Then 操作成功
      And 郵件模板 500 的 post_meta "allow_repeat_send" 為 "no"

  # ========== 後置（狀態）==========

  Rule: 後置（狀態）- 預設值為 true（允許重複寄送）

    Example: 新建郵件模板時 allow_repeat_send 視為 true
      When 管理員 "Admin" 新建一封郵件模板
      Then 該郵件模板的 allow_repeat_send 解析為 true

    Example: 既有模板無 allow_repeat_send meta 時視為 true
      Given 郵件模板 500 沒有 "allow_repeat_send" 這個 post_meta
      When 系統讀取郵件模板 500 的 allow_repeat_send
      Then allow_repeat_send 解析為 true

  Rule: 後置（狀態）- 'yes' 解析為 true、'no' 解析為 false

    Example: meta 為 'yes' 時解析為 true
      Given 郵件模板 500 的 post_meta "allow_repeat_send" 為 "yes"
      When 系統讀取郵件模板 500 的 allow_repeat_send
      Then allow_repeat_send 解析為 true

    Example: meta 為 'no' 時解析為 false
      Given 郵件模板 500 的 post_meta "allow_repeat_send" 為 "no"
      When 系統讀取郵件模板 500 的 allow_repeat_send
      Then allow_repeat_send 解析為 false
