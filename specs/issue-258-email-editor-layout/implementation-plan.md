# 實作計畫 — Issue #258 郵件編輯器沉浸式版面

> 由 planner 制定，交接 `@zenbu-powers:tdd-coordinator` 執行。
> 範圍模式：**HOLD SCOPE**（純前端版面呈現優化，不動 API / 資料模型 / 儲存邏輯）。
> 澄清結論：`D C A A C B`（見 `execution-plan.md`）。

---

## 1. 需求摘要

郵件模板編輯器（`EmailEditor/index.tsx` → `j7-easy-email-extensions` `StandardLayout` 三欄）原為整頁全螢幕設計，嵌入 WordPress 後台後外層疊著 WP 主選單（`#adminmenu`）+ WP 頂部工具列（`#wpadminbar`）+ Refine 側邊欄，多層側欄擠壓下中間信件畫布又窄又扁，管理員需反覆左右捲動。

**目標**：進入編輯頁自動進入沉浸全螢幕（隱藏 WP chrome + Refine 側欄，三欄獨佔瀏覽器寬度）、可切換並記住偏好、面板可收合、窄螢幕改浮動抽屜、中間畫布可視寬度 ≥ 640px，且退出後不破壞後台既有元件、不改變任何已儲存信件內容。

---

## 2. 現況盤點（已讀程式碼）

| 事實 | 位置 | 對計畫的影響 |
|------|------|------------|
| SPA root `#power_course`，掛在 `admin.php?page=power-course`，HashRouter | `js/src/main.tsx` / `helpers/admin-page.ts` | 沉浸模式需操作 React root **之外**的 WP DOM（`#adminmenu` 等），用 body class + CSS |
| App 外層寫死 `<div className="overflow-x-auto"><div className="w-[1200px] xl:w-full">` | `js/src/App1.tsx:47-49` | 沉浸模式須覆寫此寬度約束，否則畫布仍受限 |
| Refine 自有側欄 `ThemedLayoutV2` + `ThemedSiderV2` | `js/src/components/layout/layout.tsx`、`sider.tsx` | 沉浸模式須一併收起 Refine 側欄 |
| 郵件編輯路由 `#/emails/edit/:id` | `js/src/App1.tsx:200-215` | 沉浸模式 effect 只在此頁掛載/卸載時 add/remove |
| 三欄由 `<StandardLayout showSourceCode={false}>` 產生（lazy import 套件） | `EmailEditor/index.tsx:163` | 面板收合 / 抽屜 / 最小寬度須以 CSS 覆寫套件 DOM（**選擇器待 DOM spike 確認**） |
| 儲存邏輯：`short_description` 存 JSON、存檔時才轉 `description` HTML；parseFailed 有防呆 | `Edit/index.tsx:44-88`、`EmailEditor/index.tsx:47-64,140-145` | **一律不得更動**，計畫只包版面容器 |
| Jotai 為局部 UI state 慣例；`atomWithStorage` 專案尚未用過但 jotai 已安裝 | `react.rule.md` | 偏好記憶採 `atomWithStorage`（client-side localStorage） |
| 已有 E2E `email-template.spec.ts`（列表頁），admin project | `tests/e2e/01-admin/` | 新增沉浸版面 spec 沿用 `navigateToAdmin` / storageState |

> ⚠️ **本 CI 環境未安裝 node_modules**，`j7-easy-email-extensions` `StandardLayout` 的實際 DOM class 名稱無法離線讀取 → 列為 Phase A「DOM spike」前置步驟（見 §4）。

---

## 3. 檔案清單與修改摘要

| # | 檔案 | 操作 | 內容摘要 |
|---|------|------|---------|
| F1 | `js/src/pages/admin/Emails/Edit/immersive/atom.ts`（新） | create | `atomWithStorage<boolean>('pc-email-editor-immersive', true)`（預設 true = 首訪自動沉浸，Q2=C）。key 用連字號對齊專案慣例 |
| F2 | `js/src/pages/admin/Emails/Edit/immersive/useImmersiveMode.ts`（新） | create | 自訂 hook：讀寫 F1 atom；`useEffect` 依 immersive 狀態 add/remove `document.body` 的 `pc-email-immersive` class；**卸載時 cleanup 必移除 class**（避免離開編輯頁殘留污染其他頁）。回傳 `{ immersive, toggle }` |
| F3 | `js/src/pages/admin/Emails/Edit/immersive/immersive.css`（新，或併入既有全域 css entry） | create | 以 `body.pc-email-immersive` 為 scope 的覆寫規則：隱藏 `#adminmenumain, #adminmenuback, #wpadminbar, #wpfooter`；`#wpcontent{margin-left:0}`；`html.wp-toolbar{padding-top:0}`；覆寫 App1 `w-[1200px]` 約束；收起 Refine 側欄；三欄容器滿版、中間畫布 `min-width:640px`；面板收合態與窄螢幕（`@media (max-width:~1360px)`）浮動抽屜規則。**選擇器待 F0 spike 校正** |
| F4 | `js/src/pages/admin/Emails/Edit/EmailEditor/index.tsx` | modify | 包一層版面容器 div（掛 immersive/collapse 態的 class hooks）；不動 `EmailEditorProvider` / `getInitContent` / `setFieldValue` / parseFailed 任何既有邏輯，僅在 `<StandardLayout>` 外層加容器與收合控制 |
| F5 | `js/src/pages/admin/Emails/Edit/index.tsx` | modify | 於 `Edit` 的 `headerButtons`（目前 `() => null`）放「進入/退出全螢幕」切換按鈕，接 F2 `toggle`；不動 `handleSubmit` / `footerButtons` 儲存邏輯 |
| F6 | `tests/e2e/01-admin/email-editor-immersive.spec.ts`（新） | create | Playwright 驗收（見 §5） |
| F7 | `scripts/i18n-translations/manual.json` + `pnpm run i18n:build` | modify | 新增按鈕字串繁中翻譯（見 §6 i18n），連帶產出 `.pot/.po/.mo/.json` 一起 commit |

> **不涉及**：任何 `.php`、REST endpoint（`power-course` / `power-email`）、自訂資料表、`erm.dbml`、`api.yml`、信件內容儲存流程。

---

## 4. 實作順序（依賴關係）

### Phase A — DOM Spike（前置，解除最大未知）
- **A0**：用 **playwright-cli**（本地站 `https://local-turbo.powerhouse.tw/wp-admin`，帳密見 `.env`）開啟任一郵件模板編輯頁，`document.querySelector` 抓 `StandardLayout` 實際根 DOM 與左/中/右三欄的 class 名稱、以及左右面板可收合的容器節點，記錄到本計畫附註。**F3 CSS 選擇器以此為準**，避免瞎猜套件內部 class。
- 產出：確認的選擇器清單（三欄容器、左面板、右面板、中間畫布 iframe/scroll 容器）。

### Phase B — 偏好記憶 + 沉浸切換（核心）
- **B1**：F1 atom（`atomWithStorage`，預設沉浸）。
- **B2**：F2 `useImmersiveMode` hook（body class add/remove + 卸載 cleanup）。
- **B3**：F3 CSS 的「沉浸模式」段（隱藏 WP chrome / Refine 側欄 / 覆寫 1200px 寬 / 三欄滿版）。
- **B4**：F5 切換按鈕接線（進入/退出全螢幕，文案隨狀態切換）。
- 對應驗收：Feature Rule「自動進入」「自由切換」「偏好記憶」「不影響後台既有元件」。

### Phase C — 最小寬度 + 面板收合（畫布空間）
- **C1**：F3 CSS「中間畫布 `min-width:640px`」+ F4 容器。
- **C2**：F4 左/右面板各自收合控制（收合態 class → CSS 隱藏該面板、畫布補寬），可再展開叫回。
- 對應驗收：Rule「畫布 ≥ 640px」「面板可各自收合」。

### Phase D — 窄螢幕浮動抽屜（邊界）
- **D1**：F3 CSS media query（三欄展開會使畫布 <640px 的斷點，約 ≤1360px）→ 左右面板轉浮動抽屜（`position:absolute/fixed` 覆蓋畫布上方、不推擠寬度），預設收起。
- **D2**：F4 抽屜開關按鈕（點按滑出、再點收起）。
- 對應驗收：Rule「窄螢幕浮動抽屜」兩個 Scenario。

### Phase E — i18n + E2E + 驗證
- **E1**：F7 i18n（§6）。
- **E2**：F6 E2E spec（§5）。
- **E3**：`pnpm run lint:ts` + `pnpm run build`（TS 編譯驗證）+ `pnpm run test:e2e:admin`。

> Phase A 必須先行；B/C/D 有先後依賴（沉浸容器 → 最小寬度 → 抽屜）；E 收尾。

---

## 5. 測試策略

| 層級 | 範圍 | 說明 |
|------|------|------|
| **E2E（Playwright, admin project）** | `tests/e2e/01-admin/email-editor-immersive.spec.ts` | 主要驗收手段。純前端版面行為，無後端可測 |
| TS 編譯 / Lint | `pnpm run build` + `pnpm run lint:ts` | strict mode 型別 + ESLint（無前端 unit test，依此把關） |
| 手動視覺（可選） | playwright-cli 本地站四斷點截圖 | 佐證 PR / issue，非 CI 必要 |

**E2E 待覆蓋案例（對齊 Feature 的 Rule）**：
1. 開啟編輯頁 → 自動沉浸：`#adminmenumain` / `#wpadminbar` 不可見（`toBeHidden`），三欄容器可見，中間畫布寬度 ≥ 640px。
2. 點「退出全螢幕」→ `#adminmenumain`、`#wpadminbar`、`#wpfooter`、儲存按鈕恢復可見可操作。
3. 偏好記憶：退出後 reload / 重進另一模板 → 維持一般版面（localStorage）；沉浸態同理沿用。
4. 收合左面板 / 右面板 → 該面板隱藏、中間畫布可視寬度增加、可再展開。
5. `Scenario Outline` 四斷點 `page.setViewportSize({1280/1440/1920/2560})` → 中間畫布可視寬度 ≥ 640px，三欄不重疊。
6. 窄螢幕（1280 且三欄展開會 <640）→ 左右面板為收起抽屜、畫布 ≥ 640；點展開 → 抽屜覆蓋畫布上方、畫布寬度不變；再點收起。
7. 內容不變回歸：沉浸模式開啟模板但不改內容 → 儲存 → 讀回 `short_description` 與優化前一致（用 `helpers/api-client.ts` 直接讀 API 比對，繞過 UI）。

> 測試資料準備優先用 `helpers/api-client.ts` 建立/讀取郵件模板（e2e-testing.rule）。E2E 選擇器 spike 後可能需在 F4/F5 加 `data-testid`（如 `data-testid="immersive-toggle"`、`data-testid="collapse-left"`）以穩定定位。

---

## 6. i18n（新增字串，遵 i18n.rule）

新增按鈕/aria 文案（英文 msgid + `power-course` domain，React 端 `@wordpress/i18n`）：

| msgid（英文） | zh_TW |
|---------------|-------|
| `Enter fullscreen` | 進入全螢幕編輯 |
| `Exit fullscreen` | 退出全螢幕 |
| `Collapse` | 收合（術語表已有「Close sidebar / 關閉側邊欄」可視情況復用）|

流程：字串寫進程式 → 繁中補 `scripts/i18n-translations/manual.json` → 跑 `pnpm run i18n:build` → 一起 commit `.pot/.po/.mo/.json`。**禁手改 `.po`**。

---

## 7. 資料流分析

純前端狀態流，無伺服器往返：

```
進入 #/emails/edit/:id
  → useImmersiveMode 讀 atomWithStorage('pc-email-editor-immersive')
      ├─ true（無紀錄預設 / 上次沉浸）→ body.add('pc-email-immersive') → CSS 隱藏 WP chrome + Refine 側欄，三欄滿版
      └─ false（上次一般）→ 不加 class，維持一般後台版面
  → 使用者點切換按鈕 → toggle → 寫回 atom（localStorage）→ body class 同步
  → 卸載（離開編輯頁）→ useEffect cleanup → body.remove('pc-email-immersive')（保證不污染其他頁）
信件內容流（不變）：short_description(JSON) ↔ StandardLayout ↔ 存檔轉 description(HTML)
```

---

## 8. 錯誤處理 / 邊界登記表

| 情境 | 處理 |
|------|------|
| 離開編輯頁未清 body class → WP 選單永久消失 | F2 hook `useEffect` **cleanup 必移除 class**；E2E 案例 2/3 驗證退出後 WP chrome 恢復 |
| localStorage 不可用（隱私模式 / 停用） | `atomWithStorage` 讀取失敗 fallback 預設值 `true`（沉浸），不拋錯 |
| StandardLayout 套件 DOM class 版本變動 → CSS 失效 | 選擇器集中於 F3 單檔，加註「對應 j7-easy-email-extensions 4.17.2」；升級套件時需回歸此頁 |
| 覆寫 `#wpcontent margin` / `#wpadminbar` 影響其他 WP 頁 | 一律以 `body.pc-email-immersive` 前綴 scope，class 只在本頁掛載期間存在 |
| 沉浸模式蓋住「儲存郵件」footer 按鈕 | F3 確保 `.sticky-card-actions` footer 在沉浸模式仍可見（Edit 外層已有 `sticky-card-actions`）；E2E 案例 2 驗證儲存鈕可點 |
| 既有 parseFailed 防呆被破壞 | F4 **不觸碰** `EmailEditorProvider` children render 邏輯與 `setFieldValue`，只加外層容器 |

---

## 9. 風險評估

| 風險 | 等級 | 緩解 |
|------|------|------|
| StandardLayout 內部 DOM 未知 → CSS 選擇器猜錯 | **高** | Phase A DOM spike 先行（playwright-cli 本地站實抓），再寫 F3 |
| 覆寫 WP admin 全域樣式外溢 | 中 | body class scope + 卸載 cleanup + E2E 退出驗證 |
| 浮動抽屜與套件既有拖拉互動衝突（元件拖入畫布） | 中 | 抽屜以 CSS position 覆蓋、不改套件事件；Phase D 後手動驗拖拉；E2E 案例 6 檢查抽屜可操作 |
| 四斷點 CSS 斷點值需實測微調 | 低 | Phase E 四斷點 E2E + 本地站截圖校正 |
| i18n 漏跑 build | 低 | §6 流程 + PR 驗收清單 |

---

## 10. 交接資訊

- **下一棒**：`@zenbu-powers:tdd-coordinator`，產 Red→Green→Refactor 藍圖。
- **測試先行提醒**：本任務以 **E2E 為主要（也是唯一）自動化驗收**，Red 階段先寫 §5 的 `email-editor-immersive.spec.ts`（含四斷點 Outline 與內容回歸），再進 Green（F1–F5）。Phase A DOM spike 為 Green 前的必要偵察，非 TDD 迴圈本體。
- **不可退讓的約束**：不動任何 PHP / API / 儲存邏輯；body class 必 cleanup；msgid 英文 + `power-course` domain。
- **驗收對齊**：`execution-plan.md` §驗收標準 6 條 + Feature 全部 Rule。
