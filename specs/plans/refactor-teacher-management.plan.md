# 實作計劃：重構講師管理（對齊 Power Shop 顧客模組）

## 概述

將 Power Course 的「講師管理」（`/teachers`）前端 UI/UX 對齊 Power Shop 顧客模組（`power-shop/js/src/components/user/UserTable` + `pages/admin/Users/Edit`）的設計。核心洞察：「Power Shop 顧客」與「Power Course 講師」本質都是 WordPress 用戶，差異僅在 `is_teacher` user_meta；因此講師管理 = 顧客管理 + `is_teacher` 過濾 + 講師專屬 computed field（負責課程數、學員人數）。

本次範圍為**純重構**：不變更講師資料模型，僅提升前端 UI 結構、列表功能豐富度、Edit 頁完整度，並補齊後端端點權限與查詢支援。

---

## 需求重述

- **正在建構什麼**：重建 `/teachers` 列表頁（新 `TeacherTable`）+ 新增 `/teachers/edit/:id` 編輯頁（4-Tab 結構：基本資料 / 訂單紀錄 / 學習紀錄 / Meta），並補齊後端 `ExtendQuery`、端點權限、computed field。
- **服務對象**：Power Course 站長（WP admin / WC admin），在 WordPress Admin 側邊欄「講師」頁面管理所有 `is_teacher=yes` 的用戶。
- **成功的樣子**：
  1. 列表可按關鍵字、WP 角色、手機、生日月份、**負責課程**篩選；顯示頭像/Email、註冊時間、**負責課程數**、**學員人數**、WP 角色 Tag、操作。
  2. 批次操作可「傳送密碼重設連結」與「移除講師身分（含二次確認 Modal）」。
  3. 點講師頭像進入 `/teachers/edit/:id`，可在 4 個 Tab 檢視/編輯：基本資料、訂單紀錄（講師本人的訂單）、學習紀錄（講師本人的 `avl_courses`）、Meta（`other_meta_data` 動態編輯）。
  4. 新增講師（`Create instructor`）與「從 WP 用戶加為講師」（UserSelector）兩條入口保留。
  5. 後端 `inc/classes/Api/User.php` 的 5 個端點顯式加 `manage_woocommerce` 權限；`Teacher\Core\ExtendQuery` 支援 `teacher_course_id` meta_query 反查與 `teacher_courses_count`/`teacher_students_count` computed field。
  6. 所有新增字串走 `__('...', 'power-course')` 並同步 `scripts/i18n-translations/manual.json`；`pnpm run i18n:build` 產出一致。
  7. 既有 Students 頁（`pages/admin/Students`）與 `components/user/UserTable` **完全不動**，E2E 保留現有行為。

---

## 決策摘要（Q1~Q11）

| Q | 決策 | 理由 |
|---|------|------|
| Q1 | **B** 只重構 Teachers 頁 | 最小影響面；Students 頁已穩定、不值得連帶改 |
| Q2 | ~~A 保留 Drawer~~ **被 Q8 推翻** | |
| Q3 | **B** 批次 = 重設密碼 + 移除講師身分（二次確認） | 實用 + 拒絕 Power Shop 刪 WP 用戶的危險語意 |
| Q4 | **D++** Filter 6 欄（含負責課程篩選） | 使用者指定最滿版；UX 對齊 Power Shop |
| Q5 | **全要** 列表 7 欄（含 2 個 computed field + WP 角色） | 使用者指定最滿版；業務洞察最豐富 |
| Q6 | **A** 5 端點全補 `manage_woocommerce` | 語意清晰化，對齊專案 rule（非安全漏洞修復） |
| Q7 | **C** Probe Powerhouse `/users/resetpassword`，無則自建 | 保險；避免重複實作 |
| Q8 | 🚨 **推翻 Q2 → B** 走 Edit 頁 | 使用者最終決策；擴充 Drawer 改為新建 Edit 頁 |
| Q9 | Edit 頁 4 Tab：基本 / 訂單 / 學習 / Meta | 使用者指定 |
| Q10 | **A** 訂單紀錄 = 講師本人下的訂單 | 對齊 Q1=B 的「最小差異」哲學 |
| Q11 | **A** 學習紀錄 = 講師本人作為學員 | 同 Q10 |

---

## 現狀分析

### Power Shop 顧客模組（參考對象）

```
power-shop/js/src/components/user/
├── index.tsx                   # barrel: UserTable / OrderCustomerTable / ContactRemarks
├── UserTable/
│   ├── index.tsx               # Refine useTable({resource:'users'}) + FilterTags + Card + Table + ActionArea
│   ├── atom.tsx                # selectedUserIdsAtom (jotai)
│   ├── Filter/index.tsx        # search / role__in / billing_phone / user_birthday / include（hidden）
│   ├── BulkAction/
│   │   ├── index.tsx           # ResetPassButton + DeleteButton
│   │   ├── ResetPassButton/    # 打 ${apiUrl}/users/resetpassword（default = Powerhouse）
│   │   └── DeleteButton/       # 真刪 WP 用戶 + CONFIRM_WORD（講師情境不移植）
│   ├── hooks/useColumns.tsx    # 會員 / 角色 / 手機 / 生日 / 訂單數 / 消費額 / 平均 / 註冊時間
│   └── utils/index.tsx         # keyLabelMapper
├── OrderCustomerTable/         # 訂單內顯示客戶資料（本次不移植）
├── ContactRemarks/             # comments resource + comment_type=contact_remark（Q9 不含，不移植）
└── types/index.tsx             # TUserRecord / TUserDetails / TUserContactRemark / TUserCartItem

power-shop/js/src/pages/admin/Users/Edit/
├── index.tsx                   # Refine <Edit> + useForm + IsEditingContext + RecordContext
├── Detail/
│   ├── index.tsx               # 左：Statistic + Tabs[Basic/AutoFill/Meta]；右：ContactRemarks + Cart + RecentOrders
│   ├── Basic/index.tsx         # 姓名/顯示名/Email/角色/生日/簡介 + 密碼區塊
│   ├── AutoFill/index.tsx      # billing/shipping InfoTable（講師情境不移植）
│   ├── Meta/index.tsx          # other_meta_data 動態列（含雙層 confirm）
│   ├── Cart/index.tsx          # 當前購物車
│   └── RecentOrders/index.tsx  # 最近訂單
└── hooks/
    ├── useRecord.tsx           # Context hook TUserDetails
    └── useIsEditing.tsx        # Context hook boolean
```

### Power Course 講師管理現狀

```
power-course/js/src/pages/admin/Teachers/
├── index.tsx                           # 1 行薄殼：<TeacherTable/>
├── TeacherTable/
│   ├── index.tsx                       # 自寫 Table，permanent filter is_teacher=yes
│   │                                    # + UserSelector + 移除按鈕 + useUserFormDrawer 新增
│   └── hooks/useColumns.tsx            # 僅 1 欄「Instructor」
└── UserSelector/index.tsx              # 🚨 @ts-nocheck；搜尋非講師用戶並打 /users/add-teachers

power-course/inc/classes/Api/User.php   # 5 個端點，permission_callback=null（= manage_options||manage_woocommerce）
  - POST  users                         # 建用戶
  - POST  users/(?P<id>\d+)             # 更新用戶
  - POST  users/add-teachers            # 加講師（update_user_meta is_teacher=yes）
  - POST  users/remove-teachers         # 移除講師（delete_user_meta is_teacher）🚨 遇到失敗會 break
  - POST  users/upload-students         # CSV 匯入學員

power-course/inc/classes/Resources/Teacher/Core/
└── ExtendQuery.php                     # 僅支援 is_teacher=!yes（非講師查詢）；無 Api/Service/Loader
```

### 差距（Power Shop vs Power Course 講師）

| 項目 | Power Shop Users | Power Course Teachers（現況） | 本次要改 |
|---|---|---|---|
| List 結構 | Filter + Table + BulkAction + SelectedItem（ActionArea） | 僅 Table + UserSelector | ✅ 新建 `TeacherTable` 完整骨架 |
| Filter 欄位 | 4 欄（search/role/phone/birthday） | 0 欄 | ✅ 6 欄（+ 負責課程 + hidden include） |
| 列表欄位 | 7 欄電商導向 | 1 欄 | ✅ 7 欄講師導向（含 2 個 computed field） |
| 批次操作 | 重設密碼 + 刪用戶 | 只有移除講師身分 | ✅ 重設密碼 + 移除講師（二次確認） |
| 多選互動 | jotai atom + 全域 selectedUserIds | 只有 selectedRowKeys（單頁） | ✅ 改用 atom pattern 支援跨頁多選 |
| Edit 頁 | 4 Tab（Basic/AutoFill/Meta + ContactRemarks 右側） | 無 Edit 頁，用 Drawer | ✅ 新建 Edit 頁 4 Tab（基本/訂單/學習/Meta） |
| 後端權限 | Powerhouse 預設 | `null` → fallback `manage_options||manage_woocommerce` | ✅ 顯式寫 `manage_woocommerce` |
| Computed field | `total_spend`/`orders_count`/`avg_order_value` | 無講師專屬 | ✅ 新增 `teacher_courses_count`/`teacher_students_count` |
| meta_query 支援 | — | `is_teacher=!yes` | ✅ 新增 `teacher_course_id`（課程反查講師） |

---

## 架構變更

### 新增檔案

```
js/src/components/teacher/                     # 🆕 新目錄（與 components/user/ 平級）
├── index.tsx                                  # barrel
├── types/index.tsx                            # TTeacherRecord / TTeacherDetails
├── TeacherTable/
│   ├── index.tsx                              # Refine useTable + permanent filter is_teacher=yes
│   ├── atom.tsx                               # selectedTeacherIdsAtom
│   ├── Filter/index.tsx                       # 6 欄 Filter
│   ├── BulkAction/
│   │   ├── index.tsx                          # ResetPassButton + RemoveRoleButton
│   │   ├── ResetPassButton/index.tsx          # 打 /users/resetpassword（優先 Powerhouse, fallback 自建）
│   │   └── RemoveRoleButton/index.tsx         # 二次確認 Modal + /users/remove-teachers
│   ├── hooks/useColumns.tsx                   # 7 欄
│   ├── hooks/useOptions.tsx                   # 打 /users/options 取 role 清單（若 Powerhouse 無則自建）
│   ├── utils/index.tsx                        # keyLabelMapper
│   └── AddTeacherArea/index.tsx               # 整合 Create 按鈕 + UserSelector（取代舊 UserSelector @ts-nocheck）

js/src/pages/admin/Teachers/Edit/              # 🆕 新目錄
├── index.tsx                                  # Refine <Edit> + Context Providers
├── Detail/
│   ├── index.tsx                              # Statistic（講師統計）+ Tabs
│   ├── Basic/index.tsx                        # 姓名/顯示名/Email/角色/簡介 + 密碼區塊 + UserAvatarUpload
│   ├── Orders/index.tsx                       # 合併 Power Shop Cart + RecentOrders
│   ├── Learning/index.tsx                     # avl_courses 渲染 + HistoryDrawer 整合
│   └── Meta/index.tsx                         # other_meta_data（雙層 confirm）
└── hooks/
    ├── index.tsx                              # barrel
    ├── useRecord.tsx                          # Context hook TTeacherDetails
    └── useIsEditing.tsx                       # Context hook boolean

inc/classes/Resources/Teacher/Service/         # 🆕 新目錄（若邏輯量大才拆）
└── Query.php                                  # 可選：若 ExtendQuery 過大則拆出
```

### 修改檔案

```
inc/classes/Api/User.php                       # 5 個端點補 permission_callback；修正 remove-teachers 的 batch bug
inc/classes/Resources/Teacher/Core/ExtendQuery.php
                                               # 加 teacher_course_id meta_query 支援
                                               # 加 teacher_courses_count / teacher_students_count computed field
                                               # （透過 powerhouse/user/get_meta_keys_array filter 附加）
inc/classes/Resources/Loader.php               # 若新增 Service 類，此處註冊

js/src/pages/admin/Teachers/index.tsx          # 改 import <TeacherTable from '@/components/teacher'>
js/src/resources/index.tsx                     # 加 edit: '/teachers/edit/:id'
js/src/App1.tsx                                # 加 Route '/teachers/edit/:id'

scripts/i18n-translations/manual.json          # 加入所有新增 msgid 的繁中翻譯
```

### 刪除檔案

```
js/src/pages/admin/Teachers/TeacherTable/      # 整個目錄（含 hooks/useColumns.tsx, index.tsx）
js/src/pages/admin/Teachers/UserSelector/      # 整個目錄（@ts-nocheck 技術債一起清掉）
```

### 保持不動（邊界）

- `js/src/components/user/**`（被 Students 頁與 CourseStudents 用）
- `js/src/pages/admin/Students/**`
- `js/src/pages/admin/Courses/Edit/tabs/CourseStudents/**`
- `js/src/components/user/UserDrawer/index.tsx`（仍作為新增講師的「Create」入口可能繼續用，也可考慮改為 Create Modal；本計劃中保留現狀，由實作者視整合情況決定）
- `js/src/hooks/useUserFormDrawer.tsx`
- `js/src/pages/admin/Courses/List/types/user.ts`（`TUserRecord` 原地不動）
- `components/user/types/`（不存在，不新增；講師型別放 `components/teacher/types/`）

---

## 資料流分析

### 流程 1：列表載入 + 篩選

```
 User (admin)
     │
     ▼
 GET /v2/powerhouse/users?is_teacher=yes&role__in[]=...&billing_phone=...
 &user_birthday=...&teacher_course_id=...&meta_keys[]=is_teacher&meta_keys[]=role
 &meta_keys[]=teacher_courses_count&meta_keys[]=teacher_students_count
     │
     ▼
 Refine useTable + dataProvider (default = Powerhouse)
     │
     ▼
 Powerhouse \WP_User_Query ── filter: powerhouse/user/prepare_query_args/meta_query_builder
     │                            │
     │                            ▼
     │                      Teacher\Core\ExtendQuery::extend_query_args()
     │                        - 處理 is_teacher=!yes 轉成 OR NOT EXISTS
     │                        - 處理 teacher_course_id 反查（read Course post meta teacher_ids）
     │
     ▼
 users[] → 經 powerhouse/user/get_meta_keys_array filter 附加 meta →
     │                            │
     │                            ▼
     │                      Teacher\Core\ExtendQuery::extend_meta_keys()
     │                        - 計算 teacher_courses_count（count of courses where teacher_ids 含 user_id）
     │                        - 計算 teacher_students_count（join pc_avl_coursemeta）
     │
     ▼
 REST Response (TTeacherRecord[])
     │
     ▼
 TeacherTable <Table> render
     │
     ▼
 [nil?]     [empty?]      [stale?]
  │           │              │
  ▼           ▼              ▼
 avatar      <Empty/>    invalidate on mutation
 fallback    提示        success
```

**Shadow paths**：
- nil：`record.formatted_name` 缺失 → fallback `display_name`（`UserName` 已實作）
- empty：`dataSource=[]` → Ant Table 內建「暫無資料」
- invalid：Filter `user_birthday` 格式錯誤 → Select 只允許 01~12，無法送錯
- exception：後端 500 → Refine notificationProvider 顯示錯誤 Toast
- stale：mutation 後 `useInvalidate()` 重取 list
- partial：computed field 計算失敗（SQL error）→ fallback 0（PHP 端 try-catch）

### 流程 2：批次操作（移除講師身分 / 重設密碼）

```
 User selects N teachers
     │
     ▼
 selectedTeacherIdsAtom (jotai) updated via useRowSelection
     │
     ▼
 Click "Remove instructor role" → 二次確認 Modal
     │                              │
     │                              ▼
     │                        輸入 CONFIRM 文字符合才啟用 OK
     │
     ▼
 POST /power-course/v2/users/remove-teachers { user_ids: string[] }
     │
     ▼
 ApiBase::try() wraps callback ── try/catch 轉 500
     │
     ▼
 permission_callback 驗 manage_woocommerce（Q6=A 新增）
     │
     ▼
 foreach user_ids → delete_user_meta(user_id, 'is_teacher')
     │                (目前 break on fail → 本次修正為收集錯誤陣列，回傳部分成功)
     │
     ▼
 Response { code, message, data: { user_ids, failed: [] } }
     │
     ▼
 onSuccess → message.success + invalidate list + clear atom
     │
     ▼
 [nil?]         [empty user_ids?]   [partial failure?]
  │                 │                 │
  ▼                 ▼                 ▼
 驗證拒絕     驗證拒絕400       回傳 failed[]
                                    UI 顯示「N 中 M 成功」
```

**重設密碼流程同理，差別在 endpoint**：
- 優先打 `${useApiUrl()}/users/resetpassword`（default = Powerhouse）
- 若 404 → fallback `${useApiUrl('power-course')}/users/resetpassword`（需自建）

### 流程 3：Edit 頁載入

```
 User clicks teacher row / "edit" action
     │
     ▼
 Navigate to #/teachers/edit/:id
     │
     ▼
 Refine useForm({ resource:'users', action:'edit', id })
     │
     ▼
 GET /v2/powerhouse/users/{id}?meta_keys[]=description&...
     │
     ▼
 Response (TTeacherDetails: user fields + recent_orders + cart + avl_courses + other_meta_data)
     │
     ▼
 RecordContext.Provider value={record}
     │
     ▼
 <Tabs>
   ├─ Basic      → useRecord() read; useIsEditing() 切換 view/edit 模式
   ├─ Orders     → 顯示 record.recent_orders 與 record.cart
   ├─ Learning   → 顯示 record.avl_courses + <HistoryDrawer> 打開進度
   └─ Meta       → 顯示 record.other_meta_data 雙層 confirm 才能編輯
     │
     ▼
 Save → onFinish → POST /v2/powerhouse/users/{id}
                  （form-data；billing/shipping 不處理因講師不顯示 AutoFill）
     │
     ▼
 [nil record?]   [empty avl_courses/orders?]
  │                 │
  ▼                 ▼
 isLoading        <Empty/> 提示
 skeleton
```

**Shadow paths**：
- nil：`record` 未到時 `useRecord()` 回傳 `{} as TTeacherDetails` → 各 Tab 內需防呆（`?.`）
- empty：`recent_orders=[]` → Orders Tab 顯示 Empty；`avl_courses=[]` → Learning Tab 顯示 Empty
- error：`useForm` query 失敗 → Refine notificationProvider 顯示；頁面顯示 ErrorComponent
- stale：Meta 編輯後 `useInvalidate({id})` 重取
- conflict：兩個 admin 同時編輯 → WP 無 optimistic lock，後寫覆蓋（現有行為，不變更）

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|---|---|---|---|---|
| `POST /users/resetpassword`（Powerhouse or 自建） | user_ids 空 | 400 | 前端 button `disabled={!user_ids.length}` | ✅ 按鈕灰階 |
| 同上 | `retrieve_password()` 失敗（SMTP/用戶不存在） | WP_Error | 後端收集失敗陣列回傳 partial success | ✅ Toast 顯示 |
| `POST /users/remove-teachers` | user_ids 空 | 400 | 前端 button disabled + 後端 validate | ✅ 按鈕灰階 |
| 同上 | `delete_user_meta()` 個別失敗 | 布林 false | 🚨 **目前 bug**：一失敗就 break。修正為收集 failed[] | ✅ Toast 顯示 partial |
| 同上 | 非 admin 呼叫 | 403 | `manage_woocommerce` 檢查（Q6=A） | ✅ Toast 顯示 forbidden |
| `GET /v2/powerhouse/users?is_teacher=yes` | meta_query SQL error | 500 | try-catch in ExtendQuery filter | ✅ Refine error notification |
| 同上 | `teacher_course_id` 值無效（非 numeric） | 靜默 | 後端 absint 後過濾空值 | ❌（正常，空篩選） |
| computed field 計算 | Course post meta `teacher_ids` 格式異常 | Exception | try-catch fallback 0 | ❌（靜默返回 0） |
| `POST /users/{id}` | Email 重複 | WP_Error | 現有 code 已處理回 400 | ✅ Toast 顯示 |
| Edit 頁 useForm | query 404（ID 不存在） | Refine 內建 | Refine 跳 ErrorComponent | ✅ |
| Edit 頁 Meta 編輯 | 雙層 confirm 未過 | UI 層 | 欄位不可編輯 | ✅ 按鈕灰階 |
| `useOptions` `/users/options` | Powerhouse 無此端點 | 404 | 🟡 implementation-time probe，無則自建 | N/A |

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|---|---|---|---|---|---|
| `TeacherTable/BulkAction/RemoveRoleButton` 重複快速點擊 | 多次送出相同請求 | ❌ 需加 | 需 E2E | ✅ 可能顯示多次 toast | `isLoading` 狀態 disable button |
| 同上：二次確認文字輸入到一半離開 | 狀態殘留 | ❌ | 需單元 | ❌ 使用者不易察覺 | Modal `onCancel` reset value state |
| `selectedTeacherIdsAtom` 換頁時選取 | 跨頁選取遺失 | ✅ 沿用 Power Shop pattern | 需 E2E | ✅ | `useEffect` 同步 filters/pagination |
| Edit 頁 Meta 編輯中離開路由 | 未儲存內容遺失 | ✅ Refine `UnsavedChangesNotifier` 已在 App1.tsx | — | ✅ 瀏覽器原生 confirm | warnWhenUnsavedChanges=true |
| Edit 頁 `record` 未到就 render Tab | 顯示空狀態 | ✅ `useForm` isLoading 已處理 | — | ✅ skeleton | Refine 內建 |
| `/users/remove-teachers` 部分失敗 | 一半用戶保留講師身分 | ❌ 目前 break | 需單元 | ❌ 目前回 success+status 200 | 收集 failed[] 回 partial 200 + UI 顯示 |
| `teacher_courses_count` 計算時課程 meta 欄位改名 | computed field 回 0 | ❌ | 需單元 | ❌ 顯示 0 靜默 | try-catch + `wc_logger` warn |
| `/teachers/edit/:id` 直接開網址但 user 不是 `is_teacher=yes` | Edit 頁照常顯示 | ❌ | 需 E2E | ✅ 能編輯非講師 | Edit 頁 load 後驗 `is_teacher`，非則 redirect list + toast |
| Filter 的「負責課程」選項空清單（Course CPT 未建） | Select 顯示 Empty | ✅ Ant Design 預設 | — | ✅ | 無需特別處理 |
| Filter 表單 `user_birthday` 傳 `'ALL'` | 後端忽略 | ✅ 現有 meta_query 會略過 | — | ❌ | 無需處理 |

---

## 實作步驟

> **範圍模式**：原 HOLD SCOPE，因 Q4/Q5/Q8 加欄位擴張為 **EXPANSION（有節制）**。預估 ~28 檔，未觸 30 檔硬停紅線。
> 每階段都設計為**可獨立 PR 合併**；階段 1 完成後 Teachers 頁已可用（只是沒有 Edit 頁，仍可走舊 Drawer）。

---

### 第一階段：後端基礎建設（PHP）

**目標**：端點權限補齊、Teacher ExtendQuery 擴充、修正 remove-teachers batch bug。**可獨立 PR**。

1. **補 API 端點權限**（檔案：`inc/classes/Api/User.php:30-56`）
   - 行動：5 個端點的 `'permission_callback' => null` → `'permission_callback' => fn() => \current_user_can('manage_woocommerce')`
   - 原因：消除 R1 的語意不清晰（雖非安全漏洞，但顯式寫出更符合專案 rule）
   - 依賴：無
   - 風險：低（ApiBase fallback 行為本就等價）

2. **修正 remove-teachers batch bug**（檔案：`inc/classes/Api/User.php:216-243`）
   - 行動：`post_users_remove_teachers_callback` 把 `break` on fail 改為收集 `$failed[]`；回傳結構加 `data: { user_ids, failed_user_ids }`；status 按全成功 200 / partial 200 with `failed_user_ids` / 全失敗 400 判斷
   - 原因：既有邏輯一失敗就中斷、不回報哪些成功，使用者無法排查
   - 依賴：無
   - 風險：低

3. **Teacher ExtendQuery 擴充**（檔案：`inc/classes/Resources/Teacher/Core/ExtendQuery.php`）
   - 行動：
     - 新增 `extend_query_args` 支援 `teacher_course_id` clause：查該 course_id post meta `teacher_ids` 中的用戶 → 用 `user__in` 過濾
     - 新增 `extend_meta_keys` 掛 `powerhouse/user/get_meta_keys_array` filter，對請求的 user，計算：
       - `teacher_courses_count`：`WP_Query` courses post meta `teacher_ids` contains user_id → count
       - `teacher_students_count`：從上述 course_ids 查 `pc_avl_coursemeta` `WHERE meta_key='avl_course_ids' AND meta_value IN (...)` distinct user → count
     - 全部包 try-catch fallback 0
   - 原因：支援 Q4 篩選 + Q5 列表欄位
   - 依賴：步驟 1（因為新 logic 同檔觸發權限檢查統一）
   - 風險：中（SQL 效能需注意，講師常數少但學員多）

4. **probe Powerhouse `/users/resetpassword`**（檔案：新增 `inc/classes/Api/User.php` endpoint 或確認不需要）
   - 行動：實作者執行 `curl /wp-json/v2/powerhouse/users/resetpassword` 或看 Powerhouse `vendor/j7-dev/wp-utils/src/classes/` 是否有對應 callback。若無 → 在 `inc/classes/Api/User.php` `$apis` 加一項 `['endpoint' => 'users/resetpassword', 'method' => 'post']`，callback 內部呼叫 `retrieve_password($user_login)` per id，收集失敗
   - 原因：Q7=C，實作者現場決定
   - 依賴：無
   - 風險：低

5. **probe Powerhouse `/users/options`**（同上手法）
   - 行動：確認是否回 `{ roles: [{value, label}] }`；若無 → 自建 endpoint 回 `wp_roles()->get_names()` 轉格式
   - 原因：Q4 需要角色下拉選項
   - 依賴：無
   - 風險：低

**階段成功標準**：
- [ ] `composer run phpstan` 通過 level 9
- [ ] `pnpm run lint:php` 通過
- [ ] 手動 curl 測試 5 個端點：未登入 → 401 / 訂閱者登入 → 403 / admin → 200
- [ ] Powerhouse GET users 帶 `meta_keys[]=teacher_courses_count` 回傳數字
- [ ] GET users 帶 `teacher_course_id=<course_id>` 只回該課程的講師

**預估複雜度**：中

---

### 第二階段：前端 `components/teacher/` 骨架（TypeScript）

**目標**：建立新 TeacherTable 元件樹，與 Power Shop UserTable 結構 1:1 對應。**可獨立 PR**（但依賴第一階段的 computed field / meta_query）。

1. **types**（檔案：`js/src/components/teacher/types/index.tsx`）
   - 行動：定義 `TTeacherRecord`（extends `TUserRecord` from `@/pages/admin/Courses/List/types`）、`TTeacherDetails`（加 first_name / last_name / description / role / recent_orders / cart / other_meta_data / teacher_courses_count / teacher_students_count / billing / shipping）
   - 依賴：無
   - 風險：低

2. **atom**（檔案：`js/src/components/teacher/TeacherTable/atom.tsx`）
   - 行動：`selectedTeacherIdsAtom = atom<string[]>([])`
   - 依賴：無
   - 風險：低

3. **Filter**（檔案：`js/src/components/teacher/TeacherTable/Filter/index.tsx`）
   - 行動：6 欄 Form.Item：`search`（Input）/ `role__in`（Select，來自 `useOptions`）/ `billing_phone`（Input）/ `user_birthday`（Select 01~12）/ `teacher_course_id`（useCourseSelect）/ `include`（hidden Select mode=tags）
   - 依賴：步驟 9（useOptions hook）；existing `useCourseSelect`
   - 風險：低

4. **useOptions hook**（檔案：`js/src/components/teacher/TeacherTable/hooks/useOptions.tsx`）
   - 行動：抄 Power Shop `useOptions`，打 `${useApiUrl()}/users/options`（**注意**：不指定 dataProvider → default = Powerhouse）；若第一階段步驟 5 自建了端點則改打 `useApiUrl('power-course')`
   - 依賴：第一階段步驟 5
   - 風險：低

5. **useColumns**（檔案：`js/src/components/teacher/TeacherTable/hooks/useColumns.tsx`）
   - 行動：7 欄，全部走 `__('...', 'power-course')`：
     - Instructor（UserName + 點擊 → `edit('teachers', id)` via `useNavigation`）
     - Email（純 text）
     - Registered at（沿用學員版的雙行渲染）
     - Courses count（`teacher_courses_count`，右對齊，0 顯示 dash）
     - Students count（`teacher_students_count`，右對齊）
     - Role（`<UserRole role={role}/>` from `antd-toolkit/wp`）
     - Actions（Edit 按鈕，連結 edit 路由）
   - 依賴：步驟 1
   - 風險：低

6. **BulkAction/RemoveRoleButton**（檔案：`js/src/components/teacher/TeacherTable/BulkAction/RemoveRoleButton/index.tsx`）
   - 行動：
     - 使用 `useModal` + `Modal` + `Input` + 二次確認文字（如 `CONFIRM_WORD = '我確定要移除這些講師的身分'`）
     - `useCustomMutation` 打 `POST /power-course/v2/users/remove-teachers` FormData user_ids
     - onSuccess 顯示 partial success 訊息（若 `failed_user_ids` 非空 → `message.warning`）
     - 重複點擊防呆：`isLoading` disable
     - `onCancel` reset input value state
   - 依賴：步驟 2（atom）
   - 風險：中

7. **BulkAction/ResetPassButton**（檔案：`js/src/components/teacher/TeacherTable/BulkAction/ResetPassButton/index.tsx`）
   - 行動：`useCustomMutation`，URL 先嘗試 default provider（`${useApiUrl()}/users/resetpassword`）；若 Powerhouse 已提供就直接用；`isLoading` disable；成功 → `message.success`，失敗 → `message.error`
   - 依賴：第一階段步驟 4
   - 風險：低

8. **BulkAction/index.tsx**（檔案：`js/src/components/teacher/TeacherTable/BulkAction/index.tsx`）
   - 行動：`<ResetPassButton user_ids={selectedTeacherIds} mode="multiple"/> <RemoveRoleButton/>`
   - 依賴：6, 7
   - 風險：低

9. **utils**（檔案：`js/src/components/teacher/TeacherTable/utils/index.tsx`）
   - 行動：`keyLabelMapper(key)` switch `__()` 對應翻譯
   - 依賴：無
   - 風險：低

10. **AddTeacherArea**（檔案：`js/src/components/teacher/TeacherTable/AddTeacherArea/index.tsx`）
    - 行動：整合「Create instructor」按鈕（打開現有 `useUserFormDrawer` + `UserDrawer`，送出時強制 `is_teacher: 'yes'`）+ 新版 UserSelector（**去掉 @ts-nocheck**，正確定型 `useSelect<TUserRecord>` 過濾 `is_teacher=!yes`，選擇後 POST `/users/add-teachers`）
    - 依賴：existing `useUserFormDrawer`, `UserDrawer`
    - 風險：中（要修型別）

11. **TeacherTable/index.tsx**（檔案：`js/src/components/teacher/TeacherTable/index.tsx`）
    - 行動：抄 Power Shop `UserTable/index.tsx` 結構：
      - `useTable<TTeacherRecord, HttpError, TFilterValues>({ resource: 'users', pagination: { pageSize: 20 }, filters: { permanent: [{ field: 'is_teacher', operator: 'eq', value: 'yes' }, { field: 'meta_keys', operator: 'eq', value: ['is_teacher', 'role', 'teacher_courses_count', 'teacher_students_count'] }], defaultBehavior: 'replace' }, onSearch: values => objToCrudFilters(values) })`
      - `useRowSelection` + `selectedTeacherIdsAtom` 跨頁同步（邏輯抄 Power Shop）
      - render：`<Card title={__('Filter')}>` + `<Filter>` + `<FilterTags>` / `<Card>` + `<AddTeacherArea>` + `<Table>` / `{!!selectedTeacherIds.length && <ActionArea>...<BulkAction/><SelectedItem/>}`
      - i18n：全部走 `__('...', 'power-course')`
    - 依賴：1~10
    - 風險：中

12. **barrel**（檔案：`js/src/components/teacher/index.tsx`）
    - 行動：`export * from './TeacherTable'; export * from './types'`
    - 依賴：11
    - 風險：低

13. **頁面接線**（檔案：`js/src/pages/admin/Teachers/index.tsx`）
    - 行動：`import { TeacherTable } from '@/components/teacher'` 取代舊 import
    - 依賴：12
    - 風險：低

14. **清理舊檔**（刪除：`js/src/pages/admin/Teachers/TeacherTable/` 整個目錄、`js/src/pages/admin/Teachers/UserSelector/` 整個目錄）
    - 行動：`git rm -r` 並確認 `grep` 沒有其他地方 import
    - 依賴：13（確認無他處引用後）
    - 風險：低

**階段成功標準**：
- [ ] `pnpm run lint:ts` 通過，無 `@ts-nocheck`
- [ ] `pnpm run build` 通過
- [ ] 手動：`/teachers` 頁顯示 7 欄、Filter 6 欄、批次操作 2 顆按鈕
- [ ] 批次「移除講師身分」二次確認 Modal 打字符合才啟用 OK
- [ ] 批次「傳送密碼重設」實際收到信（或 wp debug log 顯示 `retrieve_password` 被呼叫）
- [ ] 點列表頭像 → 跳轉 `/teachers/edit/:id`（階段 3 完成前可能 404，預期）

**預估複雜度**：中

---

### 第三階段：Edit 頁（TypeScript）

**目標**：新建 4-Tab Edit 頁，對齊 Power Shop 結構。**可獨立 PR**。

1. **hooks**（檔案：`js/src/pages/admin/Teachers/Edit/hooks/useRecord.tsx`, `useIsEditing.tsx`, `index.tsx`）
   - 行動：抄 Power Shop 對應檔，型別改為 `TTeacherDetails`
   - 依賴：階段 2 步驟 1（type）
   - 風險：低

2. **Edit/index.tsx**（檔案：`js/src/pages/admin/Teachers/Edit/index.tsx`）
   - 行動：抄 Power Shop `pages/admin/Users/Edit/index.tsx`，但：
     - 砍 `handleOnFinish` 中 billing/shipping flatten 邏輯（講師不用）
     - `<Edit resource="posts">` → `<Edit resource="teachers">`（僅影響麵包屑文字）
     - `headerButtons` 保留「前往傳統用戶編輯介面」（`record?.edit_url`）
     - i18n：全部走 `__()`
   - 依賴：1
   - 風險：低

3. **Detail/Basic**（檔案：`js/src/pages/admin/Teachers/Edit/Detail/Basic/index.tsx`）
   - 行動：抄 Power Shop `Basic`，保留：姓名 / 顯示名 / Email / 角色（`useOptions`）/ 簡介 / 密碼區塊（`ResetPassButton` + 直接修改）；加 `UserAvatarUpload` 欄位在最上方
   - 依賴：1, `useOptions`（階段 2 步驟 4）
   - 風險：低

4. **Detail/Orders**（檔案：`js/src/pages/admin/Teachers/Edit/Detail/Orders/index.tsx`）
   - 行動：合併 Power Shop `Cart` + `RecentOrders` 到同一檔；上半 `record.cart` 渲染、下半 `record.recent_orders` 渲染；empty state 走 `<Empty>`
   - 依賴：1；`@/components/general.Price`
   - 風險：低

5. **Detail/Learning**（檔案：`js/src/pages/admin/Teachers/Edit/Detail/Learning/index.tsx`）
   - 行動：從 `components/user/UserTable/hooks/useColumns.tsx` 第 32-156 行的 `avl_courses` render logic **抽出為純函式元件** `<AvlCoursesList courses={record.avl_courses} user={record}/>` 放 `components/teacher/` 底下（新增 `components/teacher/AvlCoursesList/index.tsx`）→ 給 Learning Tab 用；複用現有 `HistoryDrawer` + `historyDrawerAtom`
   - 依賴：1
   - 風險：中（抽出不動舊邏輯，純拆成可復用元件）

6. **Detail/Meta**（檔案：`js/src/pages/admin/Teachers/Edit/Detail/Meta/index.tsx`）
   - 行動：抄 Power Shop `Meta`，i18n 本地化；雙層 confirm 保留
   - 依賴：1
   - 風險：低

7. **Detail/index.tsx**（檔案：`js/src/pages/admin/Teachers/Edit/Detail/index.tsx`）
   - 行動：結構抄 Power Shop `Detail`，但：
     - 左上 `Statistic` 改為講師專屬：`teacher_courses_count` / `teacher_students_count` / `user_registered` / `date_last_active`（捨棄 `total_spend` 等電商統計）
     - 左下 `<Tabs items={[Basic, Orders, Learning, Meta]}/>` （使用者 Q9 4 Tab）
     - 右側 **不**放 ContactRemarks（Q9 不含）；改為空（或全寬 Tabs 佔整個左側）
   - 依賴：3~6
   - 風險：低

8. **路由註冊**（檔案：`js/src/resources/index.tsx:33-39`）
   - 行動：teachers resource 加 `edit: '/teachers/edit/:id'`
   - 依賴：無
   - 風險：低

9. **App Route**（檔案：`js/src/App1.tsx:136-143`）
   - 行動：把 `<Route path="teachers">` 擴成 List + Edit 子路由（模仿 `courses` 結構）
   - 依賴：2
   - 風險：低

**階段成功標準**：
- [ ] `/teachers/edit/:id` 可打開顯示 4 Tab
- [ ] Basic Tab 可切換 view/edit、儲存後 useInvalidate 重取
- [ ] Orders Tab 顯示 cart + recent_orders；空資料顯示 Empty
- [ ] Learning Tab 顯示 avl_courses；點「學習歷程」打開 HistoryDrawer
- [ ] Meta Tab 雙層 confirm 才能編輯
- [ ] 進入非 `is_teacher` 用戶的 edit 頁會 redirect 回 list + toast（新增驗證）
- [ ] `pnpm run lint:ts` / `pnpm run build` 通過

**預估複雜度**：高

---

### 第四階段：i18n + 驗收打磨

**目標**：所有字串進 manual.json、跑完整 i18n pipeline；補錯誤邊界；E2E smoke。**必須 PR**。

1. **i18n 新增字串**（檔案：`scripts/i18n-translations/manual.json`）
   - 行動：把下方「i18n 字串清單」的 40+ 個 msgid 對應繁中加入
   - 依賴：階段 2, 3 所有字串定案
   - 風險：低

2. **跑 i18n pipeline**（指令：`pnpm run i18n:build`）
   - 行動：產出新 `.pot` / `.po` / `.mo` / `.json`，確認無 warning
   - 依賴：1
   - 風險：低

3. **錯誤邊界補強**
   - 行動：Edit 頁加 `useEffect` 驗 `record.is_teacher`，非 true 則 `notification.warning` + navigate list
   - 依賴：階段 3 完成
   - 風險：低

4. **Playwright E2E smoke**（檔案：`tests/e2e/admin/teachers.spec.ts`，可新增或擴充既有）
   - 行動：最少涵蓋：
     - 列表載入顯示 7 欄
     - Filter 搜尋生效
     - 批次「移除講師身分」走完二次確認流程並驗證 `is_teacher` meta 被刪
     - 新增講師 → 列表出現
     - 點講師進 Edit 頁 → 4 Tab 可切換 → Basic Tab 編輯儲存成功
     - 批次「重設密碼」按鈕可點、toast 顯示成功
   - 依賴：階段 2, 3 完成
   - 風險：中（E2E 環境配置）

**階段成功標準**：
- [ ] `pnpm run i18n:build` 無錯
- [ ] 查 `languages/power-course.po` 新字串 msgstr 有繁中
- [ ] `pnpm run test:e2e:admin -- teachers` 全綠
- [ ] `pnpm run lint:ts` + `pnpm run lint:php` + `composer run phpstan` 全綠

**預估複雜度**：中

---

## i18n 字串清單（新增 msgid → 繁中對照）

> 放進 `scripts/i18n-translations/manual.json`；msgid 全英文、繁中放 `msgstr` 側。
> 以下清單供實作者直接套用；若與既有 msgid 衝突則沿用既有即可。

| msgid | 繁中翻譯建議 | 用於 |
|---|---|---|
| `Filter` | 篩選 | TeacherTable Card title |
| `Keyword search` | 關鍵字搜尋 | Filter 標籤 |
| `Enter user ID, username, email or display name` | 輸入用戶 ID、帳號、Email 或顯示名稱 | Filter search placeholder |
| `Role` | 角色 | Filter 標籤 |
| `Phone` | 手機 | Filter 標籤 |
| `Enter phone number` | 輸入手機號碼 | Filter 手機 placeholder |
| `Birthday month` | 生日月份 | Filter 標籤 |
| `Select month` | 選擇月份 | Filter 月份 placeholder |
| `Taught courses` | 負責課程 | Filter 標籤 |
| `Include specific users` | 包含指定用戶 | Filter hidden include |
| `Enter user ID` | 輸入用戶 ID | Filter include placeholder |
| `Reset` | 重置 | Filter 按鈕 |
| `Instructor` | 講師 | 列表欄位（已有） |
| `Email` | Email | 列表欄位 |
| `Registered at` | 註冊時間 | 列表欄位（已有） |
| `Courses count` | 負責課程數 | 列表欄位 |
| `Students count` | 學員人數 | 列表欄位 |
| `Role tag` | 角色標籤 | 列表欄位 |
| `Actions` | 操作 | 列表欄位 |
| `Edit` | 編輯 | 操作按鈕 |
| `Create instructor` | 新增講師 | Button（已有） |
| `Add from WordPress user` | 從 WP 用戶加為講師 | UserSelector（已有） |
| `Try searching email, name, or ID` | 嘗試搜尋 Email、姓名或 ID | UserSelector placeholder（已有） |
| `Send password reset link` | 傳送密碼重設連結 | BulkAction button |
| `Send password reset links (%s)` | 批次傳送密碼重設連結 (%s) | BulkAction button（multiple）|
| `Remove instructor role` | 移除講師身分 | BulkAction button（已有）|
| `Confirm to remove instructor role for %d teachers` | 確認要移除 %d 位講師的身分嗎？ | Modal title |
| `I am sure I want to remove these instructor roles` | 我確定要移除這些講師的身分 | CONFIRM_WORD |
| `Please type the confirmation text above` | 請輸入上方的確認文字 | Modal Input placeholder |
| `Successfully removed instructor role for %d users` | 已成功移除 %d 位用戶的講師身分 | Toast |
| `%1$d succeeded, %2$d failed` | %1$d 成功、%2$d 失敗 | Partial success toast |
| `Batch operation` | 批次操作 | ActionArea label |
| `Selected %d teachers` | 已選擇 %d 位講師 | SelectedItem label |
| `Show selected` | 顯示已選用戶 | SelectedItem button |
| `Clear selection` | 清除選取 | SelectedItem button |
| `Instructor information` | 講師資料 | Edit 頁 Heading |
| `Basic info` | 基本資料 | Tab 標籤 |
| `Order records` | 訂單紀錄 | Tab 標籤 |
| `Learning records` | 學習紀錄 | Tab 標籤 |
| `Meta` | Meta | Tab 標籤 |
| `First name` | 名 | Basic 欄位 |
| `Last name` | 姓 | Basic 欄位 |
| `Display name` | 顯示名稱 | Basic 欄位（已有） |
| `Description` | 簡介 | Basic 欄位 |
| `Password` | 密碼 | Basic 欄位（已有） |
| `Change password directly` | 直接修改密碼 | Basic button |
| `Enter new password` | 請輸入新密碼 | Basic placeholder |
| `Edit user` | 編輯用戶 | Edit footer button |
| `Cancel` | 取消 | Edit footer button（已有） |
| `Save` | 儲存 | Edit footer button（已有） |
| `Go to classic user edit page` | 前往傳統用戶編輯介面 | Header link |
| `Current cart` | 當前購物車 | Orders Tab Heading |
| `Recent orders` | 最近訂單 | Orders Tab Heading |
| `Cart is empty` | 購物車為空 | Orders Empty |
| `No recent orders` | 沒有最近訂單 | Orders Empty |
| `Cart total` | 購物車金額 | Orders |
| `Order total` | 訂單總額 | Orders |
| `Granted courses` | 已授權課程 | Learning Tab（已有） |
| `No granted courses` | 尚未開通任何課程 | Learning Empty |
| `Danger zone` | 危險操作 | Meta Tab Alert |
| `This directly edits user_meta. If you are not sure, do not change anything.` | 這邊是直接操作 user_meta 的資料，如果您不明白這個含意，請不要做任何變更 | Meta Tab Alert desc |
| `I know what I am doing` | 我很清楚我在做什麼 | Meta Tab button |
| `Courses taught` | 負責課程 | Statistic 標籤 |
| `Students taught` | 學員人數 | Statistic 標籤 |
| `User is not an instructor` | 此用戶不是講師 | Edit 頁驗證 toast |

---

## 測試策略

### 單元測試（PHPUnit，`tests/`）
- `inc/classes/Resources/Teacher/Core/ExtendQuery.php`
  - `teacher_course_id` clause 正確轉成 `user__in`
  - `teacher_courses_count` / `teacher_students_count` 回正確數字
  - 課程 meta 格式異常時 fallback 0
- `inc/classes/Api/User.php`
  - `post_users_remove_teachers_callback` 部分失敗時回 `failed_user_ids`
  - 未登入回 401；訂閱者登入回 403

### 整合測試（REST API 手動 curl）
- `GET /v2/powerhouse/users?is_teacher=yes&teacher_course_id=<course_id>` 只回該課程講師
- `GET /v2/powerhouse/users?meta_keys[]=teacher_courses_count` 每個 user 帶 count

### E2E（Playwright，`tests/e2e/admin/teachers.spec.ts`）
- 見階段 4 步驟 4

### 測試執行指令
```bash
composer run test                                    # PHPUnit
composer run phpstan                                 # PHPStan level 9
pnpm run lint:php                                    # phpcbf + phpcs + phpstan
pnpm run lint:ts                                     # ESLint
pnpm run build                                       # Vite build
pnpm run test:e2e:admin -- teachers                  # Playwright teachers spec
pnpm run i18n:build                                  # 產新 .pot/.po/.mo/.json
```

### 關鍵邊界情況
- 列表載入時 `avl_courses` 很大（>100 課程 per 講師） → 效能注意
- 批次選取 >50 講師後按密碼重設 → 後端 `retrieve_password` 可能 rate-limit
- Meta Tab 雙層 confirm 後編輯中重新整理 → `UnsavedChangesNotifier` 攔截
- Edit 頁收到非 `is_teacher` 用戶 → redirect 列表

---

## 依賴項目

### 外部依賴
- Powerhouse 外掛（`powerhouse/user/prepare_query_args/meta_query_builder` filter + `powerhouse/user/get_meta_keys_array` filter）
- WooCommerce（`wc_logger`, `manage_woocommerce` capability）
- `@wordpress/i18n`（JS 端）
- `antd-toolkit`（`Card`, `FilterTags`, `ActionArea`, `SelectedItem`, `objToCrudFilters`, `defaultSelectProps`, `UserRole`）
- `@refinedev/antd`, `@refinedev/core`, `@refinedev/react-router`

### Probe 項目（實作階段由 master agent 確認）
- Powerhouse 是否提供 `POST /v2/powerhouse/users/resetpassword`
- Powerhouse 是否提供 `GET /v2/powerhouse/users/options`
- Powerhouse `users` endpoint 回傳的 TUserDetails 是否已含 `recent_orders` / `cart` / `other_meta_data` / `billing` / `shipping`（Power Shop 在用，應該有）

---

## 風險與緩解措施

- **高**：Q10/Q11 的 `recent_orders` / `cart` / `other_meta_data` 欄位依賴 Powerhouse 回傳，若 Powerhouse 未回則 Edit 頁 Orders Tab / Meta Tab 顯示空
  - 緩解：實作階段先 probe Powerhouse response；若缺則：(a) 改向 WC REST API 二次查詢 orders（`customer_id=<teacher_id>`）；(b) Meta 走 `update_user_meta` 配合自建 `GET /users/{id}/meta` endpoint
- **中**：`teacher_students_count` 計算複雜（跨多課程聚合 `pc_avl_coursemeta`），講師帶多門課 + 大量學員時 SQL 慢
  - 緩解：用單一 `SELECT COUNT(DISTINCT user_id) FROM pc_avl_coursemeta WHERE meta_key='avl_course_ids' AND meta_value IN (<course_ids>)`；若仍慢則 transient cache 60 秒
- **中**：`meta_query teacher_course_id` 的課程 `teacher_ids` 可能是 serialized array（WP 慣用），`meta_value LIKE '%<id>%'` 易誤命中（如 `12` 命中 `121`）
  - 緩解：使用 `serialize($id)` 後 LIKE，或改以 JSON 存（若 Power Course 本就是 JSON 則直接比對）。實作者需 inspect 現有 `teacher_ids` 儲存格式
- **中**：Edit 頁 Orders Tab 顯示「講師本人的訂單」與使用者認知可能落差（可能預期看到「講師教的課的訂單」）
  - 緩解：Tab 內顯眼位置加 subtitle「Your own purchase history」/ 「您本人的購買紀錄」；若後續需求變更，改為 Q10=B 是擴充而非重做
- **中**：刪除舊 `pages/admin/Teachers/TeacherTable/` 與 `UserSelector/` 可能被其他檔案 import
  - 緩解：刪除前 `grep` 驗證；刪除後 `pnpm run build` 會報錯
- **低**：i18n `manual.json` 條目過多未審核
  - 緩解：本計劃提供完整字串清單，實作者對照套用
- **低**：`@ts-nocheck` 移除後型別推斷可能紅字
  - 緩解：明確宣告 `useSelect<TUserRecord>` 與 filters 型別

---

## 錯誤處理策略

- **前端 mutation 錯誤**：統一用 Refine `notificationProvider` + `notificationProps`（已全域註冊於 `App1.tsx`），每個 `useCustomMutation` 傳 `...notificationProps`
- **後端 API 例外**：`ApiBase::try()` 已自動 try/catch 包裝所有 callback，轉 500；新邏輯直接 throw Exception 即可
- **partial batch failure**（remove-teachers）：回傳 `data: { user_ids, failed_user_ids }`，前端依 `failed_user_ids.length` 判斷 success/warning message
- **computed field 計算失敗**：PHP 端 try-catch fallback 0，只在 `WP_DEBUG` 時 `wc_logger` warn
- **Edit 頁非 is_teacher 用戶**：`useEffect` 驗 `record.is_teacher`，非則 `notification.warning` + `navigate('/teachers')`

---

## 限制條件（此計劃不會做的事）

- ❌ **不動** `js/src/components/user/**` 與 `js/src/pages/admin/Students/**`（Q1=B）
- ❌ **不刪** WP 用戶（講師批次操作不含 DeleteButton）
- ❌ **不做**「講師教的課程訂單」聚合報表（Q10=A）
- ❌ **不做**「講師班級學員進度總覽」（Q11=A）
- ❌ **不移植** Power Shop ContactRemarks（Q9 4 Tab 不含）
- ❌ **不處理** Powerhouse User endpoint 本身（若缺欄位走 probe 再決定）
- ❌ **不改** `TUserRecord` 定義位置（保留在 `pages/admin/Courses/List/types/`）
- ❌ **不新增** 資料庫欄位（`is_teacher` 仍是唯一 marker，computed field 皆 runtime 算）

---

## 成功標準（Definition of Done）

### 功能
- [ ] `/teachers` 列表顯示 7 欄（講師 / Email / 註冊時間 / 負責課程數 / 學員人數 / WP 角色 / 操作）
- [ ] Filter 6 欄可用（關鍵字 / 角色 / 手機 / 生日 / 負責課程 / hidden include）
- [ ] 批次選取跨頁保持
- [ ] 批次「傳送密碼重設」成功呼叫 Powerhouse 或自建 endpoint
- [ ] 批次「移除講師身分」需二次確認文字才可點 OK；成功後 `is_teacher` meta 被刪
- [ ] 新增講師流程（Create Button）與從 WP 用戶加為講師（UserSelector）皆正常
- [ ] `/teachers/edit/:id` 4 Tab 可切換、可編輯、可儲存
- [ ] 非 `is_teacher` 用戶直接開 Edit 頁會 redirect

### 後端
- [ ] 5 個 endpoint 顯式 `manage_woocommerce`
- [ ] `remove-teachers` partial failure 正確回傳 `failed_user_ids`
- [ ] `teacher_course_id` meta_query 有效
- [ ] `teacher_courses_count` / `teacher_students_count` 回正確數

### 品質
- [ ] `composer run phpstan`（level 9）全綠
- [ ] `pnpm run lint:php` 全綠
- [ ] `pnpm run lint:ts` 全綠，無 `@ts-nocheck`，無 `any` 洩漏
- [ ] `pnpm run build` 成功
- [ ] `pnpm run i18n:build` 成功，新字串有繁中 msgstr

### 測試
- [ ] PHPUnit Teacher ExtendQuery 與 User API 單元測試全綠
- [ ] Playwright `teachers.spec.ts` smoke 全綠

### i18n
- [ ] 所有新字串 msgid 為英文，呼叫 `__('...', 'power-course')`
- [ ] `scripts/i18n-translations/manual.json` 已加入對應繁中
- [ ] `.pot` / `.po` / `.mo` / `.json` 一起 commit

### 清理
- [ ] 舊 `pages/admin/Teachers/TeacherTable/` 已刪除
- [ ] 舊 `pages/admin/Teachers/UserSelector/` 已刪除（含 `@ts-nocheck` 技術債一起清）

---

## 預估複雜度：中高

- 前端新增檔案：~20 個
- 後端修改檔案：~2 個
- 前端刪除：~3 個檔案
- i18n 字串新增：~55 條
- 預估開發工時：2~3 個工作天（含 TDD 測試）
- 可獨立合併的 PR 切分：4 個（階段 1 → 階段 2 → 階段 3 → 階段 4）

---

## 實作者提醒

- **Q7 / probe**：第一階段前請先 `curl -u admin:pass http://localhost/wp-json/v2/powerhouse/users/resetpassword` 與 `/users/options` 確認回應；若 404 則進第一階段步驟 4 / 5 自建
- **Q8 推翻 Q2**：不要用舊 Drawer 作主要編輯入口，Drawer 僅保留為「Create」時用（由 `AddTeacherArea` 裡的 Button 觸發）
- **Mode Drift**：本計劃已從 HOLD SCOPE 漂移到 EXPANSION（~28 檔）；若實作中發現更大範圍，請回報 planner 重新評估是否需要 REDUCTION
- **Teacher `teacher_ids` 儲存格式**：實作者需現場 inspect 現有課程 post meta 是 serialized array 還是 JSON 或多筆 meta，據此決定 meta_query 寫法
- **Students 頁回歸**：每次階段 PR 前跑一次 `pnpm run test:e2e:admin -- students` 確保未受影響
