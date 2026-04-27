# 實作計劃：Elementor CPT 自動支援 (Issue #48)

## 概述

當 Power Course 與 Elementor 同時啟用時，自動將 `product` 寫入 Elementor 的 `elementor_cpt_support` option，讓管理員能直接使用 Elementor 編輯課程頁面（WooCommerce 商品），無需手動到 Elementor 設定頁勾選。附帶修復 `pc_chapter` filter 的重複值問題。

## 範圍模式：HOLD SCOPE

**預估影響**：2 個生產檔案（1 新 + 1 修改），總計變動量極小。

## 需求重述

1. Elementor 已啟用時，在 `admin_init` hook 自動將 `product` 加入 `elementor_cpt_support` option（DB 寫入）
2. `product` 已存在時不重複加入、不呼叫 `update_option`（避免不必要的 DB 寫入）
3. 既有的 CPT 支援（`post`、`page` 等）不得被移除或覆蓋
4. Elementor 未安裝或未啟用時，不執行任何操作、不產生錯誤
5. 修復 `Chapter\Core\CPT.php` 的 `add_elementor_cpt_support()` 重複值問題

## Clarifier 確認的設計決策

| 問題 | 決策 | 理由 |
|------|------|------|
| Q1 實作方式 | B: 寫入 DB (update_option) | 持久化設定，用戶可在 Elementor 設定頁看到勾選狀態 |
| Q2 觸發時機 | A: 無條件 | 只要兩個外掛同時啟用就生效，簡單且安全 |
| Q3 影響範圍 | A: 所有商品 | 符合 Elementor 以 CPT 為單位的設計 |
| Q4 重複值修復 | A: 一併修復 | 成本極低，加一行 in_array 檢查 |
| Q5 其他建構器 | A: 只處理 Elementor | 避免過早抽象 |
| Q6 程式碼位置 | B: 獨立類別 Compatibility\Elementor | 職責分離 |

## 架構變更

### 檔案 1：新增 `inc/classes/Compatibility/Elementor.php`

```
namespace J7\PowerCourse\Compatibility;

final class Elementor {
    use \J7\WpUtils\Traits\SingletonTrait;

    public function __construct() {
        add_action('admin_init', [$this, 'ensure_product_cpt_support']);
    }

    public function ensure_product_cpt_support(): void {
        // 1. 檢查 Elementor 是否已啟用（class 'Elementor\Plugin' 存在）
        // 2. 取得 elementor_cpt_support option
        // 3. 若 product 不在陣列中，加入並 update_option
    }
}
```

**Elementor 啟用偵測**：使用 `class_exists('Elementor\Plugin')` 判斷，比檢查 `active_plugins` 更可靠（相容 MU plugins 和 symlink 安裝）。

**Hook 選擇**：`admin_init` — 在後台管理頁面載入時觸發，確保 Elementor 的 class 已載入。只在後台執行，不影響前台效能。

### 檔案 2：修改 `inc/classes/Resources/Chapter/Core/CPT.php`

修改 `add_elementor_cpt_support()` 方法，加入 `in_array` 檢查：

```php
// Before
public function add_elementor_cpt_support( $value ): array {
    $value[] = self::POST_TYPE;
    return $value;
}

// After
public function add_elementor_cpt_support( $value ): array {
    if ( ! in_array( self::POST_TYPE, $value, true ) ) {
        $value[] = self::POST_TYPE;
    }
    return $value;
}
```

### 檔案 3：初始化新類別

在適當的初始化位置（如 `plugin.php` 或既有的 Loader）呼叫 `Elementor::instance()` 確保 singleton 被初始化。需確認現有 `Compatibility\Compatibility` 的初始化路徑，判斷是否可從該處觸發，或需要獨立初始化。

## 風險評估

| 風險 | 嚴重度 | 緩解措施 |
|------|--------|---------|
| 用戶手動取消 product 勾選後被自動加回 | 低 | Q2 決策為無條件生效，屬設計行為 |
| 每次 admin 頁面載入都讀一次 option | 極低 | get_option 有 object cache，且只在需要時才 update |
| Elementor 移除後 option 殘留 product | 無 | 不影響功能，用戶可手動清理 |
