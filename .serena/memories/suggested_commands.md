# Power Course - 常用指令

## 開發環境
```bash
pnpm run dev          # 啟動 Vite 開發伺服器 (port 5174)
pnpm run build        # 建置生產版本
pnpm run build:wp     # WordPress 專用配置建置
```

## 程式碼品質
```bash
pnpm run lint:php     # PHP: phpcbf + phpcs + phpstan
pnpm run lint:ts      # TypeScript ESLint 檢查並自動修正
pnpm run format       # Prettier 格式化 TSX 檔案
composer run phpstan  # PHP 靜態分析 (level 9)
composer run test     # PHPUnit 測試
```

## E2E 測試
```bash
pnpm run test:e2e              # 全部 E2E 測試
pnpm run test:e2e:admin        # 僅管理端測試
pnpm run test:e2e:frontend     # 僅前台測試
pnpm run test:e2e:integration  # 僅整合測試
pnpm run test:e2e:ui           # Playwright UI 模式
```

## 發佈
```bash
pnpm run release         # 發佈 patch 版本
pnpm run release:minor   # 發佈 minor 版本
pnpm run release:major   # 發佈 major 版本
pnpm run zip             # 打包外掛 zip
```

## WordPress 環境 (wp-env)
```bash
pnpm run env:start    # 啟動 Docker 環境
pnpm run env:stop     # 停止環境
pnpm run env:destroy  # 銷毀環境
```

## 系統工具 (Windows)
- Shell: Git Bash (Unix 語法)
- 使用 `/` 路徑分隔符，`/dev/null` 而非 `NUL`
