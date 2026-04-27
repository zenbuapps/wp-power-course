# Step F — 全綠閘門（lint / format / phpstan / build）

> 對應 Spec §6 Step F｜依賴：Step E｜風險：低｜預估：1.0h｜建議執行者：`@zenbu-powers:react-reviewer`（審）+ `@zenbu-powers:react-master`（修）

## 目標

全部驗證指令通過，確保 PR 可合併。

## 變更檔案

無新增；本步驟僅執行驗證與修小問題。

## 驗收指令（序列執行）

```bash
# 1. TypeScript / ESLint
pnpm run lint:ts
# 期望：零 error、零新增 warning（允許既有 warning）

# 2. Prettier
pnpm run format
# 期望：跑完無檔案被改（表示已格式化）

# 3. PHPStan（本次零後端改動，應無新 error）
composer run phpstan
# 期望：零新增 error

# 4. Vite build
pnpm run build
# 期望：成功產出 bundle，無 TS error

# 5. i18n 產物檢查（Step D 已跑，此步再確認）
pnpm run i18n:build
git diff languages/
# 期望：diff 穩定（若再跑一次無變動表示已在穩態）
```

## 驗收條件

- [ ] `pnpm run lint:ts` 零 error
- [ ] `pnpm run format` 執行後無檔改動
- [ ] `composer run phpstan`（level 9）無新 error
- [ ] `pnpm run build` 成功
- [ ] `UserTable/index.tsx` 無新增 `@ts-nocheck`
- [ ] `UserTable/index.tsx` 無新增 `as any`（`as string[]` 既有 cast 可接受）
- [ ] `UserTable/index.tsx` 無硬編碼中文字串（全走 `__` / `sprintf`）
- [ ] `languages/power-course-zh_TW.po` 本次新 msgid 皆有非空 msgstr
- [ ] `scripts/i18n-translations/manual.json` 格式合法（JSON.parse 成功）

## 常見問題與解法

| 問題 | 處理 |
|------|------|
| ESLint 抱怨 import 順序 | 跑 `pnpm run lint:ts` 會自動 fix；否則手動按 builtin > external > internal > parent > sibling > index 排並空行分隔 |
| Prettier 改動太多（tab/quote） | 確認 editor 有啟用 project Prettier 設定；不要手動調格式 |
| phpstan 爆新 error（不應該） | 本次零後端改動，若有新 error 表示 import 鏈意外連動到 PHP，必須排查 |
| build 失敗於型別 | 常見：`expireDate` 型別、`Dayjs` import、`selectedRowKeys as string[]` cast |
| i18n `.po` diff 只剩 header 時間戳變動 | 正常，commit 時一併進 |

## 交付後輸出摘要給 tdd-coordinator

- 所有驗收指令輸出貼於 PR description
- 列出手動驗收過的場景（global 模式 / course-exclude 模式 / 成功路徑 / 失敗路徑 / DatePicker 永久 vs 指定日期）
