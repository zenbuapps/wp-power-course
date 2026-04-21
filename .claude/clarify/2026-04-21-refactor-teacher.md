# Clarify Session 2026-04-21 — 重構講師管理（參考 Power Shop 顧客）

## Idea

> 使用者要求：完全參考 Power Shop 顧客模組，重構 Power Course 的「講師管理」。
>
> 核心洞察（使用者說的）：Power Shop 顧客 與 Power Course 講師本質上都是 WordPress 用戶，差異只差別在 user_meta `is_teacher` 而已。
>
> 參考來源：`C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\user`
> 目標專案：Power Course（工作目錄內）
> 要求：產出計劃文件（不修改程式碼），找出「前端 UI/UX 是否對齊後端 API，或只改前端」等關鍵決策點。

## 研究摘要（完成步驟 0~3）

- Power Shop `components/user`：UserTable（Filter + BulkAction[Delete/ResetPass] + useColumns）、OrderCustomerTable、ContactRemarks、types。
- Power Course 現況：
  - `pages/admin/Teachers/index.tsx` = 薄殼呼叫 `TeacherTable`
  - `pages/admin/Teachers/TeacherTable/index.tsx` = 自己寫的簡單 Table（permanent filter `is_teacher=yes`，單欄位「講師」，含 UserSelector + 批次移除）
  - `components/user/UserTable/index.tsx` = 為**學員**高度客製化（avl_courses 欄位、GrantCourseAccess、CsvUpload、HistoryDrawer）
- 後端：
  - 講師加入/移除走 `inc/classes/Api/User.php` 的 `/users/add-teachers` / `/users/remove-teachers`（`is_teacher` user_meta）
  - 講師只有 `Resources/Teacher/Core/ExtendQuery.php` 處理「非講師」查詢支援（沒有自己的 Api 類）
  - `users` resource 實際走 Powerhouse 的 `default` data provider（`/v2/powerhouse/users`）
- **安全隱患**：`inc/classes/Api/User.php` 所有端點 `permission_callback=null`，未限制 `manage_woocommerce`
- **型別耦合**：`TUserRecord` 定義在 `pages/admin/Courses/List/types/user.ts`，被 Teachers/Students/UserDrawer/UserName 全部引用
- **i18n 要求**：所有 msgid 必須英文 + `'power-course'` text domain，硬搬 Power Shop 會違規

## Q&A

- Q1: 「完全參考 Power Shop 顧客」的實際對齊範圍？ → A: **B** 只重構 Teachers 頁，新建 `js/src/components/teacher/TeacherTable/`，不動 `components/user/UserTable`（Students 頁維持現狀）；後端保留現有端點但補權限。
- Q2: 講師編輯模式要 Edit 頁還是 Drawer？ → A: **A** 保留 Drawer，擴充 `useUserFormDrawer` + `UserDrawer` 欄位（加 first_name/last_name/role 等）。
- Q3: Teachers 頁的批次操作包含哪些？ → A: **B** 「批次傳送密碼重設連結」+「批次移除講師身分（二次確認 Modal）」。
- Q4: Filter 欄位保留哪幾個？ → A: **D++**（使用者指定最滿版本）= 關鍵字 + WP 角色 + 手機 + 生日月份 + **負責課程篩選**（講師專屬，需後端 `teacher_course_id` meta_query）+ hidden include。
- Q5: 列表欄位顯示哪些？ → A: 講師 / Email / 註冊時間 / **負責課程數** / **學員人數** / **WP 角色 Tag** / 操作（共 7 欄，含 2 個 computed field）。
- Q6: API 權限補強？ → A: **A** 5 個端點全補 `\current_user_can('manage_woocommerce')`。**R1 修正**：`permission_callback=null` 經 ApiBase fallback 實際為 `manage_options OR manage_woocommerce`（見 `powerhouse/vendor/j7-dev/wp-utils/src/classes/ApiBase.php:60-62, 79-92`），非「任何登入用戶」。但語意不清、與專案 rule 預設不完全對齊，仍應改成顯式 `manage_woocommerce`。
- Q7: 批次密碼重設端點？ → A: **C** 實作者先 probe `/v2/powerhouse/users/resetpassword`，有則走 Powerhouse；無則自建 `power-course/v2/users/resetpassword`。
- Q8: 🚨 **推翻 Q2**。從 Drawer 改為 **Edit 頁**，新建 `pages/admin/Teachers/Edit/`，結構照 Power Shop `pages/admin/Users/Edit`。
- Q9: Edit 頁 4 Tab：**基本資料 / 訂單紀錄 / 學習紀錄 / Meta**（不含 ContactRemarks）。
- Q10: 訂單紀錄 Tab 語意？ → A: **A** 講師本人作為顧客的訂單（沿用 Power Shop `RecentOrders` + `Cart`，合併到同一 Tab）。
- Q11: 學習紀錄 Tab 語意？ → A: **A** 講師本人作為學員的學習紀錄（沿用現有 `avl_courses` + `HistoryDrawer`）。
