# Power Course E2E 測試配置指南

> **最後更新：** 2025-03 | **測試數量：** 96 個測試（Admin 45 + Frontend 32 + Integration 19）

---

## 目錄

- [環境需求](#環境需求)
- [本地開發設定](#本地開發設定)
- [執行測試](#執行測試)
- [CI（GitHub Actions）](#cigithub-actions)
- [測試架構](#測試架構)
- [疑難排解](#疑難排解)
- [已知問題](#已知問題)

---

## 環境需求

| 工具 | 最低版本 | 說明 |
|------|----------|------|
| Node.js | 20+ | Playwright 與 wp-env 需要 |
| Docker Desktop | 最新版 | wp-env 依賴 Docker 容器 |
| npm | 10+ | E2E 測試依賴使用 npm 安裝（非 pnpm） |

### 為什麼用 npm 而非 pnpm？

E2E 測試目錄 (`tests/e2e/`) 有獨立的 `package.json`，使用 npm 安裝以避免 Windows NTFS junction 權限問題。

---

## 本地開發設定

### 1. 安裝 E2E 依賴

```bash
cd tests/e2e
npm install
```

### 2. 安裝 Playwright 瀏覽器

```bash
cd tests/e2e
npx playwright install --with-deps chromium
```

### 3. 啟動 WordPress 測試環境

```bash
# 從專案根目錄（.wp-env.json 所在位置）
npx wp-env start
```

wp-env 會：
- 啟動 WordPress 6.8 + PHP 8.2 容器
- 安裝 WooCommerce、Powerhouse、Power Course 三個外掛
- 設定中文語系和固定連結
- 測試站台：`http://localhost:8889`
- 預設帳密：`admin` / `password`

### 4. Windows 用戶注意事項

Windows 上需要在每個新的 PowerShell 視窗設定 Docker PATH：

```powershell
$env:PATH = "C:\Program Files\Docker\Docker\resources\bin;" + $env:PATH
```

建議加入 PowerShell Profile 避免每次手動設定。

---

## 執行測試

### 全部測試

```bash
# 從專案根目錄
npm run test:e2e

# 或直接進入 tests/e2e
cd tests/e2e && npx playwright test
```

### 按專案（模組）執行

```bash
npm run test:e2e:admin        # 後台 Admin SPA 測試（45 tests）
npm run test:e2e:frontend     # 前台頁面測試（32 tests）
npm run test:e2e:integration  # 跨模組整合測試（19 tests）
```

### 互動模式

```bash
npm run test:e2e:ui      # Playwright UI 模式
npm run test:e2e:debug   # 步進除錯模式
```

### 執行單一測試檔案

```bash
cd tests/e2e
npx playwright test 01-admin/001-smoke.spec.ts
npx playwright test 02-frontend/001-course-product-page.spec.ts
```

---

## CI（GitHub Actions）

E2E 測試已整合至 `.github/workflows/ci.yml` 的 `e2e` job。

### CI 觸發條件

- PR 到 `main` 分支時自動執行

### CI 執行流程

1. Checkout 程式碼
2. 安裝 `tests/e2e/` 依賴（npm ci）
3. 安裝/快取 Playwright chromium 瀏覽器
4. 快取 wp-env 環境（`~/.wp-env`）
5. `wp-env start` 啟動 WordPress 容器
6. `npx playwright test` 執行所有測試
7. 上傳 playwright-report 和 test-results 到 artifacts
8. 還原 plugin.php（安全措施）
9. `wp-env stop` 關閉容器

### CI 快取策略

| 快取 | Key | 路徑 |
|------|-----|------|
| Playwright 瀏覽器 | `pw-{OS}-{package-lock hash}` | `~/.cache/ms-playwright` |
| wp-env | `wp-env-{OS}-{.wp-env.json hash}` | `~/.wp-env` |

### 查看測試報告

測試失敗時，在 GitHub Actions 的 Artifacts 區下載 `playwright-report` 壓縮檔，解壓後用瀏覽器開啟 `index.html`。

---

## 測試架構

```
tests/e2e/
├── playwright.config.ts          # Playwright 配置（3 projects）
├── global-setup.ts               # 測試前置：LC bypass + 登入 + 資料準備
├── global-teardown.ts            # 測試後置：還原 plugin.php
├── package.json                  # 獨立的 npm 依賴
├── helpers/
│   ├── admin-page.ts             # Admin SPA HashRouter 導航
│   ├── api-client.ts             # REST API 呼叫封裝（~560 行）
│   ├── frontend-setup.ts         # 前台測試資料準備
│   ├── lc-bypass.ts              # plugin.php LC bypass 工具
│   └── wc-checkout.ts            # WooCommerce 結帳流程
├── fixtures/
│   └── test-data.ts              # 測試常數（課程名、選擇器等）
├── 01-admin/                     # 後台 Admin SPA（15 個 spec）
├── 02-frontend/                  # 前台 PHP 頁面（14 個 spec）
└── 03-integration/               # 跨模組整合（6 個 spec）
```

### 三個測試專案

| 專案 | 目錄 | 超時 | 說明 |
|------|------|------|------|
| admin | `01-admin/` | 30s | React Admin SPA 頁面 |
| frontend | `02-frontend/` | 30s | PHP 模板渲染的前台頁面 |
| integration | `03-integration/` | 120s | 跨模組流程（購買→開通→上課） |

### LC Bypass 機制

Power Course 外掛有授權檢查（License Check），測試環境需要繞過：

1. `global-setup.ts` 會備份 `plugin.php` 到 `plugin.php.e2e-backup`
2. 注入 `$args['lc'] = false;` 到 plugin.php
3. `global-teardown.ts` 自動從 backup 還原
4. CI 中有額外的 `if: always()` 步驟確保還原

**plugin.php.e2e-backup 已加入 .gitignore，絕不會被 commit。**

---

## 疑難排解

### Docker 未啟動

```
Error: Cannot connect to Docker
```

確認 Docker Desktop 正在執行。Windows 用戶需要設定 PATH（見上文）。

### wp-env 啟動失敗

```bash
# 清除並重新建立環境
npx wp-env destroy
npx wp-env start
```

### REST API 回應緩慢

wp-env 本地環境的 REST API 回應較慢（10-30 秒），這是正常的。測試中已設定適當的超時。

### Playwright 瀏覽器未安裝

```bash
cd tests/e2e
npx playwright install --with-deps chromium
```

### 測試失敗後 plugin.php 未還原

```bash
# 手動還原
cp plugin.php.e2e-backup plugin.php
```

---

## 已知問題

### Smoke Test #36 — 網站標題不匹配

測試 `WordPress homepage is accessible` 的 `toHaveTitle` 會因為 wp-env 預設標題 "PowerCourseE2E" 與正則 `/power-course|Power Course/i` 不匹配而標記為 expected failure。這不影響 CI 通過。

### Workers 必須為 1

WordPress 共享 session 不支援平行測試，`workers: 1` 是必須的。

### Integration 測試超時較長

整合測試涉及完整的購買→開通→上課流程，單一測試可能需要 60-120 秒。CI 已設定 `timeout-minutes: 60`。
