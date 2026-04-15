# Power Course - 程式碼風格與慣例

## PHP 慣例
- **命名空間**: `J7\PowerCourse\{SubNamespace}` (PSR-4)
- **縮排**: Tab (4 spaces width)
- **陣列語法**: 短陣列 `[]`，禁用 `array()`
- **型別宣告**: `declare(strict_types=1)` 在 plugin.php
- **WordPress Coding Standards**: 依循 WordPress-Core/Extra/Docs
- **靜態分析**: PHPStan level 9
- **Trait 方法**: 必須標記為 `final`
- **類別**: 鼓勵 `final class`
- **註解語言**: 繁體中文，技術名詞維持英文

## TypeScript/React 慣例
- **縮排**: Tab
- **分號**: 不使用分號
- **引號**: 單引號
- **尾逗號**: 所有場合
- **路徑別名**: `@/` 對應 `js/src/`
- **元件**: Functional Components only
- **import 排序**: builtin > external > internal > parent > sibling > index
- **型別**: 避免 `any`（warn level），優先使用 `interface` / `type`
- **未使用變數**: `_` 前綴忽略
- **Hooks**: exhaustive-deps (warn), rules-of-hooks (error)

## Git Commit 風格
- Conventional Commits 格式
- 繁體中文描述
- 範例: `feat: 新增字幕上傳功能`, `fix: 修正課程進度計算`
