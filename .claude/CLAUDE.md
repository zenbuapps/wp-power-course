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
| i18n | `@wordpress/i18n` (PHP + JS 共用 .po/.mo)。Pipeline：`pot`（WP-CLI + gettext-extractor）→ `merge`（build-zhtw-po.mjs 合併 `scripts/i18n-translations/*.json` 對照表）→ `mo`（PHP runtime）→ `json`（JS runtime）。翻譯唯一來源是 `scripts/i18n-translations/manual.json`，**禁止手改 .po**。詳見 `.claude/rules/i18n.rule.md` |

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
pnpm run build:wp         # WordPress 專用配置建置（前台 bundle，結尾串 build:css:front）
pnpm run build:css:front  # 前台元件 + utility CSS → inc/assets/dist/css/front-{components,utilities}.min.css
pnpm run build:css:admin  # 後台 utility CSS → js/dist/css/admin-utilities.min.css（build 結尾已串）

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
pnpm run i18n:build       # 全套：pot → merge → mo → json（**日常只需要這一個**）
pnpm run i18n:pot         # 從 PHP + JS 掃字串到 languages/power-course.pot
pnpm run i18n:merge       # .pot + 既有 .po + scripts/i18n-translations/*.json → 新 .po
pnpm run i18n:mo          # .po → .mo（PHP runtime 讀）
pnpm run i18n:json        # .po → JED JSON（JS runtime 讀）

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

- **REST API 驅動**: 多數 CRUD 操作透過 REST API，前端不直接操作資料庫。namespace 有兩種：一般 Resource Api 使用 `power-course`（無 v2，ApiBase 直接以 namespace 註冊）；舊版 v2 端點沿用 `power-course/v2`
- **自訂資料表**: 9 張表管理授權、進度、郵件紀錄、活動日誌、章節續播進度、使用者持有權限包關係、作業繳交 / 附件 / 訊息（非 WordPress post meta）。DB 版本記於 `pc_db_version` option；`AbstractTable::maybe_upgrade()` 除了比對版本，也會檢查是否有表缺失才早退——否則「版本號已是最新、表卻不存在」的站會永遠修不好
- **Resource 模式**: 每個業務實體（Course, Chapter, Student, AccessPass 等）封裝為 Resource，包含 Core/Model/Service/Utils。AccessPass（Issue #252）新增 CPT `pc_access_pass` 與自訂表 `pc_user_access_pass`，Gate service 以 OR 疊加方式接入 `Utils/Course::is_avl()` 觀看判定鏈。UI 顯示用語為「課程通行證」（zh_TW），程式碼內部代號保留 AccessPass / 權限包。期限模型（DB 1.1.0）：`limit_type` = `unlimited | fixed | assigned | follow_subscription`（`assigned` = 絕對 timestamp 到期）
- **Assignment Resource**（Issue #131）: CPT `pc_assignment` 掛在章節下（`post_parent` = 所屬 `pc_chapter` post ID；`show_in_rest=false`，避免經 `/wp/v2/pc_assignment` 外洩作業說明，一律走 `power-course/assignments` 與 `power-course/my/assignments`），封裝為 Resource（Core/Model/Service/Utils，`inc/classes/Resources/Assignment/`）。繳交表 `pc_assignment_submissions` 一人一作業一列（`UNIQUE KEY (assignment_id, user_id)`），**重新繳交是覆寫同一列，不是新增版本**，且會清空 feedback / graded_by / graded_at 並把 status 重置為 `pending`（DEC-11 決議，見 `Service/Submission.php`）。附件（`pc_assignment_attachments`）實體檔存於受保護目錄（`Utils/Storage`，預設 `wp-content/uploads/power-course-private/assignments/`，可用 `PC_ASSIGNMENT_STORAGE_DIR` 常數或 `power_course_assignment_storage_dir` filter 覆寫），`stored_path` 一律不對外輸出，下載一律走 `assignment-attachments/{id}/download` 端點的權限檢查後 streaming（`Utils/Stream`）。批改狀態機 `pending | pass | fail`（`Service/Grading::ALLOWED_STATUSES`），批改為 pass / fail 時觸發 PowerEmail `assignment_graded`；`Service/DueReminder` 每日排程掃描觸發 `assignment_due_soon`（trigger 定義見 `PowerEmail/Resources/Email/Trigger/AtHelper.php`）。UI 顯示用語「作業」「作業批改」，程式碼內部代號保留 Assignment。
- **Assignment 討論串與匯入批改 CSV**（Issue #131 第二波）: **討論串**（`Service/Thread`，學員端 `my/assignment-submissions/{id}/messages`、管理端 `assignment-submissions/{id}/messages`）的 **GET 有副作用**——先把「對方」發的未讀訊息標已讀再回傳（所以 callback 第一句必須 `nocache_headers()`）；`mine` 只看 `sender_type` 是否等於查詢端（學員端 = student、管理端 = teacher，不看 `sender_id`，多位管理員的訊息彼此都是 mine）；回應永不輸出 `sender_id` / `sender_email`；學員只驗 ownership、不驗觀看權；**發訊息不寄信、不寫日誌**。**匯入批改結果 CSV**（`POST assignment-submissions/import`，`Service/CsvImport` + 自寫嚴格解析器 `Utils/CsvParser`）依**表頭名稱與別名**定位欄位（不依位置，別名含匯出表頭的站台語系譯文），**逐列呼叫 `Grading::grade()`**（副作用與批改作業相同），**不開交易**，單列失敗不影響其他列；單批上限 5000 列 / 檔案 10MB，無法解析 / 空檔 / 只有表頭 / 超量 → 200 + `errors[0].row = 0` 且不處理任何列。整份重匯會讓已批改學員再收一次批改通知（用戶裁決不防），所以批改頁預設篩選是「待批改」。語意細節與後果見 `.claude/rules/wordpress.rule.md`
- **Refine.dev 資料流**: 前端透過 Refine.dev DataProvider 統一管理 API 呼叫，支援 wp-rest / wc-rest / wc-store 三種 provider。共用 axios 實例的 header 污染陷阱、需要自訂逾時的請求（唯一例外）見 `.claude/rules/react.rule.md`
- **Lazy Loading**: 所有管理頁面使用 `React.lazy()` 按需載入
- **自產 Tailwind utility CSS**（Issue #285）: 模板與 SPA 用的 Tailwind / daisyUI class **不再只靠** Powerhouse 的 `front.min.css` / `admin.min.css`（那兩份是 Powerhouse 建置時用 `../power-*` 相對路徑掃旁邊外掛產生的，3.6.0 建置環境旁邊沒有 power-course，前台漏 786 條、後台漏 787 條規則 →「我的課程」空白、教室圖示爆大）。PC 用 Tailwind CLI 自己掃自己：設定 `tailwind.config.{front,admin}.cjs`（共用 `tailwind.config.base.cjs`，必須與 Powerhouse 設定產出相同規則；daisyUI `themes: []` 不輸出主題變數，否則會蓋掉站長選的主題色）+ `postcss.tailwind.cjs`（移植 Powerhouse 的 `scripts/postcss-scope-daisyui.cjs`）。**載入順序是正確性的一部分**（兩邊 specificity 相同、靠順序決勝；PR #286 審查曾量到元件排錯位置會讓評分星星變灰、姊妹外掛的 pc-alert-warning 變灰、簽約 modal 不滿版）：前台 `Templates\Ajax::enqueue_utility_style()` 全站載入兩份——`front-components`（daisyUI 元件）掛成 `powerhouse_front` 的相依、**排在它之前**，Powerhouse 的元件 / 手寫修正 / 姊妹外掛的 utility 覆寫照舊勝出；`front-utilities` 相依 `powerhouse_front`、排在其後。後台 `Bootstrap::enqueue_admin_utility_style()` 只在課程後台載入；admin.min.css 是寫死在 `<head>` 的 `<link>`，排不到它前面，所以元件包在 `@layer power-course-components`（不在 layer 的 Powerhouse 規則永遠優先）。前後台 base 的 `--tw-*` 都必須包在 `@layer tailwind`（裸露的話會重設佈景主題 cascade layer 裡的同名變數）（**不可**放進 `Bootstrap::enqueue_script()`，那支也會隨 vidstack 播放器進前台）。前台 React 元件（`js/src/App2`、`WaterMark`）若新增目錄，要同步加進 `tailwind.config.front.cjs` 的 `content`。CSS 產物在 dist（gitignore），必須排在各自的 `vite build` 之後產生（vite 會清空 outDir）
- **i18n 單一翻譯來源**: PHP 與 React 共用 `power-course` text domain（連字號），單一 `.po/.mo` 兩端共用。兩個 script handle 都透過 `wp_set_script_translations()` + `inject_locale_data_to_handle()` 載入 JED JSON：Admin React SPA 在 `inc/classes/Bootstrap.php::enqueue_script()`；前台 vanilla TS 在 `inc/classes/Templates/Ajax.php::wp_enqueue_scripts()`
- **MCP Server**: 透過 `wordpress/mcp-adapter` 暴露 `power-course-mcp` server（41 tools × 9 領域），讓 AI Agent 可操控 LMS。入口 `inc/classes/Api/Mcp/Server.php`，tool 基類 `AbstractTool`，工具目錄 `inc/classes/Api/Mcp/Tools/{Domain}/`，管理 REST `inc/classes/Api/Mcp/RestController.php`，前端 `js/src/pages/admin/Settings/Mcp/`

## 本地開發環境與前端驗證

本專案有一個常駐的本地開發站可供瀏覽器操作驗證：

| 項目 | 值 |
|------|------|
| 前台 URL | `https://local-turbo.powerhouse.tw` |
| 後台 URL | `https://local-turbo.powerhouse.tw/wp-admin` |
| 帳號密碼 | 見專案根目錄 `.env`（`TEST_USERNAME` / `TEST_PASSWORD`） |

### 使用方式

需要驗證前端畫面、操作流程、或截圖回報時，可使用 **playwright-cli** skill 開啟瀏覽器進行操作：

1. 從 `.env` 讀取 `TEST_SITE_URL`、`TEST_USERNAME`、`TEST_PASSWORD`
2. 使用 playwright-cli 導航到目標頁面
3. 登入後操作並截圖 / 錄製 GIF 回報

適用場景：
- 實作完成後驗證 UI 是否正確渲染
- 重現 bug 或確認修復結果
- 截圖輔助 PR review 或 Issue 回報

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
- 新增/修改字串後：(1) 把繁中翻譯加到 `scripts/i18n-translations/manual.json`，(2) 跑 `pnpm run i18n:build` 同步全套四個檔，(3) 一起 commit `.pot` / `.po` / `.mo` / `.json` —— **禁止手改 `.po`**，會被 pipeline 覆寫
