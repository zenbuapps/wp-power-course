# Power Course E2E 測試指南

Playwright + wp-env 端到端測試，透過真實瀏覽器驗證前後台完整流程。

## 目錄

- [環境需求](#環境需求)
- [快速開始](#快速開始)
- [測試專案（Projects）](#測試專案projects)
- [執行方式](#執行方式)
- [常用參數](#常用參數)
- [無頭 / 有頭模式](#無頭--有頭模式)
- [冒煙測試](#冒煙測試)
- [UI 模式與 Debug 模式](#ui-模式與-debug-模式)
- [環境變數](#環境變數)
- [測試結構](#測試結構)
- [Global Setup / Teardown](#global-setup--teardown)

---

## 環境需求

| 工具 | 版本 |
|------|------|
| Node.js | 20+ |
| pnpm | 10.x |
| wp-env | 已安裝（`@wordpress/env`） |
| Docker | 執行 wp-env 的必要條件 |
| Playwright browsers | 執行 `npx playwright install` 安裝 |

### 安裝 Playwright 瀏覽器

```bash
cd tests/e2e
npx playwright install chromium
# 或安裝全部瀏覽器
npx playwright install
```

### 啟動 wp-env

```bash
# 在 plugin 根目錄執行
npx wp-env start
```

E2E 測試目標站點由 `.env` 的 `TEST_SITE_URL` 決定，未設定時 fallback 為 `http://localhost:8889`（Playwright config 預設值）。wp-env 主環境 port 為 `8895`，但 E2E 測試不一定打 wp-env，視 `.env` 設定而定。

---

## 快速開始

```bash
# 跑全部 E2E 測試
pnpm run test:e2e

# 只跑 Admin 測試（最常用）
pnpm run test:e2e:admin

# 打開 UI 模式（互動式，開發時使用）
pnpm run test:e2e:ui
```

---

## 測試專案（Projects）

`playwright.config.ts` 定義了三個 project，對應三個測試目錄：

| Project | 指令 | 目錄 | 說明 |
|---------|------|------|------|
| `admin` | `pnpm run test:e2e:admin` | `01-admin/` | WordPress 後台管理功能 |
| `frontend` | `pnpm run test:e2e:frontend` | `02-frontend/` | 前台課程頁面、購買流程 |
| `integration` | `pnpm run test:e2e:integration` | `03-integration/` | 跨層整合（購買→開通→上課），timeout 120s |

---

## 執行方式

### 全部測試

```bash
pnpm run test:e2e
# 等同於
cd tests/e2e && npx playwright test
```

### 指定 project

```bash
pnpm run test:e2e:admin
pnpm run test:e2e:frontend
pnpm run test:e2e:integration
```

### 指定測試檔案

```bash
cd tests/e2e && npx playwright test 01-admin/course-create.spec.ts
```

### 指定測試名稱（`--grep`）

```bash
# 只跑名稱包含「smoke」的測試
cd tests/e2e && npx playwright test --grep smoke

# 支援 regex
cd tests/e2e && npx playwright test --grep "課程列表|課程建立"

# 排除特定名稱
cd tests/e2e && npx playwright test --grep-invert "Bunny"
```

### 指定 project + 檔案（組合）

```bash
cd tests/e2e && npx playwright test --project=admin 01-admin/smoke.spec.ts
```

---

## 常用參數

| 參數 | 說明 | 範例 |
|------|------|------|
| `--project <name>` | 指定 project | `--project admin` |
| `--grep <pattern>` | 過濾測試名稱（regex） | `--grep smoke` |
| `--grep-invert <pattern>` | 排除測試名稱 | `--grep-invert Bunny` |
| `--headed` | 有頭模式（顯示瀏覽器視窗） | |
| `--headless` | 無頭模式（強制，即使本地也無頭） | |
| `--workers <n>` | 並行 worker 數量（預設 1） | `--workers 2` |
| `--retries <n>` | 失敗重試次數 | `--retries 2` |
| `--timeout <ms>` | 單一測試 timeout（毫秒） | `--timeout 60000` |
| `--reporter <type>` | 報告格式 | `--reporter html` |
| `--ui` | 打開 Playwright UI 模式 | |
| `--debug` | 開啟 Playwright Inspector（debug 模式） | |
| `--last-failed` | 只重跑上次失敗的測試 | |
| `--repeat-each <n>` | 每個測試重複 N 次（壓力測試） | `--repeat-each 3` |
| `--trace on` | 強制記錄 trace（預設 on-first-retry） | |
| `--video on` | 強制錄影（預設 retain-on-failure） | |
| `--screenshot on` | 強制截圖（預設 only-on-failure） | |
| `--forbid-only` | 禁止 `test.only`（CI 必備） | |
| `--output <dir>` | 測試產物輸出目錄 | `--output ./test-results` |

### 組合範例

```bash
# 開發時：有頭 + 指定檔案 + debug
cd tests/e2e && npx playwright test --headed --debug 01-admin/course-create.spec.ts

# CI：無頭 + 報告 + 禁止 .only
cd tests/e2e && npx playwright test --headless --reporter=html --forbid-only

# 快速確認：只跑 smoke，有頭
cd tests/e2e && npx playwright test --grep smoke --headed

# 壓力測試：重複 5 次
cd tests/e2e && npx playwright test 01-admin/smoke.spec.ts --repeat-each 5

# 只重跑上次失敗
cd tests/e2e && npx playwright test --last-failed
```

---

## 無頭 / 有頭模式

`playwright.config.ts` 的預設行為：

| 環境 | 預設模式 |
|------|----------|
| CI（`CI=true`） | 無頭（headless） |
| 本地開發 | **有頭**（headful，可看到瀏覽器） |

強制切換：

```bash
# 強制無頭（本地也無頭，加速執行）
cd tests/e2e && npx playwright test --headless

# 強制有頭（CI 上也開視窗，需要 display 環境）
cd tests/e2e && npx playwright test --headed
```

---

## 冒煙測試

冒煙測試位於 `01-admin/smoke.spec.ts`，驗證最基本的基礎設施：

1. WordPress 首頁可訪問
2. Admin 登入成功（storageState）
3. Power Course 管理頁面可進入（React SPA 載入）

快速執行冒煙：

```bash
# 跑 smoke.spec.ts 這個檔案
cd tests/e2e && npx playwright test 01-admin/smoke.spec.ts

# 或用名稱 grep（全部 project 中符合的）
cd tests/e2e && npx playwright test --grep "Smoke Test"
```

> **建議**：每次環境重建或部署後，先跑冒煙測試確認基礎設施正常，再跑完整測試。

---

## UI 模式與 Debug 模式

### UI 模式（開發首選）

```bash
pnpm run test:e2e:ui
# 等同於
cd tests/e2e && npx playwright test --ui
```

UI 模式提供：
- 視覺化選擇要執行的測試
- 即時看到每一步的截圖
- 時間軸回放
- 可重新執行單一測試

### Debug 模式（逐步 debug）

```bash
pnpm run test:e2e:debug
# 等同於
cd tests/e2e && npx playwright test --debug
```

Debug 模式會：
- 開啟 Playwright Inspector 視窗
- 在第一行暫停等待手動繼續
- 可逐步執行，查看 DOM 和 locator

指定單一測試 debug：

```bash
cd tests/e2e && npx playwright test --debug 01-admin/course-create.spec.ts
```

### 查看 HTML 報告

```bash
cd tests/e2e && npx playwright show-report
```

預設報告在 `playwright-report/` 目錄，測試失敗時自動開啟。

---

## 環境變數

在 plugin 根目錄建立 `.env`（參考 `.env.example`）：

```bash
# E2E 測試目標站點 URL（未設定時 fallback 為 http://localhost:8889）
# 若使用 Local WP 等本地開發環境，填入該站點 URL 即可
TEST_SITE_URL=http://localhost:8889

# WordPress Admin 帳號（global-setup 登入用）
# 這些值也可以在 tests/e2e/fixtures/test-data.ts 的 WP_ADMIN 物件中直接設定
```

`playwright.config.ts` 會自動從根目錄的 `.env` 載入。

---

## 測試結構

```
tests/e2e/
├── playwright.config.ts      # Playwright 設定（projects, timeout, reporters）
├── global-setup.ts           # 測試前：登入、LC bypass、清理舊資料、建立共用測試資料
├── global-teardown.ts        # 測試後：還原 LC bypass
├── fixtures/
│   └── test-data.ts          # 共用常數（URLS, WP_ADMIN, 測試資料）
├── helpers/
│   ├── api-client.ts         # REST API 操作工具（admin 認證）
│   ├── frontend-setup.ts     # 前台共用測試資料建立
│   └── lc-bypass.ts          # License check bypass（測試用）
├── 01-admin/                 # Admin project（後台管理）
│   ├── smoke.spec.ts         # 冒煙測試：基礎設施確認
│   ├── course-create.spec.ts # 課程建立
│   ├── course-edit.spec.ts   # 課程編輯
│   ├── course-list.spec.ts   # 課程列表
│   ├── chapter-manage.spec.ts # 章節管理
│   ├── student-manage.spec.ts # 學員管理
│   ├── teacher-manage.spec.ts # 講師管理
│   ├── settings.spec.ts      # 設定頁面
│   ├── analytics.spec.ts     # 數據分析
│   ├── email-template.spec.ts # 郵件模板
│   ├── bundle-product.spec.ts # 課程組合商品
│   ├── product-binding.spec.ts # 課程商品綁定
│   ├── shortcodes.spec.ts    # Shortcode
│   ├── media-library.spec.ts # 媒體庫
│   ├── bunny-media.spec.ts   # Bunny CDN 媒體
│   ├── api-course-crud.spec.ts # REST API：課程 CRUD
│   ├── api-chapter-crud.spec.ts # REST API：章節 CRUD
│   ├── api-student-manage.spec.ts # REST API：學員管理
│   └── api-settings.spec.ts  # REST API：設定
├── 02-frontend/              # Frontend project（前台）
│   ├── 001-course-product-page-render.spec.ts # 課程商品頁渲染
│   ├── 002-course-product-pricing.spec.ts     # 定價顯示
│   ├── 003-chapter-collapse.spec.ts           # 章節折疊
│   ├── 004-teacher-info.spec.ts               # 講師資訊
│   ├── 005-review-section.spec.ts             # 評論區
│   ├── 006-add-to-cart.spec.ts                # 加入購物車
│   ├── 007-classroom-video.spec.ts            # 教室影片播放
│   ├── 008-classroom-chapters.spec.ts         # 教室章節列表
│   ├── 009-finish-chapter.spec.ts             # 完成章節
│   ├── 010-course-progress.spec.ts            # 課程進度
│   ├── 011-my-account.spec.ts                 # 我的帳戶
│   ├── 012-access-denied-buy.spec.ts          # 未購買存取拒絕
│   ├── 013-access-denied-expired.spec.ts      # 已過期存取拒絕
│   └── 014-access-denied-not-ready.spec.ts    # 未上架存取拒絕
└── 03-integration/           # Integration project（跨層整合，timeout 120s）
    ├── 001-purchase-flow.spec.ts      # 完整購買流程
    ├── 002-expire-date.spec.ts        # 到期日行為
    ├── 003-access-control.spec.ts     # 存取控制
    ├── 004-permission.spec.ts         # 權限驗證
    ├── 005-plugin-dependency.spec.ts  # 外掛依賴
    ├── 006-php-errors.spec.ts         # PHP 錯誤偵測
    ├── 007-chapter-completion.spec.ts # 章節完成流程
    ├── 008-student-lifecycle.spec.ts  # 學員生命週期
    ├── 009-edge-xss-injection.spec.ts # XSS 注入邊緣案例
    ├── 010-edge-boundary-values.spec.ts # 邊界值
    └── 011-edge-invalid-ids.spec.ts   # 非法 ID 邊緣案例
```

---

## Global Setup / Teardown

每次跑測試前後自動執行，**不需要手動操作**：

### Global Setup（`global-setup.ts`）

1. **套用 LC bypass** — 注入授權跳過標記，讓外掛在測試環境可正常運作
2. **登入 WordPress Admin** — 儲存認證狀態到 `.auth/admin.json`，供所有測試的 `storageState` 使用
3. **刷新 Rewrite Rules** — 確保 WordPress 永久連結正確
4. **停用 Coming Soon 模式** — 透過 REST API 設定
5. **切換 Classic Checkout** — 確保測試用 WooCommerce Classic Checkout
6. **清理舊 E2E 測試資料** — 刪除前次測試殘留的課程、章節資料（避免 slug 衝突）
7. **建立前台共用測試資料** — 課程、章節、訂閱者帳號、BACS 付款設定

### Global Teardown（`global-teardown.ts`）

1. **還原 LC bypass** — 恢復 `plugin.php` 原始內容

> 如果測試突然中斷（Ctrl+C），LC bypass 可能未還原。此時手動執行：
> ```bash
> cd tests/e2e && node -e "import('./helpers/lc-bypass.js').then(m => m.revertLcBypass())"
> ```
