@ignore @query
Feature: 權限包觀看判定

  # Compute-on-read：使用者進入課程時，系統即時彙整所有有效權限來源判定可否觀看。
  # 任一來源允許即可看（OR 疊加）：逐課綁定(avl_course_ids) ∪ 全站包 ∪ 分類包(含子分類) ∪ 特定包。
  # 全站 / 分類包為動態範圍——日後新增且落在範圍內的課程自動可看，無需補開通。
  # 期限三模式：permanent 恆有效；follow_subscription 依訂閱 active/pending-cancel；limited 依到期時間。

  Background:
    Given 系統中有以下用戶：
      | userId | name    | email            | role          |
      | 2      | UserA   | usera@test.com   | subscriber    |
    And 系統中有以下課程分類：
      | termId | name | taxonomy    | parent |
      | 10     | HTML | product_cat | 0      |
      | 11     | 前端 | product_cat | 10     |
    And 系統中有以下課程：
      | courseId | name        | _is_course | status  | product_cat |
      | 100      | HTML 入門課 | yes        | publish | 10          |
      | 101      | 前端工程課  | yes        | publish | 11          |
      | 200      | PHP 基礎課  | yes        | publish |             |
      | 300      | 進階架構課  | yes        | publish |             |

  # ========== 前置（狀態）==========

  Rule: 前置（狀態）- 未登入使用者一律不可觀看

    Example: 未登入訪客判定為不可觀看
      Given 使用者未登入
      When 系統判定對課程 100 的觀看權限
      Then 觀看權限應為「不可觀看」

  # ========== 後置（回應）：全站範圍 ==========

  Rule: 後置（回應）- 持有有效全站永久權限包時，任一課程皆可觀看

    Example: 持有全站永久包可觀看任意課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode |
        | 300    | all        | permanent  |
      When 系統判定學員 "UserA" 對課程 200 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 持有全站包可觀看購買後才新增的課程（動態範圍）
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode |
        | 300    | all        | permanent  |
      And 系統新增課程：
        | courseId | name      | _is_course | status  |
        | 999      | 全新上架課 | yes        | publish |
      When 系統判定學員 "UserA" 對課程 999 的觀看權限
      Then 觀看權限應為「可觀看」

  # ========== 後置（回應）：分類標籤範圍（含子分類）==========

  Rule: 後置（回應）- 持有分類權限包時，僅範圍內課程可觀看，範圍外不可觀看

    Example: 持有 HTML 分類包可觀看 HTML 課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | term_ids |
        | 301    | category   | permanent  | [10]     |
      When 系統判定學員 "UserA" 對課程 100 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 持有 HTML 分類包不可觀看範圍外的 PHP 課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | term_ids |
        | 301    | category   | permanent  | [10]     |
      When 系統判定學員 "UserA" 對課程 200 的觀看權限
      Then 觀看權限應為「不可觀看」

  Rule: 後置（回應）- 分類範圍涵蓋子分類課程

    Example: 持有父分類 HTML 包可觀看子分類「前端」課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | term_ids |
        | 301    | category   | permanent  | [10]     |
      When 系統判定學員 "UserA" 對課程 101 的觀看權限
      Then 觀看權限應為「可觀看」

  # ========== 後置（回應）：特定課程範圍 ==========

  Rule: 後置（回應）- 持有特定課程包時，僅清單內課程可觀看，且不隨新增課程擴張

    Example: 持有特定課程包可觀看清單內課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | course_ids |
        | 302    | specific   | permanent  | [100, 101] |
      When 系統判定學員 "UserA" 對課程 100 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 特定課程包不涵蓋日後新增的同分類課程
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | course_ids |
        | 302    | specific   | permanent  | [100]      |
      And 系統新增課程：
        | courseId | name        | _is_course | status  | product_cat |
        | 998      | HTML 新課   | yes        | publish | 10          |
      When 系統判定學員 "UserA" 對課程 998 的觀看權限
      Then 觀看權限應為「不可觀看」

  # ========== 後置（回應）：期限模式 ==========

  Rule: 後置（回應）- 跟隨訂閱權限包，訂閱有效時可觀看

    Scenario Outline: 訂閱狀態為 <訂閱狀態> 時觀看權限為 <觀看權限>
      Given 學員 "UserA" 持有以下跟隨訂閱權限包：
        | passId | scope_type | limit_mode          | 訂閱狀態     |
        | 303    | all        | follow_subscription | <訂閱狀態>   |
      When 系統判定學員 "UserA" 對課程 200 的觀看權限
      Then 觀看權限應為「<觀看權限>」

      Examples:
        | 訂閱狀態        | 觀看權限   |
        | active          | 可觀看     |
        | pending-cancel  | 可觀看     |
        | on-hold         | 不可觀看   |
        | cancelled       | 不可觀看   |
        | expired         | 不可觀看   |

  Rule: 後置（回應）- 限時權限包，未到期可觀看、已到期不可觀看

    Example: 限時權限包未到期時可觀看
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | 到期狀態 |
        | 304    | all        | limited    | 未到期   |
      When 系統判定學員 "UserA" 對課程 200 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 限時權限包已到期時不可觀看
      Given 學員 "UserA" 持有以下權限包：
        | passId | scope_type | limit_mode | 到期狀態 |
        | 304    | all        | limited    | 已到期   |
      When 系統判定學員 "UserA" 對課程 200 的觀看權限
      Then 觀看權限應為「不可觀看」

  # ========== 後置（回應）：多來源 OR 疊加 ==========

  Rule: 後置（回應）- 任一有效來源允許即可觀看（權限包與逐課綁定並集）

    Example: 逐課綁定命中時可觀看（即使無權限包涵蓋）
      Given 學員 "UserA" 已透過逐課綁定取得課程 300 的觀看權限（avl_course_ids 含 300）
      When 系統判定學員 "UserA" 對課程 300 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 訂閱包失效但單獨購買的課程仍可觀看
      Given 學員 "UserA" 持有以下跟隨訂閱權限包：
        | passId | scope_type | limit_mode          | 訂閱狀態  |
        | 303    | all        | follow_subscription | cancelled |
      And 學員 "UserA" 已透過逐課綁定取得課程 300 的觀看權限（avl_course_ids 含 300）
      When 系統判定學員 "UserA" 對課程 300 的觀看權限
      Then 觀看權限應為「可觀看」

    Example: 兩個來源皆涵蓋同一課程時可觀看
      Given 學員 "UserA" 持有以下有效權限包：
        | passId | scope_type | limit_mode | term_ids |
        | 301    | category   | permanent  | [10]     |
      And 學員 "UserA" 已透過逐課綁定取得課程 100 的觀看權限（avl_course_ids 含 100）
      When 系統判定學員 "UserA" 對課程 100 的觀看權限
      Then 觀看權限應為「可觀看」
