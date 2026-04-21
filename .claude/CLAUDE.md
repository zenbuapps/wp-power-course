# Power Course -- 專案開發指引

## 專案概述

Power Course 是 WordPress 線上課程外掛（LMS），讓站長透過 WooCommerce 銷售與管理線上課程。前後端分離架構：後端 PHP (WordPress + WooCommerce + Powerhouse)，前端 React SPA (Refine.dev + Ant Design) 嵌入 WordPress Admin。前後端透過 REST API (`power-course/v2`) 溝通。

PHP >= 8.0 | Node: pnpm 10.x | TypeScript 5.5

## 技術棧總覽

| 層級 | 技術 |
|------|------|
| 後端語言 | PHP 8.0+, `declare(strict_types=1)` |
| 後端框架 | WordPress, WooCommerce >= 7.6.0, Powerhouse >= 3.3.41 |
| 後端 Namespace | `J7\PowerCourse` (PSR-4: `inc/classes/`, `inc/src/`) |
| 前端語言 | TypeScript 5.5 (strict mode) |
| 前端框架 | React 18, Refine.dev 4.x, Ant Design 5.x, Ant Design Pro Components |
| 狀態管理 | Jotai (UI state), TanStack Query 4.x (server state) |
| 建構工具 | Vite + @kucrut/vite-for-wp |
| 視頻播放 | VidStack, hls.js, Bunny CDN |
| 郵件編輯 | j7-easy-email (MJML-based) |
| 測試 | Playwright E2E, PHPUnit |
| PHP 品質 | PHPCS (WordPress standards) + PHPStan level 9 |
| TS 品質 | ESLint + Prettier |
| i18n | `@wordpress/i18n` (PHP + JS 共用 .po/.mo)。工具鏈：PHP 端 WP-CLI `wp i18n make-pot --skip-js`（本地 composer global / CI 透過 `shivammathur/setup-php@v2 tools: wp-cli`），JS/TSX 端 `gettext-extractor`，兩者以 `gettext-parser` 合併。詳見 `scripts/i18n-make-pot.mjs` |

## 目錄結構

```
power-course/
├── plugin.php              # 外掛入口 (Singleton)
├── inc/                    # PHP 後端
│   ├── classes/            # PSR-4 autoload (Api/, Resources/, PowerEmail/, Utils/ 等)
│   ├── src/Domain/         # DDD 風格：Product Events
│   ├── templates/          # PHP 前台模板
│   └── assets/             # 前台 vanilla TS (HLS, watermark)
├── js/src/                 # React 前端 (Admin SPA, Refine.dev)
│   ├── components/         # 可重用元件
│   ├── pages/admin/        # 管理頁面 (lazy-loaded)
│   ├── hooks/              # 自訂 Hooks
│   ├── resources/          # Refine.dev resource 定義
│   └── types/              # 型別定義 (wpRestApi, wcRestApi, wcStoreApi)
├── tests/e2e/              # Playwright E2E (admin / frontend / integration)
└── specs/                  # 業務規格文件
```

## 溝通與註解風格

- 所有程式碼註解使用**繁體中文**，技術名詞與程式碼維持英文
- Git commit 使用 Conventional Commits 格式，繁體中文描述
- 範例：`feat: 新增字幕上傳功能`、`fix(chapter): 修正章節排序邏輯`

## Git 工作流程

- Commit message：`<type>[scope]: <繁體中文描述>`
- 分支：`master` 為主分支，feature branch 開發後 PR 合併
- PR 安全檢查：確認無 `any` 型別洩漏、SQL injection、XSS、未 escape 的輸出

## 全域建置指令

```bash
# 開發
pnpm run dev              # Vite dev server (port 5174)
pnpm run build            # 建置生產版本
pnpm run build:wp         # WordPress 專用配置建置

# 品質檢查
pnpm run lint:php         # phpcbf + phpcs + phpstan
pnpm run lint:ts          # ESLint 檢查 + 自動修正
pnpm run format           # Prettier 格式化
composer run phpstan      # PHPStan level 9

# 測試
composer run test             # PHPUnit
pnpm run test:e2e             # 全部 E2E
pnpm run test:e2e:admin       # 管理端 E2E
pnpm run test:e2e:frontend    # 前台 E2E
pnpm run test:e2e:integration # 整合 E2E

# 國際化 (i18n)
pnpm run i18n:pot         # 從 PHP + JS 掃出可翻譯字串到 languages/power-course.pot
pnpm run i18n:json        # 從 .po 產 React runtime 用的 JED JSON

# 發佈
pnpm run release          # patch
pnpm run release:minor    # minor
pnpm run release:major    # major
pnpm run zip              # 打包 zip
```

## WordPress 依賴

- **WooCommerce**: 商品、訂單、結帳流程
- **Powerhouse**: 提供 `J7\WpUtils` 工具庫（詳見 `.claude/rules/wordpress.rule.md`）

## 核心架構決策

- **REST API 驅動**: 所有 CRUD 操作透過 `power-course/v2` REST API，前端不直接操作資料庫
- **自訂資料表**: 5 張表管理授權、進度、郵件紀錄、活動日誌、章節續播進度（非 WordPress post meta）
- **Resource 模式**: 每個業務實體（Course, Chapter, Student 等）封裝為 Resource，包含 Core/Model/Service/Utils
- **Refine.dev 資料流**: 前端透過 Refine.dev DataProvider 統一管理 API 呼叫，支援 wp-rest / wc-rest / wc-store 三種 provider
- **Lazy Loading**: 所有管理頁面使用 `React.lazy()` 按需載入
- **i18n 單一翻譯來源**: PHP 與 React 共用 `power-course` text domain（連字號），單一 `.po/.mo` 兩端共用。React 端透過 `wp_set_script_translations()` 在 `inc/classes/Bootstrap.php::enqueue_script()` 接線載入 JED JSON

## 國際化 (i18n) 資源

| 類型 | 路徑 | 用途 |
|------|------|------|
| Skill | `.claude/skills/wordpress-i18n/SKILL.md` | WP i18n 完整 API reference（PHP + JS + 工具鏈） |
| Rule | `.claude/rules/i18n.rule.md` | 本專案 i18n 規範（text domain、escape、placeholder、PR 驗收標準） |
| Agent | `.claude/agents/i18n-refactor.agent.md` | i18n 重構協調員，掃 → 分批 → 派發 → 驗收（不直接改程式碼） |

**重要規則**（詳見 rule）：
- text domain 一律 `'power-course'`（連字號），**禁止** `'power_course'`（底線）
- React 端使用 `@wordpress/i18n`，**禁止** `i18next` / `react-intl`
- PHP 輸出到 HTML 必須用 `esc_html__` / `esc_attr__` 等 escape 變體
- 含變數字串必用 `sprintf` + `%s` / `%1$s`，禁止字串拼接或 template literal
- 新增/修改字串後跑 `pnpm run i18n:pot` 同步 `.pot`
