# 實作計劃：StudentEditModal 對齊 Power-shop User Edit 卡片（max-width 1280px）

> 範圍模式：**EXPANSION**（功能對齊既有姊妹外掛、目標明確「一模一樣」，但牽涉多檔案新增與後端資料擴充）。
> 交付角色：後端步驟 → **PHP 後端 agent**；前端步驟 → **react-master**。
> 此文件由 planner 產出，不含任何實作；供主窗口 dispatch。

## 概述

把 power-course 後台「學員快速編輯 Modal」(`StudentEditModal`) 的內容，做成跟 power-shop「User Edit 卡片」**一模一樣**（雙欄：左消費數據 + 用戶資料 Tabs；右聯絡註記 + 購物車 + 最近訂單），並把 `SimpleModal` 寬度從 720 改為 **1280**。power-course 是 standalone LMS，**不依賴 power-shop**，所有後端資料（消費統計 / 購物車 / 最近訂單 / 聯絡註記）必須用 WooCommerce 核心函式 + WP comments **自含實作**。

## 需求重述

- 視覺與互動行為對齊 power-shop `js/src/pages/admin/Users/Edit/Detail/index.tsx`（雙欄 grid）。
- 左欄：消費數據（6 個 `Statistic`）+ 用戶資料（`Tabs`：基本資料 / 自動填入 / 其他欄位）。
- 右欄：聯絡註記（`Timeline`，可新增/刪除內部備註）+ 購物車（當前 persistent cart）+ 最近訂單（近 N 筆含商品縮圖）。
- 基本資料含 view↔edit 切換，欄位：姓名(last/first)、顯示名稱、Email、角色 role、生日 user_birthday、簡介 description、密碼（直接改密碼雙確認 + 寄重設信）。
- `SimpleModal width={1280}`。
- i18n：英文 msgid + `'power-course'` text domain；power-shop 參考檔的中文字串一律轉英文 msgid。

## 已知風險（來自研究）

- **風險：power-shop 的 `users/{id}`(rich) / `users/options` / `comments` 後端在任何 plugin 原始碼中都搜不到** — 它們由共用 companion（antd-toolkit 的 PHP 伴生/編譯產物）提供，**power-course 無法借用**。緩解：全部自含實作於 `inc/classes/Api/User.php`（含新 `comments` 端點），不跨外掛 REST。
- **風險：persistent cart 多站 blog_id** — meta key 為 `_woocommerce_persistent_cart_{blog_id}`。緩解：用 `get_current_blog_id()` 動態組 key，**禁止硬編碼 `_1`**。
- **風險：WC 統計函式效能** — `wc_get_customer_total_spend()` / `wc_get_customer_order_count()` 在大訂單量下可能慢。緩解：單一 user 查詢可接受；但 `recent_orders` 與 `orders-summary` 用 `limit` 限制筆數，cart/orders 的商品縮圖以 `wc_placeholder_img_src()` fallback。
- **風險：`comments` resource 在 power-course 走錯 dataProvider** — power-shop 的 ContactRemarks 用 default provider；power-course 的 `power-course` provider 才指向 `/wp-json/power-course`。緩解：所有 `useCreate`/`DeleteButton`/`useList` 對 `comments` resource **必須顯式帶 `dataProviderName: 'power-course'`**，且後端把 comments 端點註冊在 `power-course` namespace。
- **風險：large payload** — UserDetail 一次回傳統計 + cart + recent_orders + contact_remarks，payload 變大。緩解：保留既有 `orders-summary` 端點分離；recent_orders 限 5 筆、contact_remarks 不分頁但單一 user 量可控；GET callback 第一行 `nocache_headers()`。
- **風險：role 更新權限** — 改 role 需 `edit_users`（已具備），但不得允許把自己降權造成鎖死。緩解：後端更新 role 時若 `$user_id === get_current_user_id()` 則忽略 role 變更（防自我降權）。
- 未發現其他額外已知風險。

## 架構決策（第一性原理）

1. **後端自含**：grep 確認 power-course `inc/` 從未 reference power-shop；power-shop 後端是 DDD `Domains/ProfitShop`，與 User REST 無關。結論：擴充 `inc/classes/Api/User.php`，用 WC 核心函式計算統計、persistent cart meta 讀購物車、`wc_get_orders()` 讀最近訂單。
2. **聯絡註記用 WP comments**：自建 `comment_type='contact_remark'` 的 list/create/delete 三端點，註冊於 `power-course` namespace。資料儲存於 `wp_comments`（`comment_post_ID=0`，被留言對象 user_id 存於 commentmeta `commented_user_id`）。
3. **資料形狀對齊 `antd-toolkit/wp` 的 `TUserBaseRecord`**：該型別已定義 `total_spend / orders_count / avg_order_value / date_last_active / date_last_order / user_registered / user_registered_human / user_birthday / description / role / edit_url`。**這是後端 GET `users/{id}` 必須鏡像的契約**——前端統計區直接吃這些欄位即可零摩擦對齊 power-shop。
4. **前端用 Context 而非 atom 傳遞 record/isEditing**：power-shop 子元件（Basic/AutoFill/Meta/Cart/RecentOrders/ContactRemarks）全靠 `useRecord()`/`useIsEditing()`（React Context）。power-course 在 Modal 內建立同名 Context Provider，讓子元件零改動移植（中文字串轉 i18n 除外）。
5. **SimpleModal `width` 即內容寬度**：型別定義確認無 `open` prop，`width={1280}` 直接達標。

---

## 端點命名空間校正（重要）

- 既有 `User` API `$namespace = 'power-course'`，`power-course` dataProvider 映射到 `/wp-json/power-course`（`App1.tsx` L55-58，`${API_URL}/${KEBAB}`）。
- 既有端點實際路徑為 `/wp-json/power-course/users/{id}`（**非** `/v2`；`api.yml` 的 `/v2` 字樣為文件殘留，以程式碼為準）。
- 新端點一律掛在同一 `power-course` namespace：
  - `GET  users/{id}`（擴充既有回傳）
  - `POST users/{id}`（擴充支援 role / description / user_birthday）
  - `GET  users/options`（roles 清單）
  - `GET  comments`（list contact_remark，query: `commented_user_id`）
  - `POST comments`（create）
  - `DELETE comments/{id}`（delete）
  - `GET  users/{id}/orders-summary`（擴充 recent[].order_items[]，向下相容）

---

## 資料流分析

### 流程 A：載入學員詳細（GET users/{id}）

```
REQUEST(id) ─▶ get_userdata ─▶ collect[meta+stats+cart+orders+remarks] ─▶ shape ─▶ RESPONSE
   │               │                  │                                      │          │
   ▼               ▼                  ▼                                      ▼          ▼
[id 非數字?]   [user 不存在?404]  [WC 函式丟例外?]                      [欄位缺漏?]  [payload 過大?]
[未登入?401]                     [cart meta 空?→[]]                    [null→''/0]  [限筆數]
[無edit_users?403]               [無訂單?→stats=0]
                                 [商品已刪?→placeholder img]
```

### 流程 B：新增聯絡註記（POST comments）

```
REQUEST{note,is_customer_note,commented_user_id} ─▶ validate ─▶ wp_insert_comment ─▶ add commentmeta ─▶ RESPONSE
        │                                              │              │                    │              │
        ▼                                              ▼              ▼                    ▼              ▼
   [note 空?]                                  [user 不存在?]   [insert 失敗?]      [meta 寫入失敗?]  [回傳新 comment]
   [commented_user_id 缺?]                     [無edit_users?403]                                    [invalidate users detail]
```

### 流程 C：刪除聯絡註記（DELETE comments/{id})

```
REQUEST(comment_id) ─▶ load comment ─▶ assert type=contact_remark ─▶ wp_delete_comment(force) ─▶ RESPONSE
       │                   │                    │                            │                       │
       ▼                   ▼                    ▼                            ▼                       ▼
   [id 非數字?]        [不存在?404]      [非 contact_remark?403 拒刪]   [刪除失敗?500]          [invalidate users detail]
   [無edit_users?403]
```

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --- | --- | --- | --- | --- |
| GET users/{id} | user 不存在 | 404 | `WP_Error user_not_found` | 是（Modal 顯示空/錯誤 message） |
| GET users/{id} | WC 統計函式丟例外 | 500/降級 | try/catch，統計欄位 fallback 0/'' | 否（靜默降級，數據顯示 0） |
| GET users/{id} | persistent cart meta 不存在 | 空集合 | cart=[] | 是（購物車顯示「empty」） |
| GET users/{id} | cart/order 商品已被刪除 | nil path | `wc_placeholder_img_src()` + 商品名 fallback `(deleted)` | 是（顯示 placeholder） |
| POST users/{id} | role 改成不存在角色 | 驗證 | 以 `wp_roles()->is_role()` 檢核，非法則忽略 | 否（靜默忽略該欄位） |
| POST users/{id} | 自我降權 | 防呆 | `$user_id===current_user?` 則忽略 role | 否 |
| POST users/{id} | user_birthday 格式非 YYYY-MM-DD | 驗證 | regex 檢核，不符則不寫入該 meta | 否 |
| POST comments | note 空白 | 400 | `WP_Error empty_note` | 是（前端按鈕後 message.error） |
| POST comments | commented_user_id 對應 user 不存在 | 400 | `WP_Error invalid_user` | 是 |
| DELETE comments/{id} | comment 非 contact_remark | 403 | 拒刪，回 `WP_Error forbidden_comment_type` | 是 |
| DELETE comments/{id} | comment 不存在 | 404 | `WP_Error comment_not_found` | 是 |
| 所有寫入 | 無 edit_users | 403 | 既有 `check_edit_users_permission` | 是 |
| 前端 save | 網路/伺服器 500 | onError | `message.error`（既有模式） | 是 |
| 前端 ContactRemarks create | 同上 | onError | Refine notificationProvider | 是 |

> 無「處理方式=無 且 使用者可見=靜默」項目 → 無 CRITICAL GAP。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| --- | --- | --- | --- | --- | --- |
| GET users/{id} 統計 | 大訂單量慢查詢 | 部分（限 user 單筆） | E2E smoke | 否 | 可接受；未來加 cache |
| persistent cart key | 多站 blog_id 錯誤 | 是（動態 key） | 無（多站需手測） | 是 | 改用 `get_current_blog_id()` |
| comments resource | 路由到錯 dataProvider | 是（顯式 dataProviderName） | E2E（remark 新增） | 是 | 顯式指定 provider |
| 編輯模式中途關 Modal | 表單殘留 | 是（destroyOnHidden + resetFields） | 既有 spec | 否 | handleClose 重置 |
| 重複點「新增備註」 | 雙重送出 | 是（isLoading 鎖按鈕） | 無 | 否 | Refine isLoading |
| role 自我降權 | 管理員鎖死 | 是（後端忽略） | 無（建議加 IT） | 否 | 後端守門 |

---

## 實作步驟

> 依賴總綱：**Phase 1（後端資料 schema）必須先完成並定稿回傳形狀**，Phase 3（型別）與 Phase 4（前端）才能對接。Phase 2（後端 comments）與 Phase 1 可同一 agent 連續做。Phase 3 與 Phase 4 的 SimpleModal 寬度子任務（4-0）可立即平行（無依賴）。

### 第一階段（後端 / PHP 後端 agent）：擴充 User REST 資料

**全部檔案：`inc/classes/Api/User.php`**（PHPStan level 9、PHPCS WordPress、`declare(strict_types=1)` 已在檔頭）

1. **擴充 `get_users_with_id_callback`（L213-271）回傳形狀**
   - 行動：在既有 `id/user_login/name/display_name/email/meta_data` 之外，新增頂層欄位以**鏡像 `antd-toolkit/wp` 的 `TUserBaseRecord`**：
     - `total_spend`（`(float) wc_get_customer_total_spend($user_id)`）
     - `orders_count`（`(int) wc_get_customer_order_count($user_id)`）
     - `avg_order_value`（`orders_count ? round(total_spend/orders_count, 2) : 0`）
     - `date_last_active`（讀 `wc_get_customer_last_active`/meta `wc_last_active`，無則 `''`）
     - `date_last_order`（最近一筆訂單日期，`wc_get_customer_last_order()` 的 date_created，`'c'` 格式或 `''`）
     - `user_registered`（`$user->user_registered`）
     - `user_registered_human`（`human_time_diff(strtotime(user_registered))`）
     - `user_avatar_url`（`get_avatar_url($user_id)`）
     - `user_birthday`（meta `user_birthday`，`''`）
     - `description`（meta `description`）
     - `role`（`$user->roles[0] ?? ''`）
     - `edit_url`（`get_edit_user_link($user_id)`）
     - `billing`（物件：`{first_name,last_name,email,phone,company,country,state,postcode,city,address_1,address_2}` 讀 `billing_*` meta）
     - `shipping`（同上去 email/company/phone，視 power-shop InfoTable 欄位）
     - `cart`（見步驟 2）
     - `recent_orders`（見步驟 3）
     - `contact_remarks`（見 Phase 2 步驟 7 的共用 reader）
     - `other_meta_data`（見步驟 4）
   - 原因：前端統計區與 AutoFill/InfoTable、Cart、RecentOrders、Meta、ContactRemarks 全部直接吃這些欄位，達成「一模一樣」。
   - 依賴：無（但 cart/orders/remarks 子方法須先有）。
   - 風險：中（payload 變大、WC 函式效能）。
   - 註：保留既有扁平 `meta_data{billing_*,shipping_*}` 不刪，向下相容既有 `StudentEditModal` 與 E2E（避免破壞 issue/229 已綠的測試）。

2. **新增私有方法 `get_persistent_cart( int $user_id ): array`**
   - 行動：讀 `get_user_meta($user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true)`，解析其中 `cart` 陣列；對每個 item 用 `wc_get_product($product_id)` 取 `product_name` / 縮圖（`wp_get_attachment_image_url(get_post_thumbnail_id, 'thumbnail')` 或 `wc_placeholder_img_src()`）、`quantity`、`line_total`（`item['line_total']` 或 `price*qty`）。回傳 `TUserCartItem[]` 形狀：`{product_id, product_name, quantity, price, line_total, product_image}`。
   - 原因：對齊 power-shop `Cart` 元件（紅框右欄「購物車」）。
   - 依賴：無。風險：中（多站 blog_id、商品已刪）。

3. **新增私有方法 `get_recent_orders( int $user_id, int $limit = 5 ): array`**
   - 行動：`wc_get_orders(['customer_id'=>$user_id,'limit'=>$limit,'orderby'=>'date','order'=>'DESC'])`；每筆回傳 `{order_id, order_date(本地化字串), order_total(float), order_status(不含 wc- 前綴), order_items[]}`，`order_items[]` 同 cart item 形狀（含縮圖）。
   - 原因：對齊 power-shop `RecentOrders`（含商品縮圖）。
   - 依賴：無。風險：中（效能）。

4. **新增私有方法 `get_other_meta_data( int $user_id ): array`**
   - 行動：查 `wp_usermeta` 取得該 user 全部 `umeta_id/meta_key/meta_value`，過濾掉敏感/系統 key（`session_tokens`、`*_capabilities`、`user_pass` 不會在此、`_woocommerce_persistent_cart_*` 等），回傳 `{umeta_id,meta_key,meta_value}[]`。
   - 原因：對齊 power-shop `Meta`（其他欄位 / 危險操作）。POST 已支援 `other_meta_data[]` 透過 `update_metadata_by_mid`（既有 L313-324），**reader 補上即可雙向**。
   - 依賴：無。風險：低。

5. **擴充 `post_users_with_id_callback`（L281-345）支援 role / description / user_birthday**
   - 行動：
     - `role`：若 body 含 `role` 且非空、`wp_roles()->is_role($role)` 為真、且 `$user_id !== get_current_user_id()`（防自我降權），呼叫 `$user_obj->set_role($role)`；否則忽略。
     - `description`：經 `WP::separator` 已落入 `$data`（WP user column）或 meta；確認 `wp_update_user(['ID'=>id,'description'=>...])` 能寫入（description 是 user table 欄位之一），必要時補寫。
     - `user_birthday`：regex `^\d{4}-\d{2}-\d{2}$` 檢核後 `update_user_meta`，不符不寫。
   - 原因：基本資料 Tab 需可編輯 role/生日/簡介。
   - 依賴：無（與步驟 1 同檔，連續做）。風險：中（自我降權守門必須有）。

6. **擴充 `get_users_with_id_orders_summary_callback`（L403-457）的 recent[] 加 `order_items[]`**
   - 行動：在既有 `recent` 每筆補 `order_items[]`（複用步驟 3 的 item 映射）。保留既有欄位不動（向下相容既有 `StudentEditModal` 與 E2E）。
   - 原因：power-shop RecentOrders 需要商品縮圖；新版前端改吃 `users/{id}` 的 `recent_orders` 後此端點可選保留，但仍對齊。
   - 依賴：步驟 3 的 item 映射抽成共用 helper。風險：低。

**Phase 1 成功標準**：`pnpm run lint:php` 全綠（PHPCS + PHPStan level 9）；以 REST client 手測 `GET users/{id}` 回傳含全部新欄位且型別正確；既有 issue/229 E2E 不因回傳擴充而壞。

---

### 第二階段（後端 / PHP 後端 agent）：聯絡註記 comments 端點

**檔案：`inc/classes/Api/User.php`**（在 `$apis` 陣列新增三條路由；callback 同檔）。

7. **新增 `$apis` 路由 + callback：comments CRUD**
   - 路由（permission 一律 `check_edit_users_permission`）：
     - `['endpoint'=>'comments','method'=>'get']` → `get_comments_callback`
     - `['endpoint'=>'comments','method'=>'post']` → `post_comments_callback`
     - `['endpoint'=>'comments/(?P<id>\d+)','method'=>'delete']` → `delete_comments_with_id_callback`
   - `get_comments_callback`：query `commented_user_id`，用 `get_comments(['type'=>'contact_remark','meta_key'=>'commented_user_id','meta_value'=>$uid])` 或 `WP_Comment_Query`。回傳對齊 power-shop `TUserContactRemark`：`{id, content, date_created, customer_note(bool), added_by(顯示名或 'system'), user_id, commented_user_id}`。
   - `post_comments_callback`：body `{comment_type:'contact_remark', commented_user_id, note, is_customer_note}`。用 `wp_insert_comment` 建立（`comment_post_ID=0`，`comment_type='contact_remark'`，`comment_content=note`，`user_id=current_user`，`comment_approved=1`），再 `add_comment_meta($cid,'commented_user_id',$uid)` 與 `is_customer_note`。回傳新 comment（Refine create 格式 `{data:{id}}`）。
   - `delete_comments_with_id_callback`：load comment，assert `comment_type==='contact_remark'`（否則 403），`wp_delete_comment($id, true)`（force）。回傳 Refine delete 格式 `{data:{id}}`。
   - 全部 callback 第一行 `\nocache_headers()`。
   - 原因：對齊 power-shop ContactRemarks（`comments` resource create/delete + record.contact_remarks list）。
   - 依賴：步驟 1 的 `contact_remarks` reader 複用此 list 邏輯（抽 `get_contact_remarks(int $user_id): array` 私有方法供 GET users/{id} 與 GET comments 共用）。
   - 風險：中（comment_type 守門避免誤刪一般留言；Refine 格式對齊）。

8. **新增 `users/options` 路由 + callback**
   - 路由：`['endpoint'=>'users/options','method'=>'get']`（permission `check_edit_users_permission`）→ `get_users_options_callback`。
   - 行動：回傳 `{data:{roles:[{value,label}]}}`（對齊 power-shop `useOptions` 讀 `data?.data?.data?.roles`）。roles 來自 `wp_roles()->get_names()`（`{slug:display_name}` 轉 `{value,label}`）。
   - 原因：基本資料 Tab role 下拉選項來源。
   - 依賴：無。風險：低。

**Phase 2 成功標準**：`pnpm run lint:php` 全綠；REST client 手測 comments create→list→delete 一輪正常；`users/options` 回傳 roles。

> ⚠️ Phase 2 路由命名衝突檢查：`comments` 與既有 `users/...` 不衝突；`users/options` 必須在 `users/(?P<id>\d+)` **之前或之後皆可**（WP REST 以完整 pattern 比對，`options` 非數字不會被 `\d+` 吃掉），但建議將 `users/options` 放在 `$apis` 陣列中 `users/(?P<id>\d+)` 之前以清楚表意。

---

### 第三階段（型別 / react-master）：擴充前端型別

**檔案：`js/src/components/user/types/index.ts`**

9. **擴充 `TUserDetail` 並新增子型別**
   - 行動：
     - `TUserDetail` 新增頂層欄位對齊 Phase 1 回傳：`total_spend / orders_count / avg_order_value / date_last_active / date_last_order / user_registered / user_registered_human / user_avatar_url / user_birthday / description / role / edit_url`（型別比照 `antd-toolkit/wp` 的 `TUserBaseRecord`：數字欄 `number | null`，日期欄 `string | null`）。
     - 新增 `billing` / `shipping` 物件型別（`TUserInfo`：`{first_name,last_name,email?,phone?,company?,country,state,postcode,city,address_1,address_2}`）。
     - 新增 `TUserCartItem`：`{product_id, product_name, quantity, price, line_total, product_image}`（照 power-shop）。
     - 新增 `recent_orders`：`{order_id, order_date, order_total, order_status, order_items: TUserCartItem[]}[]`。
     - 新增 `TUserContactRemark`：`{id, content, date_created, customer_note, added_by, user_id, commented_user_id}`。
     - 新增 `other_meta_data: {umeta_id, meta_key, meta_value}[]`。
   - 原因：前端各子區塊型別安全對接後端。
   - 依賴：**Phase 1/2 回傳形狀定稿後**才動，避免反覆。風險：低。
   - 註：保留既有 `TUserMeta` 與既有 `TUserDetail.meta_data`（向下相容）。

**Phase 3 成功標準**：`pnpm run lint:ts` 與 `pnpm run build` 對型別檔無錯。

---

### 第四階段（前端 / react-master）：StudentEditModal 改造為 power-shop 雙欄卡片

> 所有新元件放在 `js/src/components/user/StudentEditModal/` 子目錄下，**不可跨外掛 import power-shop**，照抄 pattern 並把中文字串轉英文 msgid（`@wordpress/i18n`）。

**4-0. SimpleModal 寬度（可立即平行、零依賴）**
   - 檔案：`js/src/components/user/StudentEditModal/index.tsx` L395。
   - 行動：`width={720}` → `width={1280}`。
   - 原因：驗收條件 1。風險：低。

**4-1. Modal 內 Context + 資料載入骨架**
   - 檔案：`js/src/components/user/StudentEditModal/index.tsx`（重構主體）。
   - 行動：
     - 新增 `hooks/index.tsx`（同目錄）：`RecordContext`(`createContext<TUserDetail|undefined>`)、`IsEditingContext`、`useRecord()`、`useIsEditing()`——**移植自 power-shop `Users/Edit/hooks`**。
     - Modal 主體保留 jotai `studentEditModalAtom` 開關與 `useOne<TUserDetail>({resource:'users', id, dataProviderName:'power-course'})` 載入（已會吃到 Phase 1 擴充欄位）。
     - 以 `IsEditingContext.Provider value={isEditing}` + `RecordContext.Provider value={detail}` 包住 Modal body。
     - footer 維持 view↔edit 切換（既有「Edit user / Cancel / Save」）。
     - 編輯儲存：沿用既有 `useCustomMutation` POST `users/{id}`（multipart/form-data）；新增 role/user_birthday/description 欄位進 payload。billing/shipping 攤平成 `billing_xxx`（移植 power-shop `Edit/index.tsx` `handleOnFinish` 的攤平邏輯，因 InfoTable 用巢狀 `[type, field]` Form 結構）。
   - 對應 power-shop 參考：`pages/admin/Users/Edit/index.tsx`（Context Provider + handleOnFinish 攤平）、`Users/Edit/hooks/*`。
   - 依賴：Phase 3 型別、Phase 1 後端。風險：中（Form 巢狀結構 + 攤平正確性）。

**4-2. Detail 雙欄 layout**
   - 檔案：`js/src/components/user/StudentEditModal/Detail/index.tsx`（新）。
   - 行動：移植 power-shop `Detail/index.tsx`：左欄消費數據（6 `Statistic`，吃 `useRecord()` 的統計欄位 + `useWoocommerce()` 取 currency symbol）+ 用戶資料 `Tabs`（Basic/AutoFill/Meta）；右欄聯絡註記 + 購物車 + 最近訂單。所有中文 label 轉 i18n（`Consumption data`/`Total spend`/`Total orders`/`Average order value`/`Last active`/`Last order`/`Registration time`/`User data`/`Contact notes`/`Cart`/`Recent orders` 等）。
   - 對應 power-shop 參考：`Detail/index.tsx`。
   - 依賴：4-1、4-3~4-7。風險：低（純組裝）。

**4-3. Basic（基本資料 + 密碼）**
   - 檔案：`js/src/components/user/StudentEditModal/Detail/Basic/index.tsx`（新）。
   - 行動：移植 power-shop `Detail/Basic/index.tsx`：姓名(last/first)、顯示名稱、Email、角色（`useOptions()` roles）、生日（`DatePicker` + `getValueProps/normalize` YYYY-MM-DD）、簡介（`TextArea`）、密碼（重設信 + 直接改密碼雙確認）。
     - `useOptions` → 移植到 `js/src/components/user/StudentEditModal/hooks/useOptions.tsx`（新）：`useCustom` GET `users/options`（**帶 `dataProviderName:'power-course'`**）+ `USER_ROLES`(from `antd-toolkit/wp`) label 對照。
     - `INFO_LABEL_MAPPER` → 移植到 `js/src/utils/constants.ts`（若無則新建）或 `StudentEditModal/constants.ts`，值轉英文（`First name`/`Last name`/`Postcode`/`Country`/`State`/`City`/`Address line 1`/`Address line 2`）。
     - 密碼直接改：沿用既有 `Input.Password` + 雙確認 validator（既有 modal 已有此邏輯，移植進 Basic）。重設信按鈕呼叫既有 `users/{id}/reset-password`。
   - 對應 power-shop 參考：`Detail/Basic/index.tsx`、`Users/List/hooks/useOptions.tsx`、`utils/constants.ts`。
   - 依賴：4-1、Phase 2 `users/options`。風險：中（role 下拉、生日格式）。

**4-4. AutoFill + InfoTable（帳單/運送）**
   - 檔案：
     - `js/src/components/user/StudentEditModal/Detail/AutoFill/index.tsx`（新）
     - `js/src/components/user/InfoTable/index.tsx`（新，移植 power-shop `components/order/InfoTable`）
     - `js/src/components/user/InfoTable/AddressInput.tsx`（新，移植 power-shop `components/order/InfoTable/AddressInput`，**需確認 power-shop 該檔內容**）
   - 行動：AutoFill 讀 `useRecord()` 的 `billing`/`shipping` 物件，渲染兩個 `InfoTable`。InfoTable view 模式用 `useCountries()`(from `antd-toolkit/wp`) 反查國名；edit 模式用巢狀 Form `[type, field]`。中文 label 轉 i18n。
   - 對應 power-shop 參考：`Detail/AutoFill/index.tsx`、`components/order/InfoTable/index.tsx`（+ `AddressInput`）。
   - 依賴：4-1（Form 巢狀 + 攤平）、Phase 1 `billing`/`shipping` 物件回傳。風險：中（需先讀 power-shop `AddressInput` 補入計畫；見「待補讀」）。

**4-5. Meta（其他欄位 / 危險操作）**
   - 檔案：`js/src/components/user/StudentEditModal/Detail/Meta/index.tsx`（新）。
   - 行動：移植 power-shop `Detail/Meta/index.tsx`：讀 `useRecord().other_meta_data`，雙層 confirm（危險操作 Alert）後可編輯，Form name `['other_meta_data', index, ...]`。中文轉 i18n（`Dangerous operation`/`I know what I'm doing` 等）。儲存複用既有 POST `users/{id}` 的 `other_meta_data[]` 支援。
   - 對應 power-shop 參考：`Detail/Meta/index.tsx`。
   - 依賴：4-1、Phase 1 `other_meta_data` reader。風險：低。

**4-6. Cart + RecentOrders + Price**
   - 檔案：
     - `js/src/components/user/StudentEditModal/Detail/Cart/index.tsx`（新）
     - `js/src/components/user/StudentEditModal/Detail/RecentOrders/index.tsx`（新）
     - `js/src/components/general/Price/index.tsx`（新，移植 power-shop `components/general/Price`，內用 `antd-toolkit` 的 `Amount`）
   - 行動：
     - Cart：讀 `useRecord().cart`，渲染商品縮圖列表 + 購物車金額（`Price`）。中文轉 i18n（`User's current cart contains:`/`Cart is empty`/`Cart total`）。
     - RecentOrders：讀 `useRecord().recent_orders`，用 `ORDER_STATUS`(from `antd-toolkit/wp`) 顯示狀態 Tag + 商品縮圖 + 訂單總額（`Price`）。中文轉 i18n（`Unknown status`/`Order total`）。
   - 對應 power-shop 參考：`Detail/Cart/index.tsx`、`Detail/RecentOrders/index.tsx`（注意 power-shop 這兩檔內容互換，移植時以「Cart 顯示 cart、RecentOrders 顯示 recent_orders」的語意為準）、`components/general/Price/index.tsx`。
   - 依賴：4-1、Phase 1 `cart`/`recent_orders`。風險：低。

**4-7. ContactRemarks**
   - 檔案：`js/src/components/user/ContactRemarks/index.tsx`（新，移植 power-shop `components/user/ContactRemarks`）。
   - 行動：移植 power-shop ContactRemarks：`Timeline` 依日期分組、`useCreate({resource:'comments'})` + `DeleteButton resource="comments"`。
     - **關鍵 wiring**：`useCreate`/`useInvalidate`/`DeleteButton` 全部顯式帶 `dataProviderName:'power-course'`（power-shop 用 default，power-course 必須指向 `power-course` provider）。
     - `cn`/`renderHTML` from `antd-toolkit`。中文轉 i18n（`Customer note`/`Internal note`/`Added by`/`system`/`Add`/`Confirm delete this note?` 等）。
   - 對應 power-shop 參考：`components/user/ContactRemarks/index.tsx`。
   - 依賴：4-1、Phase 2 comments 端點。風險：中（dataProvider wiring、Refine create/delete 格式對齊後端）。

**Phase 4 成功標準**：`pnpm run lint:ts` + `pnpm run build` 全綠；Modal 寬 1280、雙欄渲染、view↔edit 切換、各區塊資料正確、新增/刪除聯絡註記成功。

---

### 第五階段（i18n / react-master 連 PHP 後端 agent 各自補字串）

10. **補英文 msgid 的繁中翻譯到 `scripts/i18n-translations/manual.json` 並跑 pipeline**
   - 行動：
     - 收集 Phase 1-4 所有新英文 msgid（PHP 端錯誤訊息 + React 端 label）。
     - 在 `scripts/i18n-translations/manual.json` 補對應繁中 `msgstr_zh_TW`（依 `.claude/rules/i18n.rule.md` 術語表；新詞先加術語表）。
     - 跑 `pnpm run i18n:build`（pot→merge→mo→json 全套），commit 四檔 `.pot/.po/.mo/.json`。
   - 依賴：Phase 1-4 字串定稿。風險：低（但**禁止手改 `.po`**）。
   - **驗收**：`[build-zhtw-po] 未翻譯` 數量不因本次上升；msgid 全英文無中日韓字元。

**Phase 5 成功標準**：i18n pipeline 跑完、四檔 diff 一起 commit、無中文 msgid。

---

### 第六階段（測試 / react-master）：E2E 隨新結構調整

11. **更新 `tests/e2e/01-admin/student-quick-edit.spec.ts`**
   - 行動：
     - 既有 5 個 smoke 多數仍有效（`.student-edit-modal` 容器、編輯用戶切換、顯示名稱儲存、密碼不一致、列表電話）。但新結構為 `Tabs`，「顯示名稱」「密碼」欄位移到 **Basic Tab** 內——調整選擇器：進編輯模式後先確保 Basic Tab active（預設 active 即 Basic）。
     - 新增 smoke：開 Modal 後驗證左欄「消費數據 / Consumption data」標題與右欄「聯絡註記 / Contact notes」「購物車 / Cart」「最近訂單 / Recent orders」標題可見（對齊「一模一樣」結構）。
     - 新增 smoke（可選）：在聯絡註記新增一則內部備註，驗證 Timeline 出現該內容（依賴 Phase 2 comments）。
   - 依賴：Phase 4 完成。風險：中（Tab 內欄位選擇器、Modal 寬度變化導致 layout）。
   - 註：本地站未啟動，瀏覽器驗證由主窗口在站台起來後執行；本 Phase 先把 spec 改好。

**Phase 6 成功標準**：`pnpm run test:e2e:admin -- student-quick-edit`（站台啟動後）全綠。

---

## 平行 / 依賴關係總表

| 階段 | 角色 | 可平行? | 依賴 |
| --- | --- | --- | --- |
| 4-0 SimpleModal 1280 | react-master | ✅ 立即 | 無 |
| Phase 1 後端資料 | PHP 後端 agent | ✅ 與 4-0 平行 | 無 |
| Phase 2 comments/options | PHP 後端 agent | 接續 Phase 1 同 agent | Phase 1 抽共用 reader |
| Phase 3 型別 | react-master | ⛔ 等 Phase 1/2 形狀定稿 | Phase 1, 2 |
| Phase 4 前端 | react-master | ⛔ 等 Phase 3 | Phase 1, 2, 3 |
| Phase 5 i18n | 兩者各補字串 | ⛔ 等 Phase 1-4 字串定稿 | Phase 1-4 |
| Phase 6 E2E | react-master | ⛔ 等 Phase 4 | Phase 4 |

**建議排程**：Day1 並行（PHP agent 跑 Phase 1→2；react-master 跑 4-0）；後端形狀定稿後 react-master 接 Phase 3→4→6；最後雙方各補 Phase 5 字串、一起 i18n:build。

## 前端子區塊 ↔ power-shop 參考檔對照

| power-course 新檔 | power-shop 參考檔 |
| --- | --- |
| `StudentEditModal/Detail/index.tsx` | `pages/admin/Users/Edit/Detail/index.tsx` |
| `StudentEditModal/Detail/Basic/index.tsx` | `Detail/Basic/index.tsx` |
| `StudentEditModal/Detail/AutoFill/index.tsx` | `Detail/AutoFill/index.tsx` |
| `components/user/InfoTable/index.tsx` (+AddressInput) | `components/order/InfoTable/index.tsx` (+AddressInput) |
| `StudentEditModal/Detail/Meta/index.tsx` | `Detail/Meta/index.tsx` |
| `StudentEditModal/Detail/Cart/index.tsx` | `Detail/Cart/index.tsx`（語意，非檔名） |
| `StudentEditModal/Detail/RecentOrders/index.tsx` | `Detail/RecentOrders/index.tsx`（語意，非檔名） |
| `components/general/Price/index.tsx` | `components/general/Price/index.tsx` |
| `components/user/ContactRemarks/index.tsx` | `components/user/ContactRemarks/index.tsx` |
| `StudentEditModal/hooks/useOptions.tsx` | `pages/admin/Users/List/hooks/useOptions.tsx` |
| `StudentEditModal/hooks/index.tsx`(Context) | `pages/admin/Users/Edit/hooks/*` |
| `INFO_LABEL_MAPPER`(constants) | `utils/constants.ts` |

## 待補讀（react-master 開工前先讀，planner 未讀到的檔）

- power-shop `components/order/InfoTable/AddressInput`（4-4 需要，planner 未讀；react-master 移植前先 Read）。
- power-shop `pages/admin/Orders/List/types`（`TOrderInfo`/`TOrderNote` 定義，型別對齊用；planner 已從用法反推但建議核對）。

## 測試策略

- 單元/整合測試（後端，PHPUnit）：建議新增 `comments` create→list→delete IT、role 自我降權守門 IT、persistent cart reader 對空 meta 的 nil path（若 issue/229 已有 User API IT 套件則延用）。
- E2E（Playwright）：Phase 6 調整 + 新增結構驗證 smoke。
- 驗證指令：`pnpm run lint:php`、`composer run phpstan`、`pnpm run lint:ts`、`pnpm run build`、`pnpm run i18n:build`、`pnpm run test:e2e:admin`。
- 關鍵邊界：空購物車、無訂單（統計全 0）、商品已刪（placeholder）、role 自我降權、生日格式非法、comments 非 contact_remark 拒刪、多站 blog_id。

## 依賴項目

- WooCommerce 核心函式：`wc_get_customer_total_spend`、`wc_get_customer_order_count`、`wc_get_customer_last_order`、`wc_get_orders`、`wc_get_product`、`wc_placeholder_img_src`。
- WP 核心：`wp_insert_comment`/`wp_delete_comment`/`get_comments`、`wp_roles`、`get_avatar_url`、`get_edit_user_link`、`human_time_diff`、`get_current_blog_id`。
- 前端：`antd-toolkit`(`SimpleModal`/`Heading`/`Amount`/`cn`/`renderHTML`)、`antd-toolkit/wp`(`useWoocommerce`/`useCountries`/`ORDER_STATUS`/`USER_ROLES`/`TUserBaseRecord`)、`@refinedev/core`、`@refinedev/antd`(`DeleteButton`)、`@wordpress/i18n`、`dayjs`、`lodash-es`。

## 風險與緩解措施（彙整）

- **高**：comments 後端 Refine 格式 / dataProvider 路由錯 → 顯式 `dataProviderName:'power-course'` + 後端回傳 `{data:{id}}` 格式；先以 REST client 驗證再接前端。
- **中**：billing/shipping 巢狀 Form 攤平錯誤 → 移植 power-shop `handleOnFinish` 並對 save payload 做單測/手測。
- **中**：role 自我降權鎖死 → 後端守門忽略 `current_user` 的 role 變更。
- **中**：persistent cart 多站 blog_id → 動態 `get_current_blog_id()`。
- **中**：payload 過大 → recent_orders 限筆、保留 orders-summary 分離端點。
- **低**：i18n 手改 .po → 一律走 manual.json + i18n:build。
- **低**：E2E 因 Tabs 結構壞 → 調整選擇器先切 Basic Tab。

## 錯誤處理策略

採「後端守門 + 前端可見回饋」雙層：後端對非法輸入回 `WP_Error`(含 HTTP status)，對可降級資料（統計/cart/商品已刪）靜默 fallback（0/''/placeholder）；前端沿用既有 `message.error` 與 Refine notificationProvider。所有 GET callback 第一行 `nocache_headers()`。

## 限制條件（本計劃不做）

- 不實作「客戶可見備註」的前台呈現（power-shop 該功能 Switch 為 disabled，僅內部備註；保持一致）。
- 不改 `students` 列表端點與列表電話欄位（issue/229 已完成）。
- 不引入新的前端 HTTP 客戶端；一律 Refine hooks。
- 不跨外掛 import power-shop 任何模組。
- 不刪除既有 `StudentEditModal` 的扁平 `meta_data` 回傳與 `orders-summary` 端點（向下相容）。
- 不動 vendor / powerhouse 程式碼。
- 不實作發票（power-shop 該區塊本身註解掉）。

## 成功標準

- [ ] SimpleModal `width={1280}`（驗收條件 1）。
- [ ] Modal 內容與 power-shop User Edit 卡片結構一模一樣：左欄消費數據(6 Statistics)+用戶資料 Tabs(Basic/AutoFill/Meta)；右欄聯絡註記+購物車+最近訂單（驗收條件 2）。
- [ ] view↔edit 切換可編輯姓名/顯示名稱/Email/role/生日/簡介/密碼。
- [ ] 聯絡註記可新增/刪除內部備註並即時刷新。
- [ ] 後端自含（無 power-shop REST 依賴），統計用 WC 核心函式、cart 用 persistent cart meta、remarks 用 WP comments。
- [ ] `pnpm run lint:php` / `phpstan` / `lint:ts` / `build` / `i18n:build` 全綠；msgid 全英文。
- [ ] E2E `student-quick-edit.spec.ts` 隨新結構調整後通過（站台啟動後）。

## 預估複雜度：高（後端資料擴充 + 6 個前端子元件移植 + comments 端點 + i18n + E2E 調整）
