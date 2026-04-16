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

# 發佈
pnpm run release          # patch
pnpm run release:minor    # minor
pnpm run release:major    # major
pnpm run zip              # 打包 zip

# MCP Server
wp mcp-adapter list                                          # 列出所有 MCP server
wp mcp-adapter serve --server=power-course-mcp --user=admin  # STDIO 模式供 AI client 連接
```

## WordPress 依賴

- **WooCommerce**: 商品、訂單、結帳流程
- **Powerhouse**: 提供 `J7\WpUtils` 工具庫（詳見 `.claude/rules/wordpress.rule.md`）

## 核心架構決策

- **REST API 驅動**: 所有 CRUD 操作透過 `power-course/v2` REST API，前端不直接操作資料庫
- **自訂資料表**: 4 張表管理授權、進度、郵件紀錄、活動日誌（非 WordPress post meta）
- **Resource 模式**: 每個業務實體（Course, Chapter, Student 等）封裝為 Resource，包含 Core/Model/Service/Utils
- **Refine.dev 資料流**: 前端透過 Refine.dev DataProvider 統一管理 API 呼叫，支援 wp-rest / wc-rest / wc-store 三種 provider
- **Lazy Loading**: 所有管理頁面使用 `React.lazy()` 按需載入
- **MCP Server**: 透過 `wordpress/mcp-adapter` 暴露 `power-course-mcp` server（41 tools × 9 領域），讓 AI Agent 可操控 LMS。入口 `inc/classes/Api/Mcp/Server.php`，tool 基類 `AbstractTool`，工具目錄 `inc/classes/Api/Mcp/Tools/{Domain}/`，管理 REST `inc/classes/Api/Mcp/RestController.php`，前端 `js/src/pages/admin/Settings/Mcp/`
