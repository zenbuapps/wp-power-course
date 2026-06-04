# 實作計畫 — 學員編輯 Modal 修復與權限優化（Issue #238）

> 由 planner agent 產出，作為 `@zenbu-powers:tdd-coordinator` 的執行藍圖。
> 範圍模式：**HOLD SCOPE**（bug 修復 + 權限優化，影響 9 個檔案，未超過 15 檔上限，不需 REDUCTION）。
> 前置：`StudentEditModal`（Issue #229）已實作；`RoleGate` / `IS_ADMIN`（Issue #221）可複用。
> 澄清結論：`B A A A B A A`（見 `execution-plan.md` 第 0 節）。

---

## 0. 權限基準（全程一致）

| 層級 | 機制 |
|------|------|
| 前端 UI 隱藏 | `IS_ADMIN`（`@/utils/env`，= 後端 `current_user_can('manage_options')` 加密注入）/ `<RoleGate capability="admin">` |
| 後端硬邊界 | `current_user_can('manage_options')` 的 `permission_callback`（真正安全邊界） |

雙保險：前端隱藏只是 UX，後端 permission_callback 才是安全防線。

---

## 1. 檔案變更清單（共 9 檔）

### 前端（6 檔）

| # | 檔案 | 對應需求 | 變更摘要 |
|---|------|---------|---------|
| 1 | `js/src/components/user/StudentEditModal/Detail/Basic/index.tsx` | **B1 / F2** | (B1) 角色 `<Select>` 加 `getPopupContainer={(trigger) => trigger.parentElement as HTMLElement}`，讓 popup 渲染在 Modal stacking context 內、不被遮擋；(F2) `import { IS_ADMIN }`，編輯模式下僅 `IS_ADMIN` 才渲染 `<Select>`，非 Admin 改渲染純文字唯讀（顯示「目前：<role label>」，沿用 `roleOptions` 對照） |
| 2 | `js/src/components/user/StudentEditModal/index.tsx` | **B4 / F5** | (F5) 「前往傳統用戶選項編輯介面」按鈕包 `<RoleGate capability="admin">`；(B4) 修跳轉——改用明確 `<a target="_blank" rel="noopener noreferrer">`，href 優先用 `detail?.edit_url`，**空字串時** fallback 組 `${SITE_URL}/wp-admin/user-edit.php?user_id=${user_id}`（避免 `href=""` 導致 reload 停在 SPA） |
| 3 | `js/src/components/user/StudentEditModal/Detail/index.tsx` | **F6** | `import { IS_ADMIN }` / `RoleGate`：消費數據區塊、購物車、最近訂單 JSX 包 `<RoleGate>`；Tabs `items` 依 `IS_ADMIN` 條件組裝——非 Admin 只保留 `Basic` Tab，移除 `AutoFill` / `Meta`。ContactRemarks（聯絡註記）**維持對非 Admin 可見**（不在 F6 隱藏清單內） |
| 4 | `js/src/components/user/ContactRemarks/index.tsx` | **B3** | 移除 L158 `<Switch size="small" disabled />` 的 `disabled`；更新 L31 過時註解。Switch 綁定 `is_customer_note`（OFF=內部備註，為預設）；確認 badge 顏色（blue=內部 / yellow=客戶可見）隨切換更新（既有 `customer_note` 渲染邏輯已支援） |
| 5 | `js/src/components/user/UserTable/index.tsx` | **B7 / F5 / Q6** | 匯出 CSV 按鈕以 `IS_ADMIN` / `RoleGate` 控管，確保 Administrator 在全域學員頁穩定可見（目前被 `showAdminFeatures = mode==='global' && canGrantCourseAccess` 連帶綁住）；B7 端到端驗證下載流程（搭配後端權限修正） |
| 6 | `js/src/utils/env.tsx` | （無需改）| `SITE_URL` / `IS_ADMIN` 已存在，直接 import 使用 |

### 後端（3 檔）

| # | 檔案 | 對應需求 | 變更摘要 |
|---|------|---------|---------|
| 7 | `inc/classes/Resources/Student/Core/Api.php` | **B7 / Q6** | 新增 `public static function check_manage_options_permission()`（未登入回 401、無 `manage_options` 回 403，比照 `User::check_manage_woocommerce_permission` 寫法）；三個 export 端點（`students/export/(?P<id>\d+)`、`students/export-all`、`students/export-count`）`permission_callback` 由 `null` 改為 `[ self::class, 'check_manage_options_permission' ]` |
| 8 | `inc/classes/Resources/Student/Service/ExportAllCSV.php` | **F8 / Q5=B** | `$columns` 新增 11 個 billing_* + 9 個 shipping_* 欄位標頭（i18n）；`get_rows()` 的 row 物件以 `get_user_meta($user->ID, 'billing_*'/'shipping_*', true)` 補上對應值 |
| 9 | `inc/classes/Resources/Student/Service/ExportCSV.php` | **F8 / Q5=B** | 同 #8：`$columns` 與 `get_rows()` row 物件補 billing_*/shipping_* |

> **F2 後端守門（同檔 `inc/classes/Api/User.php`，第 9.5 項）**：`POST users/{id}` 角色更新邏輯（L778-787）的 if 條件需**追加** `\current_user_can('manage_options')`——非 Admin（即使具 `edit_users`）送 `role` 參數時靜默忽略，其餘允許欄位照常更新。此為 F2 的後端硬邊界，必做。

---

## 2. 實作順序（依賴關係 + 風險遞增）

依澄清的拆分建議，分 3 批；批次內各項互相獨立。

### 第 1 批：純前端 UI bug（低風險，無後端依賴）
1. **B1** — Basic/index.tsx 角色 Select `getPopupContainer`
2. **B3** — ContactRemarks Switch 解鎖
3. **B4** — StudentEditModal 跳轉修正（先不含 RoleGate，純修 href）

### 第 2 批：匯出功能修復 + 欄位擴充（前後端，需一起驗）
4. **後端 Q6/B7** — Student Core Api.php 補 `check_manage_options_permission` + 三端點權限
5. **後端 F8** — ExportAllCSV.php + ExportCSV.php 補 billing/shipping 欄位
6. **前端 B7** — UserTable 匯出按鈕端到端驗證 + i18n（新欄位標頭）

### 第 3 批：權限控管（複用 RoleGate/IS_ADMIN，集中做）
7. **F2** — Basic/index.tsx 非 Admin 角色唯讀 + User.php 後端角色守門加 `manage_options`
8. **F5** — StudentEditModal「前往傳統編輯」按鈕 + UserTable 匯出按鈕包 RoleGate
9. **F6** — Detail/index.tsx 消費數據 / Tabs / 購物車 / 最近訂單依 IS_ADMIN 隱藏

### 收尾
10. **i18n** — 新增字串補進 `scripts/i18n-translations/manual.json`（`msgstr_zh_TW` 必填，`msgstr_ja` 比照既有慣例補），跑 `pnpm run i18n:build`，commit `.pot`/`.po`/`.mo`/`.json`
11. **品質閘** — `pnpm run lint:ts`、`pnpm run build`、`pnpm run lint:php`

---

## 3. i18n 新增字串（英文 msgid，繁中走 manual.json）

匯出 CSV 欄位標頭（約 20 條）：

```
Billing first name / Billing last name / Billing email / Billing phone /
Billing company / Billing country / Billing state / Billing city /
Billing postcode / Billing address 1 / Billing address 2 /
Shipping first name / Shipping last name / Shipping company /
Shipping country / Shipping state / Shipping city / Shipping postcode /
Shipping address 1 / Shipping address 2
```

F2 唯讀角色標籤（若採「目前：%s」格式，1 條）：`Current role: %s`（含 `/* translators */`）。

> ⚠️ msgid 一律英文（見 `.claude/rules/i18n.rule.md`）；繁中翻譯只能寫進 `manual.json`，**禁止手改 `.po`**。

---

## 4. 測試策略

### PHP Integration（PHPUnit，`composer run test`）— 後端硬邊界，最高優先
| 測試 | 斷言 |
|------|------|
| 匯出權限 — 非 Admin | 以僅具 `edit_users`（無 `manage_options`）使用者呼叫 `export-all` / `export-count` / `export/{id}` → 回 403 |
| 匯出權限 — Admin | 以 `manage_options` 使用者呼叫 → 不被權限攔截（200 / 串流） |
| 匯出欄位 — F8 | `ExportAllCSV` / `ExportCSV` 產出的 `$columns` 與 row 物件包含全部 billing_*/shipping_* key，且值取自對應 user_meta |
| 角色守門 — F2（可套用） | 具 `manage_options` 使用者 POST `users/{id}` 帶 `role` → 角色變更生效 |
| 角色守門 — F2（應忽略） | 僅具 `edit_users`（無 `manage_options`）POST `users/{id}` 帶 `role` + 其他欄位 → 角色**不變**、其餘欄位正常更新 |

### E2E（Playwright，`pnpm run test:e2e:admin`）— Admin 端 UI
| 測試 | 步驟 |
|------|------|
| B1 | Admin 開 Modal → 編輯 → 點角色下拉 → 浮層展開可見、可點選選項 |
| B3 | 開 Modal → 聯絡註記 Switch 可點擊切換、badge 顏色變化 |
| B4 + F5 | Admin 點「前往傳統編輯」→ 新分頁開 `user-edit.php?user_id=X` |
| F6 | Admin 開 Modal → 見完整消費數據 / 自動填入 Tab / 其他欄位 Tab / 購物車 / 最近訂單 |
| B7 | 全域學員頁點「學員匯出 CSV」→ 確認 Modal 顯示筆數 → 確認 → 下載 CSV |

> **非 Admin UI 隱藏（F2/F5/F6 的負向案例）**：若 E2E 測試環境僅有 admin 帳號，非 Admin 的隱藏行為改以「PHPUnit 後端 403」+「元件邏輯（IS_ADMIN 條件渲染）程式碼審查」覆蓋；若可建立 Shop Manager 測試帳號則補 E2E 負向案例（見風險 R3）。

---

## 5. 風險與失敗模式登記表

| ID | 風險 / 失敗模式 | 影響 | 處置 |
|----|----------------|------|------|
| R1 | **B1** `getPopupContainer` 指向 `parentElement` 後，若該 `<td>` 有 `overflow:hidden` 或 transform 仍會裁切 popup | 下拉仍被遮 | 退而求其次：保留 body 掛載但以 `classNames.popup` 拉高 z-index（高於 SimpleModal）；以瀏覽器（playwright-cli）實際驗證 |
| R2 | **B4** edit_url 空字串的根因假設（`get_edit_user_link` 無權限回 ''→`href=""`→reload） | 修錯方向 | 先以 systematic-debugging 確認 Network/DOM：是 `href=""` reload 還是 target 失效；fallback 用 `SITE_URL` 組 URL 可繞過空字串問題（雙重保險） |
| R3 | **F2/F5/F6 負向案例** 測試環境可能無非-Admin 帳號 | 隱藏行為無法 E2E | 後端以 PHPUnit 403 斷言；前端隱藏以程式碼審查 + 既有 `RoleGate` 機制保證；如可建 Shop Manager 帳號再補 E2E |
| R4 | **B3** 「開關預設為開啟」字面與資料模型 `is_customer_note`（預設 false=內部）語意落差 | 行為與驗收標準對不上 | 採資料模型語意：預設=內部備註（`is_customer_note=false`），Switch 解鎖可切換、badge 變色即滿足「預設內部 + 可切換」核心驗收；實作時若需反轉 Switch 顯示語意，由 tdd-coordinator 於 Green 階段對齊 feature 並回報 |
| R5 | **B7** 匯出「失效」根因未定（權限 / 串流未 exit / 按鈕被 `canGrantCourseAccess` 連帶隱藏） | 修了權限仍失效 | 第 2 批先以 systematic-debugging 抓實際失敗點（403？JSON 非 CSV？按鈕不顯示？），再對症修；補後端權限為必做基礎 |
| R6 | **F8** 匯出列數大時逐筆 `get_user_meta` × (billing 11 + shipping 9) 增加查詢 | 大站匯出變慢 | 沿用既有 `PowerhouseUtils::batch_process` 分批；WP user_meta 同 user 首次讀會 cache，影響可控；如需可後續優化為單次 `get_user_meta($id)` 全抓 |
| R7 | i18n 只跑 `i18n:pot` 漏 merge | 繁中 fallback 顯示英文 | 嚴格跑 `pnpm run i18n:build`（全套四檔），檢查 `git diff languages/` |

---

## 6. 驗收對應（Issue #238 驗收標準 → 本計畫）

| 驗收項 | 由哪個變更滿足 |
|--------|--------------|
| B1 下拉可點選 | 檔案 #1（getPopupContainer / z-index）+ E2E B1 |
| B3 Switch 可切換 | 檔案 #4 + E2E B3 |
| B4 新分頁開 user-edit.php | 檔案 #2 + E2E B4 |
| B7 匯出下載成功 | 檔案 #5+#7（前端）+ #7（後端權限）+ E2E B7 |
| F2 Admin 可改 / 非 Admin 唯讀 + 後端不套用 | 檔案 #1（前端唯讀）+ User.php 守門（後端）+ PHPUnit |
| F5 按鈕僅 Admin 可見 | 檔案 #2 / #5（RoleGate） |
| F6 敏感欄位僅 Admin 可見 | 檔案 #3（IS_ADMIN/RoleGate） |
| F8 CSV 含 billing/shipping 且標頭有翻譯 | 檔案 #8+#9 + i18n + PHPUnit |
| 前端 build / lint:ts 通過 | 收尾步驟 11 |
| 後端 lint:php 通過 | 收尾步驟 11 |
| 權限雙保險 | 第 0 節原則貫穿全計畫 |
| 新字串同步 manual.json + i18n:build | 收尾步驟 10 |

---

## 7. 交接

本計畫交 `@zenbu-powers:tdd-coordinator` 依第 2 節 3 批順序執行 Red→Green→Refactor。
PHP 批次 → wordpress-master；TSX 批次 → react-master；測試 → test-creator。
敏感領域（權限 / 個資匯出）建議於 Refactor 後 opt-in 補 `@zenbu-powers:security-reviewer`。
