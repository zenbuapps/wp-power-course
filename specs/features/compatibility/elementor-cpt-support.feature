@ignore @hook
Feature: Elementor CPT 自動支援

  當 Power Course 與 Elementor 同時啟用時，系統自動將 `product` 寫入
  Elementor 的 `elementor_cpt_support` option（DB 層級），讓管理員能直接
  使用 Elementor 編輯課程（WooCommerce 商品）頁面，無需手動到 Elementor
  設定頁勾選「商品」。

  設計決策（Issue #48 clarifier 確認）：
  - 寫入方式：update_option（持久化到 DB，非 runtime filter）
  - 觸發時機：無條件——只要 Power Course + Elementor 同時啟用即生效
  - 影響範圍：所有 WooCommerce 商品（product CPT），不限定課程商品
  - 附帶修復：pc_chapter 的 option_elementor_cpt_support filter 重複值問題
  - 程式碼位置：獨立類別 Compatibility\Elementor

  Background:
    Given WordPress 已安裝以下外掛：
      | plugin       | status |
      | power-course | active |
      | woocommerce  | active |

  # ========== product 自動寫入 DB ==========

  Rule: Elementor 已啟用時，product 自動寫入 elementor_cpt_support option

    Example: product 不在 CPT 支援清單中——自動加入
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的值為 ["post", "page"]
      When admin_init hook 觸發
      Then option "elementor_cpt_support" 應為 ["post", "page", "product"]

    Example: product 已在 CPT 支援清單中——不重複、不呼叫 update_option
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的值為 ["post", "page", "product"]
      When admin_init hook 觸發
      Then option "elementor_cpt_support" 應維持 ["post", "page", "product"]
      And update_option 不應被呼叫

    Example: elementor_cpt_support 為空陣列——加入 product
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的值為 []
      When admin_init hook 觸發
      Then option "elementor_cpt_support" 應為 ["product"]

    Example: option 不存在（Elementor 剛安裝尚未儲存設定）——建立並加入 product
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 不存在
      When admin_init hook 觸發
      Then option "elementor_cpt_support" 應為 ["product"]

  # ========== 既有 CPT 不受影響 ==========

  Rule: 自動設定只新增 product，不得移除或覆蓋既有的 CPT 支援項目

    Example: 用戶已勾選 post、page、custom_type——全部保留
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的值為 ["post", "page", "custom_type"]
      When admin_init hook 觸發
      Then option "elementor_cpt_support" 應為 ["post", "page", "custom_type", "product"]

  # ========== Elementor 未啟用 ==========

  Rule: Elementor 未安裝或未啟用時，不執行任何操作、不產生錯誤

    Example: Elementor 未安裝
      Given Elementor 外掛未安裝
      When admin_init hook 觸發
      Then 不應讀取或修改 option "elementor_cpt_support"
      And 不應產生 PHP error 或 warning

    Example: Elementor 已安裝但未啟用
      Given Elementor 外掛已安裝但未啟用
      When admin_init hook 觸發
      Then 不應讀取或修改 option "elementor_cpt_support"
      And 不應產生 PHP error 或 warning

  # ========== pc_chapter filter 重複值修復 ==========

  Rule: pc_chapter 的 option_elementor_cpt_support filter 不應產生重複項目

    Example: pc_chapter 已在 CPT 支援清單中——不重複加入
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的原始值為 ["post", "page", "pc_chapter"]
      When get_option("elementor_cpt_support") 被呼叫觸發 filter
      Then 結果陣列中 "pc_chapter" 只出現 1 次

    Example: pc_chapter 不在 CPT 支援清單中——正常加入
      Given Elementor 外掛已啟用
      And option "elementor_cpt_support" 的原始值為 ["post", "page"]
      When get_option("elementor_cpt_support") 被呼叫觸發 filter
      Then 結果陣列應包含 "pc_chapter"
      And "pc_chapter" 只出現 1 次
