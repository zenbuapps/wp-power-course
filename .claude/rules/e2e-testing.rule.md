---
paths:
  - "tests/e2e/**/*.ts"
  - "playwright.config.ts"
---

# E2E 測試開發規範

## 測試架構

Playwright E2E 測試，分三個 project：

| Project | 目錄 | 用途 |
|---------|------|------|
| admin | `tests/e2e/01-admin/` | WordPress 管理後台操作 |
| frontend | `tests/e2e/02-frontend/` | 前台頁面渲染與互動 |
| integration | `tests/e2e/03-integration/` | 跨模組整合流程 |

## 檔案命名

- Admin specs: `{feature-name}.spec.ts`（如 `course-create.spec.ts`）
- Frontend specs: `{NNN}-{feature-name}.spec.ts`（數字排序，如 `001-course-product-page-render.spec.ts`）
- Integration specs: `{NNN}-{feature-name}.spec.ts`（如 `001-purchase-flow.spec.ts`）

## 測試工具

- `helpers/api-client.ts`: REST API 直接呼叫（繞過 UI 做資料準備）
- `helpers/admin-page.ts`: 管理頁面操作輔助
- `helpers/frontend-setup.ts`: 前台頁面環境設定
- `helpers/wc-checkout.ts`: WooCommerce 結帳流程輔助
- `helpers/lc-bypass.ts`: License check bypass
- `fixtures/test-data.ts`: 共用測試資料

## 執行指令

```bash
pnpm run test:e2e              # 全部測試
pnpm run test:e2e:admin        # 管理端
pnpm run test:e2e:frontend     # 前台
pnpm run test:e2e:integration  # 整合
pnpm run test:e2e:ui           # UI 互動模式
pnpm run test:e2e:debug        # Debug 模式
```

## 測試環境

使用 `@wordpress/env` (wp-env) 提供 Docker 化的 WordPress 測試環境：
```bash
pnpm run env:start    # 啟動
pnpm run env:stop     # 停止
```

配置檔：`.wp-env.json`

## 撰寫測試注意事項

- 測試資料準備優先使用 API client，避免透過 UI 操作（速度更快、更穩定）
- 前台測試需考慮課程是否已授權（avl_course 狀態）
- WooCommerce 相關測試需注意商品狀態（published/draft）與庫存
- 使用 `test.describe.serial()` 處理有順序依賴的測試
