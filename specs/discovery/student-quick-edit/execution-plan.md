# Execution Plan — 學員快速編輯（Issue #229）

> AIBDD Phase 01 產出。本文件彙整 Composition / Flow / Structural Read / Impact / Behavior / Clarify / Quality Gate 七步結果，作為 Phase 02+ 的 scope 依據。
> feature-slug：`student-quick-edit`

---

## 1. Composition（組成分析）

| 區塊 | 完整度 | 資訊來源 |
|------|--------|---------|
| 共用編輯 Drawer（兩入口） | ✅ 已確認 | PM 文件旅程 1/5、Issue 第 1 輪 |
| 基本資料編輯（display_name/email/姓/名/密碼） | ✅ 已確認 | PM 欄位表、Q3=C |
| WC 帳單資料編輯 | ✅ 已確認 | PM 欄位表、Q1=C |
| WC 收件資料編輯（可收合） | ✅ 已確認 | Q4=B |
| 密碼：直接設定 + 發送重設信 | ✅ 已確認 | Q3=C、Q10=A |
| 列表電話欄位 | ✅ 已確認 | Q5=A |
| 國家/州省 WC 下拉 | ✅ 已確認 | Q6=A |
| 權限 edit_users | ✅ 已確認 | Q7=C |
| 登入 Email vs 帳單 Email 獨立 | ✅ 已確認 | Q8=A |
| 訂單摘要（內嵌筆數+最近+查看全部） | ✅ 已確認 | Q9=B |
| 「最近 N 筆」之 N 值 | ⚠️ 預設 5（可調） | 未明確指定，採合理預設 |

---

## 2. Flow Alignment（流程對齊）

主流程與子流程見：
- `student-quick-edit.mmd`（點擊→開 Drawer→載入→編輯→驗證→儲存→同步列表，含查看訂單/關閉分支）
- `reset-password.mmd`（發送 WordPress 原生密碼重設信子流程）

---

## 3. Structural Read（既有結構，serena/檔案探查）

**後端**
- `inc/classes/Api/User.php`
  - `GET /power-course/users`：`format_user_data()` 回傳 `id/name/email/meta_data`（meta_data 已含所有 user meta，含 billing_*）。
  - `POST /power-course/users/{id}`：以白名單分離 user data（user_email/user_pass/display_name/first_name/last_name/role）與 meta data，meta 走 `update_user_meta` → **已能寫入 billing_*/shipping_***。
  - 三個端點目前 `permission_callback => null`（開放）→ **需改為 edit_users**。
  - 目前**缺**：單一學員 GET（`/users/{id}`）、密碼重設信端點、訂單摘要端點。
- `inc/classes/Api/Students.php`：`GET /power-course/students` 已支援 `meta_keys` 參數 → 列表電話可沿用。

**前端**
- `js/src/components/general/UserDrawer/index.tsx`：現有 Drawer，僅 display_name + Email(disabled) → 需大幅擴充欄位、解鎖 Email、加密碼/帳單/收件/訂單摘要。
- `js/src/pages/admin/Users/UsersDrawer/index.tsx`：含 user_login/display_name/email/password 的範本，可參考。
- `js/src/pages/admin/Students/`：`StudentsTable.tsx` + `hooks/useColumns.tsx`（目前回傳 `[]`，無欄位）→ 需加電話欄位與點擊開 Drawer。
- `js/src/pages/admin/Courses/CourseSelector/`：課程學員 Tab 入口。
- `TUserRecord`（CourseSelector/types.ts）已含 `billing_phone?`。

---

## 4. Impact Analysis（影響範圍）

| 類型 | 檔案 / 區域 | 變更 |
|------|------------|------|
| 後端 API | `inc/classes/Api/User.php` | 新增 `GET /users/{id}`、`POST /users/{id}/reset-password`、`GET /users/{id}/orders-summary`；三端點 permission 改 edit_users |
| 後端 API | `inc/classes/Api/Students.php` | 確保列表回傳 billing_phone |
| 前端元件 | `components/general/UserDrawer` | 擴充為完整編輯 Drawer（分區、驗證、密碼確認、重設信、訂單摘要） |
| 前端頁面 | `pages/admin/Students/*` | 列表電話欄位 + 點擊開 Drawer |
| 前端頁面 | 課程學員 Tab（CourseSelector） | 接相同 Drawer |
| 前端型別 | `types.ts` / `TUserRecord` | 擴充 WC meta 欄位型別 |
| i18n | 新增字串 | 依 `.claude/rules/i18n.rule.md` 加到 `scripts/i18n-translations/manual.json` 並跑 `pnpm run i18n:build` |

**相容性風險**
- billing_country/billing_state 必須存 WC 代碼，否則破壞結帳/訂單顯示 → 前端用 WC 下拉。
- Power Course 與 WP 原生用戶頁編輯同一份資料，後存覆寫先存（接受，UI 引導集中於 PC 操作）。
- 已完成訂單地址為快照，不隨 usermeta 更新（屬 WC 標準行為，需於 UI/客服說明）。

---

## 5. Behavior Design（行為設計）

- Feature Rules（@ignore，無 Examples）：`specs/student-quick-edit/student-quick-edit.feature`
  - 涵蓋：開 Drawer（兩入口）、唯讀欄位、基本資料、Email 獨立、WC 帳單/收件、密碼設定/留空/二次確認、發送重設信、Email 格式驗證、後端錯誤、列表電話、訂單摘要、權限。
- Activity：見第 2 步兩張 .mmd。

---

## 6. Clarify（釐清紀錄）

已於 Issue #229 完成兩輪澄清，全部鎖定：

| 題 | 決策 |
|----|------|
| Q1 範圍 | C 全三期 |
| Q2 後端 | B Powerhouse User Model 優先，必要時退回既有 `Api/User.php`（合約不變） |
| Q3 密碼 | C 直接設定 + 發送重設信 |
| Q4 收件 | B 帳單 + 收件 |
| Q5 列表電話 | A 顯示 |
| Q6 國家/州省 | A WC 下拉 |
| Q7 權限 | C edit_users |
| Q8 雙 Email | A 獨立不連動 |
| Q9 訂單摘要 | B 內嵌筆數 + 最近數筆 + 查看全部連結 |
| Q10 重設信 | A WordPress 原生 |

剩餘僅 1 項採合理預設（無阻擋）：訂單摘要「最近 N 筆」N 預設 5。

---

## 7. Quality Gate（一致性檢查）

- [x] Activity 節點 ↔ Feature Rule 對齊（開 Drawer / 編輯儲存 / 驗證錯誤 / 重設信 / 訂單摘要 / 權限）
- [x] api.yml endpoint ↔ Feature 行為對齊（GET 單一學員 / POST 更新 / reset-password / orders-summary / students 列表）
- [x] erm.dbml ↔ 欄位來源對齊（wp_users 核心 + wp_usermeta 的 billing_*/shipping_* + 訂單唯讀）
- [x] 全部決策皆有 Issue 出處，無腦補（唯一預設值已標記）

---

## 交付物清單

| 檔案 | 內容 |
|------|------|
| `specs/student-quick-edit/student-quick-edit.feature` | Gherkin Feature（@ignore，無 Examples） |
| `specs/student-quick-edit/api.yml` | OpenAPI 合約 |
| `specs/student-quick-edit/erm.dbml` | 資料模型（沿用 WP 既有表） |
| `specs/discovery/student-quick-edit/student-quick-edit.mmd` | 主流程 Activity |
| `specs/discovery/student-quick-edit/reset-password.mmd` | 重設信子流程 Activity |
| `specs/discovery/student-quick-edit/execution-plan.md` | 本文件 |

> 下一步：由後續 workflow step 的 tdd-coordinator 接手 Phase 02+（form-bdd-analysis 補 Examples → Red/Green/Refactor 實作）。
