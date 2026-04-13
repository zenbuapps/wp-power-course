# 實作計劃：銷售方案商品數量自由設定 (Issue #185)

## 概述

為銷售方案中的每個商品新增數量欄位（含當前課程），數量影響庫存扣減與訂單明細，不影響課程存取權。同時移除 `exclude_main_course` 開關，將當前課程改為普通商品項目，可自由加入/移除。

## 範圍模式：HOLD SCOPE

預估影響 12 個檔案，範圍已定，專注於向下相容與邊界情況。

## 需求

- 管理員可在銷售方案編輯介面為每個商品設定數量（1~999）
- 當前課程作為普通商品，可自由加入/移除/設定數量
- 新建方案時預設自動加入當前課程 ×1
- 數量僅影響庫存扣減，不影響課程存取權
- 前台卡片顯示 `×N`（含 N=1）
- 訂單 bundled item 數量 = 方案設定數量 × 購買份數
- 移除 `exclude_main_course` UI 開關
- 向下相容：舊方案無 `pbp_product_quantities` 時 fallback 為 1

## 架構變更

| 檔案 | 變更類型 | 說明 |
|------|----------|------|
| `inc/classes/BundleProduct/Helper.php` | 修改 | 新增 `get_product_quantities()` / `set_product_quantities()` / `get_product_quantity()` 方法 |
| `inc/classes/Api/Product.php` | 修改 | API response 新增 `pbp_product_quantities`；向下相容讀取邏輯；寫入邏輯 |
| `inc/classes/Resources/Order.php` | 修改 | bundled item 數量改為 `方案數量 × 購買份數` |
| `inc/templates/components/card/bundle-product.php` | 修改 | 前台卡片顯示 `×N` |
| `inc/templates/pages/course-product/list.php` | 修改 | 接收並顯示數量參數 |
| `js/src/components/product/ProductTable/types/index.ts` | 修改 | 新增 `pbp_product_quantities` 型別 |
| `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/BundleForm.tsx` | 修改（重點） | 移除 `exclude_main_course` 開關，當前課程改為可移除項目，新增數量 InputNumber |
| `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/index.tsx` | ���改 | 初始化向下相容邏輯，表單提交包含 quantities |
| `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/atom.tsx` | 修改 | 新增 `productQuantitiesAtom` |
| `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/utils/index.tsx` | 修改 | `getPrice()` 加入數量計算；新增 `PRODUCT_QUANTITIES_FIELD_NAME` |
| `js/src/pages/admin/Courses/Edit/tabs/CourseBundles/index.tsx` | 修改 | 新建方案時自動加入 `pbp_product_ids: [courseId]` 和 `pbp_product_quantities: {courseId: 1}` |

## 資料流分析

### 管理員建立/編輯銷售方案

```
前端表單 ──▶ 驗證 (qty 1~999) ──▶ toFormData ──▶ REST API ──▶ 儲存 meta
    │              │                    │              │            │
    ▼              ▼                    ▼              ▼            ▼
  [空值?]     [qty<1?→1]          [JSON序列化]    [sanitize]   [update_meta]
  [NaN?]      [qty>999?→拒絕]     [pbp_product    [驗證JSON]   [save_meta_data]
  [小數?→取整]                      _quantities]
```

### 讀取銷售方案（向下相容）

```
get_product_ids() ──▶ 檢查 exclude_main_course ──▶ 補入 course_id? ──▶ get_product_quantities()
       │                        │                         │                       │
       ▼                        ▼                         ▼                       ▼
  [pbp_product_ids]       [='yes'? → 不補入]       [已存在? → 跳過]        [meta 不存在?]
                          [≠'yes'? → 需補入]       [不存在? → prepend]     [→ fallback 所有商品 qty=1]
```

### 學員購買方案（訂單處理）

```
order_item ──▶ is_bundle? ──▶ get_product_ids() ──▶ get_product_quantities() ──▶ add_product()
     │              │                │                        │                       │
     ▼              ▼                ▼                        ▼                       ▼
  [qty=N]     [helper→true]   [included_ids]          [per_product_qty]        [qty = N × per_qty]
  [N=購買份數]                                         [fallback → 1]          [name = "方案 - 商品"]
                                                                               [subtotal = 0]
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|-------------|----------|---------|------------|
| `Helper::set_product_quantities()` | JSON encode 失敗 | RuntimeException | 不會發生（PHP array 總是可序列化） | 否 |
| `Helper::get_product_quantities()` | meta 不存在 | null | fallback 所有商品 qty=1 | 否（靜默） |
| `Helper::get_product_quantities()` | meta 為非法 JSON | string | json_decode 返回 null，fallback 為空 object | 否（靜默） |
| API POST `pbp_product_quantities` | qty < 1 或空值 | 驗證錯誤 | 自動修正為 1 | 否（靜默修正��� |
| API POST `pbp_product_quantities` | qty > 999 | 驗證錯誤 | 回傳 400 錯誤 | 是（API error） |
| API POST `pbp_product_quantities` | qty 為負數 | 驗證錯誤 | 回傳 400 錯誤 | ���（API error） |
| 前端 InputNumber | 輸入 0 | 前端驗證 | min=1 強制，onChange clamp | 是（欄位修正） |
| 前端 InputNumber | 輸入 > 999 | 前端驗證 | max=999 強制 | 是（欄位修正） |
| 訂單處理 `add_product()` | included_product 不存在 | null check | continue 跳過 | 否 |
| 訂單處理 quantity 溢出 | qty × 購買份數 > PHP_INT_MAX | 理論可能 | 實務不會發生（999×999=998001） | 否 |
| 向下相容讀取 | `exclude_main_course='yes'` + 新 UI 儲存 | 資料衝突 | 儲存時清除 `exclude_main_course` meta | 否 |

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|---------|------------|---------|
| API 儲存 quantities | JSON 格式錯誤 | ✅ 後端驗證 | ✅ feature spec | 是（400 error） | 修正輸入 |
| 向下相容讀取 | 舊方案無 quantities meta | ✅ fallback=1 | ✅ feature spec | 否 | 自動處理 |
| 向下相容讀取 | 舊方案 exclude=yes | ✅ 不補入 course | ✅ feature spec | 否 | 自動處理 |
| 向下相容讀取 | 舊方案 exclude=no，ids 無 course | ✅ runtime 補入 | ✅ feature spec | 否 | 自動處理 |
| 訂單庫存扣減 | 商品已下架/刪除 | ✅ null check | ✅ 現有邏輯 | 否 | 跳過該商品 |
| 課程複製 | quantities 未被複製 | ✅ WC 自動複製 meta | ⬜ 需驗證 | 否 | 自動處理 |
| 前端 quantities 與 ids 不同步 | 移除商品但 qty 殘留 | ✅ 儲存時同步 | ⬜ E2E | 否 | 下次儲存修正 |

## 實作步驟

### 第一階段：PHP 後端 — 資料層

#### 步驟 1.1：Helper.php 新增數量方法

**檔案**：`inc/classes/BundleProduct/Helper.php`

**行動**：

新增常數：
```php
const PRODUCT_QUANTITIES_META_KEY = 'pbp_product_quantities';
```

新增方法 `get_product_quantities(): array`：
```php
/**
 * 取得方案中每個商品的數量
 * 若 meta 不存在，fallback 所有商品數量為 1
 *
 * @return array<string, int> {"product_id": qty, ...}
 */
public function get_product_quantities(): array {
    $id = $this->product->get_id();
    $raw = \get_post_meta($id, self::PRODUCT_QUANTITIES_META_KEY, true);
    
    $quantities = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $quantities = $decoded;
        }
    }
    
    // fallback：如果 quantities 為空或缺少某些商品，預設為 1
    $product_ids = $this->get_product_ids();
    foreach ($product_ids as $product_id) {
        $str_id = (string) $product_id;
        if (!isset($quantities[$str_id]) || $quantities[$str_id] < 1) {
            $quantities[$str_id] = 1;
        }
    }
    
    return $quantities;
}
```

新增方法 `get_product_quantity(int $product_id): int`：
```php
/**
 * 取得方案中特定商品的數量
 *
 * @param int $product_id ���品 ID
 * @return int 數量（至少 1）
 */
public function get_product_quantity(int $product_id): int {
    $quantities = $this->get_product_quantities();
    return max(1, (int) ($quantities[(string) $product_id] ?? 1));
}
```

新增方法 `set_product_quantities(array $quantities): void`：
```php
/**
 * 儲存方案中每個商品的數量
 *
 * @param array<string|int, int> $quantities {"product_id": qty, ...}
 * @return void
 */
public function set_product_quantities(array $quantities): void {
    // 清理：確保每個 qty 至少為 1，最大為 999
    $clean = [];
    foreach ($quantities as $product_id => $qty) {
        $qty = (int) $qty;
        $clean[(string) $product_id] = max(1, min(999, $qty));
    }
    
    $this->product->update_meta_data(
        self::PRODUCT_QUANTITIES_META_KEY,
        wp_json_encode($clean)
    );
    $this->product->save_meta_data();
}
```

新增方法 `get_product_ids_with_compat(): array`（向下相容讀取）：
```php
/**
 * 取得商品 IDs（含向下相容邏輯）
 * 
 * 向下相容：若 exclude_main_course ≠ 'yes' 且 link_course_id 不在列表中，
 * 自動補入 link_course_id 到列表前面
 *
 * @return array<string> product_ids
 */
public function get_product_ids_with_compat(): array {
    $product_ids = $this->get_product_ids();
    $product_id = $this->product->get_id();
    
    $exclude_main_course = (string) \get_post_meta($product_id, 'exclude_main_course', true);
    
    // 如果 exclude_main_course = 'yes'，不補入當前��程
    if ($exclude_main_course === 'yes') {
        return $product_ids;
    }
    
    // 如果 link_course_id 有值，且不在 product_ids 中，補入
    $course_id = (string) $this->link_course_id;
    if ($this->link_course_id > 0 && !in_array($course_id, $product_ids, true)) {
        array_unshift($product_ids, $course_id);
    }
    
    return $product_ids;
}
```

**原因**：建立底層資料讀寫能力，所有上層邏輯都依賴這些方法
**依賴**：無
**風險**：低 — 純新增方法，不影響現有邏輯

---

#### 步驟 1.2：API Response 新增 quantities 與向下相容

**檔案**：`inc/classes/Api/Product.php`

**行動**：

1. 在 `format_product_details()` 方法（約 line 580）中：
   - 將 `Helper::INCLUDE_PRODUCT_IDS_META_KEY` 的值改為使用 `$helper->get_product_ids_with_compat()`
   - 新增 `pbp_product_quantities` 欄位，值為 `$helper->get_product_quantities()`
   - 移除 `exclude_main_course` 欄位（或保留但不再前端使用）

修改處（約 line 581）：
```php
// 原本：
Helper::INCLUDE_PRODUCT_IDS_META_KEY => ( $helper !== null ? $helper->get_product_ids() : [] ),
// 改為：
Helper::INCLUDE_PRODUCT_IDS_META_KEY => ( $helper !== null ? $helper->get_product_ids_with_compat() : [] ),
Helper::PRODUCT_QUANTITIES_META_KEY  => ( $helper !== null ? $helper->get_product_quantities() : (object) [] ),
```

> 注意：`get_product_quantities()` 內部已經調用 `get_product_ids()`（不是 `get_product_ids_with_compat()`），需要確保 quantities 也包含向下相容補入的 course_id。解法：`get_product_quantities()` 改為接受一個可選的 `$product_ids` 參數，或在 API 層組合：

```php
$compat_ids = $helper !== null ? $helper->get_product_ids_with_compat() : [];
$quantities = $helper !== null ? $helper->get_product_quantities() : [];

// 確保 compat 補入的 course_id 在 quantities 中也有值
foreach ($compat_ids as $pid) {
    if (!isset($quantities[(string) $pid])) {
        $quantities[(string) $pid] = 1;
    }
}
```

**原因**：API 是前後端的溝通介面，必須先定義好回傳結構
**依賴**：步驟 1.1
**風險**：低 — 新增欄位不影響既有欄位

---

#### 步驟 1.3：API 儲存邏輯處理 quantities

**檔案**：`inc/classes/Api/Product.php`

**行動**：

在 `handle_special_fields()` ���法中（約 line 917），新增對 `pbp_product_quantities` 的處理：

```php
// 在 handle_special_fields() 方法中，處理 pbp_product_quantities
$quantities_key = Helper::PRODUCT_QUANTITIES_META_KEY;
if (isset($meta_data[$quantities_key])) {
    $quantities_raw = $meta_data[$quantities_key];
    
    // 如果是字串（FormData 傳來的 JSON 字串），先解碼
    if (is_string($quantities_raw)) {
        $quantities_raw = json_decode($quantities_raw, true);
    }
    
    if (is_array($quantities_raw)) {
        // 驗證每個 qty
        $clean = [];
        foreach ($quantities_raw as $pid => $qty) {
            $qty_int = (int) $qty;
            if ($qty_int < 0 || $qty_int > 999) {
                // qty > 999 或負數：回傳錯誤（由呼叫方處理）
                // 但在 handle_special_fields 中較難回傳錯誤
                // 改為在呼叫前驗證，此處只做 clamp
            }
            $clean[(string) $pid] = max(1, min(999, $qty_int));
        }
        $product->update_meta_data($quantities_key, wp_json_encode($clean));
    }
    
    unset($meta_data[$quantities_key]);
}
```

同時，在 `post_bundle_products_callback()` 和 `post_bundle_products_with_id_callback()` 中：
- 儲存時清除 `exclude_main_course` meta（向下相容遷移）

```php
// 在 save 成功後：
\delete_post_meta($product->get_id(), 'exclude_main_course');
```

**原因**：API 必須能正確儲存前端傳來的數量資料
**依賴**：步驟 1.1
**風險**：中 — 需確保 FormData 中的 JSON 字串正確解碼。注意 `WP::sanitize_text_field_deep()` 可能會對 JSON 字串做 sanitize，需測試確認。若有問題，改為在 sanitize 前提取 quantities。

---

### 第二階段：PHP 後端 — 訂單處理

#### ���驟 2.1：修改訂單 bundled item 數量邏輯

**檔案**：`inc/classes/Resources/Order.php`

**行動**：

修改 `_handle_add_course_item_meta_by_order()` 方法（約 line 95-136）：

原始碼（line 112-134）：
```php
$helper = Helper::instance( $product );
if ( $helper?->is_bundle_product ) {
    $included_product_ids = $helper?->get_product_ids() ?: [];
    foreach ( $included_product_ids as $included_product_id ) {
        $included_product = \wc_get_product( $included_product_id );
        if ( ! $included_product ) {
            continue;
        }
        $qty = $item->get_quantity() ?: 1;
        $order->add_product(
            $included_product,
            $qty,
            [
                'name'     => $product->get_name() . ' - ' . $included_product->get_name(),
                'subtotal' => 0,
                'total'    => 0,
            ]
        );
    }
    $order->save();
}
```

改為：
```php
$helper = Helper::instance( $product );
if ( $helper?->is_bundle_product ) {
    $included_product_ids = $helper?->get_product_ids_with_compat() ?: [];
    $quantities           = $helper?->get_product_quantities() ?: [];
    $order_qty            = $item->get_quantity() ?: 1; // 購買份數

    foreach ( $included_product_ids as $included_product_id ) {
        $included_product = \wc_get_product( $included_product_id );
        if ( ! $included_product ) {
            continue;
        }
        
        // 方案設定數量（fallback 為 1）
        $bundle_qty = max(1, (int) ($quantities[(string) $included_product_id] ?? 1));
        // 最終數量 = 方案設定數量 × 購買份數
        $final_qty = $bundle_qty * $order_qty;
        
        $order->add_product(
            $included_product,
            $final_qty,
            [
                'name'     => $product->get_name() . ' - ' . $included_product->get_name(),
                'subtotal' => 0,
                'total'    => 0,
            ]
        );
    }
    $order->save();
}
```

**原因**：核心業務邏輯，購買方案後庫存扣減必須正確
**依賴**：步驟 1.1
**風險**：高 — 直接影響訂單金額與庫存。需確保 `$final_qty` 計算正確。舊方案沒有 quantities meta 時 fallback 為 1，行為與更新前一致。

---

### 第三階段：React 前端 — 型別與狀態

#### 步驟 3.1：更新 TypeScript 型別

**檔案**：`js/src/components/product/ProductTable/types/index.ts`

**行動**：

在 `TProductRecord` 中新增：
```typescript
pbp_product_quantities: Record<string, number>
```

移除（或保留但標記 deprecated）：
```typescript
// deprecated — Issue #185 移除，保留向下相容
exclude_main_course: 'yes' | 'no' | ''
```

**原因**：TypeScript 型別是前端開發的基礎
**依賴**：無
**��險**：低

---

#### 步驟 3.2：新增 quantities atom

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/atom.tsx`

**��動**：

新增：
```typescript
// 各商品數量 {"product_id": qty}
export const productQuantitiesAtom = atom<Record<string, number>>({})
```

**原因**：獨立管理 quantities 狀態，方便各元件存取
**依賴**：無
**風險**：低

---

#### 步驟 3.3：更新 utils 常數與價格計算

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/utils/index.tsx`

**行動**：

1. 新增常數：
```typescript
export const PRODUCT_QUANTITIES_FIELD_NAME = 'pbp_product_quantities'
```

2. 修改 `getPrice()` 函式，加入數量參���：
```typescript
export const getPrice = ({
  isFetching = false,
  type,
  products,
  course,
  returnType = 'number',
  quantities = {},
  courseId,
}: {
  isFetching?: boolean
  type: 'regular_price' | 'sale_price'
  products: TBundleProductRecord[] | undefined
  course: TCourseRecord | undefined
  returnType?: 'string' | 'number'
  quantities?: Record<string, number>
  courseId?: string
}): React.ReactNode => {
  if (isFetching) {
    return <div className="w-20 bg-slate-300 animate-pulse h-3 inline-block" />
  }
  
  // 課程價格 × 數量（如果課程在方案中）
  const courseQty = courseId ? (quantities[String(courseId)] ?? 1) : 0
  const coursePrice = Number(course?.[type] || course?.regular_price || 0) * courseQty
  
  // 其他商品價格 × 各自數量
  const productsTotal = Number(
    products?.reduce((acc, product) => {
      const qty = quantities[String(product.id)] ?? 1
      return acc + Number(product?.[type] || product.regular_price) * qty
    }, 0)
  )
  
  const total = productsTotal + coursePrice

  if ('number' === returnType) return total
  return `NT$ ${total?.toLocaleString()}`
}
```

> 注意：移除 `excludeMainCourse` 參數。當前課程是否在方案中由 `selectedProducts` + `courseId 是否在 pbp_product_ids 中` 決定。

**原因**：價格計算必須反映數量
**依賴**：步驟 3.1
**������**：低

---

### 第四階段：React 前端 — BundleForm 重構（核心）

#### 步��� 4.1：重構 BundleForm.tsx

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/BundleForm.tsx`

**行動**（這是最大的變更，詳細拆解）：

**4.1a：移除 exclude_main_course 相關邏輯**

- 移除 `watchExcludeMainCourse` 變數和相關的 `Form.useWatch`
- 移除 `FiSwitch` 元件（排除目前課程開關）
- 移除所有 `watchExcludeMainCourse` 條件判斷
- 移除 `excludeMainCourse` 在 `getPrice()` 呼叫中的參數
- import 列表移除 `FiSwitch`

**4.1b：當前課程改為可移除/可加入的普通商品**

原本當前課程是獨立渲染的區塊（line 242-258），帶有「目前課程」Tag 和 opacity 控制。

改為：
- 將當前課程納入 `selectedProducts` 列表中管理
- 初始化時：從 record 的 `pbp_product_ids` 判斷課程是否在方案中
  - 如果在 → 加入 selectedProducts
  - 如果不在 → 不加入
- 新建方案時 → 預設加入當前課程（由 `CourseBundles/index.tsx` 處理初始 `pbp_product_ids`）
- 刪除原本獨立渲染當前課程的區塊
- 當前課程在列表中以「目前課程」Tag 標示，但可以用 PopconfirmDelete 移除
- 搜尋商品時，如果當前課程不在方案中，搜尋結果應能搜到當前課程

搜尋過濾修改：移除 `exclude` filter 中的 `courseId`（原本排除當前課程）。改為在 `handleClick` 中判斷是否已存在。

```typescript
// 搜尋時不再排除當前課程
filters: [
  // ... 其他 filters
  // 移除 exclude filter，或改為排除已選中的商品
]
```

**4.1c：每個商品新增數量 InputNumber**

引入 Jotai atom：
```typescript
import { productQuantitiesAtom } from './atom'
const [quantities, setQuantities] = useAtom(productQuantitiesAtom)
```

在每個已選中商品的列表項中（line 325-366），在商品名稱和刪除按鈕之間加入：
```tsx
<InputNumber
  min={1}
  max={999}
  value={quantities[String(id)] ?? 1}
  onChange={(val) => {
    setQuantities((prev) => ({
      ...prev,
      [String(id)]: Math.max(1, val ?? 1),
    }))
  }}
  className="w-20"
  size="small"
/>
```

當前課程若在列表中，同樣顯示 InputNumber。

**4.1d：同步 quantities 到表單**

在已有的 `useEffect` 中（line 144-166），新增 quantities 同步：

```typescript
useEffect(() => {
  // 同步 product_ids 到表單
  const allProducts = [
    ...selectedProducts.filter(({ id }) => String(id) !== String(courseId)),
  ]
  // 如果當前課程在已選商品中，放在最前面
  const courseInSelected = selectedProducts.some(
    ({ id }) => String(id) === String(courseId)
  )
  const productIds = courseInSelected
    ? [courseId, ...allProducts.map(({ id }) => id)]
    : allProducts.map(({ id }) => id)
  
  bundleProductForm.setFieldValue([INCLUDED_PRODUCT_IDS_FIELD_NAME], productIds)
  
  // 同步 quantities 到表單
  bundleProductForm.setFieldValue([PRODUCT_QUANTITIES_FIELD_NAME], quantities)
  
  // 同步價格
  bundleProductForm.setFieldValue(
    ['regular_price'],
    getPrice({
      type: 'regular_price',
      products: allProducts,
      course: courseInSelected ? course : undefined,
      quantities,
      courseId: courseInSelected ? String(courseId) : undefined,
    })
  )
}, [selectedProducts, quantities])
```

**4.1e：初始化 quantities**

在初始化 `selectedProducts` 的 `useEffect` 中（line 137-142），同時初始化 quantities：

```typescript
useEffect(() => {
  if (!initIsFetching) {
    // 初始化商品（含當前課程）
    const initProducts = [...includedProducts]
    
    // 如果當前課程在 pbp_product_ids 中，且不在 initProducts 中
    // 需要將 course 也加入 selectedProducts
    if (
      record?.[INCLUDED_PRODUCT_IDS_FIELD_NAME]?.includes(courseId) &&
      !initProducts.some(({ id }) => String(id) === String(courseId))
    ) {
      // 用 course 資料建構一個 product-like 物件加入
      if (course) {
        initProducts.unshift(course as unknown as TBundleProductRecord)
      }
    }
    
    setSelectedProducts(initProducts)
    
    // 初始化 quantities
    const initQuantities = record?.pbp_product_quantities ?? {}
    setQuantities(initQuantities)
  }
}, [initIsFetching])
```

**原因**：這是使用者直接互動的核心 UI，需要正確處理所有邊界情況
**依賴**：步驟 3.1, 3.2, 3.3
**風險**：高 — UI 邏輯複雜，需要仔細測試：
- 新建方案 → 當前課程自動加入
- 編輯舊方案（exclude=yes）→ 不含當前課程
- 編輯舊方案（exclude=no）→ 含當前課程
- 移除當前課程 → 可透過搜尋重新加入
- 修改數量 → 正確同步��表單
- 重複商品防護

---

#### 步驟 4.2：更新 Edit/index.tsx

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/index.tsx`

**行動**：

1. 移除 `watchExcludeMainCourse` 變數
2. 修改驗證邏輯（line 64-71）：
```typescript
// 原本：
if (
  !selectedProducts?.length &&
  values?.bundle_type === 'bundle' &&
  watchExcludeMainCourse
) {
// 改為（直接檢查 selectedProducts 是否為空）：
if (!selectedProducts?.length && values?.bundle_type === 'bundle') {
```

3. 在表單提交時確保 `pbp_product_quantities` 被正確序列化為 JSON 字串（因為 `toFormData` 不能直接處理嵌套物件）：
```typescript
const handleOnFinish = () => {
  const values = form.getFieldsValue() as Partial<TBundleProductRecord> & {
    bundle_type: 'bundle'
    sale_date_range: [Dayjs | number, Dayjs | number]
    pbp_product_quantities?: Record<string, number>
  }
  // ...existing validation...
  
  form.validateFields().then(() => {
    const formattedValues = formatDateRangeData(values, 'sale_date_range', [
      'date_on_sale_from',
      'date_on_sale_to',
    ])
    
    // 確保 quantities 序列化為 JSON 字串
    if (formattedValues.pbp_product_quantities) {
      formattedValues.pbp_product_quantities = JSON.stringify(
        formattedValues.pbp_product_quantities
      )
    }
    
    onFinish(toFormData(formattedValues))
  })
}
```

4. 移除 Alert 中的「排除當前課程」相關提示文字

**原因**：表單提交邏輯需要配合新的資料結構
**依賴**：步�� 4.1
**風險**：��� — 需確保 `toFormData` 正確序列化 quantities

---

#### 步驟 4.3：更新新建方案��輯

**檔案**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/index.tsx`

**行動**：

修改 `handleCreate`（line 95-111），新建方案時自動帶入當前課程：

```typescript
const handleCreate = () => {
  const values = {
    status: 'publish',
    bundle_type: 'bundle',
    name: '銷售方案',
    link_course_ids: [courseId],
    pbp_product_ids: [courseId],    // 預設加入當前課程
    pbp_product_quantities: JSON.stringify({ [String(courseId)]: 1 }), // 數量 1
  }
  const formData = toFormData(values)
  create({
    dataProviderName: 'power-course',
    resource: 'bundle_products',
    values: formData,
    invalidates: ['list'],
  })
}
```

**原因**：新建方案時預設加入當前課程 ×1
**依賴**：���驟 1.3
**風險**：低

---

### 第五階段：PHP 前台模板

#### 步驟 5.1：更新 bundle-product.php 卡片顯示

**檔案**：`inc/templates/components/card/bundle-product.php`

**行動**：

1. 使用 `get_product_ids_with_compat()` 取代 `get_product_ids()`
2. 取得 quantities
3. 傳遞數量到子模板

修改（約 line 31）：
```php
$pbp_product_ids  = $helper?->get_product_ids_with_compat() ?? [];
$pbp_quantities   = $helper?->get_product_quantities() ?? [];
```

修改 foreach 迴圈（約 line 85-99���：
```php
foreach ( $pbp_product_ids as $pbp_product_id ) :
    if (!is_numeric($pbp_product_id)) {
        continue;
    }
    $pbp_product = \wc_get_product( $pbp_product_id );
    $qty = (int) ($pbp_quantities[(string) $pbp_product_id] ?? 1);
    echo '<div>';
    Plugin::load_template(
        'course-product/list',
        [
            'product'  => $pbp_product,
            'quantity' => $qty,
        ]
    );
    echo '</div>';
    Plugin::load_template( 'divider' );
endforeach;
```

#### 步驟 5.2：更新 course-product/list.php 顯示數量

**檔案**：`inc/templates/pages/course-product/list.php`

**行動**：

新增 `quantity` 參數支援，在商品名稱後方顯示 `×N`���

```php
$default_args = [
    'product'  => $GLOBALS['course'] ?? null,
    'quantity' => 1,
];

// ...existing code...

[
    'product'  => $product,
    'quantity' => $quantity,
] = $args;

// 在 printf 中的商品名稱後面加上 ×N
$qty_html = sprintf(' <span class="text-primary font-bold">×%d</span>', (int) $quantity);

printf(
    '
<div class="grid grid-cols-[1fr_2fr] gap-5">
    <div class="group aspect-video rounded overflow-hidden">
        <img class="w-full h-full object-cover group-hover:scale-105 duration-500 transition ease-in-out" src="%1$s" alt="%2$s" loading="lazy" decoding="async">
    </div>
    <div>
        <h6 class="text-sm font-semibold mb-1">%2$s%4$s</h6>
        <del class="tw-block text-xs text-gray-600">%3$s</del>
    </div>
</div>',
    $product_image_url,
    $product_name,
    $regular_price_html,
    $qty_html
);
```

**原因**：前台卡片需要顯示各商品數量
**依賴**：步驟 1.1
**風險**：低

---

### 第六階段：課程複製

#### 步驟 6.1：驗證複製邏輯

**檔案**：`inc/classes/Utils/Duplicate.php`

**行動**：

`WC_Admin_Duplicate_Product::product_duplicate()` 會自動複製所有 postmeta，包括 `pbp_product_quantities`。但需要確認：

1. `pbp_product_quantities` 是以 `update_meta_data()` 儲存的（單一 meta row），`product_duplicate()` 會正確複製
2. 複製後需要更新 quantities 中的 course_id：如果原方案包含原課程 ID，複製後應指向新課程 ID

在 `duplicate_product()` 方法中（line 158-186），在 `update_meta_data(Helper::LINK_COURSE_IDS_META_KEY, ...)` 之後新增：

```php
// 更新 pbp_product_quantities 中的課程 ID 對應
$quantities_json = $new_product->get_meta(Helper::PRODUCT_QUANTITIES_META_KEY);
if ($quantities_json) {
    $quantities = json_decode($quantities_json, true);
    if (is_array($quantities) && isset($quantities[(string) $post_id])) {
        // 將原課程 ID 的數量轉移到新課程 ID
        // 注意：$post_id 是原商品 ID，但在 bundle 複製場景中
        // $post_id 是 bundle product ID，$new_parent 是新課程 ID
        // 需要的是 link_course_id（原課程 ID）→ $new_parent（新課程 ID）
    }
}
```

> 實際上，在 `duplicate_bundle_product()` 呼叫 `$duplicate->process()` 時，`$new_parent = $new_id`（新課程 ID）。在 `duplicate_product()` 中 `$post_id` 是銷售方案 ID。我們需要知道原課程 ID。

修正方案：
```php
if (is_numeric($new_parent)) {
    // 更新銷售方案的 link_course_ids
    $new_product->update_meta_data(Helper::LINK_COURSE_IDS_META_KEY, (string) $new_parent);
    
    // 更新 pbp_product_ids 中的原課程 ID → 新課程 ID
    $old_course_id = (string) \get_post_meta($post_id, Helper::LINK_COURSE_IDS_META_KEY, true);
    if ($old_course_id) {
        // 更新 pbp_product_ids
        $old_product_ids = $new_product->get_meta(Helper::INCLUDE_PRODUCT_IDS_META_KEY);
        // 因為 pbp_product_ids 是多筆 meta row，需要逐筆檢查替換
        // 使用 Helper 的方法操作
        $helper = Helper::instance($new_product);
        if ($helper) {
            $product_ids = $helper->get_product_ids();
            $updated_ids = array_map(function($pid) use ($old_course_id, $new_parent) {
                return $pid === $old_course_id ? (string) $new_parent : $pid;
            }, $product_ids);
            $helper->set_bundled_ids(array_map('intval', $updated_ids));
        }
        
        // 更新 pbp_product_quantities 中的 key
        $quantities_json = $new_product->get_meta(Helper::PRODUCT_QUANTITIES_META_KEY);
        if ($quantities_json) {
            $quantities = json_decode($quantities_json, true);
            if (is_array($quantities) && isset($quantities[$old_course_id])) {
                $quantities[(string) $new_parent] = $quantities[$old_course_id];
                unset($quantities[$old_course_id]);
                $new_product->update_meta_data(
                    Helper::PRODUCT_QUANTITIES_META_KEY,
                    wp_json_encode($quantities)
                );
            }
        }
    }
    
    $new_product->save_meta_data();
}
```

**原因**：複製課程時，銷售方案中的原課程 ID 需要替換為新課程 ID
**依賴**：步驟 1.1
**風險**：中 — 需確保 pbp_product_ids（多筆 meta row）和 pbp_product_quantities（JSON 字串）中的課程 ID 都被正確替換

---

## 測試策略

> 此 section 供 tdd-coordinator 交給 test-creator 執行

### PHP Integration Test

測試檔案：`tests/integration/bundle-quantity-test.php`（或依現有結構）

- **數量儲存與讀取**：
  - 設定 quantities → 讀取 → 驗證一致
  - 未設定 quantities → 讀取 → fallback 為 1
  - quantities 部分缺失 → 缺失的 fallback 為 1
  - qty=0 → 自動修正為 1
  - qty=1000 → clamp 為 999
  - qty=-1 → clamp 為 1

- **向下相容**：
  - exclude_main_course='yes' → get_product_ids_with_compat() 不含 course
  - exclude_main_course='no' → get_product_ids_with_compat() 含 course
  - exclude_main_course 為空 → 等同 'no'
  - pbp_product_ids 已含 course → 不重複加入

- **訂單處理**：
  - 方案設定 qty=2, 購買 1 份 → bundled item qty=2
  - 方案設定 qty=3, 購買 2 份 → bundled item qty=6
  - 舊方案（無 quantities）→ bundled item qty=1

### E2E 測試

測試檔案：`tests/e2e/01-admin/bundle-quantity.spec.ts`

- **管理端**：
  - 建立銷售方案 → 當前課程自動加入 → 修改數量 → 儲存 → 重新載入 → 數量正確
  - 移除當前課程 → 透過搜尋重新加入 → 數量正確
  - 數量輸入邊界：0 → 自動變 1, 999 → 正常, 1000 → 限制
  - 不再顯示「排除當前課程」開關

- **前台**：
  - 銷售方案卡片顯示 ×N
  - N=1 也顯示 ×1

- **整合**：
  - 購買銷售方案 → 訂單中 bundled items 數量正確 → 庫存正確扣減
  - 複製課程 → 新方案數量資訊正確

### 測試執行指令
```bash
composer run test                    # PHPUnit
pnpm run test:e2e:admin             # 管理端 E2E
pnpm run test:e2e:frontend          # 前台 E2E
pnpm run test:e2e:integration       # 整合 E2E
```

### 關鍵邊界情況

1. 舊方案（無 `pbp_product_quantities` meta）→ 所有商品 qty fallback 1
2. 舊方案 `exclude_main_course='yes'` → 不含當前課程
3. 舊方案 `exclude_main_course='no'`，`pbp_product_ids` 不含 course → runtime 補入
4. 舊方��� `exclude_main_course='no'`，`pbp_product_ids` 已含 course → 不重複
5. 數量 0、空值、負數 → 修正為 1
6. 數量 > 999 → 後端拒絕、前端限制
7. 重複加入同一商品 → 提示已存在
8. 移除商品後 quantities 中殘留 key → 不影響（只讀取 pbp_product_ids 中存在的）
9. 購買 N 份方案 → 每個 bundled item qty = 方案設定 × N
10. 課程複製 → quantities 中的 course_id 替換為新 course_id

## 風險與緩解措施

- **風險**：`WP::sanitize_text_field_deep()` 可能破壞 JSON 字串格式
  - 緩解：在 `handle_special_fields()` 中，若收到的是字串型別，先 `json_decode` 再處理。或在 `sanitize_text_field_deep` 之前提取 quantities 值。需在步驟 1.3 中測試確認。

- **風險**：FormData 序列化 nested object 的行為不一致
  - 緩解：在前端提交時，將 `pbp_product_quantities` 先 `JSON.stringify()` 再放入 FormData，後端以 JSON 字串接收。

- **風險**：向下相容邏輯可能導致 course_id 重複加入
  - 緩解：`get_product_ids_with_compat()` 已有 `in_array` 檢查防止重複。

- **風險**：BundleForm.tsx 重構範圍大，可能引入回歸 bug
  - 緩解：拆分為明確的子步驟（4.1a~4.1e），每步可獨立驗證。E2E 測試覆蓋核心流程。

- **風險**：課程複製時 pbp_product_ids（多筆 meta row）中的課程 ID 可能未被替換
  - 緩解：步驟 6.1 中明確處理此情況，使用 `set_bundled_ids()` 方法替換。

## 成功標準

- [ ] 管理員可在銷售方案編輯介面看到每個商品旁的 InputNumber（1~999）
- [ ] 當前課程作為普通商品出現在方案中，可自由加入/移除/設定數量
- [ ] 新建方案時預設加入當前課程 ��1
- [ ] 不再顯示「排除當���課程」開關
- [ ] 數量預設為 1，最小值 1，最大值 999
- [ ] 儲存方案後重新開啟，數量正確顯示
- [ ] 前台銷售方案卡片顯示各商品 ×N（含 N=1）
- [ ] 購買方案後，bundled item qty = 方案設定數量 × 購買份數
- [ ] 庫存依 bundled item qty 正���扣減
- [ ] 舊方案（無 pbp_product_quantities）正常顯示，行為不變（qty=1）
- [ ] 舊方案 exclude_main_course='yes' → 不含當前課程
- [ ] 舊方案 exclude_main_course='no' → 自動補入當前課程
- [ ] 課程複製時，數量資訊一併複製，課程 ID 正確替換
- [ ] PHP lint（phpcs + phpstan level 9）通過
- [ ] TypeScript 編譯通過
- [ ] E2E 測試通過
