# TDD 協調藍圖：Issue #131 作業與作業批改（第一波）

> 產出：`@zenbu-powers:tdd-coordinator`｜日期：2026-09-17｜分支：`feat/issue-131-assignment-grading`｜規格 commit：`2397234f`
> 唯一依據：`specs/plan/issue-131-assignment-grading/implementation-plan.md`（下稱「計劃」）
> 讀者：主窗口（orchestrator）。本檔只定義「派發順序、prompt 骨架、Gate 命令與判準、回退規則、檢查點格式」，不含任何實作。
> 用戶已定案、不可推翻：`specs/clarify/2026-09-17-1415-issue131-assignment-grading.md` 的 D1–D29。

---

## 0. 使用方式

1. 從 §5（P0）開始，依 step ID 順序執行。每個 step 結束立刻更新 `.progress.json`（§3.6）。
2. **派發 step**：用 Agent 工具 spawn。`subagent_type`、`name`、`description` 照各 step 表格填。prompt 取本檔的骨架：
   - `{{S-XXX}}` → 換成 §4 對應共用區塊**全文**（原樣貼入，不摘要、不刪節）
   - `{{Pn-X 回報}}` → 換成上游 agent 回報的**原文**對應段落
   - `{{UC-X 結果}}` → 換成用戶的回答原文
3. **主窗口親跑 step**：照 §3.2 的命令跑，照 §3.3 貼證據，依該 step 寫的判準判定。**不接受 sub-agent 的口頭「完成」**。
4. 判準不符 → 查 §3.4 失敗處理表。任何 STOP → 保留所有變更、progress 標 `blocked`、回報用戶。
5. 需要用戶的地方（§3.7 的 UC）一律停下來問。**commit / push 一律「待用戶同意」**，本藍圖不安排任何自動 commit。
6. 同一時間只允許一個東西在跑 PHPUnit（tests 容器共用一個 DB）。主窗口在 sub-agent 執行中不跑 PHPUnit / E2E。

### 0.1 派發總覽

| 階段 | 派發步驟（spawn 次數） | 主窗口親跑的 Gate | 用戶確認點 |
|------|----------------------|------------------|-----------|
| P0 前置基線 | 0 | P0-1 ~ P0-7 | UC-0（不擋 P1） |
| P1 資料表與 DB 升級 | **3**：R、G1、F | RG、GG、PG | UC-1（commit） |
| P2 作業出題 | **4**：R、G1、G2、F | RG、G1C、GG、PG | UC-2（commit） |
| P3 學員端 | **5**：R1、R2、G1、G2、F | RG、G1C、GG、PG | UC-3（commit，並提示 opt-in security-reviewer） |
| P4 批改 / 報表 / CSV | **5**：R1、R2、G1、G2、F | RG、G1C、GG、PG | UC-4（commit） |
| P5 PowerEmail 觸發 | **3**：R、G1、F | RG、GG、PG | UC-DEC10（擋 P5-R）、UC-5 |
| P6 截止提醒 | **3**：R、G1、F | RG、GG、PG | UC-6 |
| P7 Admin SPA | **5**：R、G1、G2、G3、F | RG、G1C、G2C、GG、PG | UC-DEC11（擋 P7-R）、UC-7 |
| P8 教室作業區塊 | **3**：R、G1、F | RG、GG、PG | UC-DEC15（擋 P8-R）、UC-8 |
| P9 收尾 | **1**：D（doc-updater） | FG（最終關卡）、截圖 | UC-9（最終 commit / 分支處置） |
| **合計** | **32 次派發** | | |

### 0.2 每階段的標準流程

```
[UC-DECxx 若有] → Pn-R（test-creator）→ Pn-RG（主窗口：紅燈驗收）
  → Pn-G1（實作 agent）→ Pn-G1C（主窗口：小檢查）→ Pn-G2 … → Pn-GG（主窗口：綠燈驗收）
  → Pn-F（Refactor agent）→ Pn-PG（主窗口：計劃 §9 驗證關卡）
  → Pn-CK（寫檢查點 + UC-n 問 commit）→ 下一階段
```

- **Red Gate 通過前，不得派任何 G**。
- **Phase Gate 通過前，不得派下一階段的 R**。
- 單一 G 的階段沒有 G1C，GG 直接接在 G1 之後。

---

## 1. 環境確認

### 1.1 模式與分支

| 項目 | 狀態 |
|------|------|
| 執行環境 | **本地**（`printenv GITHUB_ACTIONS` 無輸出） |
| 分支 | 已在 `feat/issue-131-assignment-grading`，不另建分支 |
| Worktree | **不使用**（計劃 §9.1 建議全部依序執行；R-28 worktree 跑 PHPUnit 有坑） |
| 收尾 | 保留分支給用戶驗收；不自動 merge / PR |

### 1.2 specs 完整性

| 項目 | 狀態 |
|------|------|
| `specs/features/assignment/` | 20 支 `.feature` + `句型.md`（第一波用 17 支；`查詢作業討論串`、`發送作業討論訊息`、`匯入批改結果CSV` 屬第二波） |
| 追加 Rule 的既有 feature | `chapter/刪除章節`、`course/複製課程`、`student/移除學員課程權限`、`email/觸發自動郵件` 皆存在 |
| `specs/entity/erm.dbml`、`specs/api/api.yml` | 存在（REST namespace `/wp-json/power-course`） |
| `specs/ui/{章節作業管理,作業批改頁,教室作業區塊}.md` | 存在 |
| clarify D1–D29、execution-plan | 存在 |
| 參考實作 `C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\` 與 `apps\web\app\...` 5 支 TSX | 路徑皆存在 |
| 結論 | 規格完整，走標準 TDD（非 spec-less 模式） |

### 1.3 技術棧（決定派誰）

| 層 | 技術 | Red | Green / Refactor |
|----|------|-----|------------------|
| 後端 PHP（P1–P6） | PHP 8 strict、WordPress、WooCommerce、Powerhouse、Action Scheduler | `@zenbu-powers:test-creator`（PHPUnit 9 + wp-phpunit） | **general-purpose**（專案沒有 wordpress-master） |
| HTTP 契約（P3、P4） | 真實 REST / multipart / 串流 | test-creator（Playwright `03-integration`） | 同該階段 PHP 實作 agent |
| Admin SPA（P7） | React 18、Refine、Ant Design、TanStack Query | test-creator（Playwright `01-admin`） | `@zenbu-powers:react-master` |
| 教室（P8） | PHP 模板 + vanilla TS（`inc/assets`） | test-creator（Playwright `02-frontend`） | general-purpose（模板與 TS 同一人） |
| 文件（P9） | CLAUDE.md、rules、specs | — | `@zenbu-powers:doc-updater` |

### 1.4 本 session 讀檔確認過的環境事實

- 本機 `php` 8.3.23、Composer 2.8.5、`@wordpress/env` 11.1.0；`vendor/bin/` 有 phpunit / phpcs / phpcbf / phpstan。
- `.wp-env.override.json` 存在（`core: null`、port 8898 / 8899，列在 `.git/info/exclude`）。
- `phpunit.xml`：只有 `integration` suite，**沒有** group 白名單（`--group` 是唯一篩選手段）。
- `tests/bootstrap.php::_power_course_create_tables()` 在 `after_setup_theme` 建既有自訂表（transaction 外 → 真實表）。
- `tests/e2e/global-setup.ts` 自動 `applyLcBypass()`（先寫 `plugin.php.e2e-backup` 再注入 `'lc'          => false,`），`global-teardown.ts` 以備份還原。
- `tests/e2e` baseURL 讀 `.env` 的 `TEST_SITE_URL`；本機非 CI 時 Playwright 為 headed。
- `js/dist/vite-dev-server.json` 目前**不存在**（Admin SPA 走 prod dist）。
- `tsconfig.json` 只 include `js/src`（`inc/assets` 的 TS 沒有 type-check）。
- `phpcs.xml` 排除 `*/tests/*`；`phpstan.neon` paths 為 `plugin.php`、`inc/`。
- `scripts/i18n-translations/manual.json`：gettext context 用 **`msgctxt`** 鍵；`context` 鍵只是來源位置註記。`build-po.mjs` 結尾印 `  zh_TW: total=…, untranslated=…, conflicts=…`。
- PHPUnit 1341 全綠 / PHPStan 96 是 memory 在 2026-09-02 的量測，**本 session 未執行**，P0 必須重新量測。

### 1.5 本功能會踩到的「會說謊的儀器」（寫進各 prompt）

| # | 儀器 | 看起來 | 真相 / 對策 |
|---|------|--------|------------|
| L1 | wp-phpunit transaction | 測試裡 `CREATE TABLE` 建了表 | `start_transaction()` 把 `CREATE TABLE` / `DROP TABLE` 改寫成 **TEMPORARY**；`WP::is_table_exists()` 查 information_schema **看不到** temporary table。斷言表 / 欄位用 `DESCRIBE`、`SHOW INDEX FROM`（參考 `tests/Integration/AccessPass/MigrationTest.php`） |
| L2 | PHPUnit 9 incomplete / skipped | exit 0、「OK, but …」 | 骨架用 `markTestIncomplete` 會假綠 → Gate 要求 group 內 Incomplete / Skipped / Risky = 0 |
| L3 | `@group` 寫在檔案 docblock | 看似分組 | 只有 class / 方法 docblock 有效 → Red Gate 比對「group 內 Tests 數 = manifest」 |
| L4 | `npx wp-env run` exit code | 0 / 非 0 | 不保證等於 PHPUnit 原始碼 → 計數以輸出摘要行為準 |
| L5 | Git Bash MSYS 路徑轉換 | `--filter '/X/'` 照傳 | 以 `/` 開頭的參數會被轉成 Windows 路徑 → 不用 regex filter，改看 `--testdox` 分 class 結果 |
| L6 | `pnpm run lint:php` | phpcbf 修好了 | phpcbf 有修就 exit 1，`&&` 短路，phpcs / phpstan 沒跑 → 分開跑 |
| L7 | `pnpm run lint:ts` | 檢查 | 帶 `--fix` 會改檔（含無關檔）→ 主窗口 Gate 用不帶 `--fix` 的 eslint |
| L8 | `phpstan.neon` 忽略 `#Constant .* not found#` | level 9 綠 | 全域常數拼錯（例如 `PC_ASSIGNMENT_STORAGE_DIR`）PHPStan 抓不到 → 一律 `defined()` 守門，靠測試抓 |
| L9 | `nocache_headers()` | response 沒 Cache-Control 也綠 | CLI 下無觀測點 → PHPUnit 靜態檢查 callback 第一句 + 016 E2E 驗真實標頭 |
| L10 | Playwright 單跑 | 綠 | 連跑會紅 / flaky → 每個 E2E Gate 分兩次獨立執行都要綠且無 flaky |
| L11 | LC bypass 還原 | `git checkout -- plugin.php` 很方便 | P1 改了 plugin.php，未 commit 時 checkout 會抹掉 P1 → **禁止**，改用備份還原（§3.2 CMD-LC） |
| L12 | 計劃 P9 的 `git grep -n "'lc' =>"` | 空 = 沒殘留 | bypass 注入的是 `'lc'          => false,`（對齊空白），單一空格的 pattern **比對不到** → 用 `'lc'[[:space:]]*=>` |
| L13 | `pnpm run build` | 只 build SPA | 內含 `i18n:build`，會改 `languages/` |
| L14 | `vite build`（`build:wp`） | build 成功 | 不做型別檢查；`inc/assets` 不在 tsconfig → 型別錯誤看不到，靠 E2E |
| L15 | `manual.json` 的 `context` 鍵 | 以為是 `_x` context | `_x` 要用 `msgctxt` 鍵；寫錯 → 譯文不命中 → zh_TW 斷言紅 |

---

## 2. 對計劃的調整與理由

### 2.1 DEC-01 ~ DEC-18：未調整

沒有 DEC 妨礙 TDD 執行。DEC-10、DEC-11、DEC-15 依計劃 §16 需要用戶確認，排在 UC-0（§3.7），只擋受影響的階段。

### 2.2 流程調整（ADJ）

| ID | 調整 | 理由 |
|----|------|------|
| ADJ-01 | 新增 **P0 前置基線**：開工前重新量測 PHPUnit 全套、PHPStan（含 JSON）、i18n 未翻譯數、ESLint / type-check、E2E canary | 計劃的關卡要比對「開工前」數字，但 1341 / 96 是 memory 在 2026-09-02 的數字，本分支沒有重新量過；依 Evidence 鐵律，沒量過的數字不能當基準。PHPStan JSON 是 >96 時比對「檔案 + message」用 |
| ADJ-02 | Gate 主要用 **`--group issue-131`**（計劃寫 `--group assignment`）；**修改既有測試類別**時（`SendNowTest`、`EmailTriggerAtTest`）只在新增 / 修改的**方法** docblock 加 `@group issue-131`；group 內 Incomplete / Skipped / Risky 必須為 0 | P5 修改的 Email 測試不在 `assignment` group，用 `assignment` 算紅燈數會漏；方法層級 `@group` 在 PHPUnit 9 有效，也不改變既有 class 的分組。新 class 照計劃同時標 `assignment` + `issue-131`。L2 的假綠要靠計數擋 |
| ADJ-03 | **Red 只寫測試**：覆寫 `aibdd-auto-tdd` `references/red/php.md` 第 2 步「建立 Model / Repository / Service stub」，test-creator 不得動 `inc/`；新增回報段「**介面契約**」，主窗口原文轉給 Green，Green 必須照做 | stub 等於替 Green 決定 class 結構，且可能和計劃 §5.1 不一致；測試先寫一定會先決定名稱與簽名，沒有明文契約時，Green 容易「改測試遷就實作」。紅燈原因（class / 常數不存在、rest_no_route、斷言不符）與計劃 §9 一致 |
| ADJ-04 | **016 HTTP 契約 E2E 提前**：P3-R2 寫上傳 / 下載 / 401 / 403，P4-R2 補 CSV；P3、P4 的 Phase Gate 各連跑兩次；P8 只回歸重跑 | `is_uploaded_file()`、`rest_pre_serve_request` 串流、真實回應標頭這三條路徑 PHPUnit 完全碰不到（R-07、R-13）。原排程要到 P8 才第一次執行，壞了要隔 5 個階段才發現，那時修的人是做模板的 agent。代價是 P3 就要面對 E2E 環境牆 → 失敗表給「環境阻擋時問用戶是否延到 P8」的出口，不靜默跳過 |
| ADJ-05 | P1-G1 **同時修改 `tests/bootstrap.php`**，在 `_power_course_create_tables()` 補三個 `create_assignment_*_table()`（計劃只列 `TestCase.php`） | 既有 6 張自訂表都是 bootstrap 在 transaction 外建的真實表；只在 `TestCase::set_up` 建，會被 L1 改寫成 TEMPORARY TABLE，行為和其他自訂表不一致（TEMPORARY TABLE 在同一查詢被引用多次有已知限制，P4 報表 SQL 有可能只在測試環境出錯，**未實測**） |
| ADJ-06 | `plugin.php` 還原改用 `plugin.php.e2e-backup` 或只刪 lc 行；**禁止** `git checkout -- plugin.php`（計劃 P8 驗證關卡原寫法） | L11：P1 改了 plugin.php，commit 要用戶同意，未 commit 時 checkout 會把 P1 一併抹掉 |
| ADJ-07 | L 規模階段的 Green 拆成**序列派發**：P2×2、P3×2、P4×2、P7×3；每個派發有「負責的測試清單」與主窗口小檢查（Gi-C）。Refactor 預設**新開** agent（可選 SendMessage 延續最後一個 Green agent） | 1800–2200 行的綠燈循環容易耗盡單一 agent 的 context；拆開後失敗重派範圍小。計劃「Refactor 由同一個實作 agent」的用意（熟悉程式碼）改用「prompt 附變更檔清單 + 必讀」滿足 |
| ADJ-08 | 主窗口的 Gate 命令**不改程式碼**：ESLint 不帶 `--fix`、PHPCS 不跑 phpcbf；phpcbf 由 Refactor agent 跑 | 主窗口是驗證者；帶 fix 的命令會讓「驗證」同時「修改」，證據失真（L6、L7） |
| ADJ-09 | E2E 每次都**加跑同 project 的 canary**：admin `01-admin/smoke.spec.ts`、frontend `02-frontend/008-classroom-chapters.spec.ts`、integration `03-integration/015-e2e-helper-contract.spec.ts`（P0 先確認 canary 本身是綠的，不綠就換一支並記錄） | 區分「功能沒做（該紅）」與「環境壞了（假紅）」 |
| ADJ-10 | DEC-10 / 11 / 15 在 P0 結束時一起問（UC-0），但只**硬性阻擋** P5-R / P7-R / P8-R | 計劃 §16 說「P2 開工前順便確認，不擋 P1」；這三條只影響 P5 / P7 / P8 的測試內容，擋 P2 沒有必要 |
| ADJ-11 | 計劃 P2「開工前補 i18n 術語表」指定由 **P2-G1 第 0 步**執行 | 計劃沒指定負責者；Red 斷言的是 feature 的中文字面值，不依賴術語表，所以在 Green 寫第一個 msgid 前補即可 |
| ADJ-12 | Red Gate 通過時記錄測試檔 sha1，GG / PG 驗證未被改動 | Green / Refactor 不得改測試；用雜湊驗，不靠 agent 自述 |

---

## 3. 全域規則

### 3.1 名詞

- **manifest**：test-creator 回報中的測試清單（§4 S-REPORT-RED 第 1 段）。
- **group 計數**：該測試在 `--group issue-131` 執行中被算進 Tests 的數量（method × data set）。
- **全套增量**：該測試讓全套 Tests 數增加的數量（新增 method × data set；修改既有 method 若沒增加 data set 則為 0）。
- **回歸守門（guard）**：實作前就應該綠的測試，必須在 manifest 事先申報並說明原因。
- **累計預期**：`issue131_group_total` = 前面所有階段 PG 記錄的 group Tests 數 + 本階段 manifest group 計數；`suite_total` = 前一階段 PG 的全套 Tests 數（P1 用 P0 基線）+ 本階段全套增量。

### 3.2 標準命令（Bash 工具 / Git Bash）

Bash 工具每次呼叫都是新 shell，**每個命令開頭都要重設變數**：

```bash
PC="C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-course"
LOG="<主窗口的 scratchpad 目錄，用正斜線>/issue131"   # 第一次用前：mkdir -p "$LOG"
TAG="p1-rg"                                              # 當前 step ID（小寫）
```

PHPUnit / E2E 可能超過 2 分鐘：Bash `timeout` 設 `600000`，或 `run_in_background: true` 等完成通知。

**CMD-GIT-SCOPE**（變更範圍）
```bash
cd "$PC" && git status --short --untracked-files=all
```

**CMD-PHPL**（語法檢查）
```bash
cd "$PC" && for f in <檔案清單，空白分隔>; do php -l "$f" 2>&1 | tail -n 1; done
```
判準：每一行都是 `No syntax errors detected in …`。

**CMD-PU-GROUP**（本功能測試）
```bash
cd "$PC" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --testdox > "$LOG/$TAG-group.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "^(OK \(|OK, but|Tests: |No tests executed)|FAILURES!|ERRORS!|Fatal error|ParseError" "$LOG/$TAG-group.log"
echo "PASS=$(grep -c '✔' "$LOG/$TAG-group.log") FAIL=$(grep -c '✘' "$LOG/$TAG-group.log") SKIP=$(grep -c '↩' "$LOG/$TAG-group.log") INCOMPLETE=$(grep -c '∅' "$LOG/$TAG-group.log") RISKY=$(grep -c '☢' "$LOG/$TAG-group.log")"
```
判定時要讀 log 內各 class 區塊的 ✔ / ✘ 明細（Gi-C、Red Gate 需要逐 class 對照）。

**CMD-PU-FULL**（全套）
```bash
cd "$PC" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit > "$LOG/$TAG-full.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "^(OK \(|OK, but|Tests: |No tests executed)|FAILURES!|ERRORS!|Fatal error|ParseError" "$LOG/$TAG-full.log"
grep -nE "^There (was|were) [0-9]+ |^[0-9]+\) " "$LOG/$TAG-full.log" | head -n 120
```

**CMD-PU-DIR**（受影響的既有目錄，Green agent 自測用；主窗口必要時用）
```bash
cd "$PC" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit tests/Integration/<Dir> > "$LOG/$TAG-dir.log" 2>&1; echo "EXIT_CODE=$?"; grep -nE "^(OK \(|OK, but|Tests: )" "$LOG/$TAG-dir.log"
```

**CMD-TEST-HASH**（Red Gate 通過時記錄；GG / PG 驗證）
```bash
# 記錄（清單 = 本階段 manifest 的所有測試檔 + 先前階段的 Assignment 測試檔）
cd "$PC" && { find tests/Integration/Assignment -type f -name "*.php"; echo <本階段修改的既有測試檔 / E2E spec>; } | sort -u | xargs sha1sum > "$LOG/$TAG-tests.sha1"; cat "$LOG/$TAG-tests.sha1"
# 驗證（把記錄的 sha1 內容也存進 .progress.json，scratchpad 遺失時可重建）
cd "$PC" && sha1sum -c "$LOG/<該階段 rg 的 TAG>-tests.sha1" | grep -v ": OK$"; echo "SHA_CHECK_DONE"
```
判準：`SHA_CHECK_DONE` 之前沒有任何輸出。例外：P1-G1 允許改 `tests/Integration/TestCase.php`、`tests/bootstrap.php`（不在雜湊清單內）。

**CMD-PHPSTAN**
```bash
cd "$PC" && composer run phpstan > "$LOG/$TAG-phpstan.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "Found [0-9]+ errors?|\[OK\] No errors" "$LOG/$TAG-phpstan.log"
```
超過基線時，列出新增錯誤（比「檔案 + message」，不比行號）：
```bash
cd "$PC" && vendor/bin/phpstan analyse --memory-limit=3072M --error-format=json > "$LOG/$TAG-phpstan.json" 2>/dev/null
node -e '
const fs=require("fs");
const norm=p=>p.replace(/\\/g,"/").replace(/^.*\/power-course\//,"");
const load=f=>{const j=JSON.parse(fs.readFileSync(f,"utf8"));const s=new Map();for(const [file,v] of Object.entries(j.files||{}))for(const m of v.messages){const k=norm(file)+" | "+m.message;s.set(k,(s.get(k)||0)+1)}return s};
const a=load(process.argv[1]),b=load(process.argv[2]);
for(const [k,n] of b){const d=n-(a.get(k)||0);if(d>0)console.log("+"+d+"  "+k)}
' "$LOG/phpstan-baseline.json" "$LOG/$TAG-phpstan.json"
```

**CMD-PHPCS**（變更過的 PHP，相對規格 commit；tests 被 phpcs.xml 排除）
```bash
cd "$PC" && FILES=$( { git diff --name-only --diff-filter=AMR 2397234f -- plugin.php inc; git ls-files --others --exclude-standard -- inc; } | grep -E '\.php$' | sort -u ); echo "$FILES"
[ -n "$FILES" ] && vendor/bin/phpcs --report=summary $FILES > "$LOG/$TAG-phpcs.log" 2>&1; echo "EXIT_CODE=$?"; cat "$LOG/$TAG-phpcs.log"
```
判準：輸出為空，或 `A TOTAL OF 0 ERRORS`（warning 列出但不擋）。

**CMD-I18N**
```bash
cd "$PC" && pnpm run i18n:build > "$LOG/$TAG-i18n.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "^\s+(zh_TW|ja): total=" "$LOG/$TAG-i18n.log"
git status --short -- languages/
```
判準：EXIT 0；兩個 locale 的 `untranslated` ≤ 基線；本階段有新字串時 `languages/` 7 檔皆有變更。

**CMD-ESLINT / CMD-TSC**（前端，主窗口版，不帶 fix）
```bash
cd "$PC" && npx eslint "js/src/**/*.{ts,tsx}" --ext .ts,.tsx --rule "no-unused-vars: off" > "$LOG/$TAG-eslint.log" 2>&1; echo "EXIT_CODE=$?"; tail -n 5 "$LOG/$TAG-eslint.log"
cd "$PC" && pnpm run type-check > "$LOG/$TAG-tsc.log" 2>&1; echo "EXIT_CODE=$?"; tail -n 10 "$LOG/$TAG-tsc.log"
```

**CMD-E2E**（兩次獨立執行；每次後面都接 CMD-LC）
```bash
cd "$PC/tests/e2e" && npx playwright test <canary spec> <target specs…> --reporter=list > "$LOG/$TAG-e2e-run1.log" 2>&1; echo "EXIT_CODE_RUN1=$?"
grep -nE "^\s*[0-9]+ (passed|failed|flaky|skipped|did not run|interrupted)" "$LOG/$TAG-e2e-run1.log"
```
（第二次把 `run1` 換成 `run2`。）

**CMD-LC**（LC bypass 殘留檢查）
```bash
cd "$PC" && echo "[lc]"; git grep -n "'lc'[[:space:]]*=>" -- plugin.php; echo "[backup]"; ls plugin.php.e2e-backup 2>/dev/null || echo "NO_BACKUP"
```
判準：`[lc]` 下無輸出，且印出 `NO_BACKUP`。有殘留時：
1. `cd "$PC" && diff plugin.php.e2e-backup plugin.php`
2. diff **只有** lc 那一行 → `cp plugin.php.e2e-backup plugin.php && rm plugin.php.e2e-backup`
3. diff 還有其他差異 → **不要 cp**；用 Edit 只刪 `'lc'          => false,` 那一行，再 `rm plugin.php.e2e-backup`
4. 重跑 CMD-LC；再跑 `git diff --stat -- plugin.php`，確認只剩 P1 的改動（P1 之前應無差異）
5. **禁止** `git checkout -- plugin.php`（ADJ-06）

**CMD-DIST**（Admin SPA 是否走 prod dist）
```bash
cd "$PC" && (ls js/dist/vite-dev-server.json 2>/dev/null || echo "NO_DEV_SERVER_JSON"); ls -la --time-style=full-iso js/dist/manifest.json
```

### 3.3 證據格式（每個 Gate 必貼）

```text
[通過|未通過] <Step ID> <Gate 名稱>

$ <完整命令>
EXIT_CODE=<值>
摘要行：<OK (…) / Tests: …, Failures: …, Errors: … / N passed …>
判準對照：
- <判準 1>：預期 <x>，實際 <y> → 符合 / 不符
- …
（未通過時附：失敗清單原文）
```

沒有貼命令輸出 = 沒過。「sub-agent 說跑過了」不算。

### 3.4 失敗處理與回退規則

**重試的做法**：Green / Refactor 重試優先用 SendMessage 延續同一個 agent（spawn 時給了 `name`；SendMessage 是 deferred tool，先 ToolSearch `select:SendMessage`）；agent 已不可用或 context 看起來耗盡時，新開同類型 agent，prompt = 原 prompt + 「前次失敗」區塊（失敗命令輸出原文 + 前次回報原文）。

| 情境 | 判定方式 | 動作 | 上限 | 耗盡後 |
|------|---------|------|------|--------|
| R：manifest 列的檔案不存在 / 數量對不上 | CMD-GIT-SCOPE、CMD-PU-GROUP 的 Tests 數 | 重派同一 R，附差異 | 2 | STOP |
| R：`php -l` 失敗、Fatal、ParseError、`No tests executed` | CMD-PHPL / 輸出 | 重派 R，附錯誤原文 | 2 | STOP |
| R：有**未申報**的綠燈測試 | testdox ✔ 不在 guard 清單 | 重派 R：修斷言，或補申報理由。主窗口只接受「本來就該成立的回歸守門」理由 | 2 | STOP |
| R：紅燈原因不對（測試 helper 自己壞、TypeError 發生在測試類別內、DB 連線錯） | 讀失敗訊息 | 重派 R | 2 | STOP |
| R：既有測試變紅 | CMD-PU-FULL 失敗清單有非 manifest 的測試 | 重派 R（多半是污染全域狀態 / static） | 2 | STOP |
| R：越界改了 `inc/`、`plugin.php`、`js/`、`languages/`、`scripts/` | CMD-GIT-SCOPE | 重派 R，要求刪除 / 還原它自己建的東西（主窗口不跑 git checkout） | 1 | STOP |
| 環境錯誤（wp-env 未啟動、Docker、DB 連線、Playwright 瀏覽器未安裝） | 輸出中的環境訊息 | 主窗口修環境後重跑，**不算** agent 重試次數 | 2 | 問用戶 |
| E2E 紅，canary 也紅 | canary 結果 | 視為環境：依 memory `reference_local_frontend_verify_playbook.md` 五道牆排查 | 2 | 問用戶；P3 / P4 的 016 可請用戶決定「延到 P8」並記入 progress |
| E2E 兩次一綠一紅 / 出現 `flaky` | CMD-E2E 摘要 | 視為紅 | 同 Green | STOP |
| G：負責的測試仍紅 | Gi-C / GG | 重試 G（附失敗輸出） | 3 | STOP，保留變更 |
| G / F：改了測試檔 | CMD-TEST-HASH | 不接受；要求該 agent 把自己對測試的修改改回（附 sha1 差異） | 1 | STOP |
| G：主張「測試與 feature 矛盾」 | agent 回報 | 主窗口對照 feature 原文判斷：**測試錯** → 派 test-creator 只修該測試（附 feature 引文），修完重跑該階段 RG 的判準（該測試須仍為紅或已申報），再重試 G；**feature 本身有缺口 / 矛盾** → STOP 問用戶 | test 修 1 次 | 問用戶 |
| G：需要 feature 沒寫的業務行為 | agent 回報 | HOLD SCOPE：STOP 問用戶，不讓 agent 自行補規則 | — | — |
| PG：全套出現非 issue-131 測試紅（regression） | CMD-PU-FULL | 派修復（新開 general-purpose，用 S-GREEN-PHP + 失敗清單）；**禁止**改該既有測試（P5 的 SendNowTest slug 數除外，那是 Red 刻意改的） | 3 | STOP |
| PG：PHPStan > 基線 | CMD-PHPSTAN + JSON 比對 | 重試 F，附新增錯誤清單 | 3 | STOP |
| PG：PHPCS 有 error | CMD-PHPCS | 重試 F | 3 | STOP |
| PG：i18n 未翻譯數上升 / 7 檔缺漏 | CMD-I18N | 重試最後一個 G：只補 `manual.json` 並重跑 `i18n:build` | 2 | STOP |
| F 之後測試變紅 | PG 的 CMD-PU-GROUP / FULL | 重試 F 修正（不自動退回 Refactor 前） | 3 | STOP |
| LC 殘留 | CMD-LC | 依 CMD-LC 還原步驟 | — | 還原步驟 3 也無法判斷時問用戶 |

**STOP 的標準動作**
1. 保留所有變更，不 stash、不 checkout、不 reset。
2. `.progress.json`：該階段 `status: "blocked"`，`blocker` 寫清楚卡在哪個 step、最後一次命令輸出的 log 路徑與摘要行。
3. 回報用戶：卡點、證據、選項——(a) 人工介入後從該 step 繼續；(b) 修改規格或計劃後重派；(c) 回退到上一個檢查點。
4. **回退（只在用戶同意後）**：上一個檢查點**有 commit** → `git stash push -u -m "issue131-<phase>-abandoned"`（可逆，不刪任何東西）；**沒有 commit** → 無法精確回退，只能保留現狀，由用戶決定。禁止 `git reset --hard`、`git checkout --`、`git clean`。

### 3.5 全流程禁止事項（寫進每個 prompt）

- commit、push、`git stash`、`git checkout --`、`git reset`、`git clean`、開 PR（除非用戶在對話中明確同意）
- `plugin.php` 的 `init()` 出現 `'lc'`（`.github/workflows/lc-guard.yml` 會擋）
- 手改 `languages/*.po`；中文 msgid
- REST callback 第一個語句不是 `\nocache_headers();`
- 未 `$wpdb->prepare()` 的 SQL；Api 層輸出 `stored_path` 或附件 URL；TS 用 `innerHTML` 插入使用者輸入
- 同時跑兩個 PHPUnit
- 用 Python heredoc（非 raw string）改 PHP（`\t` 等會被吃掉）；改檔一律用 Edit / Write
- Red agent 動 production code；Green / Refactor agent 動測試（P1-G1 的 TestCase.php、bootstrap.php 例外）
- 在 `.claude/` 以外建立報告型 .md 檔

### 3.6 `.progress.json` 檢查點

路徑：`specs/plan/issue-131-assignment-grading/.progress.json`（由主窗口維護；是否隨階段 commit 由用戶決定）。

**更新時機**：每個 step 結束（含失敗、重試、STOP）；寫入前先讀最新內容再改。

**格式**（計劃要求的 `phase / status / tests_added / tests_total / phpstan_errors / commit` 都保留在每個階段物件的頂層）：

```json
{
  "schema_version": 1,
  "issue": 131,
  "wave": 1,
  "branch": "feat/issue-131-assignment-grading",
  "spec_commit": "2397234f",
  "plan": "specs/plan/issue-131-assignment-grading/implementation-plan.md",
  "blueprint": "specs/plan/issue-131-assignment-grading/tdd-blueprint.md",
  "log_dir": "<主窗口 scratchpad>/issue131（session 專屬，換 session 會消失）",
  "updated_at": "2026-09-17T15:00:00+08:00",
  "current": { "phase": "P1", "step": "P1-RG", "status": "in_progress" },

  "baseline": {
    "measured_at": "2026-09-17T14:40:00+08:00",
    "git_head": "2397234f",
    "git_status": ["?? specs/plan/issue-131-assignment-grading/implementation-plan.md", "?? specs/plan/issue-131-assignment-grading/tdd-blueprint.md"],
    "phpunit": { "tests": 1341, "assertions": 3992, "failures": 0, "errors": 0, "skipped": 11, "incomplete": 0, "risky": 0 },
    "phpunit_group_issue131": 0,
    "phpstan_errors": 96,
    "phpstan_json": "<log_dir>/phpstan-baseline.json",
    "i18n_untranslated": { "zh_TW": 0, "ja": 0 },
    "i18n_baseline_noise": [],
    "eslint": { "errors": 0, "warnings": 0 },
    "type_check_exit": 0,
    "e2e_canary": {
      "admin": { "spec": "01-admin/smoke.spec.ts", "result": "passed" },
      "frontend": { "spec": "02-frontend/008-classroom-chapters.spec.ts", "result": "passed" },
      "integration": { "spec": "03-integration/015-e2e-helper-contract.spec.ts", "result": "passed" }
    }
  },

  "user_decisions": {
    "UC-0_commit_plan_docs": null,
    "DEC-10": null,
    "DEC-11": null,
    "DEC-15": null
  },

  "phases": {
    "P1": {
      "phase": "P1",
      "status": "pending",
      "tests_added": 0,
      "tests_total": null,
      "phpstan_errors": null,
      "commit": null,
      "commit_consent": "pending",

      "red": {
        "dispatches": [{ "id": "P1-R", "agent": "zenbu-powers:test-creator", "name": "p1-r", "attempts": 1, "status": "done" }],
        "manifest": [
          { "file": "tests/Integration/Assignment/AssignmentTablesMigrationTest.php", "kind": "new", "group_count": 7, "suite_delta": 7, "expected": "red" }
        ],
        "guards": [{ "test": "Class::method", "reason": "…" }],
        "interface_contract_ref": "P1-R 回報第 3 段（原文另存於本檔 notes 或 log_dir）",
        "red_gate": { "status": "passed", "at": "…", "group": { "tests": 7, "failures": 5, "errors": 2 }, "full": { "tests": 1348, "failures": 5, "errors": 2 }, "log": "<log_dir>/p1-rg-full.log" },
        "test_sha1": ["<sha1>  tests/Integration/Assignment/AssignmentTablesMigrationTest.php"]
      },

      "green": {
        "dispatches": [{ "id": "P1-G1", "agent": "general-purpose", "name": "p1-g1", "owned_tests": ["AssignmentTablesMigrationTest"], "attempts": 1, "status": "done", "check": "passed" }],
        "green_gate": { "status": "passed", "at": "…", "group": { "tests": 7, "result": "OK" } }
      },

      "refactor": { "id": "P1-F", "agent": "general-purpose", "attempts": 1, "status": "done" },

      "phase_gate": {
        "status": "passed",
        "at": "…",
        "group": { "tests": 7, "failures": 0, "errors": 0, "skipped": 0, "incomplete": 0, "risky": 0 },
        "full": { "tests": 1348, "failures": 0, "errors": 0, "skipped": 11 },
        "phpstan_errors": 96,
        "phpcs_errors": 0,
        "i18n_untranslated": { "zh_TW": 0, "ja": 0 },
        "languages_changed": 0,
        "e2e": null,
        "extras": { "lc_check": "clean" },
        "tests_sha1_unchanged": true
      },

      "blocker": null,
      "notes": []
    },
    "P2": { "phase": "P2", "status": "pending" },
    "P3": { "phase": "P3", "status": "pending" },
    "P4": { "phase": "P4", "status": "pending" },
    "P5": { "phase": "P5", "status": "pending" },
    "P6": { "phase": "P6", "status": "pending" },
    "P7": { "phase": "P7", "status": "pending" },
    "P8": { "phase": "P8", "status": "pending" },
    "P9": { "phase": "P9", "status": "pending" }
  }
}
```

`status` 值域：`pending` → `red_dispatched` → `red_gate_passed` → `green_in_progress` → `green_gate_passed` → `refactored` → `gate_passed` → `checkpointed`；另有 `blocked`、`deferred`（僅限用戶同意延後的 E2E 項目）。

**恢復程序（中斷後、換 session 時）**
1. 讀 `.progress.json` 的 `current` 與最後一個 `checkpointed` 階段。
2. `git status --short --untracked-files=all` 與 `git log --oneline -5`，和 progress 記錄比對；對不上就先回報用戶。
3. **上次的綠燈不算證據**：從最後一個 `checkpointed` 的下一階段恢復前，重跑一次 CMD-PU-GROUP + CMD-PU-FULL，數字要等於該階段 `phase_gate` 的記錄。
4. 若中斷在階段中間：
   - 在 `red_dispatched`：看測試檔是否存在，存在就直接跑 RG；不存在就重派 R。
   - 在 `red_gate_passed` / `green_in_progress`：重算 CMD-TEST-HASH 與 progress 的 `test_sha1` 比對，再跑 CMD-PU-GROUP 看哪些 owned tests 已綠，從第一個未完成的 G 繼續。
   - 在 `green_gate_passed` / `refactored`：直接跑 PG。
5. `log_dir` 遺失時：PHPStan 基線 JSON 以 `git stash` 以外的方式重建有困難 → 只在 PHPStan 超標時才需要；屆時回報用戶決定是否在乾淨狀態重建。

### 3.7 用戶確認點（UC）

| ID | 時機 | 問什麼 | 阻擋 |
|----|------|--------|------|
| UC-0 | P0 結束 | (1) 是否先 commit 計劃與藍圖（`docs(specs): Issue #131 實作計劃與 TDD 藍圖`）；(2) **DEC-10**：作業信件立即寄送、模板 `course_ids` 有值只寄該課程（空 = 全部）；(3) **DEC-11**：Email 模板 UI 補兩個作業 trigger 選項，選它們時隱藏章節選擇 / 觸發條件 / 延遲寄送；(4) **DEC-15**：課程作業總覽放在教室作業區塊頂部、可收合 | 不擋 P1–P4；DEC-10 擋 P5-R，DEC-11 擋 P7-R，DEC-15 擋 P8-R（未回答時到該點再問一次並等待） |
| UC-1 ~ UC-8 | 各階段 CK | 是否 commit 本階段變更（附建議訊息，見各階段）。提醒：不 commit 則無法精確回退到該檢查點 | 不擋下一階段 |
| UC-3 附帶 | P3-CK | 提示可選擇喚醒 `@zenbu-powers:security-reviewer` 審上傳 / 下載 / 受保護目錄（opt-in，不自動派） | 不擋 |
| UC-lint | P7 任一時刻 | ESLint / 格式工具若改到 P7 範圍外、且在基線時是乾淨的檔案：列出清單，問用戶是否保留 | 擋 P7-PG |
| UC-e2e | E2E 環境阻擋耗盡 | 是否把 016 的該部分延到 P8（progress 標 `deferred`） | 擋該階段 PG |
| UC-9 | P9 結束 | 最終 commit 分組、分支處置（可用 `/zenbu-powers:finishing-branch`） | — |

commit 訊息慣例：Conventional Commits、繁中描述；測試與實作可依 `zenbu-powers:git-commit` 拆分；結尾附主窗口當下的 attribution 規則。

---

## 4. 共用 prompt 區塊

> 主窗口派發時把 `{{S-XXX}}` 替換成下列區塊全文。區塊內路徑皆相對於專案根目錄 `C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-course`，memory 路徑為絕對路徑。

### S-CONTEXT

```text
【專案與分支】
- 專案根目錄：C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-course
- 分支：feat/issue-131-assignment-grading；規格 commit 2397234f
- 功能：Power Course「作業與作業批改」Issue #131 第一波
- 計劃：specs/plan/issue-131-assignment-grading/implementation-plan.md（下稱「計劃」）
- 業務決策（不可推翻）：specs/clarify/2026-09-17-1415-issue131-assignment-grading.md 的 D1–D29
- 範圍模式 HOLD SCOPE：只做 feature 已寫的行為。發現需要 feature 未涵蓋的業務行為 → 停下來回報，不要自行補規則。
- 統一使用繁體中文回報；程式碼註解繁中、技術名詞英文。

【全流程禁止】
- commit、push、git stash、git checkout --、git reset、git clean、開 PR
- plugin.php 的 init() 出現 'lc'；手改 languages/*.po；中文 msgid
- 同時跑兩個 PHPUnit；用 Python heredoc 改 PHP（改檔一律用 Edit / Write）
- 在專案內建立報告型 .md 檔（回報直接寫在最後訊息）

【命令注意】
- 你在 Windows + Git Bash。以 / 開頭的參數會被 MSYS 轉成 Windows 路徑（例如 --filter '/X/'），不要用；要看單一 class 結果就跑 --group issue-131 --testdox 然後讀該 class 區塊。
- PHPUnit 指令：npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit …（-- 不能省）
- npx wp-env run 的 exit code 只可信 0 / 非 0；計數以輸出摘要行（OK (…) 或 Tests: …）為準。
- PHPUnit 9 對 incomplete / skipped / risky 也會 exit 0，要看摘要行。
```

### S-RED-PHP（test-creator，PHPUnit）

```text
【你的角色】紅燈測試作者。只寫測試，不寫、不改任何 production code。

【載入】
- Skill：zenbu-powers:test-creation-playbook
- Skill：zenbu-powers:aibdd-auto-tdd → 只 Read references/red/php.md，取「test method 結構、Given/When/Then 寫法」。
  覆寫：該檔第 2 步「建立 Model / Repository / Service stub」本專案不做——你不得在 inc/ 建立任何檔案。

【必讀（開工前讀完）】
- .claude/CLAUDE.md、.claude/rules/wordpress.rule.md、.claude/rules/i18n.rule.md
- tests/Integration/README.md、tests/Integration/TestCase.php、tests/bootstrap.php、phpunit.xml
- tests/Integration/Chapter/ChapterApiNocacheHeadersTest.php（nocache 靜態檢查寫法）
- tests/Integration/AccessPass/ExpiringNotifierTest.php、tests/Integration/AccessPass/MigrationTest.php、tests/Integration/AccessPass/CPTStructureTest.php
- specs/features/assignment/句型.md（句型 → helper 對照）
- 計劃 §3、§4（DEC-01～18）、§4.1、§9 開頭「通用 Red → Green → Refactor 切法」、§11、§12
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\feedback_verification_lies.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_test_baseline_202608.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_wp_slash_symmetry.md

【硬性規則】
1. 一個 feature Example 一個 test method；Scenario Outline 用 @dataProvider。
2. 新測試類別：class docblock 同時寫 @group assignment 與 @group issue-131（寫在檔案 docblock 無效）。
   修改既有測試類別：只在你新增 / 修改的方法 docblock 加 @group issue-131，不改 class 的 group。
3. 新檔放 tests/Integration/Assignment/，檔名以 Test.php 結尾（抽象基底不要以 Test.php 結尾）。
4. 權限相關測試一律走 rest_get_server()->dispatch()（直呼 callback 會跳過 permission_callback，等於沒測）。REST namespace 是 /power-course（見 specs/api/api.yml servers）。
5. 斷言 zh_TW 譯文字面值（= feature 文字）；不要用 __() 包期望值（同義反覆，.po 清空也會綠）。
   「權限不足」斷言 status 403 + code rest_forbidden；「必須登入 / 您尚未登入」斷言 401 + rest_not_logged_in（DEC-01）。
6. 紅燈原因只能是：production class / method / 常數不存在的 Error、路由不存在造成的 status 不符（rest_no_route）、斷言不符。
   不得是：ParseError、測試類別自己的 TypeError / 未定義方法、DB 連線錯、整個檔案載入就 fatal。
7. 不得在 class 宣告層級引用尚未存在的 production 符號（extends / implements production class、class 常數或屬性預設值引用 production 常數）；只能在方法內引用，否則載入即 fatal、拖垮全套。
8. 不用 markTestIncomplete / markTestSkipped 當骨架（PHPUnit 9 會 exit 0 假綠）。
9. wp-phpunit 在測試進行中把 CREATE TABLE / DROP TABLE 改寫成 TEMPORARY；WP::is_table_exists() 看不到 temporary table。表 / 欄位 / 索引的斷言用 DESCRIBE、SHOW INDEX FROM。
10. 時間：用基底的 set_now()（P2 起）；is_course_ready() / is_expired() 用 time()，相關 Example 用相對時間（R-06）。
11. filter / action 在 tear_down 移除；自訂表資料在 tear_down 清；不留 static 狀態；檔案系統產物（上傳測試）在 tear_down 刪除，建議以 power_course_assignment_storage_dir filter 導到暫存目錄（驗預設路徑的那一條除外，驗完自行清）。
12. 修改既有測試時不得改變既有斷言的語意（計劃明文要求的除外，並在回報標出）。
13. 實作前就會綠的測試（回歸守門）必須在回報中逐一申報並說明原因；未申報的綠燈會被退回。
14. 介面名稱一律取自計劃 §5.1、specs/api/api.yml、specs/entity/erm.dbml、feature；找不到出處才自己決定，並在回報標「test-creator 決定」。

【回報前自我驗證（貼輸出原文）】
- 每個新增 / 修改的測試檔：php -l
- npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --testdox
  確認：沒有 No tests executed、沒有 Fatal / ParseError；本階段測試為紅（申報的守門除外）；先前階段的測試仍全綠。
- git status --short --untracked-files=all（只能出現測試檔變更）
```

### S-RED-E2E（test-creator，Playwright）

```text
【你的角色】E2E 紅燈測試作者。只寫 spec，不改 production code、不改 plugin.php、不改 tests/e2e/helpers 的既有行為（新增 helper 函式可以，但要在回報列出）。

【載入】Skill：zenbu-powers:test-creation-playbook

【必讀】
- .claude/rules/e2e-testing.rule.md、.claude/rules/i18n.rule.md
- tests/e2e/README.md、tests/e2e/playwright.config.ts、tests/e2e/global-setup.ts、tests/e2e/global-teardown.ts
- tests/e2e/helpers/api-client.ts、tests/e2e/helpers/frontend-setup.ts（loginAs、ensureFrontendTestData）、tests/e2e/helpers/lc-bypass.ts
- 以學員身分操作的既有 spec 參考：02-frontend/009-finish-chapter.spec.ts、02-frontend/013-access-denied-expired.spec.ts、01-admin/settings-ai-tab-role-gate.spec.ts
- specs/api/api.yml（tag Assignments）、對應 feature、對應 specs/ui/*.md
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_frontend_verify_playbook.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_admin_spa_browser_verify.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_site_url.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\feedback_verification_lies.md

【硬性規則】
1. 資料準備走 REST（API client），不走 UI；站台沒有 /wp/v2/users，建使用者用既有 helper 的做法。
2. getNonceFromPage 要 required；hook 的 timeout 在 hook 內 test.setTimeout() 設定。
3. 有順序依賴用 test.describe.serial()；afterAll 清掉自己建的資料（附件實體檔不刪，D9 / D16）。
4. 斷言 zh_TW 可見文字字面值。
5. 不斷言「直連受保護目錄的檔案會被擋」（本機是 Nginx，R-04）；只斷言 API 回應不含 url / stored_path。
6. LC bypass 由 global-setup / teardown 自動處理；你不得手動改 plugin.php。每次跑完執行：
   git grep -n "'lc'[[:space:]]*=>" -- plugin.php   （必須無輸出）
   ls plugin.php.e2e-backup                        （必須不存在）
   若殘留：diff plugin.php.e2e-backup plugin.php，只差 lc 行才 cp 備份回去並刪備份；否則停下回報。禁止 git checkout -- plugin.php。
7. 紅燈原因必須是「功能不存在」（元素找不到、路由 404、回應欄位不符），不能是登入 / nonce / 憑證 / confirm_admin_email 攔截頁 / license gate。

【回報前自我驗證（貼輸出原文）】
- cd tests/e2e && npx playwright test <canary spec> <你的 spec> --reporter=list
  canary 必須綠；你的每個 test 必須紅，逐一列出失敗訊息第一行並判斷是否為「功能不存在」。
- 上面第 6 點的 LC 檢查輸出
- git status --short --untracked-files=all
```

### S-GREEN-PHP（general-purpose，PHP 實作）

```text
【你的角色】WordPress / PHP 8 後端工程師（本專案沒有 wordpress-master，由你擔任）。用最小實作讓指定的紅燈測試變綠。

【載入】
- Skill：zenbu-powers:aibdd-auto-tdd → Read references/green/php.md（核心循環、WordPress DB 儲存模式）
- Skill：wordpress-i18n

【必讀】
- .claude/CLAUDE.md、.claude/rules/wordpress.rule.md、.claude/rules/i18n.rule.md
- 計劃：§4（DEC）、§4.1（msgid 對照）、§5.1、§6、§7、§12，以及本 dispatch 指定的階段段落
- 對應 feature、specs/features/assignment/句型.md、specs/entity/erm.dbml（assignments 一節起）、specs/api/api.yml（tag Assignments）、specs/clarify/2026-09-17-1415-issue131-assignment-grading.md
- 參考實作（業務行為有疑問時以此為準）：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\
- 對照程式：inc/classes/Resources/AccessPass/（Resource 結構、Core/Loader.php 的 maybe_upgrade 與 migrate_*、ExpiringNotifier）、inc/classes/Resources/Chapter/Core/Api.php 約 340 行（線性觀看檢查）、inc/classes/Resources/Chapter/Service/Progress.php、inc/classes/PowerEmail/Resources/Email/Trigger/At.php（access_pass 排信）
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_wp_slash_symmetry.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\feedback_verification_lies.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_test_baseline_202608.md

【硬性限制】
- 命名空間 J7\PowerCourse\Resources\Assignment\…；既有 Resources\Teacher\Service\Assignment 是「講師指派」，同檔需要兩者時用 use … as（R-01）
- 類別 final、declare(strict_types=1)、繁中 docblock
- REST callback 第一個語句 \nocache_headers();
- SQL 一律 $wpdb->prepare()；$wpdb->insert / update 不 wp_slash；wp_insert_post / wp_update_post / update_post_meta 要 wp_slash
- Api 層不得輸出 stored_path 或附件 URL
- 全域常數一律 defined() 守門（phpstan.neon 忽略 Constant not found，拼錯抓不到）
- 用 array_key_exists 區分「key 不存在」與「值為 null」，不要用 ??
- 攔寄信用 pre_wp_mail，不是 wp_mail filter
- 不得修改任何 tests/** 檔案（本 dispatch 明文列出的例外除外）。若你認為測試與 feature 矛盾：停下，回報「測試檔:行」與「feature 檔:行」原文，不要改測試、不要遷就寫出違反 feature 的實作。
- 介面契約（主窗口貼在下方）是綁定的：class FQCN、方法簽名、常數、路由、回應形狀照做。

【固定順序（每一輪綠燈循環都一樣）】
寫碼 → 新字串加進 scripts/i18n-translations/manual.json（每筆 msgid + msgstr_zh_TW + msgstr_ja；_x 的 context 放 "msgctxt" 鍵，"context" 鍵只是來源註記；先 grep 去重，msgid 已存在但譯文不同改用 _x）→ pnpm run i18n:build → 跑測試
PHPUnit 斷言的是 zh_TW 譯文，沒重跑 i18n:build 一定紅。

【回報前自我驗證（貼輸出原文）】
- php -l 所有新增 / 修改的 PHP 檔
- npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --testdox
  你負責的 class 全部 ✔；其他 class 的狀態和派發時一致（還沒輪到的仍紅、先前的仍綠）；摘要行無 Incomplete / Skipped / Risky
- 本 dispatch 指定的受影響既有目錄：vendor/bin/phpunit tests/Integration/<Dir>
- pnpm run i18n:build 輸出中 zh_TW / ja 的 total / untranslated 行
- git status --short --untracked-files=all
```

### S-REFACTOR-PHP（general-purpose，Refactor）

```text
【你的角色】同階段的 PHP 工程師，做 Refactor：在測試保持全綠的前提下改善結構。不新增行為。

【載入】Skill：zenbu-powers:aibdd-auto-tdd → Read references/refactor/php.md 與 references/refactor/code-quality-core.md

【必讀】S-GREEN-PHP 的必讀清單全部，外加本階段 Green 回報（主窗口貼在下方）與 git status 列出的所有變更檔。

【通用 Refactor 項目】
- final class、declare(strict_types=1)、繁中 docblock（含 @param / @return / @throws）
- 重複驗證 / 查詢抽共用；每個方法 < 50 行為目標
- 不得改測試；不得改變任何對外行為（路由、回應形狀、錯誤訊息、hook 名稱）

【Lint 順序（分開跑，不要用 pnpm run lint:php）】
1. vendor/bin/phpcbf <變更的 PHP 檔>        （有修東西會 exit 1，屬正常）
2. vendor/bin/phpcs <變更的 PHP 檔>          （0 error）
3. composer run phpstan                      （Found N errors，N ≤ 主窗口給的基線）
4. 若有改字串：manual.json → pnpm run i18n:build
5. npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --testdox（全綠）
6. npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit（全套 0 failure / 0 error）

【回報】S-REPORT-GREEN 格式，並加一段「重構前後差異摘要」與 phpcbf / phpcs / phpstan 輸出摘要行。
```

### S-GREEN-REACT（react-master，P7）

```text
【你的角色】React 18 / TypeScript 前端工程師。讓指定的 E2E spec 變綠。

【載入】Skill：zenbu-powers:react-coding-standards、zenbu-powers:tanstack-query、wordpress-i18n

【必讀】
- .claude/CLAUDE.md、.claude/rules/react.rule.md、.claude/rules/i18n.rule.md、.claude/rules/e2e-testing.rule.md
- 計劃 §5.2、§9 P7 全段、§12 R-19、R-20；DEC-11
- specs/ui/章節作業管理.md、specs/ui/作業批改頁.md、specs/api/api.yml（tag Assignments）、對應 feature（回應欄位）
- 參考實作：
  C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\zb-course\submissions\SubmissionsManager.tsx
  C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\zb-course\lessons\[lessonId]\assignments\AssignmentsManager.tsx
  C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\zb-course\courses\[id]\AssignmentsModal.tsx
- 對照程式：js/src/components/chapters/Edit.tsx、js/src/components/course/SortableChapters/、js/src/components/user/UserTable/index.tsx（window.open + _wpnonce 下載）、js/src/components/emails/SendCondition/
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_admin_spa_browser_verify.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_frontend_assets_build.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_frontend_verify_playbook.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_antd_toolkit_style_css.md

【硬性限制】
- {items,total} + limit/offset 不合 Refine 預設分頁 → 用 useCustom / useCustomMutation；禁止直接 fetch
- 所有字串用 @wordpress/i18n，text domain 'power-course'；含變數用 sprintf；禁止 i18next / react-intl
- 避免 any；型別放 types/；路徑別名 @/
- 不得修改 E2E spec 與任何 PHP。若後端 API 不足以完成 UI：停下回報缺什麼（端點、欄位），主窗口決定是否派 PHP 修復
- ESLint 只對你改的檔案帶 --fix：npx eslint <你的檔案> --fix；不要跑 pnpm run lint:ts（會 --fix 全部檔案）

【固定順序】
寫碼 → manual.json（msgstr_zh_TW + msgstr_ja；_x 用 msgctxt 鍵）→ npx eslint <你的檔案> --fix → pnpm run type-check → pnpm run build（含 i18n:build，產出 js/dist，並確認 js/dist/vite-dev-server.json 不存在）→ E2E

【回報前自我驗證（貼輸出原文）】
- pnpm run type-check 的結尾輸出與 exit code
- pnpm run build 的結尾輸出與 exit code；i18n 的 zh_TW / ja untranslated 行
- cd tests/e2e && npx playwright test 01-admin/smoke.spec.ts <你負責的 spec> --reporter=list，**連跑兩次**，兩次摘要行
- 每次 E2E 後：git grep -n "'lc'[[:space:]]*=>" -- plugin.php（無輸出）、ls plugin.php.e2e-backup（不存在）；殘留時依 S-RED-E2E 第 6 點處理，禁止 git checkout -- plugin.php
- git status --short --untracked-files=all
```

### S-REPORT-RED（Red 回報格式）

```text
【回報格式（缺一段就會被退回）】
1. 測試清單（表格）：檔案 | 新增 / 修改 | class | 新增或修改的 method | data set 數 | group 計數 | 全套增量 | 預期紅 / 綠 | 對應 feature 檔與 Example 名稱
   最後一列給合計（group 計數合計、全套增量合計、預期紅燈數合計）。
2. 回歸守門清單：Class::method（含 data set 名稱）| 為什麼實作前就綠 | 它守住的回歸是什麼
3. 介面契約（Green 必須照做）：production class FQCN、方法簽名、常數名、REST route + HTTP method、request / response 形狀、meta key、hook / filter 名稱、DB 欄位。每條註明出處（計劃 §x / api.yml / erm.dbml / feature），自行決定的標「test-creator 決定」。
4. 自我驗證輸出原文：php -l、--group issue-131 --testdox 的摘要行與本階段各 class 的 ✔ / ✘ 明細、（E2E）兩個 spec 的執行摘要與 LC 檢查
5. git status --short --untracked-files=all 原文
6. 疑義：feature 與計劃不一致處、無法寫成自動化測試的 Example（附原因與替代驗證方式）
```

### S-REPORT-GREEN（Green / Refactor 回報格式）

```text
【回報格式】
1. git status --short --untracked-files=all 原文
2. 新增 / 修改的 msgid：msgid | msgctxt | zh_TW | ja；以及 i18n:build 的 zh_TW / ja total / untranslated 行
3. 測試輸出原文：--group issue-131 --testdox 的摘要行 + 你負責的 class 明細；受影響既有目錄的摘要行；（前端）E2E 兩次摘要行與 LC 檢查
4. 與介面契約或計劃的偏離（應為空；若有，逐條說明原因）
5. 未解問題、發現的規格缺口（只回報，不自行補規則）
```

---

## 5. P0：前置基線（主窗口親跑，無派發）

| Step | 動作 | 判準 / 記錄 |
|------|------|------------|
| P0-1 | `mkdir -p "$LOG"`；`cd "$PC" && printenv GITHUB_ACTIONS; git rev-parse --abbrev-ref HEAD; git log -1 --format=%h; git status --short --untracked-files=all` | 分支正確；HEAD = `2397234f`；未追蹤只有 `specs/plan/issue-131-assignment-grading/` 下的計劃與藍圖。其他變更 → 先問用戶 |
| P0-2 | `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --version` | 印出 PHPUnit 9.x。失敗 → `npx wp-env start`（環境修復最多 2 次，再不行問用戶） |
| P0-3 | CMD-PU-FULL（TAG=`p0-full`）＋ CMD-PU-GROUP（TAG=`p0-group`） | 全套 Failures 0、Errors 0，記錄 Tests / Assertions / Skipped / Incomplete / Risky 實際值（預期 1341 / 3992 / 11）。**數字不同但全綠 → 以實測為基線並記 note；有任何紅 → STOP 問用戶**（基線不綠就無法歸因）。group 應為 `No tests executed!`（0 支） |
| P0-4 | CMD-PHPSTAN（TAG=`p0`）＋ `vendor/bin/phpstan analyse --memory-limit=3072M --error-format=json > "$LOG/phpstan-baseline.json" 2>/dev/null` | 記錄錯誤數（預期 96）。> 96 → 回報用戶，由用戶決定上限 |
| P0-5 | CMD-I18N（TAG=`p0`） | 記錄 zh_TW / ja untranslated。若 `languages/` 因此出現變更（沒有改程式碼卻有 diff），把檔名記進 `baseline.i18n_baseline_noise` |
| P0-6 | CMD-ESLINT + CMD-TSC（TAG=`p0`）＋ CMD-DIST | 記錄 eslint errors / warnings、type-check exit、dist 模式 |
| P0-7 | CMD-E2E（TAG=`p0-canary`，只跑一次）：`01-admin/smoke.spec.ts 02-frontend/008-classroom-chapters.spec.ts 03-integration/015-e2e-helper-contract.spec.ts`，之後 CMD-LC | 三支 canary 結果；不綠的依 memory 五道牆排查（環境修復最多 2 次），仍不綠就換同 project 另一支既有 spec 並記錄；CMD-LC 必須乾淨（**P1 之前**必須乾淨，否則 P1 改 plugin.php 後還原會出事） |
| P0-8 | 建立 `.progress.json`（§3.6 格式，填 baseline） | — |
| P0-9 | **UC-0** | 見 §3.7；回答寫入 `user_decisions`。不等回答即可進 P1 |

---

## 6. P1：資料表與 DB 版本升級

- 計劃：§9 P1；DEC-04；R-03
- 本階段派發 3 次：P1-R → P1-G1 → P1-F

### P1-R（派發）

| 欄位 | 值 |
|------|----|
| subagent_type | `zenbu-powers:test-creator` |
| name | `p1-r` |
| description | `P1 紅燈：作業資料表遷移測試` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P1（資料表與 DB 版本升級）的紅燈測試。

{{S-RED-PHP}}

【本階段必讀】
- 計劃 §0、§4 DEC-04、§5.1「修改」表中 plugin.php / AbstractTable.php / TestCase.php 三列、§9 P1 全段（含 DDL 要點）、§12 R-03、R-08
- specs/entity/erm.dbml 的 pc_assignment_submissions / pc_assignment_attachments / pc_assignment_messages
- inc/classes/AbstractTable.php（DB_VERSION_OPTION = pc_db_version、CURRENT_DB_VERSION、maybe_upgrade、install、create_*_table）、plugin.php（既有 *_TABLE_NAME 常數、activate()）——只讀
- tests/bootstrap.php 的 _power_course_create_tables()——只讀

【要寫的測試】tests/Integration/Assignment/AssignmentTablesMigrationTest.php
1. 三張表存在，欄位、型別、索引符合計劃 P1「DDL 要點」（表名取 Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME / ASSIGNMENT_ATTACHMENTS_TABLE_NAME / ASSIGNMENT_MESSAGES_TABLE_NAME）
2. uq_assignment_submissions_owner 真的擋重複：同 (assignment_id, user_id) 第二筆 insert 失敗、筆數仍為 1
3. pc_db_version = 1.1.0 時呼叫 AbstractTable::maybe_upgrade() → 版本變 1.2.0、三張表可用；再呼叫一次不報錯（冪等）
4. 升級不影響既有 6 張表的既有列
5. Plugin::activate() 之後三張表可用

【本階段特有陷阱（要實驗，不要推論）】
- Green 之後，tests/bootstrap.php 會在 transaction 外把三張表建成真實表（主窗口已決定 ADJ-05），而測試進行中的 DROP TABLE 會被改寫成 DROP TEMPORARY TABLE。
  請在回報說明：你的每個測試在「表是真實表」與「表不存在」兩種狀態各會怎樣；不得因為表已存在就永遠綠（例如「升級會補建」要能在補建邏輯被拿掉時變紅）。
- 不要改 tests/Integration/TestCase.php、tests/bootstrap.php（那是 Green 的工作；在 Red 改會讓全套因方法不存在而紅）。
- 紅燈形式必須是單一測試的 Error / Failure（例如 Undefined constant J7\PowerCourse\Plugin::ASSIGNMENT_…）。

【預期】全部紅；「既有列不受影響」這類在實作前就成立的測試，請申報為回歸守門。

{{S-REPORT-RED}}
```

### P1-RG：Red Gate（主窗口親跑）

1. CMD-GIT-SCOPE：只有 `tests/Integration/Assignment/**` 新增。
2. CMD-PHPL：manifest 所有檔案。
3. CMD-PU-GROUP（TAG=`p1-rg`）：
   - Tests = manifest group 計數合計
   - Failures + Errors = manifest 預期紅燈數；✔ 的測試全部在守門清單
   - 無 `No tests executed`、Fatal、ParseError；Incomplete / Skipped / Risky = 0
   - 逐一讀失敗訊息：屬於 S-RED-PHP 第 6 點允許的原因
4. CMD-PU-FULL（TAG=`p1-rg`）：Tests = 基線 + 全套增量；Failures + Errors = 預期紅燈數；`N)` 清單中的測試全部屬於 manifest。
5. CMD-TEST-HASH 記錄（TAG=`p1-rg`）。
6. progress：`red_gate_passed`，寫 manifest、guards、介面契約、sha1。

### P1-G1（派發）

| 欄位 | 值 |
|------|----|
| subagent_type | `general-purpose` |
| name | `p1-g1` |
| description | `P1 綠燈：三張作業表與 DB 1.2.0` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P1 綠燈：讓 AssignmentTablesMigrationTest 全綠。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §4 DEC-04、§5.1「修改」表、§9 P1 全段（DDL 要點）、§12 R-03；inc/classes/AbstractTable.php；plugin.php；tests/Integration/TestCase.php；tests/bootstrap.php；inc/classes/Resources/AccessPass/Core/Loader.php

【紅燈回報（主窗口貼入原文：測試清單、回歸守門、介面契約）】
{{P1-R 回報第 1～3 段}}

【你負責的測試】tests/Integration/Assignment/AssignmentTablesMigrationTest.php 全部

【本 dispatch 允許修改的測試相關檔（唯一例外）】
- tests/Integration/TestCase.php：ensure_tables_exist() 與 clean_custom_tables() 補三張表
- tests/bootstrap.php：_power_course_create_tables() 補呼叫三個 create_assignment_*_table()（讓測試 DB 裡是真實表，與既有 6 張表一致）

【實作步驟】
1. plugin.php：新增 ASSIGNMENT_SUBMISSIONS_TABLE_NAME、ASSIGNMENT_ATTACHMENTS_TABLE_NAME、ASSIGNMENT_MESSAGES_TABLE_NAME 常數；activate() 補建三張表。不得碰 init() 的參數。
2. inc/classes/AbstractTable.php：create_assignment_submissions_table()、create_assignment_attachments_table()、create_assignment_messages_table()；install() 補呼叫；CURRENT_DB_VERSION → 1.2.0；docblock 版本紀錄。
   dbDelta 格式：每欄一行、PRIMARY KEY 與 (id) 之間兩個空格、JSON 欄位用 longtext。DDL 以計劃 P1「DDL 要點」為準，這就是最終 schema。
3. 上述兩個測試相關檔。
4. 本階段預期沒有使用者可見字串；若有新增 __()，照固定順序處理 i18n。

【注意】create_*_table() 表存在就早退。若你在第一次跑測試之後又改了 DDL，tests DB 裡的舊表不會更新——停下回報主窗口，不要自行 DROP。

【受影響既有目錄】TestCase.php 與 bootstrap.php 被所有測試共用 → 回報前必跑全套：
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit（0 failure / 0 error）

{{S-REPORT-GREEN}}
```

### P1-GG：Green Gate（主窗口親跑）

1. CMD-TEST-HASH 驗證（對 `p1-rg`）。
2. CMD-GIT-SCOPE：變更只在 `plugin.php`、`inc/classes/AbstractTable.php`、`tests/Integration/TestCase.php`、`tests/bootstrap.php`（＋可能的 i18n 檔）。
3. CMD-LC：乾淨；`git diff -- plugin.php` 只有常數與 `activate()`。
4. CMD-PU-GROUP（TAG=`p1-gg`）：`OK (` 且 Tests = P1-RG 的 group Tests；無 `OK, but`。

### P1-F（派發）

| 欄位 | 值 |
|------|----|
| subagent_type | `general-purpose` |
| name | `p1-f` |
| description | `P1 重構：建表方法共用骨架` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P1 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P1-G1 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- 三個 create_assignment_*_table() 的共用骨架（表存在就早退、charset、require upgrade.php、try/catch）抽 private helper
- 重構不得改變 DDL 字串的語意（欄位、型別、索引、預設值一字不差）；若必須改，停下回報

{{S-REPORT-GREEN}}
```

### P1-PG：Phase Gate（主窗口親跑，計劃 §9 關卡）

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-GROUP（`p1-pg`） | `OK (`；Tests = P1-GG |
| 2 | CMD-PU-FULL（`p1-pg`） | Failures 0、Errors 0；Tests = 基線 + P1 全套增量；Skipped / Incomplete / Risky = 基線值 |
| 3 | CMD-PHPSTAN（`p1-pg`） | ≤ 基線 |
| 4 | CMD-PHPCS（`p1-pg`） | 0 error |
| 5 | CMD-I18N（`p1-pg`） | untranslated ≤ 基線（本階段無新字串，`languages/` 不應有非雜訊變更） |
| 6 | CMD-TEST-HASH 驗證、CMD-LC | 乾淨 |
| 7 | 手動核對 | `inc/classes/AbstractTable.php` 的 `CURRENT_DB_VERSION` = `1.2.0`；三個 DDL 與計劃一致 |

### P1-CK

- progress：`phase_gate` 全部數字、`tests_added`、`tests_total`、`phpstan_errors`，status `checkpointed`。
- **UC-1**：是否 commit。建議：`feat(db): 新增作業三張自訂表並升級 DB 版本至 1.2.0`、`test(assignment): 新增作業資料表遷移測試`。

---

## 7. P2：作業出題（CPT、CRUD、排序、查詢、章節連動）

- 計劃：§9 P2；DEC-01、02、05、06、12、13、18；R-01、R-05、R-10、R-11、R-12
- 本階段派發 4 次：P2-R → P2-G1 → P2-G2 → P2-F

### P2-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p2-r` | `P2 紅燈：作業 CRUD 與章節連動` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P2（作業出題：CPT、CRUD、排序、查詢、章節連動）紅燈測試。

{{S-RED-PHP}}

【本階段必讀】
- 計劃 §9 P2 全段、§4 DEC-01 / 02 / 05 / 06 / 12 / 13 / 18、§4.1 前 13 條、§6.5、§12 R-01 / R-05 / R-10 / R-11 / R-12
- feature：specs/features/assignment/{建立作業,更新作業,刪除作業,排序作業,查詢章節作業列表}.feature、specs/features/chapter/刪除章節.feature（作業相關 3 條 Rule）、specs/features/course/複製課程.feature（作業相關 1 條 Rule）
- specs/api/api.yml（tag Assignments：GET/POST assignments、GET/PUT/DELETE assignments/{id}、POST assignments/sort）、specs/entity/erm.dbml
- inc/classes/Resources/Chapter/Utils/ 或 ChapterUtils 的 get_flatten_post_ids、get_course_id；CourseUtils::get_all_chapters；inc/classes/Utils/Duplicate.php

【先建共用基底】tests/Integration/Assignment/AssignmentTestCase.php（abstract，繼承 Tests\Integration\TestCase；檔名不以 Test.php 結尾）
- set_up：timezone_string = Asia/Taipei、gmt_offset = ''；tear_down 還原
- set_now('Y-m-d H:i:s')：以站台時區換 timestamp，掛 power_course_assignment_now filter（DEC-02）；tear_down 移除
- create_assignment(array)：wp_insert_post + meta（parent_course_id 由章節解析）
- seed_submission(array)、seed_attachment(array, bool $with_file = true)、seed_message(array)：直接 $wpdb->insert
- dispatch(method, route, params|body)：rest_get_server()->dispatch()
- capture_mail()：pre_wp_mail 攔截並記錄收件人 / 主旨 / 內容
- run_scheduled_actions(string $hook)：取出 pending action 逐筆 do_action_ref_array 後標記完成
- 實作 TestCase 的 abstract configure_dependencies()（可為空）
- 這些 helper 會被 P3～P6 沿用，簽名請穩定

【要寫的測試】（皆在 tests/Integration/Assignment/）
- CreateAssignmentTest.php、UpdateAssignmentTest.php、DeleteAssignmentTest.php、SortAssignmentsTest.php、QueryAssignmentsTest.php：各 feature 全部 Example
- ChapterAssignmentCascadeTest.php：chapter/刪除章節 的 3 條作業 Rule + 「課程還原時作業跟著還原」1 條（DEC-06、R-10）
- CourseDuplicateExcludesAssignmentsTest.php：複製課程不複製作業（預期實作前就綠 → 申報為回歸守門）
- AssignmentCPTStructureTest.php：pc_assignment 的 show_in_rest=false、public=false 等（DEC-05）；有作業時 ChapterUtils::get_flatten_post_ids() 與 CourseUtils::get_all_chapters() 的結果不變（R-12；可能實作前就綠 → 申報）
- AssignmentApiContractTest.php：本階段 6 條路由已註冊；每個 callback 第一個語句是 \nocache_headers()（token_get_all 靜態檢查 inc/classes/Resources/Assignment/Core/Api.php，檔案不存在時要是 Failure 不是 fatal）；非管理員 Alice 403 矩陣。請設計成之後階段容易擴充路由（dataProvider）

【斷言重點】排序 tie-break 依 DEC-13；「章節不存在」依 DEC-18；錯誤訊息用 §4.1 對應的 zh_TW。

{{S-REPORT-RED}}
```

### P2-RG：Red Gate

同 P1-RG 1～6，另加：
- group 中 P1 的測試全部 ✔。
- 守門清單至少包含 `CourseDuplicateExcludesAssignmentsTest`；`AssignmentCPTStructureTest` 的 R-12 部分若為綠也必須在清單內。
- CMD-TEST-HASH 記錄（TAG=`p2-rg`）。

### P2-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p2-g1` | `P2 綠燈一：作業 CPT、CRUD、查詢、API` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P2 綠燈（第 1 / 2 棒）：CPT、CRUD、排序、查詢、管理端 API。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P2 全段、§5.1 新增表中標 P2 的列、§4.1 前 13 條、§6.5；specs/features/assignment/{建立作業,更新作業,刪除作業,排序作業,查詢章節作業列表}.feature；inc/classes/Resources/Loader.php；inc/classes/Resources/AccessPass/Core/{Loader,CPT,Api}.php

【紅燈回報（主窗口貼入原文）】
{{P2-R 回報第 1～3 段}}

【你負責讓它綠的測試】CreateAssignmentTest、UpdateAssignmentTest、DeleteAssignmentTest、SortAssignmentsTest、QueryAssignmentsTest、AssignmentCPTStructureTest、AssignmentApiContractTest
【不歸你（應維持派發時狀態）】ChapterAssignmentCascadeTest（下一棒）、CourseDuplicateExcludesAssignmentsTest（守門，必須維持綠）、P1 的測試（必須維持綠）

【步驟】
0. 在寫第一個 msgid 之前，先在 .claude/rules/i18n.rule.md 的術語表補：作業 Assignment、作業批改 Assignment grading、繳交 Submission / Submit、重新繳交 Resubmit、評語 Feedback、截止時間 Due date、已逾期 Overdue、附件 Attachment、通過率 Pass rate、平均批改時間 Average grading time（計劃 §5.3）
1. Utils/Clock.php（DEC-02）、Utils/AssignmentException.php
2. Core/CPT.php（DEC-05）、Model/Assignment.php（to_array() 對齊 api.yml Assignment）
3. Service/Crud.php：create()、update()（部分更新；合併後再驗 allow_text / allow_file；改掛章節時重算 parent_course_id；DEC-18）、trash()、sort(chapter_id, ids)（(index+1)*10；空陣列不動並回列表）；post_title / post_content 寫入依 slash 對稱規則 wp_slash
4. Service/Query.php：list_by_chapter()、list_by_course()（DEC-13）、get()
5. Core/Api.php：GET/POST assignments、GET/PUT/DELETE assignments/(?P<id>\d+)、POST assignments/sort；每個 callback 第一行 \nocache_headers();；預設權限 manage_options || manage_woocommerce
6. Core/Loader.php（含 plugins_loaded priority 20 掛 AbstractTable::maybe_upgrade，DEC-04）；inc/classes/Resources/Loader.php 註冊 Assignment\Core\Loader::instance()
7. i18n：§4.1 前 13 條（先 grep manual.json 去重）

【受影響既有目錄（回報前跑）】tests/Integration/Chapter、tests/Integration/Course

{{S-REPORT-GREEN}}
```

### P2-G1C：小檢查（主窗口親跑）

1. CMD-TEST-HASH 驗證（對 `p2-rg`）。
2. CMD-PU-GROUP（TAG=`p2-g1c`）：G1 負責的 7 個 class 全部 ✔；`ChapterAssignmentCascadeTest` 維持派發時的 ✘；`CourseDuplicateExcludesAssignmentsTest` 與 P1 測試 ✔。
3. 不符 → §3.4「G：負責的測試仍紅」。

### P2-G2（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p2-g2` | `P2 綠燈二：章節生命週期連動` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P2 綠燈（第 2 / 2 棒）：章節 trash / 還原 / 永久刪除與作業的連動。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §4 DEC-06、§6.5、§12 R-10 / R-11 / R-12；specs/features/chapter/刪除章節.feature、specs/features/course/複製課程.feature；inc/classes/Resources/Chapter/Core/LifeCycle.php（若存在）與 inc/classes/Resources/Course/Core/LifeCycle.php；上一棒已建立的 inc/classes/Resources/Assignment/**（先讀完再動手）

【紅燈回報（主窗口貼入原文）】
{{P2-R 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{P2-G1 回報}}

【你負責讓它綠的測試】ChapterAssignmentCascadeTest
【必須維持綠】P2-G1 負責的 7 個 class、CourseDuplicateExcludesAssignmentsTest、P1 測試

【步驟】
1. Service/SubmissionRepository.php：delete_by_assignment()；Service/MessageRepository.php：delete_by_submission_ids()
2. Core/LifeCycle.php（DEC-06）：
   - transition_post_status（僅 pc_chapter）：publish → trash 時把該章節的 publish 作業 wp_trash_post；trash → 非 trash 時把該章節的 trash 作業改回 publish（涵蓋 wp_untrash_post 與課程還原的 wp_update_post）
   - before_delete_post（pc_chapter）：該章節所有作業 wp_delete_post(force)
   - before_delete_post（pc_assignment）：刪該作業的 messages 與 submissions；附件列與檔案保留
   - wp_untrash_post_status filter：pc_assignment 還原為 publish
   - 連動失敗只 log，不擋章節本身的操作（§7）
3. Core/Loader.php 註冊 LifeCycle
4. 不修改 Utils\Duplicate（白名單本來就不含 pc_assignment；守門測試必須維持綠）

【受影響既有目錄（回報前跑）】tests/Integration/Chapter、tests/Integration/Course

{{S-REPORT-GREEN}}
```

### P2-GG：Green Gate

1. CMD-TEST-HASH 驗證（對 `p2-rg`）。
2. CMD-PU-GROUP（`p2-gg`）：`OK (`；Tests = P2-RG 的 group Tests。
3. CMD-LC 乾淨。

### P2-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p2-f` | `P2 重構：作業驗證與輸出格式` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P2 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P2-G1 回報}}
{{P2-G2 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- Crud 的欄位驗證只保留一份（create / update 共用 validate_fields()）
- Model\Assignment::to_array() 為唯一輸出格式（Api / Query 不得各自組陣列）
- R-01：grep -rn "Teacher\\\\Service\\\\Assignment" inc/classes/Resources/Assignment 必須為空（貼輸出）

{{S-REPORT-GREEN}}
```

### P2-PG：Phase Gate

P1-PG 的 1～6（TAG 換 `p2-pg`；全套 Tests = P1-PG 全套 + P2 全套增量），另加：

| # | 命令 | 判準 |
|---|------|------|
| 5' | CMD-I18N | 本階段有新字串 → `languages/` 7 檔皆有變更；untranslated ≤ 基線 |
| 7 | `cd "$PC" && grep -rn "Teacher\\\\Service\\\\Assignment" inc/classes/Resources/Assignment; echo "GREP_DONE"` | `GREP_DONE` 前無輸出 |
| 8 | `cd "$PC" && git diff --stat -- .claude/rules/i18n.rule.md` | 術語表已補 |

### P2-CK

progress 寫入；**UC-2**：`feat(assignment): 新增作業 CPT、CRUD、排序與章節連動`、`test(assignment): 新增作業出題與章節連動測試`、`docs(i18n): 補作業相關術語`。

---

## 8. P3：學員端（附件上傳 / 下載、繳交、我的作業）

- 計劃：§9 P3；§6.1、§6.2；DEC-08、09、12、16、17；R-04、R-07、R-13～R-17
- 本階段派發 5 次：P3-R1 → P3-R2 → P3-G1 → P3-G2 → P3-F（P3-R2 是 ADJ-04 新增）

### P3-R1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p3-r1` | `P3 紅燈：附件與繳交 PHPUnit` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P3（學員端）PHPUnit 紅燈測試。

{{S-RED-PHP}}

【本階段必讀】
- 計劃 §9 P3 全段、§6.1、§6.2、§4 DEC-08 / 09 / 12 / 16 / 17、§12 R-04 / R-06 / R-07 / R-13～R-17
- feature：specs/features/assignment/{上傳作業附件,下載作業附件,繳交作業,查詢學員作業}.feature、specs/features/student/移除學員課程權限.feature（作業 Rule）
- specs/api/api.yml：/my/assignments、/my/assignments/{id}、/my/assignments/{id}/submit、/my/assignment-attachments、assignment-attachments/{id}/download
- 既有 tests/Integration/Assignment/AssignmentTestCase.php 與 AssignmentApiContractTest.php（擴充，不要重寫）
- inc/classes/Resources/Chapter/Core/Api.php 約 340 行（線性觀看判定）、LinearViewing 類別

【要寫的測試】
- UploadAssignmentAttachmentTest.php：直接測 Service\Attachment::store(user_id, tmp_path, original_name, mime, size)（temp 檔用 tempnam）；50MB 邊界用 size 參數（整 50MB 與 +1 byte），不要真的產生大檔；實體檔在儲存根目錄內、檔名 64 位 hex 且無副檔名、≠ 原檔名；目錄有 .htaccess 與 index.php；回應沒有 url / stored_path；UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE 對應「檔案過大（上限 50MB）」（API 層的轉換函式單獨測，DEC-17）；MIME 白名單以 feature 為準（DEC-16）
- DownloadAssignmentAttachmentTest.php：dispatch() 取 response，斷言 status、Content-Disposition（含原檔名）、Content-Type、X-Content-Type-Options；Cache-Control 用 token_get_all 靜態檢查（R-07）；學員 = 上傳者、管理員 = 附件出現在任一繳交；attachment_id 700 vs 7000 的 LIKE 邊界（R-16）
- SubmitAssignmentTest.php：feature 全部 Example（驗證順序依 §6.1）；「課程尚未開課」「觀看權已到期」用相對時間（course_schedule = time() + DAY_IN_SECONDS、expire_date = time() - DAY_IN_SECONDS）；線性觀看豁免依 current_user_can，要 wp_set_current_user；補「連續繳交兩次只有 1 筆」；學員端作業不存在 / 垃圾桶 → 403「尚未報名此課程」（DEC-12）
- QueryMyAssignmentsTest.php、RemoveStudentKeepsSubmissionsTest.php（後者很可能實作前就綠 → 申報守門）
- AssignmentApiContractTest.php：擴充 5 條路由（學員 4 + 下載 1）與未登入 401 矩陣

{{S-REPORT-RED}}
```

### P3-R2（派發，ADJ-04）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p3-r2` | `P3 紅燈：016 HTTP 契約 E2E（附件）` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P3 的真實 HTTP 契約 E2E 紅燈：tests/e2e/03-integration/016-assignment-http-contract.spec.ts（附件部分）。
PHPUnit 碰不到的三條路徑要在這裡驗：真實 multipart 上傳（is_uploaded_file）、rest_pre_serve_request 串流下載、真實回應標頭。

{{S-RED-E2E}}

【本階段必讀】計劃 §9 P3、§9 P8 中 016 的描述、§6.2、§12 R-04 / R-07 / R-13 / R-14；specs/features/assignment/{上傳作業附件,下載作業附件}.feature；P3-R1 的介面契約（下方）

【P3-R1 介面契約（主窗口貼入原文）】
{{P3-R1 回報第 3 段}}

【要寫的測試（test.describe.serial）】
資料準備：以管理員 REST 建課程 / 章節 / 作業（P2 端點已可用）；建立兩位學員並給其中一位課程觀看權。
1. 學員 multipart 上傳附件成功；回應含 attachment_id / file_name / mime_type / file_size，**不含** url、stored_path
2. 學員下載自己的附件：200；Cache-Control 含 no-cache；Content-Disposition 為 attachment 且含原檔名；X-Content-Type-Options: nosniff；內容與上傳檔一致
3. 另一位學員下載他人附件：403
4. 訪客（未登入）上傳 / 下載：401
（CSV 部分留給 P4-R2 擴充，請預留 describe 區塊結構）

【canary】03-integration/015-e2e-helper-contract.spec.ts

{{S-REPORT-RED}}
```

### P3-RG：Red Gate

1. PHPUnit 部分：同 P1-RG 1～4（TAG=`p3-rg`），P1 / P2 測試全部 ✔；`RemoveStudentKeepsSubmissionsTest` 若綠必須在守門清單。
2. E2E 部分：CMD-E2E（TAG=`p3-rg`，跑一次）`03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts` → canary 綠、016 每個 test 紅且失敗訊息屬「功能不存在」（404 rest_no_route 等）；CMD-LC 乾淨。
3. CMD-TEST-HASH 記錄（TAG=`p3-rg`，清單含 016 spec）。

### P3-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p3-g1` | `P3 綠燈一：附件儲存、上傳、下載` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P3 綠燈（第 1 / 2 棒）：受保護儲存、附件上傳、附件下載串流。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P3 全段、§6.2、§4 DEC-08 / 09 / 16 / 17、§7 中 Attachment / Stream 列、§12 R-04 / R-07 / R-13 / R-14 / R-15 / R-16；specs/features/assignment/{上傳作業附件,下載作業附件}.feature；參考實作 course-assignments.service.ts 的 upload 與 MIME 白名單；inc/classes/Resources/Assignment/**（P2 產物）
另讀（要跑 E2E）：
C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md
C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_frontend_verify_playbook.md

【紅燈回報（主窗口貼入原文）】
{{P3-R1 回報第 1～3 段}}
{{P3-R2 回報第 1～3 段}}

【你負責讓它綠的測試】
- PHPUnit：UploadAssignmentAttachmentTest、DownloadAssignmentAttachmentTest、AssignmentApiContractTest 中「上傳」「下載」兩條路由的資料組
- E2E：03-integration/016-assignment-http-contract.spec.ts 全部
【必須維持派發時狀態】SubmitAssignmentTest、QueryMyAssignmentsTest（下一棒，仍紅）；守門測試與 P1 / P2 測試（綠）

【步驟】
1. Utils/Storage.php（DEC-09；resolve_path() 用 realpath 確保在根目錄內；PC_ASSIGNMENT_STORAGE_DIR 用 defined() 守門；power_course_assignment_storage_dir filter）、Utils/Stream.php（DEC-08 一次性 rest_pre_serve_request 輸出器，輸出前清空 ob_*）
2. Model/Attachment.php、Service/AttachmentRepository.php；SubmissionRepository 補 exists_with_attachment()（LIKE pattern 含結尾逗號，R-16）
3. Service/Attachment.php（DEC-16 副檔名白名單 + finfo 交叉比對；DEC-17 上傳錯誤碼對應；DB INSERT 失敗要刪已搬的檔案）
4. Core/MyApi.php：POST my/assignment-attachments（permission = 已登入，否則 401；API 層做 is_uploaded_file()）
5. Core/Api.php：GET assignment-attachments/(?P<id>\d+)/download（permission = 已登入；callback 內管理員 = manage_options || manage_woocommerce；回傳帶標頭的 WP_REST_Response + 登記串流）
6. i18n：§4.1 上傳 / 下載相關字串

【E2E 執行】
cd tests/e2e && npx playwright test 03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts --reporter=list（連跑兩次）
每次跑完做 LC 檢查（S-RED-E2E 第 6 點的做法），禁止 git checkout -- plugin.php。

【受影響既有目錄】無（新模組）；但回報前跑一次 --group issue-131

{{S-REPORT-GREEN}}
```

### P3-G1C：小檢查

1. CMD-TEST-HASH 驗證（對 `p3-rg`）。
2. CMD-PU-GROUP（`p3-g1c`）：G1 負責的 class / 資料組 ✔；`SubmitAssignmentTest`、`QueryMyAssignmentsTest` 仍 ✘；其餘 ✔。
3. CMD-E2E（`p3-g1c`，跑一次）canary + 016 → 全綠；CMD-LC 乾淨。

### P3-G2（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p3-g2` | `P3 綠燈二：繳交與我的作業` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P3 綠燈（第 2 / 2 棒）：繳交作業、查詢學員作業、線性觀看鎖定。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P3 全段、§6.1（驗證順序與 UPSERT）、§4 DEC-12、§12 R-06 / R-16 / R-17；clarify D7、D8、D10、D13、D25、D27、D29；specs/features/assignment/{繳交作業,查詢學員作業}.feature、specs/features/student/移除學員課程權限.feature；inc/classes/Resources/Chapter/Core/Api.php 約 340 行；上一棒產物 inc/classes/Resources/Assignment/**（先讀完）

【紅燈回報（主窗口貼入原文）】
{{P3-R1 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{P3-G1 回報}}

【你負責讓它綠的測試】SubmitAssignmentTest、QueryMyAssignmentsTest、RemoveStudentKeepsSubmissionsTest（守門，維持綠）、AssignmentApiContractTest 中學員端 3 條路由的資料組
【必須維持綠】P3-G1 負責的全部（含 016 E2E）、P1 / P2 測試

【步驟】
1. Model/Submission.php（attachments JSON decode 成 [{attachment_id, file_name}]）；SubmissionRepository 補 upsert()（INSERT … ON DUPLICATE KEY UPDATE，重交清空 feedback / graded_by / graded_at、status = pending）、find_by_owner()、find()
2. Utils/Utils::can_access_course(course_id, user_id) = CourseUtils::is_avl() && ! CourseUtils::is_expired(wc_get_product(), user_id) && CourseUtils::is_course_ready()
3. Service/Submission.php（§6.1 順序；附件歸屬一次查完）、Service/MyQuery.php（含 my_submission）
4. Core/MyApi.php：GET my/assignments、GET my/assignments/(?P<id>\d+)、POST my/assignments/(?P<id>\d+)/submit
5. 線性觀看：LinearViewing::is_enabled(course_id) && ! LinearViewing::is_exempt(user_id) && LinearViewing::is_chapter_locked(chapter_id, course_id, user_id)，寫法比照 Chapter/Core/Api.php，不另寫解鎖演算法；鎖定訊息沿用既有譯文（D27）
6. i18n：§4.1 繳交相關字串

【受影響既有目錄（回報前跑）】tests/Integration/Student、tests/Integration/Chapter
【E2E】回報前 016 連跑一次確認未被你弄壞，並做 LC 檢查

{{S-REPORT-GREEN}}
```

### P3-GG：Green Gate

1. CMD-TEST-HASH 驗證。
2. CMD-PU-GROUP（`p3-gg`）：`OK (`；Tests = P3-RG group Tests。
3. CMD-LC 乾淨。

### P3-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p3-f` | `P3 重構：繳交驗證拆分與路徑外洩檢查` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P3 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P3-G1 回報}}
{{P3-G2 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- Service\Submission 的形狀驗證拆成小方法（每個 < 50 行）
- 附件歸屬查詢一次撈完（不得逐筆查）
- grep -rn "stored_path" inc/classes/Resources/Assignment/Core 必須為空（貼輸出）
- Refactor 後除了 PHPUnit，還要把 016 E2E 跑一次（含 canary 與 LC 檢查），因為串流與上傳路徑只有它驗得到

{{S-REPORT-GREEN}}
```

### P3-PG：Phase Gate

P2-PG 的 1～6（TAG `p3-pg`），另加：

| # | 命令 | 判準 |
|---|------|------|
| 7 | `cd "$PC" && grep -rn "stored_path" inc/classes/Resources/Assignment/Core; echo "GREP_DONE"` | `GREP_DONE` 前無輸出 |
| 8 | CMD-E2E（`p3-pg`，**兩次**）：`03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts`，每次後 CMD-LC | 兩次都 0 failed、0 flaky；LC 乾淨 |

### P3-CK

progress 寫入；**UC-3**：`feat(assignment): 新增學員附件上傳下載與作業繳交`、`test(assignment): 新增學員端作業測試與 HTTP 契約 E2E`。附帶提示：可選擇喚醒 `@zenbu-powers:security-reviewer` 審上傳 / 下載 / 受保護目錄（WordPress 專案、涉及檔案上傳與權限判斷，屬建議補派；不自動派）。

---

## 9. P4：管理端批改、報表與 CSV 匯出

- 計劃：§9 P4；§6.3；DEC-03、07、08、13、14；R-15、R-18、R-21
- 本階段派發 5 次：P4-R1 → P4-R2 → P4-G1 → P4-G2 → P4-F

### P4-R1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p4-r1` | `P4 紅燈：批改、報表、CSV PHPUnit` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P4（批改、報表、CSV）PHPUnit 紅燈測試。

{{S-RED-PHP}}

【本階段必讀】
- 計劃 §9 P4 全段、§6.3、§4 DEC-03 / 07 / 08 / 13 / 14、§12 R-15 / R-18 / R-21
- feature：specs/features/assignment/{批改作業（寄信以外的 Rule）,查詢作業繳交列表,查詢作業批改統計,查詢有繳交紀錄的課程,查詢作業學員背景資料,匯出單一作業繳交CSV,匯出批改用CSV}.feature
- clarify D18、D19、D21、D22、D23、D26
- inc/classes/Resources/Chapter/Service/Progress.php（is_finished、mark_finished 與其 action）、inc/classes/Utils/Datetime.php（to_site_timezone）
- 參考實作 course-submission-csv.service.ts、course-submission-reporting.service.ts

【要寫的測試】
- GradeAssignmentSubmissionTest.php：狀態寫入、未給 feedback 清空、改判、pass 自動完成章節（did_action('power_course_chapter_finished') 與參數 (201, 100, 2)）、已完成不重複、改判不撤銷、副作用失敗仍成功（掛會丟例外的 power_course_assignment_graded listener）、pass / fail 觸發 power_course_assignment_graded、pending 不觸發（寄信本身留 P5）
- ListAssignmentSubmissionsTest.php（含 DEC-07 訊息計數）、AssignmentSubmissionStatsTest.php（avg_grading_seconds 55800 / 64800 / 35100）、CoursesWithSubmissionsTest.php、AssignmentStudentOverviewTest.php（user_registered seed GMT 2026-01-05 02:00:00，期望 2026-01-05 10:00:00）
- ExportAssignmentSubmissionsCsvTest.php、ExportGradingCsvTest.php：BOM、CRLF、雙引號跳脫、檔名（UTC）、5001 筆只出 5000（單一多列 INSERT，user_id 用不存在的 100000+i，不要建 5001 個 WP user）
  CSV 內容的取得方式要在介面契約寫清楚（建議：內容在 Service\Csv 層取 stream resource 斷言；REST 層斷言 status / 標頭 / 權限）
- 交叉測試：列表與 CSV 對同一組篩選的 ID 順序一致（計劃 P4 成功標準）
- AssignmentApiContractTest.php：擴充 7 條路由

{{S-REPORT-RED}}
```

### P4-R2（派發，ADJ-04）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p4-r2` | `P4 紅燈：016 HTTP 契約 E2E（CSV）` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P4：擴充 tests/e2e/03-integration/016-assignment-http-contract.spec.ts 的 CSV 部分（紅燈）。不得改動 P3 已存在的測試內容。

{{S-RED-E2E}}

【本階段必讀】計劃 §9 P4、§9 P8 中 016 的描述、§4 DEC-03 / 08；specs/features/assignment/{匯出單一作業繳交CSV,匯出批改用CSV}.feature；現有 016 spec 全文

【P4-R1 介面契約（主窗口貼入原文）】
{{P4-R1 回報第 3 段}}

【要寫的測試】
1. 管理員匯出單一作業繳交 CSV：200；body 以 UTF-8 BOM 開頭；換行為 CRLF；Content-Disposition 為 attachment 且檔名符合 feature 規則；Cache-Control 含 no-cache
2. 管理員匯出批改用 CSV：同上
3. 學員呼叫兩個匯出：403；訪客：401

【canary】03-integration/015-e2e-helper-contract.spec.ts

{{S-REPORT-RED}}
```

### P4-RG：Red Gate

同 P3-RG（TAG `p4-rg`）：PHPUnit 新測試紅、先前全綠；E2E 016 中 **P3 的測試仍綠**、新增 CSV 測試紅；CMD-LC 乾淨；CMD-TEST-HASH 記錄。

### P4-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p4-g1` | `P4 綠燈一：批改與報表查詢` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P4 綠燈（第 1 / 2 棒）：批改、繳交列表、統計、有繳交的課程、學員背景資料。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P4 全段、§6.3、§4 DEC-07 / 13 / 14、§7 Grading / Reporting 列、§12 R-18；specs/features/assignment/{批改作業,查詢作業繳交列表,查詢作業批改統計,查詢有繳交紀錄的課程,查詢作業學員背景資料}.feature；inc/classes/Resources/Chapter/Service/Progress.php；inc/classes/Utils/Datetime.php；參考實作 course-submission-reporting.service.ts；P2 / P3 產物 inc/classes/Resources/Assignment/**

【紅燈回報（主窗口貼入原文）】
{{P4-R1 回報第 1～3 段}}

【你負責讓它綠的測試】GradeAssignmentSubmissionTest、ListAssignmentSubmissionsTest、AssignmentSubmissionStatsTest、CoursesWithSubmissionsTest、AssignmentStudentOverviewTest、AssignmentApiContractTest 中對應 5 條路由的資料組
【必須維持派發時狀態】兩支 CSV 測試與交叉測試（下一棒，仍紅）；P1～P3 全部（綠）

【步驟】
1. Service/Grading.php（§6.3）：先 Progress::is_finished()，未完成才 Progress::mark_finished()；副作用外層 catch (\Throwable) → Plugin::logger（D23）；pending 不觸發 power_course_assignment_graded
2. Service/Reporting.php：list_submissions()（status 非法 → all、limit 夾 1–200 預設 50、offset ≥ 0、course 透過作業的 parent_course_id meta JOIN、排除 trash、LEFT JOIN users）；stats()；courses_with_submissions()（count DESC、title ASC）；student_overview()
   **篩選條件請做成可重用的 filter builder**（下一棒 CSV 必須共用，計劃 P4 Refactor 要求）
3. Utils/Utils::resolve_attachment_kind(mime, file_name)、MessageRepository::thread_summary(submission_ids)（一次查完）
4. Core/Api.php：GET assignment-submissions、…/stats、…/courses、…/student-overview、PATCH assignment-submissions/(?P<id>\d+)/grade
5. i18n：批改、狀態標籤（_x('Pending' / 'Passed' / 'Failed', 'assignment submission status')）

【受影響既有目錄（回報前跑）】tests/Integration/Chapter、tests/Integration/ChapterProgress、tests/Integration/Progress

{{S-REPORT-GREEN}}
```

### P4-G1C：小檢查

1. CMD-TEST-HASH 驗證（對 `p4-rg`）。
2. CMD-PU-GROUP（`p4-g1c`）：G1 負責的 ✔；兩支 CSV 測試與交叉測試仍 ✘；其餘 ✔。

### P4-G2（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p4-g2` | `P4 綠燈二：兩種 CSV 匯出` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P4 綠燈（第 2 / 2 棒）：單一作業繳交 CSV、批改用 CSV。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P4 全段、§4 DEC-03 / 08、§12 R-15 / R-21；clarify D26；specs/features/assignment/{匯出單一作業繳交CSV,匯出批改用CSV}.feature；參考實作 course-submission-csv.service.ts；上一棒產物（先讀 Service/Reporting.php 的 filter builder）
另讀（要跑 E2E）：
C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md

【紅燈回報（主窗口貼入原文）】
{{P4-R1 回報第 1～3 段}}
{{P4-R2 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{P4-G1 回報}}

【你負責讓它綠的測試】ExportAssignmentSubmissionsCsvTest、ExportGradingCsvTest、列表 / CSV 順序交叉測試、AssignmentApiContractTest 中 2 條匯出路由的資料組、016 E2E 的 CSV 測試
【必須維持綠】其餘全部（含 016 的附件測試）

【步驟】
1. Service/Csv.php：寫入 php://temp/maxmemory:5242880；BOM、CRLF、跳脫；欄位與順序照 feature；LIMIT 5000；**使用 Reporting 的 filter builder**，不得另寫一份篩選 SQL；日期欄位以 get_gmt_from_date() 轉 Y-m-d\TH:i:s.000\Z（DEC-03）；檔名規則照參考實作（preg_replace('/[^\p{L}\p{N}]+/u', '_', …)、取前 40 字，以參考實作確認）
2. Core/Api.php：GET assignments/(?P<id>\d+)/submissions/export、GET assignment-submissions/export（回傳帶標頭的 WP_REST_Response + Stream 輸出）
3. i18n：CSV 標頭，一律 context 'assignment csv'（manual.json 用 msgctxt 鍵）

【E2E 執行】canary 015 + 016 連跑兩次；每次後 LC 檢查

{{S-REPORT-GREEN}}
```

### P4-GG：Green Gate

1. CMD-TEST-HASH 驗證。
2. CMD-PU-GROUP（`p4-gg`）：`OK (`；Tests = P4-RG group Tests。
3. CMD-LC 乾淨。

### P4-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p4-f` | `P4 重構：列表與 CSV 共用篩選` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P4 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P4-G1 回報}}
{{P4-G2 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- 列表 / 統計 / CSV 共用同一個 filter builder（status、course、assignment、排除 trash），確認沒有第二份篩選 SQL（貼 grep 證據）
- Refactor 後除了 PHPUnit，016 E2E 跑一次（含 canary 與 LC 檢查）

{{S-REPORT-GREEN}}
```

### P4-PG：Phase Gate

P2-PG 的 1～6（TAG `p4-pg`），另加：

| # | 命令 | 判準 |
|---|------|------|
| 7 | CMD-E2E（`p4-pg`，兩次）：`03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts`，每次後 CMD-LC | 兩次都 0 failed、0 flaky |
| 8 | 讀 group log | 交叉測試 ✔ |

### P4-CK

progress 寫入；**UC-4**：`feat(assignment): 新增作業批改、報表查詢與 CSV 匯出`、`test(assignment): 新增批改與匯出測試`。

---

## 10. P5：PowerEmail 觸發（assignment_graded、寄送路徑）

- 計劃：§9 P5；DEC-10；R-09、R-23、R-25
- 前置：**UC-DEC10 已回答**（未回答 → 問用戶並等待）
- 本階段派發 3 次：P5-R → P5-G1 → P5-F

### P5-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p5-r` | `P5 紅燈：作業批改寄信` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P5（PowerEmail 觸發）紅燈測試。

{{S-RED-PHP}}

【用戶對 DEC-10 的決定（主窗口貼入原文）】
{{UC-DEC10 結果}}
（若用戶推翻 DEC-10 的任一部分，以用戶決定為準寫測試，並在回報第 6 段標出與計劃不同處）

【本階段必讀】
- 計劃 §9 P5 全段、§4 DEC-10、§5.1 PowerEmail 相關列、§12 R-09 / R-23 / R-25
- feature：specs/features/assignment/批改作業.feature（寄信 3 條 Rule）、specs/features/email/觸發自動郵件.feature（assignment_graded Rule）
- inc/classes/PowerEmail/Resources/Email/Trigger/{At,AtHelper,Condition}.php、inc/classes/PowerEmail/Resources/Email/{CPT,Email,Api}.php、inc/classes/PowerEmail/Resources/Email/Replace/
- tests/Integration/Email/SendNowTest.php、tests/Integration/Email/EmailTriggerAtTest.php、tests/Integration/AccessPass/ExpiringNotifierTest.php

【要寫 / 修改的測試】
- 新增 AssignmentGradedEmailTest.php：pass / fail 寄給學員、pending 不寄、同一繳交第二次批改仍寄（先寫入 identifier 含舊 graded_at 的 EmailRecord）、模板 course_ids 不含該課程時不寄、草稿模板不寄；流程 = 批改 → run_scheduled_actions('power_email_send_assignment_graded') → capture_mail()
- 新增 AssignmentEmailReplaceTest.php：feature 範例「Alice 您的作業「練習一：變數宣告」批改結果：通過。寫得很好」；{assignment_due_at} 格式 Y-m-d H:i（站台時區）
- 修改 tests/Integration/Email/SendNowTest.php：slug 數 10 → 12 與 label 對照（刻意更新；只在被修改的方法 docblock 加 @group issue-131；回報第 1 段標「修改既有」、第 6 段說明這是計劃明文要求）
- 修改 tests/Integration/Email/EmailTriggerAtTest.php：新增「兩個作業 slug 可以存」的方法（方法 docblock 加 @group issue-131）

{{S-REPORT-RED}}
```

### P5-RG：Red Gate

同 P1-RG（TAG `p5-rg`），另注意：
- group 計數含 `SendNowTest`、`EmailTriggerAtTest` 被標記的方法；全套增量只算新增的 method / data set。
- CMD-PU-FULL 的失敗清單中，`SendNowTest` / `EmailTriggerAtTest` 只能出現被標記的方法。
- CMD-TEST-HASH 清單要包含這兩個既有檔。

### P5-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p5-g1` | `P5 綠燈：作業郵件 trigger 與變數` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P5 綠燈：assignment_graded 寄信、assignment_due_soon 寄送路徑、作業變數替換。

{{S-GREEN-PHP}}

【用戶對 DEC-10 的決定（主窗口貼入原文）】
{{UC-DEC10 結果}}

【本階段必讀】計劃 §9 P5 全段、§4 DEC-10、§5.1 PowerEmail 相關列、§12 R-09 / R-25；inc/classes/PowerEmail/Resources/Email/** 相關檔；access_pass_expiring 的排信寫法（At.php）

【紅燈回報（主窗口貼入原文）】
{{P5-R 回報第 1～3 段}}

【你負責讓它綠的測試】AssignmentGradedEmailTest、AssignmentEmailReplaceTest、SendNowTest 與 EmailTriggerAtTest 中標 @group issue-131 的方法
【必須維持綠】P1～P4 全部；tests/Integration/Email、tests/Integration/AccessPass 的既有測試（ExpiringNotifierTest 等）

【步驟】
1. AtHelper：ASSIGNMENT_GRADED、ASSIGNMENT_DUE_SOON 常數、$allowed_slugs、label（When assignment graded、Before assignment due）
2. CPT::SUPPORTED_TRIGGER_SLUGS +2；Condition：作業 trigger 的 required_ids = course_ids（不走撈全站章節的分支），新增 get_course_ids()
3. Replace/Assignment.php：{assignment_title} {assignment_status} {assignment_feedback} {assignment_due_at}
4. Email.php：send_assignment_email(user_id, array $ctx)；專屬 filter power_course_assignment_email_{subject,html}（先掛 Replace\User，再掛 Replace\Assignment）；寄出後 EmailRecord::add(course_id, …, identifier)；wp_mail 失敗不寫 EmailRecord、只 log
5. At.php：add_action('power_course_assignment_graded', …) 與 add_action('power_course_assignment_due_soon', …) → course scope 過濾 → is_sent → 已排程檢查 → as_enqueue_async_action；對應 power_email_send_assignment_* callback；identifier ids：graded = [submission_id, graded_at timestamp]、due_soon = [assignment_id, due_at]；pc_email_records.post_id = 課程 ID
6. Email/Api.php：變數提示加 assignment_schema
7. i18n

【受影響既有目錄（回報前跑）】tests/Integration/Email、tests/Integration/AccessPass、tests/Integration/Student（log_type 白名單變寬，R-09）

{{S-REPORT-GREEN}}
```

### P5-GG：Green Gate

1. CMD-TEST-HASH 驗證（對 `p5-rg`）。
2. CMD-PU-GROUP（`p5-gg`）：`OK (`；Tests = P5-RG group Tests。

### P5-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p5-f` | `P5 重構：排信共同骨架` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P5 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P5-G1 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- schedule_access_pass_expiring_email 與兩個作業排信的共同骨架（撈模板 → 去重 → enqueue）可抽 private helper
- **不得改變 access_pass 的既有行為**：回報前必跑 tests/Integration/AccessPass 與 tests/Integration/Email 並貼摘要行

{{S-REPORT-GREEN}}
```

### P5-PG：Phase Gate

P2-PG 的 1～6（TAG `p5-pg`）。全套 Failures / Errors = 0 本身就涵蓋 ExpiringNotifierTest 等既有 Email / AccessPass 測試。

### P5-CK

progress 寫入；**UC-5**：`feat(email): 新增作業批改與截止提醒郵件觸發`、`test(email): 新增作業郵件測試並更新 trigger slug 數`（commit 內文註明 SendNowTest 10 → 12 為刻意更新）。

---

## 11. P6：作業截止提醒排程

- 計劃：§9 P6；§6.4；R-06、R-24
- 本階段派發 3 次：P6-R → P6-G1 → P6-F

### P6-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p6-r` | `P6 紅燈：作業截止提醒` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P6（截止提醒排程）紅燈測試。

{{S-RED-PHP}}

【本階段必讀】計劃 §9 P6 全段、§6.4、§12 R-06 / R-24；specs/features/assignment/作業截止提醒.feature、specs/features/email/觸發自動郵件.feature（assignment_due_soon Rule）；clarify D20；inc/classes/Resources/AccessPass/Service/ExpiringNotifier.php（或實際位置）與 tests/Integration/AccessPass/ExpiringNotifierTest.php

【要寫的測試】tests/Integration/Assignment/AssignmentDueReminderTest.php
- 視窗兩端（now、now+48h）含、外側 ±1 秒不含；已繳交不寄；觀看權到期不寄（expire_date 相對時間）；權限包持有者會收到；課程未開課不寄（相對時間）；trash 作業不寄；草稿模板不寄；同 identifier 不重寄；截止時間改變後再寄；寄出後 pc_email_records 有 email_id / user_id / post_id=100 / trigger_at / mark_as_sent
- 排程：maybe_schedule_scan() 後 as_next_scheduled_action('pc_assignment_due_soon_scan') 換成站台時區是 09:00、間隔 86400；已排程不重複
- email feature 範例：「「練習一：變數宣告」將於 2026-09-18 23:59 截止」

{{S-REPORT-RED}}
```

### P6-RG：Red Gate

同 P1-RG（TAG `p6-rg`）。

### P6-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p6-g1` | `P6 綠燈：截止提醒掃描` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P6 綠燈：每日 09:00 掃描並對未繳交學員觸發截止提醒。

{{S-GREEN-PHP}}

【本階段必讀】計劃 §9 P6 全段、§6.4、§7 DueReminder 列、§12 R-06 / R-24；specs/features/assignment/作業截止提醒.feature；AccessPass ExpiringNotifier 的排程寫法；P5 產物 At.php 的 due_soon 寄送路徑

【紅燈回報（主窗口貼入原文）】
{{P6-R 回報第 1～3 段}}

【你負責讓它綠的測試】AssignmentDueReminderTest
【必須維持綠】P1～P5 全部

【步驟】
1. Service/DueReminder.php：SCAN_HOOK = 'pc_assignment_due_soon_scan'、DUE_SOON_ACTION = 'power_course_assignment_due_soon'；首次執行 = 站台時區下一個 09:00 的 timestamp，as_schedule_recurring_action($first, DAY_IN_SECONDS, SCAN_HOOK)；流程依 §6.4（無 publish 的 due_soon 模板 → 直接結束；AS 未載入 → function_exists 守門；單一作業例外 catch → log 並繼續）
2. Core/Loader.php 註冊
3. i18n（若有）

【受影響既有目錄（回報前跑）】tests/Integration/AccessPass、tests/Integration/Email

{{S-REPORT-GREEN}}
```

### P6-GG：Green Gate

CMD-TEST-HASH 驗證；CMD-PU-GROUP（`p6-gg`）`OK (`、Tests = P6-RG group Tests。

### P6-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p6-f` | `P6 重構：候選學員計算` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P6 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P6-G1 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- 候選學員計算抽成 get_candidate_user_ids(course_id)，同一門課多份作業在同一次掃描內只算一次
- 已繳交者一次 SQL 撈完

{{S-REPORT-GREEN}}
```

### P6-PG：Phase Gate

P2-PG 的 1～6（TAG `p6-pg`）。

### P6-CK

progress 寫入；**UC-6**：`feat(assignment): 新增作業截止提醒每日排程`、`test(assignment): 新增截止提醒測試`。

---

## 12. P7：Admin React SPA

- 計劃：§9 P7；DEC-11；R-19、R-20
- 前置：**UC-DEC11 已回答**
- 本階段派發 5 次：P7-R → P7-G1 → P7-G2 → P7-G3 → P7-F

### P7-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p7-r` | `P7 紅燈：後台作業 UI E2E` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P7（Admin SPA）E2E 紅燈測試，共 3 支。

{{S-RED-E2E}}

【用戶對 DEC-11 的決定（主窗口貼入原文）】
{{UC-DEC11 結果}}

【本階段必讀】計劃 §9 P7 全段、§5.2、§12 R-19 / R-20；specs/ui/章節作業管理.md、specs/ui/作業批改頁.md；specs/api/api.yml（tag Assignments）；tests/e2e/01-admin/email-template.spec.ts、tests/e2e/01-admin/chapter-manage.spec.ts、tests/e2e/helpers/admin-page.ts

【要寫的測試】
1. 新增 tests/e2e/01-admin/assignment-chapter-manage.spec.ts：在章節編輯區新增作業（標題、允許檔案、上限、截止日）→ 列表顯示題型摘要 → 編輯標題 → 排序（拖拉，或以 API 改序後驗證畫面順序）→ 刪除時確認框提示「學員繳交紀錄會保留」
2. 新增 tests/e2e/01-admin/assignment-grading.spec.ts：資料以 REST 準備（學員繳交用學員身分 nonce 呼叫 my/assignments/{id}/submit）→ 課程分頁顯示繳交數 → 統計卡數字 → 狀態篩選 → 開詳情 → 批改通過 + 評語 → 列表狀態與統計更新 → 背景資料顯示進度 → 兩個匯出按鈕觸發 download 事件且檔名符合規則
3. 擴充 tests/e2e/01-admin/email-template.spec.ts：trigger 下拉出現兩個作業選項，存檔後重新載入仍保留；選作業 trigger 時的欄位顯示依 DEC-11 決定。不得改動既有測試。
UI 規格列出的每個關鍵元素都要有斷言。

【執行前】pnpm run build 已由主窗口在 Red Gate 處理；你自我驗證時若發現 js/dist/vite-dev-server.json 存在，回報，不要自行刪除。
【canary】01-admin/smoke.spec.ts

{{S-REPORT-RED}}
```

### P7-RG：Red Gate

1. CMD-GIT-SCOPE：只有 `tests/e2e/01-admin/` 下三個 spec（與可能新增的 helper）。
2. `cd "$PC" && pnpm run build > "$LOG/p7-rg-build.log" 2>&1; echo "EXIT_CODE=$?"; tail -n 15 "$LOG/p7-rg-build.log"`（以**當前程式碼**產出新鮮 dist）→ CMD-DIST：`NO_DEV_SERVER_JSON`、manifest mtime 為剛剛。
3. CMD-E2E（`p7-rg`，一次）：`01-admin/smoke.spec.ts 01-admin/assignment-chapter-manage.spec.ts 01-admin/assignment-grading.spec.ts 01-admin/email-template.spec.ts` → smoke 綠；email-template **既有**測試綠、新增測試紅；兩支新 spec 全紅且原因為「功能不存在」。
4. CMD-LC 乾淨。
5. CMD-TEST-HASH 記錄（清單 = 三個 spec + 新 helper）。

### P7-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `p7-g1` | `P7 綠燈一：章節作業管理 UI` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P7 綠燈（第 1 / 3 棒）：章節作業管理 UI。

{{S-GREEN-REACT}}

【紅燈回報（主窗口貼入原文）】
{{P7-R 回報第 1～3 段}}

【你負責讓它綠的 spec】tests/e2e/01-admin/assignment-chapter-manage.spec.ts
【必須維持】01-admin/smoke.spec.ts 綠；email-template.spec.ts 既有測試綠

【範圍】
- js/src/components/chapters/Assignments/（index.tsx、AssignmentList、AssignmentFormModal、hooks/、types/）
- 嵌入 js/src/components/chapters/Edit.tsx
- 拖拉排序沿用 components/course/SortableChapters 使用的函式庫；作業說明用既有編輯器元件；截止時間 DatePicker 以站台時區換算 10 位 timestamp，清空 = 0

{{S-REPORT-GREEN}}
```

### P7-G1C：小檢查

CMD-TEST-HASH 驗證；CMD-DIST；CMD-E2E（`p7-g1c`，一次）smoke + assignment-chapter-manage → 全綠；CMD-LC。

### P7-G2（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `p7-g2` | `P7 綠燈二：作業批改頁` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P7 綠燈（第 2 / 3 棒）：作業批改頁。

{{S-GREEN-REACT}}

【紅燈回報（主窗口貼入原文）】
{{P7-R 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{P7-G1 回報}}

【你負責讓它綠的 spec】tests/e2e/01-admin/assignment-grading.spec.ts
【必須維持綠】smoke.spec.ts、assignment-chapter-manage.spec.ts、email-template.spec.ts 既有測試

【範圍】
- js/src/resources/index.tsx、js/src/App1.tsx：assignments resource 與路由（選單名「作業批改」）
- js/src/pages/admin/Assignments/（index.tsx、components/{CourseTabs,StatsCards,SubmissionTable,SubmissionDetail,GradeForm,StudentOverview,ExportButtons}、hooks/、types/）
- 列表 / 統計 / 批改用 useCustom / useCustomMutation；附件下載與 CSV 匯出沿用 UserTable 的 window.open(url + '?_wpnonce=' + NONCE)

{{S-REPORT-GREEN}}
```

### P7-G2C：小檢查

CMD-TEST-HASH 驗證；CMD-DIST；CMD-E2E（`p7-g2c`，一次）smoke + assignment-chapter-manage + assignment-grading → 全綠；CMD-LC。

### P7-G3（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `p7-g3` | `P7 綠燈三：Email 作業 trigger UI` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P7 綠燈（第 3 / 3 棒）：Email 模板的作業 trigger 選項與變數提示（DEC-11）。

{{S-GREEN-REACT}}

【用戶對 DEC-11 的決定（主窗口貼入原文）】
{{UC-DEC11 結果}}

【紅燈回報（主窗口貼入原文）】
{{P7-R 回報第 1～3 段}}

【前兩棒回報（主窗口貼入原文）】
{{P7-G1 回報}}
{{P7-G2 回報}}

【你負責讓它綠的 spec】tests/e2e/01-admin/email-template.spec.ts 新增的測試（既有測試必須維持綠）
【必須維持綠】smoke、assignment-chapter-manage、assignment-grading

【範圍】js/src/components/emails/SendCondition/{enum.ts,Condition.tsx} 與變數提示元件（後端 P5 已提供 assignment_schema）

{{S-REPORT-GREEN}}
```

### P7-GG：Green Gate

1. CMD-TEST-HASH 驗證（對 `p7-rg`）。
2. CMD-DIST：`NO_DEV_SERVER_JSON`。
3. CMD-E2E（`p7-gg`，一次）：smoke + 三支 spec → 全綠；CMD-LC。

### P7-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `p7-f` | `P7 重構：批改頁查詢 key 與型別` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P7 Refactor。不新增行為、不改 E2E spec。

{{S-GREEN-REACT}}

【本階段 Green 回報（主窗口貼入原文）】
{{P7-G1 回報}}
{{P7-G2 回報}}
{{P7-G3 回報}}

【本階段 Refactor 項目】
- 批改頁的查詢 key 統一；批改成功後只 invalidate 列表與統計
- 元件 props 型別集中到 types/；移除 any
- 完成後：npx eslint <P7 所有變更的 ts/tsx> --fix、pnpm run type-check、pnpm run build、三支 spec + smoke 連跑兩次、LC 檢查

{{S-REPORT-GREEN}}
```

### P7-PG：Phase Gate（前端版）

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-ESLINT（`p7-pg`） | errors ≤ 基線；另跑 `npx eslint <P7 新增檔>` → 0 error |
| 2 | CMD-TSC（`p7-pg`） | exit 0 |
| 3 | `pnpm run build`（log `p7-pg-build.log`） | exit 0；log 中 zh_TW / ja untranslated ≤ 基線；`languages/` 7 檔有變更 |
| 4 | CMD-DIST | `NO_DEV_SERVER_JSON`，manifest 為剛剛 |
| 5 | CMD-E2E（`p7-pg`，**兩次**）：smoke + 三支 spec，每次後 CMD-LC | 兩次 0 failed、0 flaky |
| 6 | `cd "$PC" && git diff --name-only 2>/dev/null; git ls-files --others --exclude-standard` 篩 `*.php` | 若 P7 期間有 PHP 變更 → 追加 CMD-PU-FULL、CMD-PHPSTAN、CMD-PHPCS，判準同 P2-PG |
| 7 | CMD-GIT-SCOPE | P7 範圍外、且基線時乾淨的檔案被改 → UC-lint |
| 8 | CMD-TEST-HASH 驗證 | 乾淨 |

### P7-CK

progress 寫入；**UC-7**：`feat(admin): 新增章節作業管理與作業批改頁`、`feat(email): 郵件模板支援作業 trigger`、`test(e2e): 新增作業後台 E2E`。

---

## 13. P8：前台教室作業區塊

- 計劃：§9 P8；DEC-15；R-04、R-14、R-20
- 前置：**UC-DEC15 已回答**
- 本階段派發 3 次：P8-R → P8-G1 → P8-F（016 已在 P3 / P4 完成，本階段只回歸）

### P8-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `p8-r` | `P8 紅燈：教室作業區塊 E2E` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P8（教室作業區塊）E2E 紅燈測試。

{{S-RED-E2E}}

【用戶對 DEC-15 的決定（主窗口貼入原文）】
{{UC-DEC15 結果}}

【本階段必讀】計劃 §9 P8 全段、§4 DEC-15、§12 R-04 / R-14 / R-20；specs/ui/教室作業區塊.md；specs/features/assignment/{繳交作業,查詢學員作業}.feature；tests/e2e/02-frontend/008-classroom-chapters.spec.ts、018-linear-viewing-classroom.spec.ts、009-finish-chapter.spec.ts

【要寫的測試】tests/e2e/02-frontend/021-classroom-assignments.spec.ts
學員看到作業卡片 → 上傳檔案（setInputFiles）→ 繳交 → 徽章「待批改」、按鈕變「重新繳交」→ 以 API 批改 pass → 重新載入後徽章「通過」、顯示評語、隱藏重新繳交 → 已過截止時間且未繳交顯示「已逾期」但仍可繳交 → 點自己的附件可下載 → 線性觀看鎖定章節的表單停用並顯示鎖定訊息 → 管理員預覽模式表單全部停用 → 課程作業總覽數字正確（位置依 DEC-15 決定）
UI 規格列出的每個元素都要有斷言。

【canary】02-frontend/008-classroom-chapters.spec.ts

{{S-REPORT-RED}}
```

### P8-RG：Red Gate

1. CMD-GIT-SCOPE：只有 `tests/e2e/02-frontend/021-classroom-assignments.spec.ts`（與可能的 helper）。
2. `cd "$PC" && pnpm run build:wp > "$LOG/p8-rg-buildwp.log" 2>&1; echo "EXIT_CODE=$?"`（以當前程式碼產出新鮮 `inc/assets/dist`）。
3. CMD-E2E（`p8-rg`，一次）：`02-frontend/008-classroom-chapters.spec.ts 02-frontend/021-classroom-assignments.spec.ts` → canary 綠、021 全紅且原因為「功能不存在」；若出現舊頁面，依 playbook 清 WP Rocket 快取後重跑（環境修復，不算重試）。
4. CMD-LC 乾淨；CMD-TEST-HASH 記錄。

### P8-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p8-g1` | `P8 綠燈：教室作業模板與互動` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P8 綠燈：教室作業區塊（PHP 模板 + vanilla TS）。你同時負責模板與 TS，確保兩邊的 data 屬性一致。

{{S-GREEN-PHP}}

【用戶對 DEC-15 的決定（主窗口貼入原文）】
{{UC-DEC15 結果}}

【本階段額外必讀】
- .claude/rules/react.rule.md（TS 慣例）、.claude/rules/e2e-testing.rule.md
- 計劃 §9 P8 全段、§5.2 教室相關列、§4 DEC-15；specs/ui/教室作業區塊.md
- inc/templates/pages/classroom/body.php、inc/templates/components/ 既有元件、inc/assets/src/main.ts、inc/assets/src/events/index.ts、vite.config-for-wp.ts（@wordpress/i18n shim alias）、inc/classes/Templates/Ajax.php（前台 script 翻譯載入）
- 參考實作：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\(public)\[locale]\account\courses\[slug]\AssignmentsSection.tsx 與 …\assignments\CourseAssignmentsOverview.tsx
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_frontend_assets_build.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_frontend_verify_playbook.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md

【紅燈回報（主窗口貼入原文）】
{{P8-R 回報第 1～3 段}}

【你負責讓它綠的 spec】tests/e2e/02-frontend/021-classroom-assignments.spec.ts
【必須維持綠】02-frontend/008-classroom-chapters.spec.ts、03-integration/016-assignment-http-contract.spec.ts、PHPUnit --group issue-131 全部

【步驟】
1. inc/templates/components/assignment/{section.php,card.php,overview.php}；body.php 在 the_content() 之後、留言之前載入；資料直接呼叫 Service\MyQuery（學員）或 Service\Query（管理員預覽，表單停用）
2. inc/assets/src/events/assignments/{index.ts,upload.ts,submit.ts,render.ts}：上傳（FormData + pc_data.nonce）、繳交、局部更新卡片狀態；於 events/index.ts / main.ts 註冊
3. 線性觀看鎖定：伺服器端判斷後輸出 data-locked，表單停用並顯示既有鎖定訊息
4. i18n：模板用 esc_html__ / esc_attr__ 等 escape 變體；TS 用 @wordpress/i18n（確認 shim alias 涵蓋新 entry）
5. 使用者輸入一律 textContent，禁止 innerHTML

【固定順序補充】寫碼 → manual.json → pnpm run i18n:build → pnpm run build:wp → grep 一個新字串於 inc/assets/dist/index.js 確認 dist 新鮮 → E2E（canary 008 + 021 連跑兩次，每次 LC 檢查）→ PHPUnit --group issue-131
注意：inc/assets 不在 tsconfig，vite build 不做型別檢查——型別錯誤只能靠你自己小心與 E2E。

{{S-REPORT-GREEN}}
```

### P8-GG：Green Gate

1. CMD-TEST-HASH 驗證。
2. `pnpm run build:wp`（log `p8-gg-buildwp.log`）exit 0；`grep -c "<P8-G1 回報中的一個新字串>" inc/assets/dist/index.js` ≥ 1。
3. CMD-E2E（`p8-gg`，一次）：008 + 021 → 全綠；CMD-LC。
4. CMD-PU-GROUP（`p8-gg`）：`OK (`，Tests 與 P6-PG 相同（P8 不新增 PHPUnit）。

### P8-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `p8-f` | `P8 重構：卡片模板單一來源` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波 P8 Refactor。

{{S-REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{P8-G1 回報}}

【PHPStan 基線】{{baseline.phpstan_errors}}

【本階段 Refactor 項目】
- 卡片 HTML 只在 PHP 產生一份；TS 只更新狀態欄位，不在 TS 複製卡片模板
- grep -rn "innerHTML" inc/assets/src/events/assignments 必須為空（貼輸出）
- 完成後除 PHPUnit 外：pnpm run build:wp、008 + 021 + 016 E2E 跑一次（含 LC 檢查）

{{S-REPORT-GREEN}}
```

### P8-PG：Phase Gate

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-GROUP、CMD-PU-FULL（`p8-pg`） | 同 P2-PG；全套 Tests 與 P7-PG（或 P6-PG）相同 |
| 2 | CMD-PHPSTAN、CMD-PHPCS（`p8-pg`） | ≤ 基線；0 error |
| 3 | CMD-I18N（`p8-pg`） | untranslated ≤ 基線；`languages/` 7 檔有變更 |
| 4 | `pnpm run build:wp` + grep dist | exit 0；新字串存在 |
| 5 | `cd "$PC" && grep -rn "innerHTML" inc/assets/src/events/assignments; echo "GREP_DONE"` | `GREP_DONE` 前無輸出 |
| 6 | CMD-E2E（`p8-pg`，**兩次**）：`02-frontend/008-classroom-chapters.spec.ts 02-frontend/021-classroom-assignments.spec.ts 03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts`，每次後 CMD-LC | 兩次 0 failed、0 flaky |
| 7 | CMD-TEST-HASH 驗證 | 乾淨 |

### P8-CK

progress 寫入；**UC-8**：`feat(classroom): 新增教室作業區塊與繳交互動`、`test(e2e): 新增教室作業 E2E`。

---

## 14. P9：收尾驗收與文件同步

- 計劃：§9 P9、§14
- 本階段派發 1 次：P9-D

### P9-FG：最終關卡（主窗口親跑，派 doc-updater 之前）

| # | 命令 | 判準（計劃 §14） |
|---|------|------------------|
| 1 | CMD-PU-FULL（`p9-fg`） | 0 failure / 0 error；Tests = 基線 + P1～P6 全套增量合計 |
| 2 | CMD-PU-GROUP（`p9-fg`） | `OK (`；Incomplete / Skipped / Risky = 0 |
| 3 | CMD-PHPSTAN | ≤ 基線 |
| 4 | CMD-PHPCS | 0 error |
| 5 | CMD-ESLINT、CMD-TSC | errors ≤ 基線；type-check exit 0 |
| 6 | CMD-I18N | untranslated ≤ 基線 |
| 7 | `pnpm run build`、`pnpm run build:wp` | exit 0；CMD-DIST `NO_DEV_SERVER_JSON` |
| 8 | CMD-E2E（`p9-fg`，**兩次**）：`01-admin/smoke.spec.ts 01-admin/assignment-chapter-manage.spec.ts 01-admin/assignment-grading.spec.ts 01-admin/email-template.spec.ts 02-frontend/008-classroom-chapters.spec.ts 02-frontend/021-classroom-assignments.spec.ts 03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts`，每次後 CMD-LC | 兩次 0 failed、0 flaky |
| 9 | CMD-LC | `'lc'[[:space:]]*=>` 無結果、`plugin.php.e2e-backup` 不存在（修正計劃 P9 的 pattern，見 L12） |
| 10 | `cd "$PC" && grep -rn "stored_path\|'url'" inc/classes/Resources/Assignment/Core; echo "GREP_DONE"` | 人工確認沒有輸出附件路徑 / URL |
| 11 | `.progress.json` | P1～P8 皆 `checkpointed`，無 `deferred`（有則列入最終回報） |

任一不符 → §3.4，修復後從本表第 1 項重跑。

### P9-SS：截圖（主窗口）

用 `playwright-cli` skill（或 `tests/e2e/take-screenshots.ts` 模式）截：章節作業管理、批改頁（含詳情與背景資料）、Email trigger 選項、教室作業區塊（未繳交 / 待批改 / 通過 / 鎖定）。前置依 memory `reference_local_frontend_verify_playbook.md`：LC bypass 以 helper 套用、驗完以備份還原並跑 CMD-LC（禁止 `git checkout -- plugin.php`）；WP Rocket 快取；`confirm_admin_email` 攔截頁。截圖存主窗口 scratchpad，不進 repo。

### P9-D（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:doc-updater` | `p9-d` | `P9 文件同步：作業模組` |

```text
{{S-CONTEXT}}

【任務】Issue #131 第一波完成後的專案文件同步。只改文件，不改程式碼與測試。

【必讀】計劃 §5.3、§9 P9、§12 R-04 / R-21 / R-27；git diff --stat 2397234f 與 git status 列出的所有變更；inc/classes/Resources/Assignment/** 結構；.claude/CLAUDE.md；.claude/rules/{wordpress,i18n}.rule.md；specs/entity/erm.dbml；specs 中的「系統抽象.md」

【要更新】
1. .claude/CLAUDE.md：「6 張表」→「9 張表」；核心架構決策補 Assignment Resource（CPT pc_assignment、自訂表 pc_assignment_submissions / attachments / messages、受保護附件目錄、PowerEmail 兩個作業 trigger、截止提醒排程）
2. .claude/rules/wordpress.rule.md：自訂資料表清單；Assignment Resource 小節（Core / Model / Service / Utils 分工）；受保護目錄說明——Nginx 範例 location ^~ /wp-content/uploads/power-course-private/ { deny all; } 與 PC_ASSIGNMENT_STORAGE_DIR 常數、power_course_assignment_storage_dir filter
3. .claude/rules/i18n.rule.md：修正「四個檔」→ zh_TW + ja 共 7 檔（.pot + 2×.po/.mo/.json）；確認術語表已含作業相關詞彙
4. R-27：specs/entity/erm.dbml 的 Submit Note 第 2 點、系統抽象.md 的 Violation 表，把學員端作業不存在的 404 改為 403「尚未報名此課程」（以 feature 為準，DEC-12）
5. R-21 / R-04 延後事項：在 specs/plan/issue-131-assignment-grading/ 既有文件中補註（第二波 CSV 匯入依欄位位置解析或同時接受各語系標頭；受保護目錄 Site Health 探針、截止提醒批次化列為後續）——寫進既有 execution-plan.md 的適當段落，不新建檔

【禁止】commit / push；改程式碼或測試；建立新的報告型 .md

【回報】每個檔案的變更摘要（前後對照重點句）、git status --short 原文
```

### P9-DG：文件檢查（主窗口）

- `git diff --stat` 只有文件類檔案；CMD-TEST-HASH 與程式碼未變（`git diff --name-only` 無 `inc/`、`js/`、`tests/` 新變更）。
- 對照 P9-D 的 5 項逐條確認。

### P9-CK / UC-9

- progress：P9 `checkpointed`。
- 向用戶提出最終 commit 分組（若先前階段未 commit，依 `zenbu-powers:git-commit` 拆成 build/feat/test/docs 多個原子 commit）與分支處置選項（`/zenbu-powers:finishing-branch`）。**全部待用戶同意**。

---

## 15. 收尾（第 7 節對應）

1. **Milestone 同步**：cwd 下沒有 `specs/milestones/`，跳過。
2. 最終 Green Gate：即 P9-FG。
3. 文件同步：P9-D。
4. 本地模式：保留分支與變更等用戶驗收；不建 PR。
5. **主窗口最終回報用戶的內容**：
   - 分支名、各階段 checkpoint 與 commit 狀態
   - PHPUnit：基線 → 最終（Tests / Assertions / Skipped），issue-131 group 測試數
   - PHPStan：基線 → 最終；PHPCS 0 error；ESLint / type-check 結果
   - i18n：zh_TW / ja untranslated 基線 → 最終
   - E2E：8 支（4 新 + 擴充 email-template + 3 canary）兩次執行結果
   - 關鍵變更：新 Resource、3 張表 + DB 1.2.0、新 REST 端點數、PowerEmail 2 個 trigger、後台 2 畫面、教室區塊
   - 截圖路徑
   - 被延後（`deferred`）或回報為規格缺口的項目
   - 建議補派的 opt-in reviewer（§16）

---

## 16. Opt-in 深度審查（不在自動流程，用戶喚醒才派）

| Agent | 建議時機 | 理由 |
|-------|---------|------|
| `@zenbu-powers:security-reviewer` | **P3-CK 之後**（建議），或 P9 之後 | WordPress 專案；涉及檔案上傳、MIME 判定、受保護目錄、下載驗權、nonce、REST 權限——屬安全敏感領域 |
| `@wordpress-reviewer` | P4 或 P9 之後 | 需先 `/copy-sets`；審 Resource 結構、SQL、Action Scheduler 用法 |
| `@zenbu-powers:react-reviewer` | P7 之後 | 審 Refine 資料契約、查詢 key、型別 |

用戶喚醒後若審查 FAIL：由用戶決定是否派對應實作 agent 修，修復迴圈最多 3 輪；每輪修完重跑受影響階段的 Phase Gate。
