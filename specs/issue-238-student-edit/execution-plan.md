# Execution Plan — 學員編輯 Modal 修復與權限優化（Issue #238）

> AIBDD Phase 01 產出。彙整 Composition / Flow / Structural Read / Impact / Behavior / Clarify / Quality Gate 七步結果，作為 Phase 02+ 的 scope 依據。
> feature-slug：`issue-238-student-edit`
> 前置 feature：`student-quick-edit`（Issue #229，已實作 `StudentEditModal`）。本批次為其上的 bug 修復 + 權限優化。

---

## 0. 澄清結論（Issue #238 第 1 輪，用戶回覆 `B A A A B A A`）

| 編號 | 決策 |
|------|------|
| Q1 | **B** — 非管理員的「使用者角色」顯示為純文字唯讀（看得到目前角色但不能改） |
| Q2 | **A** — 非管理員保留「基本資料」Tab 核心欄位（姓名、顯示名稱、Email、生日、簡介），只拿掉角色編輯 |
| Q3 | **A** — 沿用既有 `IS_ADMIN`（= `current_user_can('manage_options')`）+ `RoleGate`，前端 UI 隱藏 + 後端 capability 雙保險 |
| Q4 | **A** — B3 本批只解鎖「內部備註」Switch（後台切換 內部/客戶可見 標記與 badge 顏色），客戶前台呈現另開 Issue |
| Q5 | **B** — F8 匯出 CSV 同時新增 billing_*（帳單）+ shipping_*（運送）欄位 |
| Q6 | **A** — B7 匯出功能限定 Administrator（前端 `IS_ADMIN` 隱藏按鈕 + 後端鎖 `manage_options`），與 F6 敏感資料政策一致 |
| Q7 | **A** — 角色下拉（管理員可改時）顯示全部已註冊的 WordPress 角色（沿用現況 `useOptions().roles`） |

---

## 1. Composition（組成分析）

| 區塊 | 完整度 | 資訊來源 |
|------|--------|---------|
| B1 角色下拉被遮擋無法點選 | ✅ 已確認 | Issue 第 1 點、截圖 |
| B3 內部備註 Switch disabled 無法點選 | ✅ 已確認 | Issue 第 3 點、Q4=A |
| B4 前往傳統用戶編輯介面按鈕點擊無反應 | ✅ 已確認 | Issue 第 4 點 |
| B7 匯出學員 CSV 失效 | ✅ 已確認 | Issue 第 7 點、Q6=A |
| F2 限定 Admin 才能改角色 | ✅ 已確認 | Issue 第 2 點、Q1=B、Q3=A |
| F5 限定 Admin 才能看「前往傳統編輯」按鈕 | ✅ 已確認 | Issue 第 5 點、Q3=A |
| F6 限定 Admin 才能看見敏感欄位 | ✅ 已確認 | Issue 第 6 點、Q2=A、Q3=A |
| F8 匯出 CSV 新增 billing/shipping 欄位 | ✅ 已確認 | Issue 第 8 點、Q5=B |
| 角色可選清單範圍 | ✅ 全部 WP 角色 | Q7=A |

---

## 2. Flow Alignment（流程對齊）

主流程見 `student-edit-fixes.mmd`：
- 開啟 Modal → 依 `IS_ADMIN` 決定渲染完整版 / 精簡版（F6）
- 編輯模式角色欄位：Admin 可改下拉（B1 已修 z-index 可點選）/ 非 Admin 純文字唯讀（F2/Q1=B）
- 「前往傳統用戶編輯介面」：僅 Admin 可見（F5），點擊新分頁開 `user-edit.php`（B4）
- 聯絡註記內部備註 Switch 可切換（B3）
- 匯出 CSV：僅 Admin 可見按鈕（B7/Q6），點擊 → 確認筆數 → 下載含 billing/shipping 的 CSV（F8）

---

## 3. Structural Read（既有結構，serena/檔案探查）

**前端**
- `js/src/components/user/StudentEditModal/index.tsx`
  - L248–250：「前往傳統用戶編輯介面」按鈕 `<Button target="_blank" href={detail?.edit_url}>`（B4：`edit_url` 可能為空或被 SPA router 攔截；F5：需包 `RoleGate`）。
  - 切換 view/edit mode 的「編輯用戶」按鈕。
- `js/src/components/user/StudentEditModal/Detail/Basic/index.tsx` L147–154：角色 `<Select size="small">`，options 來自 `useOptions().roles`，僅 `isEditing` 顯示（B1：popup 被 Modal 遮擋，需設 `getPopupContainer` / z-index；F2：非 Admin 改純文字唯讀）。
- `js/src/components/user/StudentEditModal/Detail/index.tsx` L16–31：三個 Tab（Basic / AutoFill / Meta）（F6：AutoFill、Meta Tab 對非 Admin 隱藏）。
- `js/src/components/user/ContactRemarks/index.tsx` L158：`<Switch size="small" disabled />`，L31–32 註解說明「客戶可見備註前台尚未實作」（B3：移除 disabled，改為可切換內部/客戶可見標記）。
- `js/src/components/user/UserTable/index.tsx`：匯出按鈕 + 確認 Modal，`/students/export-count` 取筆數、`window.open('/students/export-all?...')` 下載（B7/F5：包 `RoleGate`）。
- `js/src/components/RoleGate/index.tsx`：`<RoleGate capability="admin" fallback={...}>`，依 `IS_ADMIN`（from `@/utils/env`）判定。
- `js/src/utils/env.tsx`：`IS_ADMIN` 解密自 `window.power_course_data.env`。

**後端**
- `inc/classes/Bootstrap.php` L281：`'IS_ADMIN' => current_user_can('manage_options')`（加密注入，前端 UI gate 用）。
- `inc/classes/Api/User.php`
  - L43–50：`POST users/(?P<id>\d+)`，permission `check_edit_users_permission`；角色更新邏輯 ~L540–555（B1/F2 後端：角色變更需額外鎖 `manage_options`）。
  - L63–78：聯絡註記 `comments` GET/POST/DELETE（B3 後端已支援 `is_customer_note`）。
- `inc/classes/Resources/Student/Core/Api.php`：`students/export/(?P<id>\d+)`、`students/export-all`、`students/export-count` 三端點 **`permission_callback => null`**（B7/Q6：改為 `manage_options`）。
- `inc/classes/Resources/Student/Service/ExportCSV.php` L70–83 / `ExportAllCSV.php` L63–76：CSV 欄位 12 欄（F8：新增 billing_* + shipping_*）。

---

## 4. Impact Analysis（影響範圍）

| 類型 | 檔案 / 區域 | 變更 |
|------|------------|------|
| 前端元件 | `StudentEditModal/Detail/Basic/index.tsx` | B1 角色 Select 設 `getPopupContainer`/popup z-index；F2 非 Admin 角色改純文字唯讀（依 `IS_ADMIN`） |
| 前端元件 | `StudentEditModal/index.tsx` | F5「前往傳統編輯」按鈕包 `RoleGate`；B4 修 `edit_url` 跳轉（確保新分頁開 `user-edit.php`，不被 SPA router 攔截） |
| 前端元件 | `StudentEditModal/Detail/index.tsx` | F6 AutoFill / Meta Tab 對非 Admin 隱藏 |
| 前端元件 | `StudentEditModal/Detail/Basic`（消費數據區） | F6 消費數據區塊對非 Admin 隱藏 |
| 前端元件 | `StudentEditModal`（右欄購物車、最近訂單） | F6 對非 Admin 隱藏 |
| 前端元件 | `ContactRemarks/index.tsx` | B3 移除 Switch `disabled`，支援切換內部/客戶可見標記與 badge |
| 前端元件 | `UserTable/index.tsx` | B7/F5/Q6 匯出按鈕包 `RoleGate`，修復 export 呼叫 |
| 後端 API | `Resources/Student/Core/Api.php` | B7/Q6 三個 export 端點 `permission_callback` 改為 `manage_options` |
| 後端 Service | `Service/ExportCSV.php`、`ExportAllCSV.php` | F8 新增 billing_* + shipping_* 欄位與標頭（i18n） |
| 後端 API | `Api/User.php` 角色更新邏輯 | F2 角色變更後端額外鎖 `manage_options`（非 Admin 即使送 role 也不套用） |

---

## 5. Behavior Design

行為規格見 `student-edit-fixes.feature`（Gherkin，含 8 個 Rule 對應 B1/B3/B4/B7/F2/F5/F6/F8）。

---

## 6. Clarify（已澄清）

見第 0 節，7 題全數確認，無遺留待澄清項。

---

## 7. Quality Gate

- [ ] 前端 `pnpm run build` 與 `pnpm run lint:ts` 通過
- [ ] 後端 `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）通過
- [ ] 權限雙保險：前端 `RoleGate` / `IS_ADMIN` 隱藏 + 後端 `manage_options` permission_callback
- [ ] 新增使用者可見字串同步 `scripts/i18n-translations/manual.json` 並跑 `pnpm run i18n:build`
- [ ] 不引入新 library（沿用既有 Ant Design / Refine / RoleGate 機制）

---

## 8. 技術依賴

無新增第三方 library。全部沿用既有：Ant Design 5（Select `getPopupContainer`、Switch）、Refine.dev（`useCustom`/`useUpdate`）、`RoleGate` + `IS_ADMIN`（Issue #221）、WooCommerce billing/shipping user meta。
