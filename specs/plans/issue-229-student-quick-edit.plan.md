# 實作計畫 — 學員快速編輯（Issue #229）

> Planner 產出，交接給 `@zenbu-powers:tdd-coordinator` 執行。
> 範圍模式：**EXPANSION**（新功能，全三期）。決策來源見 `specs/discovery/student-quick-edit/execution-plan.md`。
> 規格：`specs/student-quick-edit/{student-quick-edit.feature, api.yml, erm.dbml}`、`specs/discovery/student-quick-edit/*.mmd`。

---

## 0. 一句話描述

讓管理員在「全域學員管理」與「課程編輯頁學員 Tab」點擊學員，於右側共用 Drawer 查看／編輯 WordPress 核心欄位、WooCommerce 帳單／收件 meta、設定新密碼或發送原生重設信、檢視訂單摘要；列表新增電話欄位。全程不離開 Power Course。

---

## 1. 架構現況與關鍵發現（探查結果）

| 主題 | 現況 | 對本功能的影響 |
|------|------|----------------|
| 更新端點 | `Api/User.php` 已有 `POST users/(?P<id>\d+)` → `post_users_with_id_callback`，透過 `WP::separator($body,'user',...)` 把 core fields 與 meta 分離，meta 走 `update_user_meta` | **billing_\*/shipping_\* 已能寫入**，更新合約不變；但需補強「空密碼不改」與權限 |
| 單筆讀取 | **缺** `GET users/{id}`（目前只有 POST） | 需新增 GET 端點供 Drawer 載入 |
| 密碼重設信 | 專案他處已用 `retrieve_password()`（見 `Api/User.php::process_batch_add_students`） | 直接沿用 WordPress 原生流程 |
| 訂單摘要 | 無端點 | 需新增 `GET users/{id}/orders-summary`（WC `wc_get_orders`，唯讀） |
| 列表電話 | 講師表（resource `users` + 預設 powerhouse provider）**已用 meta_keys 帶回 `billing_phone` 並顯示欄位**（`teacher/TeacherTable/hooks/useColumns.tsx`） | 學員列表只要把 `'billing_phone'` 加進 `meta_keys` permanent filter + 加欄位即可，**列表後端 0 改動** |
| 列表資料來源 | 全域學員頁 `<UserTable mode="global">` → resource `users`（**預設 powerhouse provider**）；課程學員 Tab `StudentTable` → resource `students`（**power-course provider**）。兩者共用 `@/components/user/UserTable/hooks/useColumns.tsx` 與 `UserName` | 兩張表只要改同一個 `useColumns` 即同時生效；Drawer 在兩個 table 元件各 render 一次 |
| 既有 Drawer | `components/user/UserDrawer`（教師用，僅 5 欄、Email/帳號 disabled）；`useUserFormDrawer` hook（create/update + 未存變更確認 + multipart） | **不直接擴充教師 Drawer**，改建專屬 `StudentEditDrawer`，避免污染教師流程；可參考其 form/atom/關閉確認模式 |
| 既有 Drawer 觸發模式 | `HistoryDrawer` 以 jotai atom（`historyDrawerAtom`）控制開關，table 內 render 一次、`useColumns` 內觸發 | **照搬此模式**：新增 `studentEditDrawerAtom`，`useColumns` 預設 onClick 寫 atom |
| 權限 | 既有 User API 全部 `check_manage_woocommerce_permission`（manage_woocommerce） | Q7=C 要求 `edit_users`；新增 `check_edit_users_permission`，套用到更新 + 3 個新端點 |
| 國家/州省資料 | 前端無現成 WC 國家清單來源 | 透過 `wc-rest` provider `GET data/countries` 取得（含 states），做雙層連動下拉 |
| 規範 | 所有 power-course REST callback 第一行必須 `\nocache_headers()`；PHPStan L9 + PHPCS WP standard；i18n 走 `manual.json` + `pnpm run i18n:build`，msgid 一律英文 | 全部新程式碼遵守 |

> ⚠️ **api.yml 與現況的一處對齊**：api.yml 把列表寫成 `GET /students`，但「全域學員頁」實際走 powerhouse `users`。兩者底層都用 `Powerhouse\...\User::to_array('list', $meta_keys)`，加 `billing_phone` 到 meta_keys 對**兩條路徑皆有效**，因此列表後端無需改動，api.yml 的 `/students` 視為「課程 Tab 路徑」即可。

---

## 2. 資料流分析（Data Flow）

### 2.1 開 Drawer → 載入
```
點擊 UserName(onClick) → set studentEditDrawerAtom{user_id, open:true}
  → StudentEditDrawer: useOne(resource:'users', id, dataProviderName:'power-course')
      → GET power-course/v2/users/{id}  → { id, user_login, display_name, email, meta_data{first_name,last_name,billing_*,shipping_*} }
  → useCustom GET power-course/v2/users/{id}/orders-summary?limit=5 → { total, view_all_url, recent[] }
  → form.setFieldsValue(detail) 填表；訂單摘要區塊渲染
```

### 2.2 儲存
```
Save → form.validateFields()（含 Email 格式、密碼二次一致）
  → 移除空 user_pass / 空 confirm 欄位（前端只送有值者）
  → useUpdate(resource:'users', id, dataProviderName:'power-course', multipart)
      → POST power-course/v2/users/{id}
          後端 WP::separator → wp_update_user(core) + update_user_meta(billing_*/shipping_*)
  → onSuccess: message「修改成功」；若改了 user_email 另提示「登入 Email 已更新」
            invalidate(resource:'users' 預設) + invalidate(resource:'students' power-course)
            Drawer 維持開啟、以回傳 data 重置 baseline
```

### 2.3 發送密碼重設信
```
按鈕 → useCustomMutation POST power-course/v2/users/{id}/reset-password
  → 後端 retrieve_password( user_login ) → true：200「已發送密碼重設信」；WP_Error：500 顯示錯誤
```

### 2.4 列表電話欄位
```
useTable permanent filter meta_keys 追加 'billing_phone'
  → 後端 User::to_array('list', meta_keys) 回傳 billing_phone
  → useColumns 新增「電話」欄位 render phone || '-'
```

---

## 3. 需要修改／新增的檔案清單

### 後端（PHP）— `inc/classes/Api/User.php`

| # | 變更 | 內容摘要 |
|---|------|---------|
| B1 | 新增權限方法 `check_edit_users_permission()` | 結構同既有 `check_manage_woocommerce_permission`，未登入 401／無 `current_user_can('edit_users')` 403／否則 true |
| B2 | `$apis` 註冊 3 條新路由 | `users/(?P<id>\d+)` **GET**（`check_edit_users_permission`）、`users/(?P<id>\d+)/reset-password` **POST**（同上）、`users/(?P<id>\d+)/orders-summary` **GET**（同上） |
| B3 | 既有 `users/(?P<id>\d+)` **POST** 的 `permission_callback` 改 `check_edit_users_permission` | 滿足 Feature「不具 edit_users 無法儲存」；**注意：此端點與教師編輯共用**（見風險 R1） |
| B4 | 新 callback `get_users_with_id_callback()` | `\nocache_headers()`；讀 `\get_userdata`，404 若不存在；回 `{id,user_login,name,display_name,email,meta_data{...}}`；**剔除敏感欄位**（`user_pass`、`session_tokens`、`*_capabilities` 視需要）；meta 至少含 first/last_name + billing_*/shipping_* |
| B5 | 強化 `post_users_with_id_callback()` | (a) `\nocache_headers()` 補在開頭；(b) **若 `user_pass` 為空字串/未填則 `unset($data['user_pass'])`**（防止把密碼清空 → Feature「留空不改」）；(c) 確認 billing_*/shipping_* 仍正確分流到 meta（現況已可，加測試守住） |
| B6 | 新 callback `post_users_with_id_reset_password_callback()` | `\nocache_headers()`；取 user，呼叫 `\retrieve_password( $user->user_login )`；true→200 `reset_password_email_sent`；WP_Error→500 帶訊息 |
| B7 | 新 callback `get_users_with_id_orders_summary_callback()` | `\nocache_headers()`；`wc_get_orders(['customer_id'=>$id,'limit'=>$limit,'paginate'=>true,'orderby'=>'date','order'=>'DESC'])`；組 `{total, view_all_url, recent[{id,number,date_created,status,total,currency,edit_url}]}`；`view_all_url` 需相容 HPOS（`OrderUtil::custom_orders_table_usage_is_enabled()` → `admin.php?page=wc-orders&_customer_user={id}`，否則 `edit.php?post_type=shop_order&_customer_user={id}`）；無訂單回 `total:0, recent:[]` |

> callback 命名由 ApiBase 自動推導：`users/(?P<id>\d+)/reset-password` → `post_users_with_id_reset_password_callback`；`orders-summary` → `..._orders_summary_callback`。

### 前端（TypeScript / React）

| # | 檔案 | 變更 |
|---|------|------|
| F1 | `js/src/components/user/types/index.ts` | `TUserRecord` 增 `billing_phone?: string`；新增 `TUserDetail`（含 `meta_data` 的 billing_*/shipping_*/first_name/last_name）與 `TOrdersSummary`/`TOrderSummaryItem` 型別 |
| F2 | `js/src/components/user/UserTable/atom.tsx` | 新增 `studentEditDrawerAtom = atom<{ user_id?: string; open: boolean }>({open:false})` |
| F3 | `js/src/components/user/StudentEditDrawer/index.tsx`（新檔） | 主元件：讀 atom → `useOne` 載資料 → ProForm/Form 分區（基本／帳單／收件可收合／訂單摘要）→ 唯讀 id+user_login → Email/姓名/密碼+確認 → 國家/州省連動下拉 → Save（useUpdate）→「發送密碼重設信」（useCustomMutation）→ 訂單摘要（useCustom）。關閉採未存變更確認（參考 `useUserFormDrawer`） |
| F4 | `js/src/components/user/StudentEditDrawer/hooks/useCountryOptions.tsx`（新檔，或內聚於元件） | `useCustom`/`useList` 走 `wc-rest` `data/countries`，產生國家 options 與「國家→州省」對照；州省下拉依所選國家連動，空則退純文字 Input |
| F5 | `js/src/components/user/UserTable/hooks/useColumns.tsx` | (a) 預設 `onClick` 改為「寫 `studentEditDrawerAtom`」（沿用 params.onClick 可覆蓋）；(b) 新增「電話」欄位 `dataIndex:'billing_phone'` render `phone||'-'` |
| F6 | `js/src/components/user/UserTable/index.tsx` | permanent `meta_keys` 追加 `'billing_phone'`（global 與 course-exclude 兩處）；底部 render `<StudentEditDrawer />`（取代/並存 HistoryDrawer 模式） |
| F7 | `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/StudentTable/index.tsx` | permanent `meta_keys` 追加 `'billing_phone'`；底部 render `<StudentEditDrawer />` |
| F8 | `js/src/components/user/index.tsx` | export 新元件 `StudentEditDrawer` |

### i18n

| # | 檔案 | 變更 |
|---|------|------|
| I1 | `scripts/i18n-translations/manual.json` | 新增所有新 UI 字串（英文 msgid + 繁中 msgstr）。例：`Edit student`、`Basic info`、`Billing info`、`Shipping info`、`Order summary`、`New password`、`Confirm new password`、`Passwords do not match`、`Send password reset email`、`Password reset email sent`、`Login email updated`、`Phone`、`No orders yet`、`View all orders`、`Country`、`State / Province` 等（術語沿用 `i18n.rule.md` 術語表：Save/Cancel/Email/Student/Order…） |
| I2 | 執行 `pnpm run i18n:build` | 產出並一起 commit `.pot`/`.po`/`.mo`/`.json` |

---

## 4. 實作順序（依賴關係）

> 採 TDD：每個後端端點先紅後綠；前端以型別→atom→元件→接線→i18n。

1. **B1 權限方法** → 無依賴，先行。
2. **B4 GET users/{id}**（含剔除敏感欄位）→ Drawer 載入的基礎。
3. **B5 POST users/{id} 強化**（空密碼不改 + 權限 + nocache）→ 更新的基礎。
4. **B6 reset-password** → 獨立子流程。
5. **B7 orders-summary**（含 HPOS 相容）→ 獨立。
6. **B2/B3 路由註冊 + 既有 POST 權限切換** → 收尾把端點接上（其實 B2 要早於跑 REST 測試，可與 B4–B7 同批）。
7. **F1 型別** → 前端基礎。
8. **F2 atom**。
9. **F4 國家/州省 hook**（可與 F3 並行）。
10. **F3 StudentEditDrawer 元件**（依賴 F1/F2/F4 與 B4/B5/B6/B7）。
11. **F5 useColumns**（onClick + 電話欄）。
12. **F6/F7 兩張表接線 + meta_keys**。
13. **F8 export**。
14. **I1/I2 i18n**（字串定版後統一補）。
15. 全量 `pnpm run lint:php` + `pnpm run lint:ts` + `pnpm run build` + `composer run test` + 目標 E2E。

> 後端 B1–B7 與前端 F1–F2/F4 可平行；F3 是匯流點。

---

## 5. 測試策略

### 5.1 PHP Integration（PHPUnit，`tests/Integration/Student/`）

新增 `StudentQuickEditApiTest.php`（`@group student @group user @group api`），模式參考 `TeacherApiTest`（直接 new `WP_REST_Request` 呼叫 callback 或 `rest_do_request`；權限以 `wp_set_current_user` 切換角色）。

| 對應 Feature Rule | 測試案例 |
|---|---|
| 唯讀欄位 / 載入 | `GET users/{id}` 回傳 id/user_login/display_name/email + meta_data 含 billing_*/shipping_*；**不含** `user_pass`/`session_tokens` |
| 修改基本資料 | POST 更新 display_name/user_email/first_name/last_name 成功且落庫 |
| Email 獨立 | 只送 `user_email`，`billing_email` meta 不變 |
| WC 帳單/收件 | POST 帶 billing_phone/billing_address_1/.../shipping_* → 對應 user meta 更新 |
| 設定新密碼 | 帶非空 `user_pass` → `wp_check_password` 新密碼為 true |
| **新密碼留空不改** | `user_pass=''`（或不帶）→ 密碼 hash 不變（先記錄舊 hash 後比對） |
| 發送重設信 | reset-password 端點：mock `wp_mail`（`add_filter('wp_mail', ...)` 攔截或檢查 `retrieve_password` 回 true）→ 200 + code `reset_password_email_sent` |
| 訂單摘要 | 建 N 筆 WC 訂單 → total 正確、recent 筆數=min(N,limit)、欄位齊；無訂單 → total:0/recent:[] |
| 權限 | 未登入→401；subscriber（無 edit_users）→403；administrator→通過。涵蓋 GET/POST/reset-password/orders-summary 與 `check_edit_users_permission` 單元 |

> 訂單測試需 WooCommerce 環境；參考既有 `tests/Integration/Order/OrderAutoGrantCourseTest.php` 建立 `wc_create_order`。HPOS view_all_url 可只斷言字串包含 `_customer_user={id}`。

### 5.2 E2E（Playwright，`tests/e2e/01-admin/student-quick-edit.spec.ts`）

以 smoke 為主（參考 `teacher-edit.spec.ts`，`storageState: '.auth/admin.json'`，用 `ApiClient.ensureUser` 建測試學員、`pcPostForm`/`wcPost` 補 billing meta 與訂單）：

1. 全域 `/students` 點學員名字 → 右側 Drawer 滑出、載入基本資料。
2. 課程編輯頁學員 Tab 點學員 → 開出**相同** Drawer。
3. 改 display_name → Save → 出現「修改成功」、列表同步。
4. 新密碼與確認不一致 → 出現「兩次密碼不一致」、表單不送出。
5. 列表出現「電話」欄位且顯示 billing_phone。
6.（可選）Email 格式錯誤即時錯誤；訂單摘要區塊渲染。

### 5.3 靜態品質

`pnpm run lint:php`（phpcbf+phpcs+phpstan L9）、`pnpm run lint:ts`、`pnpm run build`（TS 編譯）、`composer run test`。

---

## 6. 錯誤處理登記表（Error Handling Registry）

| 來源 | 失敗情境 | 後端行為 | 前端呈現 |
|------|---------|---------|---------|
| GET users/{id} | 用戶不存在 | 404 `user_not_found` | Drawer 顯示錯誤、可關閉 |
| GET users/{id} | 無 edit_users | 403 | （前端入口已隱藏；防禦性顯示權限不足） |
| POST users/{id} | Email 格式錯誤 | 前端先擋（rule `type:'email'`）；若繞過後端 `wp_update_user` 回 WP_Error → 400 帶訊息 | 即時欄位錯誤「Email 格式不正確」/ 後端訊息 |
| POST users/{id} | wp_update_user WP_Error | 400 `post_user_error` + message | message.error 顯示後端訊息、**Drawer 保留輸入** |
| POST users/{id} | 密碼留空 | 後端 unset user_pass（不報錯） | 正常成功、密碼不變 |
| 前端表單 | 兩次密碼不一致 | —（不送出） | Form 驗證錯誤「兩次輸入的密碼不一致」 |
| reset-password | retrieve_password 失敗 | 500 + WP_Error 訊息 | message.error「發送失敗」+ 訊息 |
| orders-summary | 查詢例外 | 500 `orders_summary_error` | 區塊顯示載入失敗（不阻斷其餘編輯） |
| 任一寫入 | 無 edit_users | 403 | 權限不足提示 |

---

## 7. 風險評估與注意事項

| # | 風險 | 說明 / 緩解 |
|---|------|------------|
| **R1** | **POST users/{id} 與教師編輯共用** | B3 把權限由 `manage_woocommerce` 改 `edit_users` 會同時影響教師 Edit。administrator 兩者皆有，**不受影響**；WooCommerce 預設 `shop_manager` 亦被授予 `edit_users`（受限於 customer），多數情境安全。**緩解**：在 PR 描述標註；若有「僅 manage_woocommerce、無 edit_users」的自訂角色，需個案評估。**不**為了相容而拆成兩個端點（避免合約分裂）。 |
| **R2** | 密碼清空 | 若不 unset 空 `user_pass`，`wp_update_user` 會把密碼設為空 hash → 帳號失守。B5 必做，並有對應測試。 |
| R3 | 國家/州省代碼相容 | 必須存 WC 代碼（TW/US…）。前端用 WC `data/countries` 下拉產生 value；州省為自由文字國家時退回 Input。避免破壞結帳/訂單顯示。 |
| R4 | HPOS 訂單路徑 | view_all_url 與 wc_get_orders 需相容 HPOS 與傳統 shop_order。用 `wc_get_orders`（抽象層）+ `OrderUtil` 判斷後台 URL。 |
| R5 | 敏感欄位外洩 | GET users/{id} 必須剔除 `user_pass`、`session_tokens` 等；密碼欄位永不回傳明文。 |
| R6 | 列表效能 | 加 `billing_phone` 到 meta_keys 與講師表同模式，單一 meta 影響可控；大量學員時維持既有分頁。 |
| R7 | 雙來源資料同步 | 儲存後需 invalidate `users`（預設 provider，全域頁）**與** `students`（power-course，課程頁）兩個 resource，否則列表不同步。 |
| R8 | nocache_headers | 4 個 callback（含強化的 POST）開頭都要 `\nocache_headers()`（專案硬規範）。 |
| R9 | i18n | 新字串一律英文 msgid + manual.json 補繁中 + `pnpm run i18n:build`；禁手改 .po。Ant Design label/placeholder/okText 全要翻。 |
| R10 | 已完成訂單地址快照 | 屬 WC 標準行為（不隨 usermeta 更新）；非 bug，於 UI 提示/客服說明即可，不在本次處理。 |

---

## 8. 交接給 tdd-coordinator

- 規格齊備（feature/api.yml/erm.dbml/activity 已存在），可直接進入 Red→Green→Refactor。
- 建議 Issue/批次切分：
  1. **後端端點批**（B1–B7）+ PHP Integration（5.1）— 可先獨立完成並通過 `composer run test`。
  2. **前端 Drawer 批**（F1–F8）+ i18n（I1–I2）。
  3. **列表電話 + 接線批**（F5–F7）。
  4. **E2E smoke 批**（5.2）。
- Reviewer 為 opt-in；涉及權限/密碼（敏感領域），建議收尾補派 `@zenbu-powers:security-reviewer` 與 `@zenbu-powers:react-reviewer`。
- 完成定義：對齊 `student-quick-edit.feature` 全部 Rule、`pnpm run lint:php`/`lint:ts`/`build` 綠燈、PHP Integration 綠燈、目標 E2E smoke 通過、i18n 四檔已同步 commit。
