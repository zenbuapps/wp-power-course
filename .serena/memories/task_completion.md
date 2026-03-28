# Power Course - 任務完成檢查清單

## 修改 PHP 程式碼後
1. `pnpm run lint:php` — 執行 phpcbf + phpcs + phpstan
2. 確認 PHPStan level 9 無新增錯誤

## 修改 TypeScript/React 程式碼後
1. `pnpm run lint:ts` — ESLint 檢查
2. `pnpm run build` — 確認建置成功

## 修改 API 端點後
1. 更新 `specs/api/api.yml` (如有)
2. 確認前後端型別一致

## 新增功能後
1. 考慮是否需要新增 E2E 測試
2. 更新相關 specs (features, activities)

## Commit 前
- 使用 Conventional Commits 格式
- 繁體中文描述
- 不要 commit 含有密鑰的檔案 (.env)
