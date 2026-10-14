# TDD 協調藍圖：Issue #131 作業與作業批改（第二波：討論串 + CSV 匯入）

> 產出：`@zenbu-powers:tdd-coordinator`（w2-tdd）｜日期：2026-10-01｜分支：`feat/issue-131-wave2`｜基準 commit：`e520fd7e`
> 唯一依據：`specs/plan/issue-131-assignment-grading/wave2-implementation-plan.md`（下稱「計劃」）
> 結構來源：第一波藍圖 `specs/plan/issue-131-assignment-grading/tdd-blueprint.md`（下稱「藍圖一」）。**藍圖一的 §3 全域規則、§4 共用 prompt 區塊、ADJ-01～12 原文沿用，本檔不重寫**；本檔只寫第二波差異，以「藍圖一 L行號 + ADJ-W2 補丁」組成。
> 讀者：主窗口。本檔只定義派發順序、prompt 組成、Gate 命令與判準、回退規則、檢查點格式，不含實作。
> 不可推翻：clarify D1–D29、計劃 DEC-W2-01～20、第一波 DEC-01～18 / R-01～28、用戶裁決 UC-W2-1～4（§3.7）。

---

## 0. 使用方式

### 0.1 與藍圖一的關係（優先序）

1. **派發 prompt 一律用 §3.2 的 CMD-PROMPT 機械組裝**，不手抄。卡片裡的 `{{S-XXX}}` 由組裝器換成藍圖一對應區塊**原文**（行號見下表，組裝前做錨點檢查），`{{W2:XXX}}` 換成本檔 §4 的第二波區塊。
2. 每個 `{{S-XXX}}` 後面都緊接對應的 `{{W2:XXX}}` 覆寫區塊；**兩者衝突時以 W2 區塊為準**（每個 W2 區塊開頭都寫明這一句）。
3. `{{貼上：…}}` 是組裝器不處理的手動欄位（上游 agent 回報原文），組裝器會列出剩下哪些。
4. 主窗口親跑的 Gate：照 §3.2 命令跑、照藍圖一 §3.3（L270–284）貼證據；E2E 證據另加 §3.3 的欄位。**不接受 sub-agent 的口頭「完成」**。
5. 任何 STOP → 藍圖一 §3.4 的「STOP 的標準動作」（L312–316），progress 寫 `.progress-wave2.json`（不是第一波的 `.progress.json`）。

| 藍圖一區塊 | 行號（code fence 內文） | 開頭錨點 | 結尾錨點 |
|-----------|----------------------|---------|---------|
| S-CONTEXT | L468–L487 | `【專案與分支】` | `- PHPUnit 9 對 incomplete` |
| S-RED-PHP | L493–L535 | `【你的角色】紅燈測試作者` | `- git status --short --untracked-files=all（只能出現測試檔變更）` |
| S-RED-E2E | L541–L574 | `【你的角色】E2E 紅燈測試作者` | `- git status --short --untracked-files=all` |
| S-GREEN-PHP | L580–L619 | `【你的角色】WordPress / PHP 8 後端工程師` | `- git status --short --untracked-files=all` |
| S-REFACTOR-PHP | L625–L644 | `【你的角色】同階段的 PHP 工程師` | `【回報】S-REPORT-GREEN 格式` |
| S-GREEN-REACT | L650–L685 | `【你的角色】React 18` | `- git status --short --untracked-files=all` |
| S-REPORT-RED | L691–L698 | `【回報格式（缺一段就會被退回）】` | `6. 疑義` |
| S-REPORT-GREEN | L704–L709 | `【回報格式】` | `5. 未解問題` |

藍圖一 working tree sha1（2026-10-01 實測）：`090f3fe41cdc45cb82c503cea068d4c95f2d2ade`。組裝器以**錨點**為準（sha1 只供參考，CRLF 正規化可能改變它）；錨點不符 → 組裝器 exit 2，STOP 問用戶（代表有人改了藍圖一，行號失效）。

### 0.2 派發總覽

| 階段 | 派發（spawn 次數） | 主窗口親跑的 Gate | 依賴 tunnel 的步驟（標 [T]） |
|------|------------------|------------------|---------------------------|
| W0 前置基線 | 0 | W0-1～W0-11 | W0-9（E2E 基線） |
| W1 後端討論串 | **3**：R、G1、F | RG、GG、PG | 無 |
| W2 CSV 匯入 + HTTP 契約 | **5**：R1、R2、G1、G2、F | RG1、RG2、G1C、GG、PG | W2-R2 的紅燈執行、W2-RG2、W2-G2 的 017 自驗、W2-PG 第 8 項 |
| W3 Admin SPA（含修正第一波偏離：預設篩選改回待批改） | **5**：R、G0、G1、G2、F | RG、G0C、G1C、GG、PG | 全段 |
| W4 教室討論串 | **3**：R、G1、F | RG、GG、PG | 全段 |
| W5 收尾 | **1**：D（doc-updater） | FG、SS、DG | W5-FG 第 8 項、W5-SS |
| **合計** | **17 次派發** | | |

每階段標準流程沿用藍圖一 §0.2（L40–L49）：Red Gate 通過前不派 G；Phase Gate 通過前不派下一階段的 R。

### 0.3 執行順序：W1 排最前，W0 的 E2E 基線照計劃執行

```
W0（W0-1～W0-8、W0-10、W0-11）
  └─[T] W0-9 E2E 基線：照計劃執行，主窗口執行（背景 + 自己掛 Monitor 盯摘要行），可與 W1-R / W1-RG 重疊；W1-G1 派發前必須跑完（§0.4）
W1-R → W1-RG → W1-G1 → W1-GG → W1-F → W1-PG → W1-CK                       （全 PHPUnit）
W2-R1 → W2-RG1 → [T] W2-R2 → [T] W2-RG2 → W2-G1 → W2-G1C → W2-G2（017 自驗 [T]）→ W2-GG → W2-F → W2-PG → W2-CK
        （探測不通時的分支：W2-G1 → W2-G1C 先做，W2-R2 / RG2 等 tunnel，仍須在 W2-G2 之前）
[T] W3-R → W3-RG → W3-G0（修正第一波偏離）→ W3-G0C → W3-G1 → W3-G1C → W3-G2 → W3-GG → W3-F → W3-PG → W3-CK
[T] W4-R → W4-RG → W4-G1 → W4-GG → W4-F → W4-PG → W4-CK
W5-FG（第 8 項 [T]）→ [T] W5-SS → W5-D → W5-DG → W5-CK
```

**W2-R2 必須在 W2-G2 之前完成紅燈執行**：017 的匯入測試在 W2-G2 加上路由前是 `rest_no_route`（功能不存在的紅），W2-G2 之後就再也證明不了它會紅。W2-G1 只寫 Service / 解析器，不加路由，所以排在 R2 之前也不會讓 017 變綠。

**W0-9 要先確立**：第一波三支作業 E2E 在 master v1.8.11（`a070a33a` 前台 React bundle 按需載入）之後還沒跑過；沒有這個基線，W3 / W4 出紅燈時分不清是新改動還是 master 帶進來的。

**依賴 tunnel 的步驟（完整清單）**：W0-9；W2-R2 的紅燈執行（撰寫與 `--list` 載入檢查不需要）；W2-RG2；W2-G2 的 017 綠燈自驗；W2-PG 第 8 項（017 + 016 + canary 兩輪）與連帶的 W2-F `Utils/UploadedFile` 抽取（ADJ-W2-16）；W3 全段（含 W3-G0 修正第一波偏離）；W4 全段；W5-FG 第 8 項；W5-SS。每一步開頭先跑 CMD-TUNNEL，探測失敗才走 `blocked_tunnel` 分支。
**不依賴 tunnel**：W0 其餘項目、W1 全段、W2-R1、W2-RG1、W2-G1、W2-G1C、W2-G2 的 PHPUnit 部分、W2-F（不含 UploadedFile）、W2-PG 第 1～7、9、10 項、W5-D（建議仍排在 FG 之後）。

**tunnel 狀態一律以執行當下的 CMD-TUNNEL 為準**。2026-10-01 16:28 本 agent 實測 `https://local-turbo.powerhouse.tw/wp-login.php` 回 HTTP 200、title「登入 ‹ turbo — WordPress」；主窗口隨後獨立驗證相同結果並確認：被系統回收的只是主窗口先前啟動的那一個 cloudflared，另外三個（含 Session 0 的服務）一直在服務。tunnel 可用，但**記憶體吃緊的前提仍成立**，§0.4 的並行限制照舊。CMD-TUNNEL 不通時：不修環境、不啟動 cloudflared，progress 該步驟標 `blocked_tunnel`，走 UC-W2-T（§3.7）。

### 0.4 並行規則（取代藍圖一 §0 第 6 點 L20，ADJ-W2-06）

| 同時進行 | 允許 | 理由 |
|---------|------|------|
| 兩個 playwright run（任何人） | **禁止** | 記憶體吃緊（tunnel 已因記憶體不足被系統回收過）；共用 `plugin.php` LC 鎖與站台資料 |
| 兩個 PHPUnit run | **禁止** | tests 容器共用一個 DB（藍圖一 L20 原則不變） |
| E2E ＋ 只跑 PHPUnit 的 **Red** agent（test-creator 寫 PHPUnit） | 允許 | 不同基礎設施；Red 不跑 build / i18n:build |
| E2E ＋ PHP **Green / Refactor** agent | **禁止** | 他們會跑 `pnpm run i18n:build`，重寫站台正在讀的 `languages/*.mo` / `*.json`，E2E 的中文斷言會在重寫瞬間假紅 |
| E2E ＋ 任何 `pnpm run build` / `build:wp` | **禁止** | 重寫站台正在服務的 `js/dist` / `inc/assets/dist` |
| PHPUnit ＋ `pnpm run i18n:build` / `build`（另一方執行） | **禁止** | PHPUnit bootstrap 讀 `languages/power-course-zh_TW.mo`，找不到會 `exit(1)`、讀到半截會讓 zh_TW 斷言假紅 |
| 主窗口 Gate 的 PHPUnit ＋ 主窗口背景的 E2E | 允許（W0-9 與 W1-R / W1-RG 的重疊就是這種） | 同上第 3 列；但見下方注意 |

注意（未實測，模糊標記）：wp-env 把整個外掛目錄掛進 tests 容器，playwright 的 global-setup / teardown 會改寫 `plugin.php`（注入 / 還原 `'lc' => false`），PHPUnit bootstrap 會讀到改寫中或改寫後的 `plugin.php`。**與 E2E 重疊的 PHPUnit 出現任何非預期紅燈，一律等 E2E 結束後重跑再歸因**，不得直接判定為功能問題。W3、W4 一律序列（ADJ-W2-15）。

### 0.5 prompt 組裝

見 §3.2 的 CMD-PROMPT。每次派發前：
1. `node "$LOG/assemble.mjs" <CARD>` → 產出 `$LOG/prompt-<CARD>.txt`，印出剩下的手動欄位
2. 把手動欄位換成上游回報原文（不摘要）
3. 以該檔全文當 Agent 的 prompt；`subagent_type`、`name`、`description` 照卡片表格

---

## 1. 環境確認（只列與藍圖一 §1 不同處）

### 1.1 模式與分支

| 項目 | 狀態 |
|------|------|
| 執行環境 | 本地 |
| 分支 | `feat/issue-131-wave2`（疊在第一波之上，已合併 master v1.8.11） |
| HEAD | `e520fd7e docs(specs): Issue #131 第二波實作計劃` |
| 既有變更 | ` M .serena/project.yml`（與本功能無關，不得 commit、不得還原） |
| Worktree | 不使用（同第一波；R-28） |
| 進度檔 | `specs/plan/issue-131-assignment-grading/.progress-wave2.json`（新檔）；第一波 `.progress.json` 唯讀 |

### 1.2 specs 完整性

| 項目 | 狀態 |
|------|------|
| `specs/features/assignment/{查詢作業討論串,發送作業討論訊息,匯入批改結果CSV}.feature` | 存在；Example / Outline 展開後 11 / 11 / 21 個案例（逐一數過） |
| api.yml 錨點 | L1371 `AssignmentMessage`、L1395 `AssignmentImportSummary`、L7386 import、L7479 管理端 messages、L7758 學員端 messages（實測行號） |
| `specs/ui/作業批改頁.md` L16–17、`specs/ui/教室作業區塊.md` L17 | 第二波段落存在 |
| 參考實作 `C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\course-assignment-thread.service.ts`、`course-submission-csv.service.ts`、`apps\api-gateway\src\common\csv\csv-import.helper.ts` | 存在 |
| 結論 | 規格完整，走標準 TDD |

### 1.3 本 session 實測的環境事實

- **REST callback 命名**（`powerhouse/vendor/j7-dev/wp-utils/src/classes/ApiBase.php` L84–95）：`{method}_{endpoint}_callback`，endpoint 的 `(?P<id>\d+)` → `with_id`、`-` 與 `/` → `_`。所以本波五個 callback 是 `Core\Api::get_assignment_submissions_with_id_messages_callback`、`post_assignment_submissions_with_id_messages_callback`、`post_assignment_submissions_import_callback`，`Core\MyApi::get_my_assignment_submissions_with_id_messages_callback`、`post_my_assignment_submissions_with_id_messages_callback`。ApiBase 的 `try()` 會把未攔截例外轉 500。
- `AssignmentTestCase` 已有 `assert_callback_starts_with_nocache_headers_for( $class, $method )`（L799）、`seed_message()`（L381）、`dispatch()`（L416，**不支援檔案**）、`set_now()`（L205）、`capture_mail()`（L437）、`run_scheduled_actions()`（L471）、`create_email_template()`（L1057）、`assert_mail_sent_to()`（L1102）；`TestCase` 有 `enroll_user_to_course()`（L206）。批改信的排程 hook 名稱 `power_email_send_assignment_graded`（`AssignmentGradedEmailTest` L43）。
- `MyApi::get_uploaded_file()` 讀 `$request->get_file_params()`（L244–281），`translate_upload_error()` 是 public static（L229，第一波測試直接呼叫）。
- PHPUnit 設定檔是 **`phpunit.xml.dist`**（藍圖一 L90 寫 `phpunit.xml`），沒有 group 白名單。
- 耗時參考：全套 PHPUnit 1:40、`--group issue-131` 0:22（第一波 P8 / P9 log）；E2E 41 支單輪 26.7～41.7 分鐘。W0-9 的 6 支 spec 共 30 支 test（`--list` 實數：smoke 4、008 3、015 3、assignment-grading 7、021 8、016 5）。
- **Playwright `--list` 不跑 global-setup**（`tests/e2e/node_modules/playwright/lib/runner/testRunner.js` L378–381：listOnly 只有 load + report 兩個 task），實測跑完 `plugin.php` 無變更、無鎖檔、不需網路 → 可當 E2E spec 的「`php -l`」，tunnel 不通時也能做。
- **`tests/e2e/README.md` 的 LC 還原指令是壞的**：`node -e "import('./helpers/lc-bypass.js')…"` 實測 `ERR_MODULE_NOT_FOUND`（沒有 .js 檔）；改成 `.ts` 在 Node v22.17.1 得到 `ERR_UNKNOWN_FILE_EXTENSION`。可用寫法：`node --experimental-strip-types --no-warnings -e "import('./helpers/lc-bypass.ts').then(m => …)"`（已實測可 import 並呼叫唯讀的 `isLcBypassApplied()` → `false`；**`revertLcBypass()` 本身未執行過**）。
- **WP 核心對已登入的 REST 請求一律送禁止快取標頭**：`wp-includes/rest-api/class-wp-rest-server.php` L487–501（本機站 WP 7.1.2）在 dispatch 之後、`rest_pre_serve_request` 之前，`rest_send_nocache_headers` 預設 = `is_user_logged_in()`。所以 E2E 對「已登入請求」斷言 `Cache-Control: no-cache` **不能**證明 callback 有呼叫 `nocache_headers()`（見 L-W2-1）。
- 2026-10-01 16:2x：無 `plugin.php.e2e-lock` / `plugin.php.e2e-backup`，`git grep "'lc'[[:space:]]*=>" -- plugin.php` 無輸出。

### 1.4 第二波新增的「會說謊的儀器」（寫進對應 prompt；藍圖一 L1–L15 照舊有效）

| # | 儀器 | 看起來 | 真相 / 對策 |
|---|------|--------|------------|
| L-W2-1 | 017 對已登入學員 GET 討論串斷言 `Cache-Control` 含 `no-cache` | 證明 callback 有 `nocache_headers()` | WP 核心對已登入請求本來就送（§1.3）。拿掉 callback 那行照樣綠。**真正的守門只有 PHPUnit 的 token_get_all 靜態檢查**；017 這條只證明「整條 stack（WP Rocket / Nginx / tunnel）沒有剝掉標頭」。第一波 016 的下載標頭斷言同理（第一波測試已凍結，只記錄不修） |
| L-W2-2 | `retries: 1` 下 playwright `EXIT_CODE=0` | 全綠 | flaky 也 exit 0。一律讀摘要行與 flaky 清單 |
| L-W2-3 | `--group issue-131` 的 Tests 數 = 287 + N | 第一波沒壞 | 本波測試也標 `issue-131`，第一波若少了一支、本波多了一支，總數照樣對。第一波回歸改用 `--group issue-131 --exclude-group issue-131-w2`，必須**正好** 287 |
| L-W2-4 | 「回應不含 sender_email」的斷言 | 有測到外洩 | 資料裡根本沒有 email 時永遠綠。必須先 seed 一則 `sender_email = admin@test.com` 的訊息，並斷言回應鍵集合 = 6 個白名單鍵、`wp_json_encode` 不含該字串 |
| L-W2-5 | 測試裡用字面 `9999` 當不存在的 ID | 不存在 | InnoDB auto_increment 不隨交易回滾，長跑後真實 ID 可能撞到。用 `nonexistent_id()` |
| L-W2-6 | 計劃 W2-R1 的「`size = 10485760` → 訊息**不是**『檔案過大』」 | 邊界測到了 | 實作前路由不存在（404），「不是檔案過大」照樣成立 → Red 就綠。改為正向斷言 400「請選擇要匯入的 CSV 檔案」（PHPUnit 裡 `is_uploaded_file()` 必為 false，ADJ-W2-14） |
| L-W2-7 | 017 的討論串測試在 W2-RG2 是綠的 | 意外綠 → 退回 | 路由 W1 就做好了，綠是正確的；必須在 manifest 申報為守門（ADJ-W2-18） |
| L-W2-8 | `reference_local_frontend_verify_playbook` 舊版第 1 點「驗完必 `git checkout -- plugin.php`」 | 標準還原步驟 | 已被 `.claude/rules/wordpress.rule.md`「併發時不要手動清」明文禁止（會砍掉別人的 bypass、連帶丟掉 `plugin.php` 未提交改動）。w2-tdd 已於 2026-10-01 更正該 memory，但計劃 W4 仍寫「依 playbook 處理 license bypass」、舊版內容也可能已在其他 agent 的 context 裡 → prompt 一律明寫「LC 照 W2:LC」 |
| L-W2-9 | `tests/e2e/README.md` 的 `revertLcBypass()` 指令 | 能還原 | 實測 import 失敗（§1.3），在「真殘留」時會讓人以為已還原。用 CMD-LC-W2 的 strip-types 寫法 |
| L-W2-10 | E2E 重疊期間的 PHPUnit 結果 | 可直接當證據 | `plugin.php` 在 E2E 開始 / 結束時被改寫（§0.4 注意）。重疊期間的紅燈先重跑再歸因 |

---

## 2. 調整（ADJ-W2）

### 2.1 對藍圖一的補丁

| ID | 修正藍圖一哪裡 | 改成什麼 | 為什麼 |
|----|---------------|---------|--------|
| ADJ-W2-01 | S-CONTEXT L470–L472（分支、規格 commit、功能、計劃） | W2:CONTEXT：分支 `feat/issue-131-wave2`、基準 `e520fd7e`、計劃改指第二波、UC 裁決、凍結規則 | 原文寫死第一波分支與 `2397234f`，照貼會讓 agent 讀錯計劃 |
| ADJ-W2-02 | §3.1 L152–L155（group 計數）、CMD-PU-GROUP L180–L186、S-CONTEXT L484–L486、S-RED-PHP L514–L515 與 L533–L534、S-GREEN-PHP L615–L617、S-REFACTOR-PHP L641、S-REPORT-RED L696、S-REPORT-GREEN L707 | 新 class 寫三個 `@group`（`assignment`、`issue-131`、`issue-131-w2`）；本波計數用 `--group issue-131-w2`；第一波回歸用 `--group issue-131 --exclude-group issue-131-w2` 且必須 = 287 | L-W2-3：只看 287 + N 的總和會讓第一波少一支被本波多一支抵銷 |
| ADJ-W2-03 | CMD-LC L254–L263、S-RED-E2E 第 6 點 L564–L567、S-GREEN-REACT L684、§3.4「LC 殘留」L310、L11 L114 | W2:LC 與 CMD-LC-W2：先看 `plugin.php.e2e-lock` 與 PID 存活，再判斷；agent 一律不動檔案；真殘留由主窗口用 strip-types 版 `revertLcBypass()` | 藍圖一「diff 後 cp 備份 / Edit 刪 lc 行」寫於引用計數鎖出現之前；併發時會拆掉別人的 bypass（memory `project_lc_bypass_concurrency_lock`、`.claude/rules/wordpress.rule.md` L307–L327、`tests/e2e/README.md` L359–L383）；README 的還原指令實測壞掉（L-W2-9） |
| ADJ-W2-04 | §3.4「E2E 兩次一綠一紅 / 出現 flaky → 視為紅」L300、L10 L113、藍圖一各 PG「兩次都 0 failed、0 flaky」（L1268、L1458、L1872、L2010、L2035） | W2:E2E-JUDGE：每輪 0 failed / 0 did not run / 0 interrupted；flaky 逐支列首次失敗原因，必須全是逾時類；**本波新 spec** 同一支 test 兩輪都 flaky 視同紅 | 出處：team-lead 2026-10-01 指示；第一波 P9 最終關卡 8 輪（`scratchpad/issue131/p9fg-*.log`）最佳為 0 failed + 1～2 flaky，從未達成 0 flaky；`tests/e2e/playwright.config.ts` 本機 `retries: 1` 註解「重試後仍紅才算真紅」。最後一條是本藍圖加嚴：第一波 `assignment-grading.spec.ts:455` 在 8 輪中 flaky 3 次、從未追根因，新程式碼不重蹈 |
| ADJ-W2-05 | CMD-E2E L247–L252 | CMD-E2E-W2：先 CMD-TUNNEL；**sub-agent 每輪前景執行**（timeout 600000，一個指令約 10 支 test 以內，超過就拆）；單輪預估超過 10 分鐘的大型 E2E（W0-9、W2-PG、W3-PG、W4-PG、W5-FG）**由主窗口執行**，主窗口背景執行時自己掛 Monitor 盯結果檔摘要行；摘要 + flaky 清單 + 首次錯誤三段輸出 | 本機每支 test 0.65～1.0 分鐘，W5-FG 單輪約 30～50 分鐘，超過前景上限；sub-agent 背景等通知實測會卡住不醒（W1-R 30 分鐘、W2-R1 約 17 小時，memory `feedback_subagent_background_wait_stall`），前景執行的 W2-R2、W2-G2 正常收尾；L-W2-2 |
| ADJ-W2-06 | §0 第 6 點 L20 | §0.4 並行矩陣 | team-lead 指示（PHPUnit 與 playwright 可並行、不得兩個 playwright）＋ 實測的共用檔案（`languages/`、`js/dist`、`plugin.php` 都被兩邊讀寫） |
| ADJ-W2-07 | S-RED-E2E 第 3 點 L561（有順序依賴用 `test.describe.serial()`） | 不用 serial；需要順序的流程放同一個 test 用 `test.step()`；共用資料在 `beforeAll` 準備 | 第一波 016 L505 的實測註記與 `assignment-grading.spec.ts` L27：serial 中一支紅，其餘全部 did not run，紅燈原因被遮住；計劃 W2-R2 亦明文要求 |
| ADJ-W2-08 | S-RED-E2E 必讀 L548 | 學員登入用 `helpers/frontend-setup.ts::loginAs()`（已處理 `confirm_admin_email`、`waitUntil:'commit'`、60s）＋ `getNonceFromPage(page, { required: true })`；**不要**複製 016 / assignment-grading.spec.ts 檔內的 `loginStudentAndGetNonce()`；章節 slug 用 `createCourseWithChapters()` 回傳的 `chapterSlugs`；建使用者用 `ensureUserViaPowerhouse()`；等提示用 `waitForMessage()`（兩套管道，icon 區分型別），優先斷言 DOM；列表斷言前等 `.ant-skeleton` 歸零 | team-lead 必知事項 4–6；那兩個檔內 helper 寫於 `loginAs` 修好之前，第一波 P9 第 8 輪 flaky 即為其 `waitForURL` 逾時（`p9fg-r8.log` L125–L140） |
| ADJ-W2-09 | S-RED-E2E 自我驗證 L571–L574 | 先 `npx playwright test <spec> --list`（Total = 申報數）；tunnel 不通時只做這步並回報「未執行」 | §1.3 實測 `--list` 不跑 global-setup；讓 E2E 撰寫不被 tunnel 擋 |
| ADJ-W2-10 | CMD-TEST-HASH L200–L207 | 三份清單：凍結 A（第一波測試 37 檔，硬凍結；其中 `assignment-grading.spec.ts` 依 ADJ-W2-28 於 W3-G0 起解凍、W3-G0C 以新 sha1 重新凍結）、凍結 B（共用測試基礎 11 檔，軟凍結）、各階段 RG 記錄的本波測試檔；主判 sha1，輔判 `git diff e520fd7e` | 計劃 §3 / §11「不改第一波任何測試檔」；autocrlf=true，sha1 抓得到「被重寫但內容相同」，git diff 分得出是否真的改了內容 |
| ADJ-W2-11 | CMD-PHPCS L228 的基準 `2397234f` | `e520fd7e` | 只檢查第二波變更的 PHP 檔 |
| ADJ-W2-12 | S-RED-PHP L506、L529（介面出處「計劃 §5.1」）；S-GREEN-PHP L588–L591；S-GREEN-REACT L656–L662 | 改指第二波計劃 §4.2 介面契約、§12.1 / §12.2 / §12.3 | 原文指向第一波計劃章節與第一波的對照程式 |
| ADJ-W2-13 | §3.5 L327「P1-G1 的 TestCase.php、bootstrap.php 例外」 | **第二波唯一的測試檔例外：W3-G0 可修改 `tests/e2e/01-admin/assignment-grading.spec.ts`，限於預設篩選變更造成的前提失效，不得改變其他斷言語意；經主窗口核准（2026-10-01）**（細節見 ADJ-W2-28、§8.0）。除此之外沒有任何測試檔例外：`TestCase.php`、`bootstrap.php` 等凍結 B 的檔案只能「新增 helper 函式」且要申報 | 本波不改 schema，沒有理由碰 bootstrap；預設篩選改回「待批改」（ADJ-W2-27）會讓該 spec 兩支 test 的前提失效，不改必紅 |
| ADJ-W2-20 | 藍圖一 §0 第 2 點（主窗口手動替換 `{{S-XXX}}`） | CMD-PROMPT 組裝器（錨點檢查 + 機械替換） | team-lead「不要重寫這些區塊」；手動貼 8 個區塊容易漏段或摘要 |
| ADJ-W2-26 | CMD-PHPSTAN 的 JSON 比對 L216–L223（`p.replace(/\\/g,"/")`） | CMD-PHPSTAN-W2：比對腳本放在本檔附錄 C，用 awk 從檔案取出（不經過命令列傳遞），路徑分隔符用 `String.fromCharCode(92)`；同時輸出總數、作業範圍錯誤數、相對基線新增數 | 本 session 實測：經 Bash 工具傳遞時 `\\` 被吃成 `\`，`/\\/g` 變成 `/\/g` → node SyntaxError（第一波 progress 的 L16 是同一類問題）。新腳本以 W0 JSON 自比得 `TOTAL=95 NORM_OK=true ASSIGNMENT_SCOPE=0 NEW_VS_BASELINE=0`；插入一筆假的 `Assignment/Service/Thread.php` 錯誤做對照組，得 `ASSIGNMENT_SCOPE=1 NEW_VS_BASELINE=1`，確認不是空檢查 |

### 2.2 對第二波計劃的調整

| ID | 修正計劃哪裡 | 改成什麼 | 為什麼 |
|----|-------------|---------|--------|
| ADJ-W2-14 | §11 W2-R1「`size = 10485760` → 訊息**不是**『檔案過大』」 | 斷言 400 +「請選擇要匯入的 CSV 檔案」 | L-W2-6；違反計劃自己的「意外綠清單：無」 |
| ADJ-W2-15 | §11.1「W4 可在 W1 通過後與 W2 / W3 同時進行」 | W3 → W4 嚴格序列 | 兩者都要跑 E2E（不得兩個 playwright）；W3 的 `pnpm run build` 與 W4 的 `i18n:build` / `build:wp` 會同時重寫 `languages/`、各自的 dist，且 `manual.json` 會衝突；「一個寫碼、一個跑 E2E」無法執行——兩邊的 Green agent 自我驗證都要跑 E2E，主窗口控制不了它們何時跑 |
| ADJ-W2-16 | §11 W2 Refactor「抽 `Utils/UploadedFile`」 | 條件式：只有 W2-PG 第 8 項（含 016）能在 Refactor 後立即執行時才做；否則跳過並在 progress 記 `deferred_refactor` | 它改的是第一波的上傳路徑，`is_uploaded_file()` 那段只有 016 的真實 multipart 驗得到（R-13）；tunnel 不通時改了等於沒驗 |
| ADJ-W2-17 | §11 W2 Green 單一派發 | 拆 G1（`CsvParser` + `CsvImport` + 往返，全 PHPUnit）、G2（import 路由 + callback + 017） | 規模 L（藍圖一 ADJ-07 的拆法）；並把 tunnel 依賴集中在 G2 |
| ADJ-W2-18 | §11「預期『意外綠』清單：無」 | 只適用 PHPUnit。017 的討論串三條（學員讀自己 200 + 標頭、讀他人 403、訪客 401）在 W2-RG2 必為綠，必須申報為守門 | L-W2-7 |
| ADJ-W2-19 | §3 第 5 列 `ImportGradingCsvTest.php`（一個檔同時放 dispatch 層與 Service 層） | 拆成 `ImportGradingCsvApiTest.php`（dispatch 層：feature 前三個 Example + INI_SIZE + 10485760 邊界）與 `ImportGradingCsvTest.php`（Service 層） | W2-G1 / G2 分棒時，Gate 依 class 判讀，不必逐方法比對 |
| ADJ-W2-21 | §11 W2-R2 的 017 清單 | 加一條「multipart 上傳無法解析的 CSV（未閉合引號）→ 200、四個計數 0、`errors[0].row = 0`」；所有匯入測試都要有「匯入前 / 匯入後」狀態對照 | feature「上傳無法解析的檔案 → 操作成功」的 200 在 PHPUnit 只能到 Service 層，API 層有沒有把 `UnexpectedValueException` 轉成 200 只有真實 HTTP 驗得到；前後對照讓 017 即使在 tunnel 延誤、失去紅燈先行時也不會空洞（UC-W2-T 選 B 的前提） |
| ADJ-W2-22 | §11 W1-R 的 `dispatch_with_file()`（W1 建、W2 用） | 移到 W2-R1 才加進 `AssignmentWave2TestCase` | W1 用不到；Red 可擴充先前階段的本波測試檔（同第一波 P3-R1 擴充 `AssignmentTestCase`） |
| ADJ-W2-23 | §11 驗證關卡 4、§17「PHPStan ≤ 95」 | 總數 ≤ **95**（W0-4 實測，合併 master v1.8.11 後）**且**作業相關檔（`inc/classes/Resources/Assignment/**`、`inc/templates/components/assignment/**`）錯誤數 = **0**（W0-4 實測為 0）；用 CMD-PHPSTAN-W2 判讀 | 只看總數，新錯誤會藏在既有 95 條噪音的波動裡（例如本波新增 1 條、別處碰巧少 1 條，總數不變）。範圍取整個作業目錄而非「本波改過的檔」：基線已是 0，取整個目錄更簡單也更嚴，且 W4 會改 `inc/templates/components/assignment/` |
| ADJ-W2-24 | §11 W4「依 `reference_local_frontend_verify_playbook` 處理 license bypass、WP Rocket」與 W5「`plugin.php.e2e-backup` 不存在（先看鎖）」 | license bypass 只靠 global-setup；還原只走 W2:LC / CMD-LC-W2；WP Rocket 清快取照 playbook 第 2、3 點 | L-W2-8 |
| ADJ-W2-25 | §11 W5「E2E 連跑兩次都綠、無 flaky」 | W2:E2E-JUDGE | 同 ADJ-W2-04 |
| ADJ-W2-27 | §11 W3（新增工作，**修正第一波偏離**） | 批改頁預設狀態篩選從「全部」改回「待批改」（`js/src/pages/admin/Assignments/index.tsx:40` 的 `useState<TStatusFilter>(STATUS_ALL)`），並修正連帶的詳情抽屜快照問題；由新的 W3-G0 單獨派發 | 主窗口查證：`specs/ui/作業批改頁.md` 未規定預設篩選；參考實作 `SubmissionsManager.tsx:513` 為 `useState<StatusFilter>('pending')`；第一波偏離了用戶「完全參照 zenbu-site-2.0」的裁決。匯出按鈕沿用目前篩選（`ExportButtons` 收 `status`），預設「待批改」時「開頁 → 匯出 → Excel 改 → 匯回」只動到待批改的列、不會對已批改學員重寄通知；參考實作「不防重匯」能成立的前提就是這個預設值。所以同時修正偏離並消除 UC-W2-2 的風險，UC-W2-2 維持 A。單獨派發是為了讓它能成為獨立 commit，必要時 cherry-pick 回第一波分支（§10 W5-CK） |
| ADJ-W2-28 | 計劃 §3 / §20「不修改第一波任何測試檔」 | **經主窗口核准的唯一例外（2026-10-01）**：`tests/e2e/01-admin/assignment-grading.spec.ts` 只能由 **W3-G0** 修改，只限 §8.0 表中標「改」的 `:455`、`:573` 兩支 test，限於預設篩選變更造成的前提失效，不得刪除或放寬任何既有斷言。凍結狀態三段：W0～W3-RG **仍凍結**（CMD-FREEZE 照驗，W3-R 不得改）→ W3-G0 派發時**解凍**（progress `freeze.thaw[0].state = thawed`）→ W3-G0C 通過時以新 sha1 **重新凍結**（`state = refrozen`），之後任何人不得再動 | ADJ-W2-27 讓該檔兩支 test 的前提（預設看得到全部列）失效，不改必紅。由 W3-G0 改而不是 W3-R：產品變更與它直接造成的測試前提變更放在同一組變更裡，W3-CK 才能把它們一起 cherry-pick 回第一波分支；W3-R 是紅燈作者，不碰第一波檔案。紅燈先行由 W3-R 在新 spec 寫「預設選中待批改」那條保證 |

### 2.3 DEC-W2 未調整

DEC-W2-01～20 沒有妨礙 TDD 執行的地方，原樣沿用。需用戶確認的 DEC-W2-12（UC-W2-1）、DEC-W2-15（UC-W2-2）已由主窗口裁決（§3.7），不再詢問。

---

## 3. 全域規則（差異；未列者沿用藍圖一 §3）

### 3.1 名詞

- **w2 group 計數**：該測試在 `--group issue-131-w2` 執行中算進 Tests 的數量（method × data set）。
- **第一波回歸數**：`--group issue-131 --exclude-group issue-131-w2` 的 Tests，必須恆為 **287**。
- **累計預期**：`w2_group_total` = 先前各階段 PG 記錄的 w2 Tests + 本階段 manifest；`suite_total` = 前一階段 PG 全套 Tests（W1 用基線 1638）+ 本階段全套增量。
- manifest、回歸守門、全套增量：同藍圖一 L151–L155。

### 3.2 標準命令（Bash 工具 / Git Bash）

每個命令開頭重設變數：

```bash
PC="C:/Users/User/LocalSites/turbo/app/public/wp-content/plugins/power-course"
LOG="C:/Users/User/AppData/Local/Temp/claude/C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course/b282e1ad-511b-4c4d-a8e2-090f63bf6a4b/scratchpad/issue131-w2"   # 第一次用前 mkdir -p "$LOG"
TAG="w1-rg"
```

**沿用藍圖一（原文照用，只換 `$LOG` / `$TAG`）**：CMD-GIT-SCOPE（L169–L172）、CMD-PHPL（L174–L178）、CMD-PU-FULL（L188–L193）、CMD-PU-DIR（L195–L198）、CMD-I18N（L233–L239）、CMD-ESLINT / CMD-TSC（L241–L245）、CMD-DIST（L265–L268）。

**CMD-PROMPT**（W0-2 取出組裝器一次；之後每次派發前執行）

```bash
# 取出（W0-2 執行一次）
cd "$PC" && awk '{sub(/\r$/,"")} /^<!-- BEGIN TOOL:ASSEMBLE -->$/{f=1;next} /^<!-- END TOOL:ASSEMBLE -->$/{f=0} f && !/^```/' specs/plan/issue-131-assignment-grading/wave2-tdd-blueprint.md > "$LOG/assemble.mjs" && PC="$PC" node "$LOG/assemble.mjs" --check
# 組裝（每次派發）
cd "$PC" && PC="$PC" node "$LOG/assemble.mjs" W1-R "$LOG"
```
判準：`--check` 印 `ANCHORS_OK`；組裝印 `WROTE …` 與 `MANUAL_PLACEHOLDERS:` 清單。exit 2（錨點不符）→ STOP。組裝器原始碼在附錄 B。

**CMD-TUNNEL**（每個 [T] 步驟開始前）

```bash
cd "$PC" && U=$(grep -E '^TEST_SITE_URL=' .env | cut -d= -f2- | tr -d '\r'); timeout 40 node -e "const c=new AbortController();setTimeout(()=>c.abort(),30000);fetch(process.argv[1]+'/wp-login.php',{redirect:'manual',signal:c.signal}).then(async r=>{const t=await r.text();console.log('TUNNEL_HTTP='+r.status+' TITLE='+((t.match(/<title>([^<]*)<\/title>/)||['',''])[1].trim()))}).catch(e=>console.log('TUNNEL_ERR='+(e.cause&&e.cause.code||e.name)))" "$U"; echo "PROBE_DONE"
```
判準：`TUNNEL_HTTP=200` 且 TITLE 含「登入」→ 通。其他（530 / 502 / `TUNNEL_ERR=`）→ 不通：不修環境、不啟動 cloudflared，該步驟標 `blocked_tunnel`。只讀 `.env` 的 `TEST_SITE_URL` 一行（不讀帳密）。2026-10-01 16:28 實測輸出：`TUNNEL_HTTP=200 TITLE=登入 &lsaquo; turbo &#8212; WordPress`。

**CMD-PHPSTAN-W2**（取代藍圖一 CMD-PHPSTAN L209–L224；ADJ-W2-23、26）

```bash
# 取出比對腳本（W0-4 執行一次）
cd "$PC" && awk '{sub(/\r$/,"")} /^<!-- BEGIN TOOL:PHPSTAN -->$/{f=1;next} /^<!-- END TOOL:PHPSTAN -->$/{f=0} f && !/^```/' specs/plan/issue-131-assignment-grading/wave2-tdd-blueprint.md > "$LOG/phpstan-scope.mjs"
# 每次 Gate
cd "$PC" && composer run phpstan > "$LOG/$TAG-phpstan.log" 2>&1; echo "EXIT_CODE=$?"; grep -nE "Found [0-9]+ errors?|No errors" "$LOG/$TAG-phpstan.log"
cd "$PC" && vendor/bin/phpstan analyse --memory-limit=3072M --error-format=json > "$LOG/$TAG-phpstan.json" 2>/dev/null; node "$LOG/phpstan-scope.mjs" "$LOG/phpstan-baseline.json" "$LOG/$TAG-phpstan.json"
```
判準：最後一行 `TOTAL ≤ 95`、`NORM_OK=true`、`ASSIGNMENT_SCOPE=0`。`NEW_VS_BASELINE` > 0 時上方會逐條印出 `+N  檔案 | 訊息`：落在作業範圍 → 必修；落在範圍外且總數仍 ≤ 95 → 記 note、列入回報（多半是既有噪音隨程式碼位移）。`NORM_OK=false` → 路徑正規化失敗，`ASSIGNMENT_SCOPE` 不可信，先修腳本再判。基線 JSON：W0-4 的 `w0-phpstan.json`（TOTAL=95、ASSIGNMENT_SCOPE=0）複製成 `$LOG/phpstan-baseline.json`。

**CMD-PU-W2**（本波測試）

```bash
cd "$PC" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131-w2 --testdox > "$LOG/$TAG-w2.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "^(OK \(|OK, but|Tests: |No tests executed)|FAILURES!|ERRORS!|Fatal error|ParseError" "$LOG/$TAG-w2.log"
echo "PASS=$(grep -c '✔' "$LOG/$TAG-w2.log") FAIL=$(grep -c '✘' "$LOG/$TAG-w2.log") SKIP=$(grep -c '↩' "$LOG/$TAG-w2.log") INCOMPLETE=$(grep -c '∅' "$LOG/$TAG-w2.log") RISKY=$(grep -c '☢' "$LOG/$TAG-w2.log")"
```

**CMD-PU-W1REG**（第一波回歸，必須正好 287）

```bash
cd "$PC" && npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --exclude-group issue-131-w2 > "$LOG/$TAG-w1reg.log" 2>&1; echo "EXIT_CODE=$?"
grep -nE "^(OK \(|OK, but|Tests: |No tests executed)|FAILURES!|ERRORS!|Fatal error|ParseError" "$LOG/$TAG-w1reg.log"
```
判準：`OK (287 tests, 1398 assertions)`。Tests ≠ 287 → STOP（第一波測試被動到或分組被破壞）。Tests = 287 但 assertions ≠ 1398 → 記 note 並查是哪個 class 的斷言數依資料而變（不自動 STOP）。

**CMD-FREEZE**（第一波測試凍結；W0-7 與每個 RG / GG / PG 執行；腳本在附錄 D）

```bash
# 取出（第一次用前）
cd "$PC" && awk '{sub(/\r$/,"")} /^<!-- BEGIN TOOL:FREEZE -->$/{f=1;next} /^<!-- END TOOL:FREEZE -->$/{f=0} f && !/^```/' specs/plan/issue-131-assignment-grading/wave2-tdd-blueprint.md > "$LOG/freeze.mjs"
# 檢查（在專案根目錄執行）
cd "$PC" && node "$LOG/freeze.mjs"
```
判準：`[A …]`、`[B …]`、`[R …]`、`[內容差異 …]` 四個標頭下都沒有輸出，最後一行 `SHA_FAIL=0 CONTENT_DIFF=0`。最後一行同時印出 `A=`（仍凍結的第一波檔數）、`R=`（重新凍結數）、`AMENDED=`（凍結 B 經主窗口核可更新的檔數）、`THAWED=`（目前解凍中的檔案）。2026-10-01 17:0x 實測（含主窗口 `8c144db7` 對 `api-client.ts` 的 amendment）：`A=37 B=11 R=0 AMENDED=1 THAWED=0 SHA_FAIL=0 CONTENT_DIFF=0`。

**解凍 / 重新凍結 `assignment-grading.spec.ts`（ADJ-W2-28，只有主窗口執行）**
- W3-G0 派發前：`cd "$PC" && node "$LOG/freeze.mjs" --thaw tests/e2e/01-admin/assignment-grading.spec.ts`
- W3-G0C 全部通過後：`cd "$PC" && node "$LOG/freeze.mjs" --refreeze tests/e2e/01-admin/assignment-grading.spec.ts`（記錄新 sha1；未解凍就重新凍結會被拒絕）
- 這兩個動作會改寫 `.progress-wave2.json` 的 `freeze.thaw`；W3-G0 之前 `state = frozen`，CMD-FREEZE 照常驗它與 `e520fd7e` 相同。

**凍結 B 的核可更新（只限主窗口的環境修復，例如逾時上限）**：`cd "$PC" && node "$LOG/freeze.mjs" --amend-b <檔案> "<改了什麼>" "<理由>"`，寫入 `freeze.amendments`（與主窗口 `8c144db7` 的手動記錄同一格式）；之後該檔改以新 sha1 驗證、不再與 `e520fd7e` 比內容。

| 結果 | 代表 | 動作 |
|------|------|------|
| A 有 FAILED，且出現在「內容差異」 | 第一波測試被改 | STOP（藍圖一 §3.4「G / F：改了測試檔」L302；Red 改的也一樣，計劃 §3 明文禁止） |
| A 有 FAILED，「內容差異」沒有它 | 被重寫但內容相同（換行 / 編碼正規化） | 記 note、問觸碰者原因；主窗口決定是否更新該檔基準，不 STOP |
| R 有 FAILED | 重新凍結後的 `assignment-grading.spec.ts` 又被動 | STOP（W3-G0C 之後任何人不得再改） |
| `THAWED=` 不是空的，但目前不在 W3-G0 ～ W3-G0C 之間 | 忘了重新凍結 | 先跑 W3-G0C 的檢查，再 `--refreeze` |
| B 有 FAILED | 共用測試基礎被動 | 讀 `git diff` 該檔：agent 只「新增函式」且有申報 → `--amend-b` 接受；主窗口的環境修復（逾時、等待條件，不改斷言與回傳形狀）→ `--amend-b` 記錄；agent 改到既有函式行為 → STOP 問用戶 |

**CMD-W2-HASH**（本波測試檔；每個 RG 記錄、之後的 G / GG / F / PG 驗證）

```bash
# 記錄（RG 通過時）
cd "$PC" && { git diff --name-only e520fd7e -- tests; git ls-files --others --exclude-standard -- tests; } | sort -u | xargs -r sha1sum > "$LOG/$TAG-tests.sha1"; cat "$LOG/$TAG-tests.sha1"
# 驗證
cd "$PC" && sha1sum -c "$LOG/<該階段 rg 的 TAG>-tests.sha1" 2>&1 | grep -v ": OK$"; echo "SHA_CHECK_DONE"
```
記錄的內容同時寫進 progress 該階段 `red.test_sha1`（`$LOG` 是 session 專屬，換 session 會消失）。

**CMD-PHPCS-W2**（ADJ-W2-11）

```bash
cd "$PC" && FILES=$( { git diff --name-only --diff-filter=AMR e520fd7e -- plugin.php inc; git ls-files --others --exclude-standard -- inc; } | grep -E '\.php$' | sort -u ); echo "$FILES"
[ -n "$FILES" ] && vendor/bin/phpcs --report=summary $FILES > "$LOG/$TAG-phpcs.log" 2>&1; echo "EXIT_CODE=$?"; cat "$LOG/$TAG-phpcs.log"
```

**CMD-E2E-W2**（ADJ-W2-05）

誰、怎麼跑：
- **sub-agent**：一律**每一輪各自前景執行**（Bash `timeout: 600000`）。一個指令約 10 支 test 以內（canary + 目標 spec，本機約 6～10 分鐘）；超過就拆成多個前景指令。多輪就前景依序跑多次。**不得** `run_in_background`、不得掛 Monitor 等通知、不得把多輪串成一個背景指令（W2:E2E-RUN 第 4、4b 點）。
- **主窗口**：單輪預估 ≤ 10 分鐘 → 同樣前景執行。單輪預估 > 10 分鐘（W0-9、W2-PG 第 8 項、W3-PG 第 5 項、W4-PG 第 6 項、W5-FG 第 8 項）→ **只由主窗口執行、不派給 sub-agent**；用 `run_in_background: true`（timeout 3600000，W5-FG 7200000），並**自己掛 Monitor 盯結果檔的摘要行**（`N passed (…m)` 出現、或 playwright PID 消失即結束），不要被動等完成通知（memory `feedback_subagent_background_wait_stall`：主窗口自己的 W0 E2E 也曾因 bash 外殼被殺，playwright 成孤兒續跑，通知沒來）。

```bash
# 前置：CMD-TUNNEL 通；cat "$PC/plugin.php.e2e-lock" 2>/dev/null 無活著的別人 PID；沒有人在 build / i18n:build
cd "$PC/tests/e2e" && npx playwright test <canary spec> <target specs…> --reporter=list > "$LOG/$TAG-e2e-run1.log" 2>&1; echo "EXIT_CODE_RUN1=$?"
```
跑完後讀結果（第二輪把 `run1` 換 `run2`）：

```bash
F="$LOG/$TAG-e2e-run1.log"
echo "[摘要]"; grep -nE "^\s*[0-9]+ (passed|failed|flaky|skipped|did not run|interrupted)" "$F"
echo "[flaky]"; awk '/^  [0-9]+ flaky/{f=1;next} /^  [0-9]+ [a-z]/{f=0} f' "$F"
echo "[failed]"; awk '/^  [0-9]+ failed/{f=1;next} /^  [0-9]+ [a-z]/{f=0} f' "$F"
echo "[首次錯誤]"; grep -nE -A14 "^\s+[0-9]+\) \[" "$F" | sed 's/\x1b\[[0-9;]*m//g' | grep -E "\) \[|Error:|TimeoutError|Timeout:|timeout of|Expected|Received|element\(s\) not found"
```
（已在第一波 `p9fg-r7.log` 實測：能列出 1 failed / 3 flaky 與各自的 Expected / Received / Timeout 行。）判準：W2:E2E-JUDGE。每輪後接 CMD-LC-W2。

**CMD-LC-W2**（取代藍圖一 CMD-LC L254–L263；ADJ-W2-03）

```bash
cd "$PC" && echo "[lock]"; cat plugin.php.e2e-lock 2>/dev/null || echo "NO_LOCK"
echo "[lc]"; git grep -n "'lc'[[:space:]]*=>" -- plugin.php; echo "[backup]"; ls plugin.php.e2e-backup 2>/dev/null || echo "NO_BACKUP"
# 鎖內每個 PID：tasklist //FI "PID eq <PID>"
```

| 看到 | 判定 | 動作 |
|------|------|------|
| `NO_LOCK`、`[lc]` 無輸出、`NO_BACKUP` | 乾淨 | — |
| 鎖內有**活著**的 PID | 有 run 在跑 | 什麼都不做；記錄「LC 檢查延後（持有者 PID …）」，等它結束再查 |
| 鎖內 PID 全死 | 崩潰遺留 | 不手動清；下次 `applyLcBypass()` 會自動剔除。若接下來沒有 E2E 要跑、又需要乾淨的 `plugin.php`（例如要 commit）→ 走下一列 |
| `NO_LOCK`（或鎖內全死）但 `'lc'` 或備份仍在 | 真殘留 | **只有主窗口**執行：`cd "$PC/tests/e2e" && node --experimental-strip-types --no-warnings -e "import('./helpers/lc-bypass.ts').then(m => m.revertLcBypass())"`，再重跑本命令；`git diff --stat -- plugin.php` 應無差異。仍殘留 → 問用戶 |

**禁止**：`git checkout -- plugin.php`、`cp plugin.php.e2e-backup plugin.php`、Edit 刪 lc 行、`rm plugin.php.e2e-lock` / `.e2e-backup`。sub-agent 一律只回報、不處理。

### 3.3 證據格式

沿用藍圖一 §3.3（L270–L284）。E2E Gate 另加：

```text
- CMD-TUNNEL 輸出：TUNNEL_HTTP=… TITLE=…
- run1 / run2 摘要行（含耗時）
- flaky 清單：spec:line｜test 名稱｜首次錯誤第一行｜分類（逾時類 / 內容錯誤）
- 本波新 spec 是否有同一支 test 兩輪都 flaky：有 / 無
- CMD-LC-W2 輸出與判定
```

### 3.4 失敗處理（藍圖一 §3.4 L286–L316 沿用；下列為覆寫與新增列）

| 情境 | 判定方式 | 動作 | 上限 | 耗盡後 |
|------|---------|------|------|--------|
| （覆寫 L300）E2E 不符 W2:E2E-JUDGE | CMD-E2E-W2 三段輸出 | 視為紅；R 階段重派 R、G 階段重試 G（附三段輸出原文） | 同 R / G | STOP |
| （覆寫 L299）E2E 紅、canary 也紅 | canary 結果 | 先 CMD-TUNNEL：不通 → `blocked_tunnel`、UC-W2-T；通 → 依 memory `reference_local_frontend_verify_playbook` 第 2～5 點排查（不得用 git checkout 還原 plugin.php，ADJ-W2-24）。環境修復不算 agent 重試 | 2 | 問用戶 |
| （覆寫 L310）LC 殘留 | CMD-LC-W2 | 依該表 | — | 問用戶 |
| CMD-PU-W1REG ≠ 287 或有紅 | 摘要行 | 先 CMD-FREEZE：凍結檔有改 → 依 CMD-FREEZE 表；沒改 → 視為 production 回歸，重試當階段最後一個 G / F（附失敗清單） | 3 | STOP |
| CMD-FREEZE 不符 | 該命令表 | 依該命令表 | — | — |
| 與 E2E 重疊期間的 PHPUnit 紅 | §0.4 | E2E 結束後重跑一次再歸因；重跑綠 → 記 note（L-W2-10），以重跑為證據 | 1 | 依重跑結果走一般列 |
| W0-9：第一波 spec 紅、canary 綠 | 摘要 | 基線問題：**不擋** W1、W2-R1～W2-G1C；**擋** W2-R2 以後所有 E2E 步驟。主窗口用 `zenbu-powers:systematic-debugging` 查根因或問用戶；不改第一波 spec（凍結） | — | 問用戶 |
| E2E worker 一啟動就崩（例如 `0xC0000142` STATUS_DLL_INIT_FAILED；W0-9 Run 2 實例：node 50 / chrome 39 個行程耗盡桌面堆積） | log 中 worker 啟動錯誤、測試幾乎全部 did not run | 該輪**無效**（不是功能訊號，也不算 agent 重試）；先確認鎖檔內沒有活著的 run，請用戶或主窗口關閉殘留的 chrome / node 行程後重跑該輪 | 2 | 問用戶（機器資源） |
| [T] 步驟遇 tunnel 不通 | CMD-TUNNEL | 標 `blocked_tunnel`；先做 §0.3「不依賴 tunnel」的步驟；到 W2-G2 仍不通 → UC-W2-T | — | UC-W2-T |
| Red 的 E2E 因 tunnel 只做了 `--list` | 回報 | 主窗口在 RG 親跑紅燈執行；紅燈原因不對 → 重派 R（附輸出） | 2 | STOP |
| Green 主張需要改 schema / 開交易 / 宣告路由 args | agent 回報 | 不接受（R-W2-15、DEC-W2-14、DEC-W2-02）；STOP 問用戶 | — | — |

### 3.5 全流程禁止事項（藍圖一 §3.5 L318–L328 沿用，另加）

- 啟動 / 停止 cloudflared、Local 站、Docker（環境由用戶決定）
- 兩個 playwright 同時跑；E2E 或 PHPUnit 執行中有人跑 `pnpm run build` / `build:wp` / `i18n:build`
- 修改凍結 A 的任何檔案；凍結 B 只能新增 helper 函式且必須申報
- `git checkout -- plugin.php`、`cp` 備份覆蓋、刪 lc 行、刪 `.e2e-lock` / `.e2e-backup`
- `START TRANSACTION` 或任何自開交易；REST 路由宣告 `args`；ALTER / 改 schema / 升 DB 版本
- `innerHTML` / `dangerouslySetInnerHTML` 插入訊息內容
- `git add .serena/project.yml`

### 3.6 `.progress-wave2.json`

路徑：`specs/plan/issue-131-assignment-grading/.progress-wave2.json`（本藍圖同時建立，已填基線、UC 裁決、凍結清單）。格式沿用藍圖一 §3.6（L336–L434）的階段物件（`phase / status / tests_added / tests_total / phpstan_errors / commit` 在頂層，`red / green / refactor / phase_gate`），差異：

- 頂層加 `wave: 2`、`base_commit`、`wave1_blueprint`（路徑、sha1、區塊行號與錨點）、`freeze`（凍結 A / B 的 sha1 陣列）、`counters`（`w2_group_total`、`issue131_group_total`、`suite_total`）、`tunnel`（最後一次 CMD-TUNNEL 結果）
- `phase_gate` 加 `w2`（本波 group）、`w1_regression`（必須 287）、`e2e`（每輪摘要、flaky 清單與分類）、`freeze_ok`
- `status` 值域加 `blocked_tunnel`（只因 tunnel 停住）與 `deferred`（用戶同意延後的 E2E 項目，見 UC-W2-T）
- `freeze.thaw`（`assignment-grading.spec.ts` 的解凍 / 重新凍結狀態，ADJ-W2-28）與 `freeze.amendments`（凍結 B 經主窗口核可的修訂，例如 `8c144db7` 的 `api-client.ts`）

**單一寫入者（2026-10-01 主窗口決定）**：`.progress-wave2.json` **只由主窗口寫入**。起因：w2-tdd 與主窗口曾交錯做「讀取 → 改自己的欄位 → 整份寫回」，有覆蓋對方修改的風險。sub-agent（含 w2-tdd）需要改進度檔時，把「鍵路徑 + 值」寫在回報裡，由主窗口寫入；附錄 D 的 `freeze.mjs --thaw / --refreeze / --amend-b` 會寫進度檔，所以也只由主窗口執行。

恢復程序沿用藍圖一 L436–L444，第 3 點的數字比對改為：CMD-PU-W2 = 最後一個 `checkpointed` 階段的 `phase_gate.w2.tests`、CMD-PU-W1REG = 287、CMD-PU-FULL = `phase_gate.full.tests`，並先跑 CMD-FREEZE。

### 3.7 用戶確認點

**已裁決（主窗口依用戶先前裁決「完全參照 zenbu-site-2.0 的作法」採預設值，已寫入 progress，不再詢問）**

| ID | 裁決 | 對測試 / 實作的影響 |
|----|------|-------------------|
| UC-W2-1 | 照參考實作：空檔 / 只有表頭 / 超過 5000 列 → 都回 row 0、不處理任何列；訊息「CSV 檔案為空」「CSV 沒有任何資料列」「單批匯入上限 5000 筆，請拆分後重試（目前 N 筆）」 | W2-R1 寫這 3 條邊界測試（不再暫缺） |
| UC-W2-2 | A：整份重匯不防（照參考實作）；**不加** C 的提醒文字 | W2-R1 寫「每列各寄一次」「缺回饋欄清空評語」現行行為測試；W3 不做提醒文字 |
| UC-W2-3 | 本波不做學員端未讀徽章 | W4 不做；W5 列為建議另開 issue |
| UC-W2-4 | 照 feature：Big5 等非 UTF-8 → 「CSV 解析失敗」 | W2-R1 解析器測試含 Big5 位元組 |

**仍需問的**

| ID | 時機 | 問什麼 | 阻擋 |
|----|------|--------|------|
| UC-W2-commit | W0-11 | **已由主窗口決定**（見 progress `user_decisions.commit_policy`）：沿用第一波「各 Phase Gate 通過後在 feature 分支 commit、不 push」；藍圖與進度檔已於 `d6b0d1e7` commit | — |
| UC-W2-T | 任何 [T] 步驟 CMD-TUNNEL 不通、且 §0.3「不依賴 tunnel」的步驟都做完時（最早在 W2-G2 派發前） | (A) 請用戶恢復 tunnel，等 W2-R2 紅燈執行後再派 W2-G2；(B) 先派 W2-G2，017 改為「實作後契約測試」（progress 標 `deferred`，靠 ADJ-W2-21 的前後對照防空洞），tunnel 恢復後補跑 W2-R2 / RG2 / PG 第 8 項。**建議 A**（只要用戶能恢復）；未回覆時停在 W2-G2 前，不自行選 B | 擋 W2-G2 |
| UC-W2-n | 各階段 CK | 依 UC-W2-commit 的政策決定是否 commit（附建議訊息） | 不擋下一階段 |
| UC-W2-sec | W2-CK、W4-CK | 提示可喚醒 `@zenbu-powers:security-reviewer`（W2：上傳層與解析器；W4：XSS），opt-in 不自動派 | 不擋 |
| UC-W2-branch | W5-CK（若用戶提早決定合併順序，則在決定當下） | 第一波是否先單獨合進 master；若是，W3 的偏離修正要先 cherry-pick 到第一波分支再 merge 進第二波（§10 W5-CK） | 不擋；影響合併順序 |

**已核准的例外（主窗口，2026-10-01）**：W3 修正第一波偏離（批改頁預設篩選改回「待批改」，ADJ-W2-27）；因此 `tests/e2e/01-admin/assignment-grading.spec.ts` 可由 **W3-G0** 修改 `:455`、`:573` 兩支 test，限於前提失效、不得改變其他斷言語意（ADJ-W2-13、28）。UC-W2-2 維持 A。

---

## 4. 第二波共用 prompt 區塊

> 由 CMD-PROMPT 依名稱取出（`<!-- BEGIN … -->` 到 `<!-- END … -->` 之間，去掉 code fence 行）。區塊內路徑相對於專案根目錄，memory 為絕對路徑。區塊內不得出現以三個反引號開頭的行。

<!-- BEGIN W2:CONTEXT -->
```text
【第二波覆寫：專案與分支（本段優先於上方第一波 S-CONTEXT；兩者衝突時以本段為準）】
- 分支：feat/issue-131-wave2（疊在第一波之上，已合併 master v1.8.11）；第二波基準 commit e520fd7e。上方「feat/issue-131-assignment-grading」「規格 commit 2397234f」只屬於第一波。
- 功能：Issue #131 第二波——作業討論串（讀 / 發 / 已讀）與匯入批改結果 CSV。
- 計劃：specs/plan/issue-131-assignment-grading/wave2-implementation-plan.md（下稱「計劃」）。第一波計劃 implementation-plan.md 的 DEC-01～18、R-01～28 全部沿用，只在本計劃引用時才需要讀。
- 本波 feature：specs/features/assignment/{查詢作業討論串,發送作業討論訊息,匯入批改結果CSV}.feature
- 用戶裁決（不可推翻）：UC-W2-1 空檔 / 只有表頭 / 超過 5000 列 → 都回 row 0、不處理任何列（訊息見計劃 DEC-W2-12）；UC-W2-2 整份重匯不防、不加提醒文字（DEC-W2-15 現行行為）；UC-W2-3 不做學員端未讀徽章；UC-W2-4 Big5 等非 UTF-8 → 「CSV 解析失敗」。
- HOLD SCOPE：只做 feature 已寫的行為與計劃 DEC-W2 已決定的做法；需要其他業務行為 → 停下回報。

【第二波覆寫：測試分組與 PHPUnit 指令（取代上方「命令注意」中的 --group issue-131 用法）】
- 本波新測試 class docblock 同時寫 @group assignment、@group issue-131、@group issue-131-w2。
- 本波測試：npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131-w2 --testdox
- 第一波回歸：npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-131 --exclude-group issue-131-w2   （必須正好 OK (287 tests, …)）
- 全套：npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit

【凍結（違反 = 退回）】
- 第一波測試檔一律不得修改：tests/Integration/Assignment/ 下既有 27 個檔（含 AssignmentTestCase.php、AssignmentApiContractTest.php）、tests/Integration/Email/{EmailTriggerAtTest,SendNowTest}.php、tests/e2e/01-admin/{assignment-chapter-manage,assignment-grading,email-template,smoke}.spec.ts、tests/e2e/02-frontend/{021-classroom-assignments,008-classroom-chapters}.spec.ts、tests/e2e/03-integration/{016-assignment-http-contract,015-e2e-helper-contract}.spec.ts。
- 唯一例外（主窗口核准，2026-10-01）：W3-G0 可修改 tests/e2e/01-admin/assignment-grading.spec.ts，限於預設篩選變更造成的前提失效、只限其卡片列出的兩支 test，不得改變其他斷言語意。除了 W3-G0，任何 agent（包括 W3-R）都不得修改此檔。
- 共用測試基礎不得改既有行為：tests/Integration/TestCase.php、tests/bootstrap.php、phpunit.xml.dist、tests/e2e/{playwright.config.ts,global-setup.ts,global-teardown.ts}、tests/e2e/helpers/*.ts。helper 只能「新增」函式，並在回報列出。
- 主窗口用 sha1 驗證，不靠自述。

【環境（第二波新增）】
- 不得啟動 / 停止 cloudflared、Local 站、Docker；連線失敗就停下回報。
- 機器記憶體吃緊：全機同一時間只能有一個 playwright run。
- 有人在跑 E2E 或 PHPUnit 時，不得執行 pnpm run build / build:wp / i18n:build（會重寫站台與 PHPUnit 正在讀的 js/dist、inc/assets/dist、languages/）。
- 不得 git add / 修改 / 還原 .serena/project.yml（既有無關變更）。
- 所有命令一律前景執行（Bash 工具 timeout: 600000；PHPUnit 全套約 2 分鐘、E2E 一輪約 6～10 分鐘）。不得 run_in_background、不得掛 Monitor 等通知、不得 sleep 輪詢——sub-agent 背景等通知曾卡住 30 分鐘到 17 小時沒被叫醒。E2E 細則見 W2:E2E-RUN。
```
<!-- END W2:CONTEXT -->

<!-- BEGIN W2:RED-PHP -->
```text
【第二波覆寫：S-RED-PHP（本段優先於上方第一波 S-RED-PHP；衝突時以本段為準）】
- 上方【必讀】的 phpunit.xml 實際檔名是 phpunit.xml.dist；三支 AccessPass 參考測試本波可略過，改讀下方【本階段必讀】。
- 覆寫第 2 點：新測試類別 class docblock 寫三個 group（assignment、issue-131、issue-131-w2）。本波不修改任何既有測試類別。
- 覆寫第 3 點：新檔放 tests/Integration/Assignment/。本波共用 helper 一律放 tests/Integration/Assignment/AssignmentWave2TestCase.php（abstract，extends AssignmentTestCase，檔名不以 Test.php 結尾）。AssignmentTestCase.php 唯讀。後續階段的 Red 可擴充 AssignmentWave2TestCase.php 與 AssignmentWave2ApiContractTest.php（只新增，不改既有方法語意），先前階段的 issue-131-w2 測試必須維持綠。
- 覆寫第 10 點：時間一律 $this->set_now( '2026-09-17 10:00:00' )（feature Background）。read_at / created_at / graded_at 斷言字面值 '2026-09-17 10:00:00'，不得用 assertNotNull。
- 覆寫第 14 點：介面名稱取自計劃 §4.2 介面契約、specs/api/api.yml、specs/entity/erm.dbml、feature。REST callback 名稱依 ApiBase 規則（{method}_{endpoint 中 (?P<id>\d+) 換成 with_id、- 與 / 換成 _}_callback），例如 J7\PowerCourse\Resources\Assignment\Core\MyApi::get_my_assignment_submissions_with_id_messages_callback；nocache 靜態檢查用基底的 assert_callback_starts_with_nocache_headers_for( $class, $method )。
- 補充（防說謊，計劃 §13；違反任一條 = 退回）：
  a. 每個測試先斷言「操作成功（200）」或「期望的錯誤 status + code + zh_TW 訊息」，再斷言欄位 / DB。Red 時不得有未申報的綠燈。
  b. 不存在的 ID 用 nonexistent_id()，不用字面 9999（InnoDB auto_increment 不隨交易回滾）。
  c. 「不檢查觀看權」：在 When 之前先 assertFalse( \J7\PowerCourse\Resources\Assignment\Utils\Utils::can_access_course( $course_id, $alice_id ) )（方法內引用，不得放在 class 層級）。
  d. 「不含 sender_email / sender_id」：先 seed 一則 sender_type = teacher、sender_email = 'admin@test.com' 的訊息；斷言每筆訊息的鍵（排序後）assertSame 6 個白名單鍵 [body, created_at, id, mine, read_at, sender_type]，且 wp_json_encode( $response->get_data() ) 不含 'admin@test.com'。
  e. 布林用 assertSame( true / false, … )；計數與 ID 用 assertSame（int）。
  f. 寄信：capture_mail() → 操作 → run_scheduled_actions( 'power_email_send_assignment_graded' ) → assert_mail_sent_to()；不可只看 did_action。
  g. 權限與 feature Example 一律 dispatch()（含 wp_set_current_user）；只有計劃 §3 明列走 Service 的（匯入內容、解析器、往返）才直呼 Service。
- 覆寫【回報前自我驗證】第 2 點：
  1. --group issue-131-w2 --testdox：本階段測試紅（申報的守門除外）、先前階段的 w2 測試綠；無 No tests executed / Fatal / ParseError
  2. --group issue-131 --exclude-group issue-131-w2：OK (287 tests, …)
  3. 全套：Failures + Errors = 本階段預期紅燈數，失敗清單全部屬於你的 manifest
```
<!-- END W2:RED-PHP -->

<!-- BEGIN W2:E2E-RUN -->
```text
【E2E 執行規則（第二波；所有會跑 Playwright 的 agent 都適用，優先於上方第一波的 E2E / LC 寫法）】
1. 寫完或改完 spec 先做載入檢查（不跑 global-setup、不碰 plugin.php、不需網路）：
   cd tests/e2e && npx playwright test <spec> --list     → Total 必須等於你申報的 test 數
2. 真的執行前先探測 tunnel（只讀 .env 的 TEST_SITE_URL 一行）：
   U=$(grep -E '^TEST_SITE_URL=' .env | cut -d= -f2- | tr -d '\r'); timeout 40 node -e "const c=new AbortController();setTimeout(()=>c.abort(),30000);fetch(process.argv[1]+'/wp-login.php',{redirect:'manual',signal:c.signal}).then(async r=>{const t=await r.text();console.log('TUNNEL_HTTP='+r.status+' TITLE='+((t.match(/<title>([^<]*)<\/title>/)||['',''])[1].trim()))}).catch(e=>console.log('TUNNEL_ERR='+(e.cause&&e.cause.code||e.name)))" "$U"
   TUNNEL_HTTP=200 且 TITLE 含「登入」才執行；否則不要執行、不要修環境，回報「未執行：tunnel 不通」並貼輸出。
3. 執行前 cat plugin.php.e2e-lock：裡面有活著的別人 PID（tasklist //FI "PID eq <PID>"）就等它結束，不要並跑。
4. 每一輪各自「前景」執行（Bash 工具 timeout: 600000），輸出導到檔案：
   cd tests/e2e && npx playwright test <canary spec> <你的 spec> --reporter=list > <log 檔> 2>&1; echo "EXIT_CODE=$?"
   - 一輪（canary + 目標 spec）在本機約 6～10 分鐘，落在前景上限內；本機每支 test 約 0.65～1.0 分鐘，一個指令超過約 10 支 test 就拆成多個前景指令（canary 只放第一個）。
   - 要跑多輪就前景依序跑多次（每輪各一個指令、各自的 log 檔，例如 run1 / run2）。不得把多輪串成一個指令、不得 run_in_background、不得掛 Monitor 等通知、不得 sleep 輪詢。
   - 理由（2026-10-01～02 實證，memory feedback_subagent_background_wait_stall）：sub-agent 背景執行後，完成事件沒有把 agent 叫醒——W1-R 卡 30 分鐘、W2-R1 卡約 17 小時；改成前景執行的 W2-R2、W2-G2 都正常收尾。
4b. 單輪被 10 分鐘截斷時：不要改用背景、不要重跑同一個大指令。回報實況——讀 log 檔看跑到第幾支、截斷前最後的輸出原文；照 W2:LC 回報鎖檔狀態（被截斷的 run 沒跑 global-teardown，鎖裡留下已死的 PID 屬預期，不要自行清）；附上 tasklist 中 node.exe 與 chrome.exe 的數量（殘留行程會耗盡桌面堆積，W0-9 Run 2 的 0xC0000142 就是這樣）。由主窗口決定拆分或改由主窗口執行。
5. 讀結果（EXIT_CODE=0 不代表沒有 flaky；retries: 1 時 flaky 也 exit 0）：
   grep -nE "^\s*[0-9]+ (passed|failed|flaky|skipped|did not run|interrupted)" <log>
   awk '/^  [0-9]+ flaky/{f=1;next} /^  [0-9]+ [a-z]/{f=0} f' <log>
   grep -nE -A14 "^\s+[0-9]+\) \[" <log> | sed 's/\x1b\[[0-9;]*m//g' | grep -E "\) \[|Error:|TimeoutError|Timeout:|timeout of|Expected|Received|element\(s\) not found"
6. 每次跑完做 W2:LC 的 LC 檢查。
```
<!-- END W2:E2E-RUN -->

<!-- BEGIN W2:E2E-JUDGE -->
```text
【E2E 判準（第二波；取代第一波「兩次都 0 failed、0 flaky」與「flaky 視為紅」）】
出處：team-lead 2026-10-01 指示；第一波 P9 最終關卡 8 輪最佳為「0 failed、1～2 flaky」（從未達成 0 flaky），單輪 26.7～41.7 分鐘；tests/e2e/playwright.config.ts 本機 retries: 1 的註解「重試後仍紅才算真紅」。
每一輪執行都要：
(1) failed = 0、did not run = 0、interrupted = 0；skipped 只能是 spec 內明文 test.skip 且已申報。
(2) flaky 逐支列出：spec:line｜test 名稱｜首次失敗的第一個錯誤訊息｜分類。
(3) 每支 flaky 的首次失敗必須是「逾時類」：
    - TimeoutError / 「Timeout NNNNms exceeded」（click、goto、waitForURL、waitForSelector、waitForResponse、request）
    - 「Test timeout of NNNNms exceeded」、「"beforeAll" hook timeout」
    - 等待存在 / 可見的 expect 逾時（toBeVisible、toBeAttached 等），且 Received 為 element(s) not found 或 hidden，或沒有 Received
    - 上述逾時後連帶的「Target page, context or browser has been closed」
    不合格（視同 failed）：expect 同時有 Expected 與 Received 兩個實值且不同（例如 Expected "通過" / Received "待批改"、toHaveCount Expected 0 / Received 1、toHaveText / toEqual / toBe 的差異）、HTTP status 或 rest_* code 不符、頁面 JS 例外。
(4) 本波新 spec（03-integration/017-assignment-thread-import-http-contract、01-admin/assignment-thread-import、02-frontend/022-classroom-assignment-thread）以及 01-admin/assignment-grading.spec.ts 中本波修改的兩支 test，同一支 test 在兩輪都 flaky → 視同紅（本藍圖加嚴：新程式碼的可重現競態要在本階段修）。
```
<!-- END W2:E2E-JUDGE -->

<!-- BEGIN W2:LC -->
```text
【LC 檢查（第二波版；取代上方第一波 S-RED-E2E 第 6 點、S-GREEN-REACT 最後一行與任何「diff / cp 備份 / 刪 lc 行 / git checkout」寫法）】
每次 playwright 結束後在專案根目錄執行：
  cat plugin.php.e2e-lock 2>/dev/null || echo NO_LOCK
  git grep -n "'lc'[[:space:]]*=>" -- plugin.php
  ls plugin.php.e2e-backup 2>/dev/null || echo NO_BACKUP
鎖內有 PID 時逐一 tasklist //FI "PID eq <PID>" 看是否存活。判定：
- NO_LOCK、git grep 無輸出、NO_BACKUP → 乾淨
- 鎖內有活著的 PID → 別的 run 在用：什麼都不要動，回報「LC 檢查延後（持有者 PID …）」
- 鎖內 PID 全死，或 NO_LOCK 但 'lc' / 備份仍在 → 不要自己處理，原樣回報給主窗口
禁止：git checkout -- plugin.php、cp plugin.php.e2e-backup plugin.php、Edit 刪 lc 行、刪除 plugin.php.e2e-lock 或 plugin.php.e2e-backup、自行呼叫 revertLcBypass()。
（理由：引用計數鎖決定誰負責還原；手動清會拆掉別人正在用的 bypass，讓對方紅得像「功能不存在」。見 .claude/rules/wordpress.rule.md「併發時不要手動清」。）
```
<!-- END W2:LC -->

<!-- BEGIN W2:RED-E2E -->
```text
【第二波覆寫：S-RED-E2E（本段優先於上方第一波 S-RED-E2E；衝突時以本段為準）】
- 覆寫第 3 點：不用 test.describe.serial()（第一波 016 與 assignment-grading.spec.ts 的實測教訓：serial 中一支紅，其餘全部 did not run，紅燈原因被遮住）。需要順序的流程寫在同一個 test 內用 test.step() 分段；共用資料在 beforeAll 準備（hook 內 test.setTimeout()），每個 test 只依賴 beforeAll 的資料，不依賴其他 test 的副作用。afterAll 清自己建的資料。
- 覆寫第 6 點：LC 檢查照 W2:LC（下方）。
- 補充必讀與 helper 用法：
  a. 學員登入：helpers/frontend-setup.ts 的 loginAs()（已處理 confirm_admin_email 攔截頁、waitUntil:'commit'、60s），再 helpers/api-client.ts 的 getNonceFromPage( page, { required: true } )。不要複製 016 或 assignment-grading.spec.ts 檔內的 loginStudentAndGetNonce()（寫於 loginAs 修好之前；第一波 P9 第 8 輪 flaky 就是它的 waitForURL 逾時）。
  b. 章節 slug 用 api.createCourseWithChapters() 回傳的 chapterSlugs，不得用常數 slug。
  c. 建使用者用 ensureUserViaPowerhouse()（本站沒有 /wp/v2/users）。
  d. 斷言 DOM 狀態，不等 toast。真的要等提示時用 helpers/admin-page.ts 的 waitForMessage( page, type )（同時認 .ant-message-* 與 .ant-notification，以 icon 區分型別）；不要用 waitForNotification（錯誤提示也會命中）。列表內容斷言前先等 .ant-skeleton 數量歸 0。
  e. 本機站 wp-admin / 前台載入 2～60 秒；config 已設 actionTimeout / navigationTimeout 60s、admin/frontend test 90s、integration 120s。不要用 waitForTimeout 當同步手段。
  f. 斷言 zh_TW 可見文字字面值；API 錯誤訊息依站台語系時，斷言 status + code 即可。
- 覆寫【回報前自我驗證】：照 W2:E2E-RUN 執行（--list → tunnel → 前景執行 canary + 你的 spec 一輪），canary 必須綠；你的每個 test 必須紅（申報的守門除外），逐一列失敗訊息第一行並判斷是否為「功能不存在」（rest_no_route / 元素找不到 / 欄位不符），不能是登入、nonce、憑證、confirm_admin_email、license gate、tunnel。tunnel 不通時只交 --list 結果並註明「紅燈執行未做」。
```
<!-- END W2:RED-E2E -->

<!-- BEGIN W2:GREEN-PHP -->
```text
【第二波覆寫：S-GREEN-PHP（本段優先於上方第一波 S-GREEN-PHP；衝突時以本段為準）】
- 覆寫【必讀】第 2～4 項：計劃 §4（DEC-W2-01～20）、§4.1（msgid 對照）、§4.2（介面契約）、§6、§7、§8、§12.1 全部（參考實作、對照程式、Memory、硬性限制）與本 dispatch 指定的階段段落；對應 feature、specs/entity/erm.dbml 的 pc_assignment_messages（含 Note）、specs/api/api.yml L1371 起 AssignmentMessage / AssignmentImportSummary、L7386、L7479、L7758。上方第一波必讀中的 AccessPass / Chapter / At.php 對照程式本波不需要。
- 硬性限制補充：
  · 不改 schema、不升 DB 版本、不 ALTER（R-W2-15）
  · 不開交易：禁止 START TRANSACTION / $wpdb->query( 'START …' ) / 任何 BEGIN（DEC-W2-14；第一波 P3 證實會提前提交 PHPUnit 測試交易）
  · 路由不宣告 args；body / file 驗證在 callback 內、permission 之後（DEC-W2-02）
  · 每個新 callback 第一個語句 \nocache_headers();
  · 訊息輸出只經 Model\Message::to_view( $viewer )；sender_type 一律用 Message::SENDER_STUDENT / SENDER_TEACHER 常數
  · 訊息內容不 trim、不 sanitize、不 wp_slash，原樣存（DEC-W2-05）
  · 時間一律 wp_date( 'Y-m-d H:i:s', Clock::now() )
- 覆寫上方硬性限制「不得修改任何 tests/** 檔案（本 dispatch 明文列出的例外除外）」：本波沒有任何例外（含 AssignmentWave2TestCase.php 與本波所有 *Test.php、所有 E2E spec）。
- 覆寫【回報前自我驗證】第 2 點：
  1. --group issue-131-w2 --testdox：你負責的測試全 ✔；其他 w2 測試狀態與派發時一致（還沒輪到的仍紅）；摘要行無 Incomplete / Skipped / Risky
  2. --group issue-131 --exclude-group issue-131-w2：OK (287 tests, …)
- i18n：每個新 msgid 同時給 msgstr_zh_TW 與 msgstr_ja；_x 的 context 用 "msgctxt" 鍵；先 grep -n scripts/i18n-translations/manual.json 去重；含 placeholder 的加 /* translators: */ 註解。
- 你跑 i18n:build 前確認沒有 E2E 或別的 PHPUnit 在跑（cat plugin.php.e2e-lock；主窗口派發時會告知）。
```
<!-- END W2:GREEN-PHP -->

<!-- BEGIN W2:REFACTOR-PHP -->
```text
【第二波覆寫：S-REFACTOR-PHP（本段優先於上方第一波 S-REFACTOR-PHP；衝突時以本段為準）】
- Lint 第 3 點：composer run phpstan 的 Found N errors，N ≤ {{JSON:baseline.phpstan_errors}}；而且作業相關檔（inc/classes/Resources/Assignment/**、inc/templates/components/assignment/**）的錯誤必須是 0 條——只看總數會讓新錯誤藏在既有噪音的波動裡。確認方式：vendor/bin/phpstan analyse --memory-limit=3072M --error-format=json 輸出到檔案，逐一檢查上述兩個目錄下的檔案沒有任何 message；回報貼出你檢查的結果（作業範圍 0 條或逐條列出）。
- Lint 第 5 點改為：--group issue-131-w2 --testdox 全綠 ＋ --group issue-131 --exclude-group issue-131-w2 為 OK (287 tests, …)。
- 不得改變任何對外行為：路由、回應形狀、錯誤訊息字面值、CSV 匯出逐字內容（第一波 ExportGradingCsvTest 守）、上傳錯誤訊息與 MyApi::translate_upload_error() 的 public static 簽名（第一波 UploadAssignmentAttachmentTest 直接呼叫）。
- 貼證據：grep -rnF "START TRANSACTION" inc/classes/Resources/Assignment; echo GREP_DONE（GREP_DONE 前必須無輸出）。
```
<!-- END W2:REFACTOR-PHP -->

<!-- BEGIN W2:GREEN-REACT -->
```text
【第二波覆寫：S-GREEN-REACT（本段優先於上方第一波 S-GREEN-REACT；衝突時以本段為準）】
- 覆寫【必讀】第 2～4 項：計劃 §4 DEC-W2-04、06、17、18、§6.2、§11 W3 全段、§12.2 全部、§14 R-W2-02 / 09 / 11；specs/ui/作業批改頁.md；specs/api/api.yml L1371 起 AssignmentMessage / AssignmentImportSummary、L7386、L7479；feature：查詢作業討論串、發送作業討論訊息、匯入批改結果CSV（回應欄位）。上方第一波的 DEC-11 / P7 / Email 項目本波不需要。
- 參考實作改為：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\zb-course\submissions\SubmissionsManager.tsx（TeacherThread、handleImportFile、匯入摘要卡）。
- 對照程式改為：js/src/pages/admin/Assignments/**（第一波批改頁，含 hooks/useColumns.tsx 的「討論」欄）、js/src/components/formItem/VideoInput/hooks/useSubtitles.tsx（multipart + useCustomMutation 寫法）。
- 硬性限制補充：
  · 討論串 useCustom 的 queryOptions：staleTime 0、refetchOnWindowFocus false（GET 會標已讀，R-W2-02）；不輪詢；不做未讀優先排序
  · 訊息只用 React 文字節點顯示，禁止 dangerouslySetInnerHTML
  · 匯入結果放頁內可關閉的 Alert（共 N 筆・更新 X・略過 Y・失敗 Z，錯誤列前 20 筆 +「另有 N 筆」），不用 toast 承載結果；成功 / 失敗提示關掉 Refine 自動通知（successNotification: false）
  · 不加 _locale=user（DEC-W2-09）；不加匯入提醒文字（UC-W2-2 = A）
  · 不得修改 E2E spec 與任何 PHP；API 不足就停下回報缺什麼
- 覆寫【固定順序】最後的「→ E2E」與【回報前自我驗證】的 E2E 與 LC 兩項：照 W2:E2E-RUN（canary 用 01-admin/smoke.spec.ts，連跑兩次，判準 W2:E2E-JUDGE），LC 照 W2:LC。build 完成後才跑 E2E；E2E 跑的時候不 build。
```
<!-- END W2:GREEN-REACT -->

<!-- BEGIN W2:REPORT-RED -->
```text
【第二波覆寫：S-REPORT-RED】
- 第 1 段表格的「group 計數」= --group issue-131-w2 的計數；另加一欄「層」（dispatch / Service / 單元 / E2E）。
- 第 4 段：testdox 改貼 --group issue-131-w2 的摘要行與本階段各 class 明細；另貼 --group issue-131 --exclude-group issue-131-w2 的摘要行。
- E2E 另貼：npx playwright test <spec> --list 的 Total 行；有執行時貼摘要行、flaky 清單（含首次失敗第一行）、W2:LC 的輸出；沒執行時明寫「未執行」與 tunnel 探測輸出。
```
<!-- END W2:REPORT-RED -->

<!-- BEGIN W2:REPORT-GREEN -->
```text
【第二波覆寫：S-REPORT-GREEN】
- 第 3 段：testdox 改貼 --group issue-131-w2 的摘要行與你負責的 class 明細；另貼 --group issue-131 --exclude-group issue-131-w2 的摘要行；E2E 貼每輪摘要行、flaky 清單與分類（W2:E2E-JUDGE）、W2:LC 輸出。
- 另加第 6 段：你是否執行過 pnpm run build / build:wp / i18n:build，各在什麼時間點（主窗口用來排除與 E2E / PHPUnit 重疊）。
```
<!-- END W2:REPORT-GREEN -->

---

## 5. W0：前置基線（主窗口親跑，無派發）

| Step | 動作 | 判準 / 記錄 |
|------|------|------------|
| W0-1 | `mkdir -p "$LOG"`；`cd "$PC" && git rev-parse --abbrev-ref HEAD; git log -1 --format=%h; git status --short --untracked-files=all` | 分支 `feat/issue-131-wave2`；HEAD `e520fd7e`（若已 commit 本藍圖則為其後一個 commit）；變更只有 ` M .serena/project.yml` 與本藍圖、progress 檔；另外允許：W0-5 `i18n:build` 造成的 `languages/` 日期雜訊（`.pot`、兩個 `.json`）、E2E 進行中的 ` M plugin.php`（先跑 CMD-LC-W2：鎖內有活著的 PID 就是正常狀態）。其他變更 → 先問用戶 |
| W0-2 | CMD-PROMPT 取出 + `--check` | `ANCHORS_OK` |
| W0-3 | PHPUnit：全套與 `--group issue-131` **主窗口已實測**（1638 / 5422 / 11 skipped / 0 failures；287 / 1398）。補跑 CMD-PU-W1REG（TAG `w0`）與 CMD-PU-W2（TAG `w0`） | W1REG = `OK (287 tests, 1398 assertions)`；W2 = `No tests executed!`（證明 `--exclude-group` 能用、目前沒有誤標 w2 的測試） |
| W0-4 | **主窗口已實測：95 errors、作業相關檔 0 條**（合併 master v1.8.11 後）。把 scratchpad 根目錄的 `w0-phpstan.json` 複製成 `$LOG/phpstan-baseline.json`，取出 CMD-PHPSTAN-W2 的比對腳本並以基線自比一次 | 自比輸出 `TOTAL=95 … NORM_OK=true ASSIGNMENT_SCOPE=0 NEW_VS_BASELINE=0`（w2-tdd 已以同一份 JSON 實測得到此結果） |
| W0-5 | i18n **主窗口已實測**（zh_TW total=1989 untranslated=5；ja untranslated=199）。補記 `git status --short -- languages/` 的雜訊檔 | 寫入 `baseline.i18n_baseline_noise`（第一波為 `.pot` 與兩個 `.json` 的日期欄位） |
| W0-6 | CMD-ESLINT + CMD-TSC（TAG `w0`）＋ CMD-DIST | 記錄 errors / warnings（第一波結束 0 / 142）、type-check exit、`NO_DEV_SERVER_JSON` 與否 |
| W0-7 | CMD-FREEZE（附錄 D 的 freeze.mjs） | 四個標頭下皆無輸出、`SHA_FAIL=0 CONTENT_DIFF=0`（w2-tdd 17:0x 實測 `A=37 B=11 R=0 AMENDED=1 THAWED=0`；`assignment-grading.spec.ts` 在 W3-G0 之前一直是 `frozen`） |
| W0-8 | CMD-TUNNEL | 記錄到 `tunnel`；通 → W0-9 可跑；不通 → W0-9 標 `blocked_tunnel`，直接進 W1 |
| W0-9 [T] | CMD-E2E-W2（TAG `w0`，**兩輪**，**主窗口執行**（不派給 sub-agent；單輪預估超過 10 分鐘時背景執行並自己掛 Monitor 盯結果檔摘要行，見 CMD-E2E-W2））：`01-admin/smoke.spec.ts 02-frontend/008-classroom-chapters.spec.ts 03-integration/015-e2e-helper-contract.spec.ts 01-admin/assignment-grading.spec.ts 02-frontend/021-classroom-assignments.spec.ts 03-integration/016-assignment-http-contract.spec.ts`（30 支 test），每輪後 CMD-LC-W2 | 依 W2:E2E-JUDGE 記錄（canary 與第一波 spec 分開記）；這是 master v1.8.11（`a070a33a` 改前台 React bundle 載入時機）合併後第一次跑第一波 E2E（R-W2-16）。可與 W1-R、W1-RG 重疊（§0.4），**W1-G1 派發前必須跑完** |
| W0-10 | `.progress-wave2.json` 已由本藍圖建立：主窗口填 W0-3～W0-9 的實測值、`current` | — |
| W0-11 | UC-W2-commit（§3.7） | 回答寫入 `user_decisions.commit_policy`；不等回答即可進 W1 |

---

## 6. W1：後端討論串（讀 / 發 / 已讀）

- 計劃：§11 W1；DEC-W2-01～08、20；R-W2-01、02、09、10、19
- 派發 3 次：W1-R → W1-G1 → W1-F；全段不需要 tunnel

### W1-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `w1-r` | `W1 紅燈：作業討論串 PHPUnit` |

派發前：W0-3 的補跑已結束（agent 會跑 PHPUnit，不得兩個 PHPUnit 並行）。W0-9 的 E2E 可以還在背景跑（§0.4）。

<!-- BEGIN CARD:W1-R -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W1（後端討論串：讀 / 發 / 已讀）的 PHPUnit 紅燈測試。只寫測試，不碰 inc/。

{{S-RED-PHP}}
{{W2:RED-PHP}}

【本階段必讀】
- 計劃 §2、§3（第 1～4 列）、§4 DEC-W2-01～08 與 DEC-W2-20、§4.1 前三列 msgid、§4.2 介面契約、§5 第 2 題、§7.1、§7.2、§8、§11 W1 全段、§13、§14 R-W2-01 / 02 / 10 / 19
- feature：specs/features/assignment/{查詢作業討論串,發送作業討論訊息}.feature；specs/features/assignment/句型.md（與討論串相關的句型）
- specs/entity/erm.dbml 的 pc_assignment_messages 與其 Note；specs/api/api.yml L1371 AssignmentMessage、L7479 管理端 messages、L7758 學員端 messages
- 參考實作：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\course-assignment-thread.service.ts
- 既有 helper（唯讀）：tests/Integration/Assignment/AssignmentTestCase.php（seed_submission、seed_message、dispatch、set_now、get_message_row、assert_permission_denied、assert_not_logged_in、assert_operation_failed_response、assert_success_response、assert_callback_starts_with_nocache_headers_for）、tests/Integration/TestCase.php（create_course、create_chapter、enroll_user_to_course）
- 第一波寫法參考（唯讀）：AssignmentApiContractTest.php（路由註冊 / 401 / 403 矩陣）、ListAssignmentSubmissionsTest.php（「討論」計數來自 MessageRepository::thread_summary）、SubmitAssignmentTest.php（學員身分 dispatch）
- 對照程式（唯讀）：inc/classes/Resources/Assignment/Service/MessageRepository.php、Core/MyApi.php、Core/Api.php、Utils/Utils.php、Utils/Clock.php

【要寫的測試】全部新檔，放 tests/Integration/Assignment/
1. AssignmentWave2TestCase.php（abstract，extends AssignmentTestCase）
   - seed_thread_fixture( string $variant ): array —— 'list' = 查詢 feature 的 Background（繳交 500 / 501 皆 pending；Alice 已加入課程 100、expire_date 0；訊息 600 student 未讀、601 teacher 未讀、602 student 已軟刪除）；'post' = 發送 feature 的 Background（500 為 fail + 評語「請補充型別說明」、graded_by = Admin、graded_at 2026-09-16 10:00:00；501 pending；沒有訊息）。兩者都建 Admin / Alice / Bob、課程 100、章節 201、作業 400，並 set_now( '2026-09-17 10:00:00' )。回傳 feature ID → 實際 ID 的對照。
   - nonexistent_id(): int —— 目前 pc_assignment_submissions 最大 id + 100000
   - get_last_message_row( int $submission_id ): ?array —— 依 created_at DESC, id DESC 取最後一則（含已軟刪除與否的欄位原值）
   - dispatch_with_file() 不要在本階段建（W2-R1 才加）
2. ListAssignmentThreadTest.php：feature 11 個 Example，加邊界：
   - 已有 read_at 的訊息不被覆寫（先 seed read_at = 2026-09-16 12:00:00 的老師訊息，學員讀後仍是該值）
   - 連讀兩次回應相同（第二次 read_at 仍為第一次的值）
   - POST 不會把任何訊息標已讀（先斷言 POST 200，再斷言 600 / 601 的 read_at 仍為 null）
   - 管理員讀取「作業在垃圾桶」的繳交 → 200（DEC-W2-03；先斷言作業 post_status = trash）
   - 同一秒兩則訊息依 id ASC 排序（created_at 相同）
3. PostAssignmentMessageTest.php：feature 11 個案例（Outline 用 @dataProvider），加邊界：
   - 10000 字 → 200 且原樣保存（mb_strlen 10000）
   - 只有空白 "   " → 200 且原樣保存（不 trim）
   - body 為陣列、為數字、沒帶 → 400「訊息內容長度必須為 1 至 10000 字」（@dataProvider）
   - "<b>x</b>" 原樣保存（不 sanitize）
   - 'C:\path "q"\' 存入後 DB 逐字相同（slash 對稱，memory project_wp_slash_symmetry）
   - 學員端 POST 回應不含 sender_id / sender_email（依 W2:RED-PHP 補充 d 的寫法）
4. ThreadUnreadSummaryConsistencyTest.php（5 支，透過管理端列表 GET assignment-submissions 的 unread_count / message_count / last_message_at 驗證，與第一波 thread_summary 同一套定義）：
   - 學員發 → 列表 unread_count = 1、message_count = 1、last_message_at = '2026-09-17 10:00:00'
   - 管理員讀 → unread_count = 0
   - 管理員發 → message_count = 2、unread_count 仍 0
   - 學員讀 → 老師訊息 read_at = '2026-09-17 10:00:00'
   - 學員重新繳交（POST my/assignments/{id}/submit）後討論串仍在（同一 submission id、訊息數不變）
5. AssignmentWave2ApiContractTest.php（W1 部分；W2-R1 會擴充 import）：
   - 4 條路由已註冊且 method 正確（GET / POST assignment-submissions/(?P<id>\d+)/messages、GET / POST my/assignment-submissions/(?P<id>\d+)/messages）：管理員 / 學員 dispatch 不得得到 rest_no_route
   - 4 個 callback 第一個語句是 \nocache_headers()（callback 名稱見 W2:RED-PHP 第 14 點）
   - 訪客呼叫 4 條皆 401（管理端 rest_forbidden、學員端 rest_not_logged_in；以實際 WP 行為為準，斷言 status 401 + code）
   - Bob 呼叫管理端 GET 與 POST（**POST 不帶 body**）皆 403 rest_forbidden（DEC-W2-02 守門：宣告 required args 會變成 400）

【預期】全部紅（路由不存在 → rest_no_route；Message / Thread class 不存在）。本階段預期「意外綠」清單為空；若有測試在實作前就綠，代表它缺少前置斷言，修正它，不要申報為守門（除非你能說明它守住的是實作前就成立的既有行為）。

{{S-REPORT-RED}}
{{W2:REPORT-RED}}
```
<!-- END CARD:W1-R -->

### W1-RG：Red Gate（主窗口親跑）

1. CMD-GIT-SCOPE：新增只在 `tests/Integration/Assignment/`（5 個檔：`AssignmentWave2TestCase.php`、`ListAssignmentThreadTest.php`、`PostAssignmentMessageTest.php`、`ThreadUnreadSummaryConsistencyTest.php`、`AssignmentWave2ApiContractTest.php`）。
2. CMD-PHPL：上述 5 檔。
3. CMD-PU-W2（TAG `w1-rg`）：Tests = manifest w2 計數合計；Failures + Errors = 預期紅燈數；✔ 全在守門清單（預期為空）；無 `No tests executed` / Fatal / ParseError；Incomplete / Skipped / Risky = 0；逐一讀失敗訊息，屬 S-RED-PHP 第 6 點允許的原因。
4. CMD-PU-W1REG（`w1-rg`）：`OK (287 tests, 1398 assertions)`。
5. CMD-PU-FULL（`w1-rg`）：Tests = 1638 + 全套增量；Failures + Errors = 預期紅燈數；`N)` 清單全屬 manifest（`ExternalCourseCTATest` DNS flaky 單獨重跑判定，見第一波 progress `phpunit_known_flaky`）。
6. CMD-FREEZE：乾淨。
7. CMD-W2-HASH 記錄（TAG `w1-rg`）。
8. progress：W1 `red_gate_passed`，寫 manifest、guards、介面契約（W1-R 回報第 3 段原文存 `$LOG/w1-r-report.md` 並在 progress 記路徑）、`test_sha1`。

### W1-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w1-g1` | `W1 綠燈：討論串讀寫與已讀` |

派發前確認：W0-9 若有在跑，必須已結束（§0.4，G1 會跑 i18n:build）。

<!-- BEGIN CARD:W1-G1 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W1 綠燈：讓 W1 的 issue-131-w2 測試全綠（討論串讀 / 發 / 已讀）。

{{S-GREEN-PHP}}
{{W2:GREEN-PHP}}

【本階段必讀】計劃 §11 W1 全段、§7.1、§7.2、§8 前 8 列、DEC-W2-01～08、§4.2（Message、Thread 的簽名）；feature 查詢作業討論串、發送作業討論訊息；參考實作 course-assignment-thread.service.ts（listForStudent / listForTeacher / postAsStudent / postAsTeacher / markRead / toView）

【紅燈回報（主窗口貼入原文：測試清單、回歸守門、介面契約）】
{{貼上：W1-R 回報第 1～3 段}}

【你負責讓它綠的測試】W1 manifest 全部（ListAssignmentThreadTest、PostAssignmentMessageTest、ThreadUnreadSummaryConsistencyTest、AssignmentWave2ApiContractTest）
【必須維持綠】第一波 287 支（--group issue-131 --exclude-group issue-131-w2），特別是 ListAssignmentSubmissionsTest（thread_summary 改用常數後行為不得變）

【步驟】（計劃 §11 W1 Green 0～5）
0. .claude/rules/i18n.rule.md 術語表補 4 個詞：討論串 → Discussion thread；老師（討論串中管理員一方，非講師角色）→ Teacher；已讀（訊息狀態）→ Read（context assignment message status）；匯入 → Import
1. Model/Message.php：SENDER_STUDENT / SENDER_TEACHER、from_row()、to_view( $viewer ) 白名單 6 鍵（id int、sender_type、body、read_at ?string、created_at、mine bool；mine = sender_type === $viewer，不看 sender_id）
2. Service/MessageRepository.php：insert()、find()、list_by_submission()（deleted_at IS NULL，ORDER BY created_at ASC, id ASC）、mark_read( submission_id, sender_type, read_at )（只更新 read_at IS NULL AND deleted_at IS NULL 的對方訊息）；thread_summary() 的 'student' 改用常數（行為不變）
3. Service/Thread.php：list_for_student / list_for_teacher（先 mark_read 再讀；mark_read 失敗只記 log、照常回傳）、post_as_student / post_as_teacher（teacher 由 admin_id 取 user_email 快照；POST 不標任何訊息已讀）；檢查順序照 DEC-W2-03（學員端：404 → 403 → 400；不查觀看權、線性觀看、作業是否在垃圾桶）
4. Core/MyApi.php +2 路由（permission = check_logged_in_permission）、Core/Api.php +2 路由（ApiBase 預設 permission）；callback 第一句 \nocache_headers()；沿用既有 respond() / get_body_args()；成功一律 200
5. i18n：You do not have permission to access this submission（無權存取此繳交紀錄）、Message must be between 1 and 10000 characters（訊息內容長度必須為 1 至 10000 字）；Submission not found 已存在，重用

【受影響既有目錄（回報前跑）】tests/Integration/Assignment 全部（= --group issue-131 與 issue-131-w2）

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W1-G1 -->

### W1-GG：Green Gate

1. CMD-W2-HASH 驗證（對 `w1-rg`）；CMD-FREEZE。
2. CMD-GIT-SCOPE：變更只在 `inc/classes/Resources/Assignment/**`、`.claude/rules/i18n.rule.md`、`scripts/i18n-translations/manual.json`、`languages/` 7 檔。
3. CMD-PU-W2（`w1-gg`）：`OK (`，Tests = W1-RG；無 `OK, but`。
4. CMD-PU-W1REG（`w1-gg`）：287 全綠。

### W1-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w1-f` | `W1 重構：討論串共用驗證` |

<!-- BEGIN CARD:W1-F -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W1 Refactor。不新增行為。

{{S-REFACTOR-PHP}}
{{W2:REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{貼上：W1-G1 回報}}

【本階段 Refactor 項目】
- Service/Thread.php 學員 / 管理員兩條路徑共用 resolve_submission() 與 validate_body()；每個方法 < 50 行
- 回應陣列只來自 Model\Message::to_view()：貼 grep -rnF "sender_email" inc/classes/Resources/Assignment/Core inc/classes/Resources/Assignment/Model inc/classes/Resources/Assignment/Service 的輸出，逐行說明每一處是「寫入路徑」而不是輸出陣列
- 'student' / 'teacher' 字面值只出現在 Message 常數定義：貼 grep -rnE "'(student|teacher)'" inc/classes/Resources/Assignment 的輸出並逐行說明

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W1-F -->

### W1-PG：Phase Gate

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-W2（`w1-pg`） | `OK (`；Tests = W1-GG |
| 2 | CMD-PU-W1REG（`w1-pg`） | `OK (287 tests, 1398 assertions)` |
| 3 | CMD-PU-FULL（`w1-pg`） | 0 failure / 0 error；Tests = 1638 + W1 全套增量；Skipped = 11 |
| 4 | CMD-PHPSTAN-W2（`w1-pg`） | TOTAL ≤ 95；ASSIGNMENT_SCOPE = 0；NORM_OK=true |
| 5 | CMD-PHPCS-W2（`w1-pg`） | 0 error |
| 6 | CMD-I18N（`w1-pg`） | zh_TW untranslated ≤ 5、ja ≤ 199；`languages/` 7 檔有變更 |
| 7 | CMD-W2-HASH 驗證、CMD-FREEZE | 乾淨 |
| 8 | `cd "$PC" && git diff --stat -- .claude/rules/i18n.rule.md` | 術語表已補 |
| 9 | `cd "$PC" && grep -rnF "START TRANSACTION" inc/classes/Resources/Assignment; echo GREP_DONE` | `GREP_DONE` 前無輸出 |
| 10 | 讀 W1-F 回報的兩段 grep 說明 | `sender_email` 只在寫入路徑 |

### W1-CK

progress 寫入（`counters.w2_group_total`、`suite_total`）；**UC-W2-1n**（依 commit 政策）：`feat(assignment): 新增作業討論串讀取、發送與已讀`、`test(assignment): 新增作業討論串測試`、`docs(i18n): 補討論串相關術語`。

---

## 7. W2：後端 CSV 匯入 + 真實 HTTP 契約

- 計劃：§11 W2；DEC-W2-09～16、20；R-W2-03～08
- 派發 5 次：W2-R1 → W2-R2 → W2-G1 → W2-G2 → W2-F（順序見 §0.3：tunnel 不通時 W2-G1 可先於 W2-R2）

### W2-R1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `w2-r1` | `W2 紅燈：CSV 匯入 PHPUnit` |

<!-- BEGIN CARD:W2-R1 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W2（匯入批改結果 CSV）的 PHPUnit 紅燈測試。只寫測試，不碰 inc/。

{{S-RED-PHP}}
{{W2:RED-PHP}}

【本階段必讀】
- 計劃 §3（第 4～7 列）、§4 DEC-W2-09～16、§4.1（匯入相關 msgid，含 UC-W2-1 三條）、§4.2（CsvImport、CsvParser）、§5 第 3a～3d 題、§7.3、§8 import 相關列、§11 W2 全段、§13、§14 R-W2-03～08
- feature：specs/features/assignment/匯入批改結果CSV.feature；第一波 specs/features/assignment/匯出批改用CSV.feature（表頭來源）
- 參考實作：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\api-gateway\src\course-assignments\course-submission-csv.service.ts（importGrades、normalizeStatus、pick）與 apps\api-gateway\src\common\csv\csv-import.helper.ts（parseCsvBuffer、MAX_CSV_ROWS）
- 既有測試（唯讀）：AssignmentGradedEmailTest.php（寄信斷言）、UploadAssignmentAttachmentTest.php（上傳錯誤碼的測法）、ExportGradingCsvTest.php（讀 CSV 串流、read_csv_stream / parse_csv_rows）、W1 的 AssignmentWave2TestCase.php 與 AssignmentWave2ApiContractTest.php（本階段擴充）
- 對照程式（唯讀）：Service/Grading.php（grade( int, array, int )）、Service/Csv.php（write_grading_csv 的表頭 msgid 與 'assignment csv' context）、Core/MyApi.php（get_uploaded_file 的錯誤碼處理）

【要寫的測試】
1. 擴充 AssignmentWave2TestCase.php（只新增）：
   - dispatch_with_file( string $route, ?array $file, int $user_id ): \WP_REST_Response —— 用 WP_REST_Request::set_file_params( [ 'file' => $file ] )（$file 為 null 時不設），不要碰 $_FILES 全域
   - build_csv( array $rows, bool $bom = false, string $eol = "\r\n" ): string —— 第一列為表頭
   - seed_import_fixture(): array —— feature Background（500 / 501 皆 pending、submitted_at 照 feature）
2. ImportGradingCsvApiTest.php（dispatch 層；ADJ-W2-19）：
   - Alice 呼叫 POST assignment-submissions/import（不帶檔）→ 403 rest_forbidden
   - Admin 不帶檔 → 400「請選擇要匯入的 CSV 檔案」
   - Admin size = 10485761（tmp_name 指向小暫存檔）→ 400「檔案過大（上限 10MB）」
   - Admin error = UPLOAD_ERR_INI_SIZE → 400「檔案過大（上限 10MB）」
   - Admin size = 10485760 → 400「請選擇要匯入的 CSV 檔案」（正向斷言；PHPUnit 下 is_uploaded_file() 必為 false。不得寫成「訊息不是檔案過大」——實作前 404 也會成立，ADJ-W2-14）
3. ImportGradingCsvTest.php（Service 層：CsvImport::import( $csv, $admin_id )；CSV 由 feature Data Table 組成，CRLF、無 BOM）：
   - 無法解析（未閉合引號）→ {0,0,0,0} + errors = [{row:0, message:'CSV 解析失敗'}]，500 / 501 仍 pending 且 graded_at 為空
   - 兩列 → 狀態 / 評語 / graded_by = admin_id / graded_at = '2026-09-17 10:00:00'，摘要 {2,2,0,0}
   - 狀態寫法 9 例（@dataProvider）；表頭別名 2 例（@dataProvider）
   - 回饋空白清空（502）
   - 副作用：Alice 在章節 201 的 finished_at = '2026-09-17 10:00:00'；create_email_template( trigger assignment_graded ) + capture_mail() + run_scheduled_actions( 'power_email_send_assignment_graded' ) → assert_mail_sent_to( Alice email )
   - 缺 id：{2,1,1,0} + [{row:2,'缺少 submission_id'}]，且 501 確實變 pass
   - 狀態 ok：{1,0,1,0} + [{row:2,'狀態無法辨識（ok），需為 pending/pass/fail'}]，500 仍 pending
   - 不存在的 id（用 nonexistent_id()）+ 500：{2,1,0,1} + [{row:2,'繳交紀錄不存在'}]，500 為 pass
   - 邊界：狀態空白 →「狀態無法辨識（空白），需為 pending/pass/fail」；中間夾全空列（空行與 ",,"）不計入 total、列號依 DEC-W2-11（非空資料列序號 + 2）；回饋 10001 字 → failed + Grading 的訊息；submission_id 為 abc / 500.0 / -1 → failed「繳交紀錄不存在」；作業在垃圾桶的繳交 → failed「繳交紀錄不存在」；同一 id 兩列 → 最後一列為準且兩列都計入 updated；沒有回饋欄 → 評語被清空（DEC-W2-15 現行行為，UC-W2-2 = A）；pass 列每次匯入都再排一次批改信（同一份 CSV 匯入兩次 → 兩封，identifier 含不同 graded_at 時）
   - UC-W2-1 三條：空字串 → row 0「CSV 檔案為空」；只有表頭 → row 0「CSV 沒有任何資料列」；5001 列 → row 0「單批匯入上限 5000 筆，請拆分後重試（目前 5001 筆）」；三條都斷言 DB 不變（500 / 501 仍 pending）
4. AssignmentCsvParserTest.php（CsvParser::parse / normalize_header 單元）：BOM、CRLF / LF / CR、引號內逗號與換行、"" 跳脫、表頭正規化（大小寫、半形 / 全形空白 → _）、四種無法解析（非 UTF-8：用 Big5 位元組 "\xb3\x71\xb9\x4c" 一類、引號到檔尾未閉合、閉合引號後接非分隔字元、未加引號欄位中間出現引號）一律 \UnexpectedValueException、比表頭多的格忽略、少的視為空、正規化後表頭重複時後者覆蓋、全空列略過
5. GradingCsvRoundTripTest.php：Csv::write_grading_csv() 的串流內容原封不動餵給 CsvImport::import() → updated = 列數、狀態不變；含逗號 / 雙引號 / 換行的評語逐字保留；英文 msgid 表頭（Status (editable: pending/pass/fail) / Feedback (editable)）的 CSV 可匯入；以 gettext_with_context filter 模擬 ja 譯文後匯出再匯入可辨識（tear_down 移除 filter）；把「可改」欄改成 fail 而「目前狀態」欄仍是「通過」→ 結果為 fail
6. 擴充 AssignmentWave2ApiContractTest.php：import 路由已註冊、callback 第一句 \nocache_headers()（post_assignment_submissions_import_callback）、訪客 401、Alice 403

【預期】全部紅（CsvParser / CsvImport class 不存在、import 路由 rest_no_route）。W1 的 issue-131-w2 測試必須維持綠。

{{S-REPORT-RED}}
{{W2:REPORT-RED}}
```
<!-- END CARD:W2-R1 -->

### W2-RG1：Red Gate（PHPUnit 部分）

同 W1-RG 1～8（TAG `w2-rg1`），差異：新檔為 `ImportGradingCsvApiTest.php`、`ImportGradingCsvTest.php`、`AssignmentCsvParserTest.php`、`GradingCsvRoundTripTest.php`，修改為 `AssignmentWave2TestCase.php`、`AssignmentWave2ApiContractTest.php`（只能新增：`git diff` 不得刪改 W1 既有方法）；CMD-PU-W2 中 **W1 的 w2 測試全 ✔**、W2 新測試全 ✘；全套 Tests = W1-PG 全套 + W2-R1 全套增量。

### W2-R2（派發，[T] 紅燈執行）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `w2-r2` | `W2 紅燈：017 討論串與匯入 HTTP 契約 E2E` |

派發前：CMD-TUNNEL（不通也可派，agent 只做 `--list`；紅燈執行改由 W2-RG2 補做，且必須在 W2-G2 之前）；沒有 PHP Green / build 在跑。

<!-- BEGIN CARD:W2-R2 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W2：新增 tests/e2e/03-integration/017-assignment-thread-import-http-contract.spec.ts（真實 HTTP 契約，紅燈）。PHPUnit 碰不到的路徑要在這裡驗：真實 multipart 上傳（is_uploaded_file、讀暫存檔、actor = 目前使用者）、解析失敗在 API 層仍回 200、站台實際語系下的匯出 → 匯入往返。

{{S-RED-E2E}}
{{W2:RED-E2E}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段必讀】計劃 §11 W2 Red-2、§13 第 5 點、§14 R-W2-02 / 04 / 05 / 08；specs/features/assignment/匯入批改結果CSV.feature、查詢作業討論串.feature；specs/api/api.yml L1395、L7386、L7758；tests/e2e/03-integration/016-assignment-http-contract.spec.ts（multipart 與匯出 CSV 的請求寫法——只看寫法，不要複製它的 loginStudentAndGetNonce 與 describe.serial）

【W2-R1 介面契約（主窗口貼入原文）】
{{貼上：W2-R1 回報第 3 段}}

【要寫的測試】（不用 serial；每個 test 開頭先斷言前置狀態）
資料準備（beforeAll）：管理員 REST 建課程 / 章節 / 作業；ensureUserViaPowerhouse 建學員 A、B 並給課程觀看權；A、B 以 loginAs + nonce 各繳交一次，記下兩個 submission id。
1. 管理員真實 multipart 匯入（field 名 file；UTF-8 BOM + CRLF；A 的列 pass、B 的列狀態 ok）：匯入前 GET 列表確認 A 為 pending → 匯入回 200、{total:2, updated:1, skipped:1, failed:0}、errors[0].row = 3 → 匯入後 A 為 pass、B 仍 pending。若 API 有暴露批改者資訊（graded_by 或批改者名稱），斷言等於目前管理員；沒有就在回報「疑義」說明。
2. 匯出 → 原封匯入（站台實際語系下的 R-21 驗證）：GET 批改用 CSV（以本 spec 建立的 course_id / assignment_id 篩選，不要重批到其他 spec 的資料）取得原始位元組 → 不做任何修改以 multipart 匯入 → 200、failed = 0、skipped = 0、updated = 匯出的資料列數（且 ≥ 2）
3. 管理員 multipart 上傳無法解析的 CSV（未閉合引號）→ 200、四個計數皆 0、errors[0].row = 0；A、B 狀態不變（ADJ-W2-21）
4. 學員呼叫 import → 403；訪客 → 401；管理員不帶檔（multipart 沒有 file 欄位）→ 400。斷言 status + code（訊息依站台語系，不斷言字面值）
5. 學員 A GET 自己的討論串（my/assignment-submissions/{A}/messages）：200，且真實回應 Cache-Control 含 no-cache
6. 學員 A GET B 的討論串 → 403；訪客 → 401
第 5、6 條在本階段**必為綠**（W1 已實作路由），請在「回歸守門清單」申報，理由寫「W1 已實作；017 驗真實 HTTP 層」。第 5 條另註明：WP 核心對已登入 REST 請求本來就送 no-cache（class-wp-rest-server.php L487–501），本條只證明 stack 未剝除標頭，不證明 callback 的 nocache_headers()（後者由 PHPUnit 靜態檢查守）。
第 1～4 條必須紅，原因是 import 路由不存在（404 rest_no_route）。

【canary】03-integration/015-e2e-helper-contract.spec.ts

{{S-REPORT-RED}}
{{W2:REPORT-RED}}
```
<!-- END CARD:W2-R2 -->

### W2-RG2：Red Gate（E2E 部分，[T]）

1. CMD-GIT-SCOPE：新增只有 017 spec（與可能新增的 helper 函式——凍結 B 只能新增，CMD-FREEZE 讀 diff 確認）。
2. `cd "$PC/tests/e2e" && npx playwright test 03-integration/017-assignment-thread-import-http-contract.spec.ts --list` → Total = 申報數。
3. CMD-TUNNEL 通 → CMD-E2E-W2（TAG `w2-rg2`，**一輪**）：`03-integration/015-e2e-helper-contract.spec.ts 03-integration/017-assignment-thread-import-http-contract.spec.ts` → canary 綠；017 第 1～4 條紅且首次錯誤為 404 / `rest_no_route`；第 5、6 條綠且已申報；CMD-LC-W2。
4. CMD-W2-HASH 記錄（TAG `w2-rg2`，清單含 017）；progress。

### W2-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w2-g1` | `W2 綠燈一：CSV 解析器與匯入服務` |

派發前：沒有 E2E 在跑（G1 會 i18n:build）。

<!-- BEGIN CARD:W2-G1 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W2 綠燈（第 1 / 2 棒）：Utils/CsvParser 與 Service/CsvImport。本棒**不加任何路由**、不改 Core/Api.php。

{{S-GREEN-PHP}}
{{W2:GREEN-PHP}}

【本階段必讀】計劃 §11 W2 Green、§7.3、DEC-W2-09～12、14～16、§4.1 匯入相關 msgid（含 UC-W2-1 三條：CSV file is empty / CSV has no data rows / Import is limited to %1$d rows per batch. Please split the file and try again (current: %2$d rows)）；參考實作 course-submission-csv.service.ts（importGrades、normalizeStatus、pick）與 csv-import.helper.ts（parseCsvBuffer）；inc/classes/Resources/Assignment/Service/{Grading,Csv}.php

【紅燈回報（主窗口貼入原文）】
{{貼上：W2-R1 回報第 1～3 段}}

【你負責讓它綠的測試】AssignmentCsvParserTest、ImportGradingCsvTest、GradingCsvRoundTripTest
【必須維持派發時狀態】ImportGradingCsvApiTest 與 AssignmentWave2ApiContractTest 的 import 資料組（下一棒，仍紅）；W1 的 w2 測試與第一波 287 支（綠）

【步驟】
1. Utils/CsvParser.php：DEC-W2-11 嚴格解析（自寫狀態機，不用 fgetcsv / str_getcsv——兩者對引號錯誤不報錯）；normalize_header()；UC-W2-1 三種情況與「無法解析」都以 \UnexpectedValueException 表示，讓 CsvImport 轉成 row 0 錯誤（訊息分別為 CSV 檔案為空 / CSV 沒有任何資料列 / 單批匯入上限… / CSV 解析失敗）
2. Service/CsvImport.php：import( string $raw, int $actor_id )；表頭別名依 DEC-W2-09（中文別名、英文 msgid、目前語系 _x( …, 'assignment csv' ) 譯文；本棒可先在 CsvImport 內重複表頭字串，Refactor 再與 Csv 共用）；狀態值依 DEC-W2-10；逐列 Grading::grade()，AssignmentException → failed + 例外訊息，其他 Throwable → failed + 'Failed to save grading result' + Plugin::logger；不開交易
3. i18n：§4.1 中本棒用到的 msgid（Failed to parse CSV、Missing submission_id、Unrecognized status (%s), must be pending/pass/fail、_x( 'blank', 'assignment import empty cell' )、UC-W2-1 三條）

【受影響既有目錄（回報前跑）】tests/Integration/Assignment 全部

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W2-G1 -->

### W2-G1C：小檢查

1. CMD-W2-HASH 驗證（對最後一個 RG：`w2-rg2`，若 R2 尚未做則 `w2-rg1`）；CMD-FREEZE。
2. CMD-PU-W2（`w2-g1c`）：G1 負責的三個 class 全 ✔；`ImportGradingCsvApiTest` 與 contract 的 import 資料組仍 ✘；W1 的 w2 測試 ✔。
3. CMD-PU-W1REG（`w2-g1c`）：287。
4. `cd "$PC" && git diff --name-only -- inc/classes/Resources/Assignment/Core` → 無輸出（本棒不得動 Core）。

### W2-G2（派發；017 自驗 [T]）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w2-g2` | `W2 綠燈二：匯入路由與真實 HTTP` |

派發前：**W2-RG2 第 3 項已完成**（017 紅燈已執行）；否則走 UC-W2-T，未回覆不派。沒有 E2E 在跑。

<!-- BEGIN CARD:W2-G2 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W2 綠燈（第 2 / 2 棒）：POST assignment-submissions/import 路由與 callback，讓 dispatch 層測試與 017 E2E 綠。

{{S-GREEN-PHP}}
{{W2:GREEN-PHP}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段必讀】計劃 §11 W2 Green、DEC-W2-01、02、13、14；§7.3 前三欄；inc/classes/Resources/Assignment/Core/MyApi.php 的 get_uploaded_file() / translate_upload_error() / is_post_max_size_exceeded()（照它的寫法，但上限 10MB、訊息用匯入專用 msgid；本棒先寫在 Api.php，Refactor 才抽共用）；上一棒產物 Utils/CsvParser.php、Service/CsvImport.php
另讀（要跑 E2E）：
C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md
C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_lc_bypass_concurrency_lock.md

【紅燈回報（主窗口貼入原文）】
{{貼上：W2-R1 回報第 1～3 段}}
{{貼上：W2-R2 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{貼上：W2-G1 回報}}

【你負責讓它綠的測試】ImportGradingCsvApiTest、AssignmentWave2ApiContractTest 的 import 資料組、017 第 1～4 條
【必須維持綠】其餘全部 w2 測試、第一波 287 支、017 第 5～6 條

【步驟】
1. Core/Api.php：POST assignment-submissions/import（ApiBase 預設 permission；不宣告 args）。callback：\nocache_headers() → function_exists( 'wc_set_time_limit' ) 時 wc_set_time_limit( 0 ) → 取檔（DEC-W2-13 順序：沒檔 / UPLOAD_ERR_NO_FILE → 400 請選擇；INI_SIZE / FORM_SIZE / 超過 post_max_size → 400 檔案過大；size > 10485760 → 400 檔案過大；之後才 is_uploaded_file()，失敗 → 400 請選擇；讀檔失敗 → 500 + log）→ CsvImport::import( file_get_contents, get_current_user_id() ) → 200
2. i18n：Please select a CSV file to import、File too large (max 10MB)（不可與第一波 50MB 那條共用）、Failed to import CSV
3. 跑 E2E：canary 03-integration/015-e2e-helper-contract.spec.ts + 017，連跑兩輪（照 W2:E2E-RUN；判準 W2:E2E-JUDGE）。tunnel 不通 → PHPUnit 部分照常完成，回報「017 未執行」。

【受影響既有目錄（回報前跑）】tests/Integration/Assignment 全部

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W2-G2 -->

### W2-GG：Green Gate

1. CMD-W2-HASH 驗證；CMD-FREEZE。
2. CMD-PU-W2（`w2-gg`）：`OK (`；Tests = W2-RG1 的 w2 Tests。
3. CMD-PU-W1REG（`w2-gg`）：287。
4. CMD-LC-W2。

### W2-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w2-f` | `W2 重構：表頭共用與匯入服務拆分` |

派發前：CMD-TUNNEL 結果寫進下方手動欄位（決定 UploadedFile 抽不抽，ADJ-W2-16）。

<!-- BEGIN CARD:W2-F -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W2 Refactor。不新增行為。

{{S-REFACTOR-PHP}}
{{W2:REFACTOR-PHP}}

【本階段 Green 回報（主窗口貼入原文）】
{{貼上：W2-G1 回報}}
{{貼上：W2-G2 回報}}

【tunnel 狀態（主窗口填：通 / 不通）】{{貼上：CMD-TUNNEL 結果}}

【本階段 Refactor 項目】
1. Service/Csv.php：把 write_grading_csv() 的表頭抽成 Csv::grading_headers()，CsvImport 的別名改從它取（DEC-W2-09）；匯出輸出逐字不變——第一波 ExportGradingCsvTest、SubmissionListCsvOrderConsistencyTest 與本波 GradingCsvRoundTripTest 必須維持綠
2. Service/CsvImport.php 每個方法 < 50 行（表頭對應、列處理、摘要組裝拆開）
3. 只有在 tunnel「通」時才做：抽 Utils/UploadedFile（從 request 取單一上傳檔、PHP 錯誤碼對應、大小上限、is_uploaded_file()；上限與訊息參數化），MyApi（50MB）與匯入（10MB）共用；MyApi::translate_upload_error() 保留 public static 且行為不變。做完必須跑 E2E 一輪，拆成兩個前景指令（照 W2:E2E-RUN；三支合計約 15 支 test，一個指令會超過 10 分鐘）：① canary 015 + 016 ② 017。tunnel「不通」→ 不做這項，回報「UploadedFile 抽取延後」
4. 非功能量測（不設門檻，失敗不擋）：在 wp-env tests 環境以 500 列（全 pass、有 assignment_graded 模板）呼叫一次 CsvImport::import()，記錄耗時與記憶體峰值。暫存腳本只能放 tests/e2e/.uploads/（gitignored，wp-env 對映到容器的 wp-content/uploads/；先用 npx wp-env run tests-cli ls wp-content/uploads 確認看得到），用 npx wp-env run tests-cli wp eval-file 執行，量完刪除腳本與資料。環境不支援就回報「未量測（原因）」。超過 30 秒在回報標出。

{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W2-F -->

### W2-PG：Phase Gate

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-W2（`w2-pg`） | `OK (`；Tests = W2-GG |
| 2 | CMD-PU-W1REG（`w2-pg`） | 287 |
| 3 | CMD-PU-FULL（`w2-pg`） | 0 failure / 0 error；Tests = W1-PG 全套 + W2 全套增量 |
| 4 | CMD-PHPSTAN-W2（`w2-pg`） | TOTAL ≤ 95；ASSIGNMENT_SCOPE = 0；NORM_OK=true |
| 5 | CMD-PHPCS-W2（`w2-pg`） | 0 error |
| 6 | CMD-I18N（`w2-pg`） | zh_TW ≤ 5、ja ≤ 199；`languages/` 7 檔有變更 |
| 7 | `cd "$PC" && grep -rnF "START TRANSACTION" inc/classes/Resources/Assignment; echo GREP_DONE` | 無輸出 |
| 8 [T] | CMD-E2E-W2（`w2-pg`，**兩輪**，**主窗口執行**（不派給 sub-agent；單輪預估超過 10 分鐘時背景執行並自己掛 Monitor 盯結果檔摘要行，見 CMD-E2E-W2））：`03-integration/015-e2e-helper-contract.spec.ts 03-integration/016-assignment-http-contract.spec.ts 03-integration/017-assignment-thread-import-http-contract.spec.ts`，每輪後 CMD-LC-W2 | W2:E2E-JUDGE；016 必須包含在內（W2-F 若抽了 UploadedFile，這是唯一驗到第一波真實上傳路徑的地方） |
| 9 | CMD-W2-HASH 驗證、CMD-FREEZE | 乾淨 |
| 10 | 讀 W2-F 回報的非功能量測 | 寫入 `phases.W2.nonfunctional`；> 30 秒 → 回報用戶評估 R-W2-06（不擋） |

第 8 項因 tunnel 無法執行 → W2 狀態 `blocked_tunnel`（PHPUnit 項目照記），W3 也無法開始；UC-W2-T 選 B 時標 `deferred`，W5-FG 前必須補跑。

### W2-CK

progress 寫入；**UC-W2-2n**：`feat(assignment): 新增匯入批改結果 CSV`、`test(assignment): 新增 CSV 匯入、解析與往返測試`、`test(e2e): 新增討論串與匯入 HTTP 契約 E2E`。附帶 **UC-W2-sec**：可喚醒 `@zenbu-powers:security-reviewer` 審上傳層與解析器（opt-in）。

---

## 8. W3：Admin SPA（批改頁的討論串與匯入，含修正第一波偏離）[T]

- 計劃：§11 W3；DEC-W2-17、18；R-W2-02、09、11；ADJ-W2-27、28
- 派發 5 次：W3-R → W3-G0 → W3-G1 → W3-G2 → W3-F；前置：W2 checkpointed（017 不得是 `deferred`）、W0-9 基線已確立

### 8.0 修正第一波偏離：批改頁預設篩選改回「待批改」（主窗口核准，ADJ-W2-27 / 28）

**改什麼**：`js/src/pages/admin/Assignments/index.tsx:40` 的 `useState<TStatusFilter>(STATUS_ALL)` → `useState<TStatusFilter>(SUBMISSION_STATUS.PENDING)`（對齊參考實作 `SubmissionsManager.tsx:513` 的 `useState<StatusFilter>('pending')`）。UC-W2-2 維持 A。

**誰做什麼**
- **W3-R（紅燈作者）**：不改第一波的 `assignment-grading.spec.ts`；在新 spec 寫「預設選中待批改、預設匯出只含待批改列」那條（紅燈先行）。
- **W3-G0（綠燈）**：改 `index.tsx` 的預設值與詳情抽屜快照（P1），並依下表修改 `assignment-grading.spec.ts` 標「改」的兩支 test——這是第二波唯一的測試檔例外（ADJ-W2-13 / 28），限於預設篩選變更造成的前提失效，不得改變其他斷言語意。
- **主窗口**：W3-G0 派發前 `--thaw`，W3-G0C 通過後 `--refreeze`（§3.2 CMD-FREEZE）。

**fixture 前提**（`assignment-grading.spec.ts` 檔頭 L16–L21、beforeAll L226–L333，beforeAll 依序繳交 A → B → C）：Student A 待批改（文字 + 1 張附件）；Student B 繳交後立即以 API 批改為 **fail**；Student C 待批改，只在 `:573` 被批改成通過。預設「全部」時列表 3 列，預設「待批改」時只有 A、C 兩列（`:573` 之後只剩 A）。

**受影響清單（w2-tdd 逐支讀過 7 支 test 與 `index.tsx`、`StatusFilter.tsx`、`ExportButtons.tsx`、`SubmissionDetail.tsx`、`hooks/index.ts::useGradeSubmission`）**

| # | 行號 / test | 原本依賴什麼 | 預設改「待批改」後 | 處理 |
|---|------------|-------------|------------------|------|
| P1 | 產品：`index.tsx` L66–L74 `detailRecord` | 批改後列表 refetch 回來仍含該筆，抽屜取最新那筆 | 該筆被「待批改」篩掉 → 抽屜退回開啟當下的快照（`graded_at` 為 null）→ 顯示「尚未批改」、批改時間不更新（程式註解已預見，預設「全部」時幾乎碰不到） | **W3-G0 必修**（主窗口已驗證此退化成立，2026-10-01）：批改成功後用 PATCH 回應（`Grading::grade()->to_array()`，含 status / feedback / graded_by / graded_at）更新快照；`useGradeSubmission` 目前 onSuccess 不傳資料，需把回應交給 `onGraded`，`index.tsx` 以 `setSelected` 合併。只改資料流 |
| T1 | `:359` 課程分頁顯示繳交數 | 課程分頁的 `(3)` 來自 courses-with-submissions | 不受狀態篩選影響 | 不改 |
| T2 | `:378` 統計卡數字 | 統計卡只依課程分頁（`StatusFilter.tsx` 註解、feature 一致） | 不受影響 | 不改 |
| T3 | `:407` 狀態篩選切換 | 進頁後依序點「待批改 → 未通過 → 通過 → 全部」，斷言 2 / 1 / 0 / 3 列 | 第一次點「待批改」是點已選中的選項（不觸發 onChange），列表本來就是 2 列，斷言照樣成立 | 不改（「預設選中待批改」改由新 spec 第 0 條斷言，不擴大例外） |
| T4 | `:455` 列表欄位與排序（L455–L501） | **預設看得到全部 3 列**：L490–L500 用 `allTextContents()` 找 A、B、C 的位置，斷言 C → B → A | B（fail）不在預設列表 → `idxB = -1` → `expect(idxC).toBeLessThan(idxB)` 必紅 | **W3-G0 改**：表格出現後先點狀態篩選「全部」，等資料列（`tr.ant-table-row`）數為 3 再往下；其餘斷言一字不動 |
| T5 | `:507` 詳情與背景資料 | 找 Student A 那一列開詳情 | A 為待批改，預設看得到 | 不改 |
| T6 | `:573` 批改通過並寫評語（L573–L658） | **預設看得到批改後的那一列**：L646–L649 批改 C 為通過後斷言 `rowC.getByText('通過')` 可見；L630–L644 斷言抽屜的批改時間已更新 | C 批改後離開「待批改」列表 → L647 必紅；另因 P1，L638 的「尚未批改」toHaveCount(0) 也會紅 | **W3-G0 改**：只改 L646–L649 這段——先斷言 rowC 從目前列表消失（`toHaveCount(0)`），再點「全部」後斷言 rowC 顯示「通過」。L630–L644 的 gradedAt 斷言**不得改**（它就是 P1 修好的證據）；統計 +1 / −1 斷言（L651–L657）不動 |
| T7 | `:664` 兩種 CSV 匯出 | 「匯出單一作業」的作業選項取自目前列表（`index.tsx` `assignmentOptions`）；批改用 CSV 只斷言檔名 | 執行到此時 A 仍是待批改，選項仍含本作業 | 不改（依賴 A 維持待批改，現有 fixture 已保證） |
| N1 | 新 spec 第 0 條（W3-R 寫） | — | — | 新增：進入批改頁、選本 spec 課程分頁後，「待批改」為選中狀態（`.pc-assignment-status-filter .ant-radio-button-wrapper-checked` 含「待批改」）、列表不含已批改的那筆；預設狀態下「匯出批改用 CSV」的下載檔含待批改學員 email、不含已批改學員 email |
| N2 | 新 spec 匯入兩條（W3-R 寫） | — | 匯入改成通過的列會離開預設列表 | 斷言「該列從待批改列表消失」，再切「全部 / 通過」斷言狀態；往返測試在預設篩選下匯出（只含待批改列）再匯入 |

**觀察（不擋、不改）**：預設「待批改」時，若該範圍沒有待批改的繳交，「匯出單一作業 CSV」的作業選單為空（選項取自目前列表）。管理員切「全部」即可；W5-D 寫進 wordpress.rule.md。

**例外的界線**：只有 W3-G0 可以改 `assignment-grading.spec.ts`，只能動 T4、T6 標明的位置；不得刪除或放寬任何既有斷言、不得改其他 test、fixture、helper、檔頭註解。W3-G0C 以 `git diff e520fd7e` 逐行審查後才 `--refreeze`。

### W3-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `w3-r` | `W3 紅燈：批改頁討論串與匯入 E2E` |

<!-- BEGIN CARD:W3-R -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W3（後台批改頁的討論串與匯入）E2E 紅燈：新增 tests/e2e/01-admin/assignment-thread-import.spec.ts。不改第一波的 assignment-grading.spec.ts（該檔的預設篩選修正由 W3-G0 負責）。

{{S-RED-E2E}}
{{W2:RED-E2E}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段必讀】計劃 §11 W3 全段、DEC-W2-17、18；specs/ui/作業批改頁.md；specs/api/api.yml L1371、L1395、L7386、L7479；tests/e2e/01-admin/assignment-grading.spec.ts（批改頁的定位方式與資料準備——只讀，不要修改，也不要複製它的 loginStudentAndGetNonce）；tests/e2e/helpers/admin-page.ts

【背景：預設篩選將改回「待批改」（主窗口核准，由 W3-G0 實作）】
第一波 js/src/pages/admin/Assignments/index.tsx:40 預設篩選為「全部」，偏離參考實作 zenbu-site-2.0 SubmissionsManager.tsx:513 的 'pending'。W3-G0 會改回「待批改」：匯出按鈕沿用目前篩選，預設「待批改」時「開頁 → 匯出 → 改 → 匯回」只動到待批改的列，不會對已批改學員重寄通知信。你的新 spec 要以「預設待批改」為前提撰寫，並用第 0 條把這個行為釘住（紅燈先行）。

【要寫的測試】新增 tests/e2e/01-admin/assignment-thread-import.spec.ts（資料以 REST 準備：學員身分 nonce 繳交、學員發訊息；fixture 至少要有一筆待批改與一筆已批改（pass 或 fail）的繳交）
0. 預設篩選：進入批改頁、選本 spec 的課程分頁後，狀態篩選「待批改」為選中狀態（.pc-assignment-status-filter 內 .ant-radio-button-wrapper-checked 含「待批改」）、列表不含已批改的那筆；在預設狀態按「匯出批改用 CSV」，用 download.path() 讀下載檔：含待批改學員的 email、不含已批改學員的 email
1. 列表「討論」徽章顯示 1 → 開詳情抽屜 → 討論串顯示學員訊息（標示「學員」）→ 關抽屜後徽章歸 0（等 .ant-skeleton 歸零後斷言徽章數字，不靠固定等待）
2. 抽屜內輸入回覆按 Enter → 新訊息出現在討論串（標示「老師」）→ 以學員 REST 讀取，收到 mine = false 的老師訊息
3. XSS：學員訊息 <img src=x onerror="window.__pcXss=1"> 以文字顯示；討論串容器內沒有 img；window.__pcXss 為 undefined
4. 匯入：setInputFiles 上傳兩列 CSV（一列 pass、一列 ok）→ 頁內結果顯示共 2 筆、更新 1、略過 1、失敗 0 與「第 3 列」錯誤 → 該筆從預設的「待批改」列表消失 → 切「全部」或「通過」後該筆顯示「通過」→ 統計卡更新
5. 往返：在預設篩選下按「匯出批改用 CSV」取得 download → 把下載檔直接餵給匯入 → 頁內結果失敗 0、略過 0
UI 規格（作業批改頁.md 第二波兩項）列出的每個關鍵元素都要有斷言。全部預期紅（第 0 條紅在「預設選中的是全部」、預設匯出含已批改學員，其餘紅在元素不存在）。

【執行前】主窗口在 W3-RG 會先 pnpm run build；你自我驗證時若 js/dist/vite-dev-server.json 存在，回報，不要自行刪除。
【canary】01-admin/smoke.spec.ts。自我驗證分兩個前景指令（合在一起約 17 支 test 會超過 10 分鐘）：① smoke + 你的新 spec ② 單獨跑 01-admin/assignment-grading.spec.ts 一輪當回歸（此時預設仍是「全部」，7 支應全綠）。

{{S-REPORT-RED}}
{{W2:REPORT-RED}}
```
<!-- END CARD:W3-R -->

### W3-RG：Red Gate [T]

1. CMD-GIT-SCOPE：只有新 spec（與申報的新增 helper 函式）。`tests/e2e/01-admin/assignment-grading.spec.ts` **不得出現**（W3-R 不改第一波檔）。
2. CMD-FREEZE：乾淨（此時 `assignment-grading.spec.ts` 仍是 `frozen`，照驗與 `e520fd7e` 相同）。
3. `npx playwright test 01-admin/assignment-thread-import.spec.ts --list` → Total = 申報數。
4. 確認沒有 PHPUnit / E2E 在跑 → `cd "$PC" && pnpm run build > "$LOG/w3-rg-build.log" 2>&1; echo "EXIT_CODE=$?"; tail -n 15 "$LOG/w3-rg-build.log"` → CMD-DIST：`NO_DEV_SERVER_JSON`、manifest 為剛剛。
5. CMD-TUNNEL → CMD-E2E-W2（`w3-rg`，一輪）：`01-admin/smoke.spec.ts 01-admin/assignment-thread-import.spec.ts 01-admin/assignment-grading.spec.ts` → smoke 綠；新 spec 全紅（第 0 條紅在「預設選中的是全部」、預設匯出含已批改學員，其餘紅在元素不存在）；assignment-grading 7 支全綠（未改的第一波回歸）；CMD-LC-W2。
6. CMD-W2-HASH 記錄（`w3-rg`）。

### W3-G0（派發）：修正第一波偏離

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `w3-g0` | `W3 綠燈零：批改頁預設篩選改回待批改` |

派發前（主窗口）：`cd "$PC" && node "$LOG/freeze.mjs" --thaw tests/e2e/01-admin/assignment-grading.spec.ts`（印 `THAW_OK … → thawed`），progress W3 記 `wave1_deviation_fix.status = in_progress`。

<!-- BEGIN CARD:W3-G0 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W3 綠燈（第 0 棒，修正第一波偏離）：批改頁預設狀態篩選從「全部」改回「待批改」，修正因此暴露的詳情抽屜快照問題，並修改第一波 tests/e2e/01-admin/assignment-grading.spec.ts 中因此前提失效的兩支 test。只做這件事，不碰討論串與匯入。

{{S-GREEN-REACT}}
{{W2:GREEN-REACT}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【為什麼】第一波 js/src/pages/admin/Assignments/index.tsx:40 是 useState<TStatusFilter>(STATUS_ALL)，偏離參考實作 C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\zb-course\submissions\SubmissionsManager.tsx:513 的 useState<StatusFilter>('pending')（用戶裁決「完全參照 zenbu-site-2.0」）。匯出按鈕沿用目前篩選；預設「待批改」時整份匯出再匯回只動到待批改的列，不會對已批改學員重寄通知信。

【本 dispatch 的測試檔例外（覆寫上方「不得修改 E2E spec」；主窗口核准，2026-10-01）】
你可以、而且只可以修改 tests/e2e/01-admin/assignment-grading.spec.ts 的下列兩處，限於預設篩選變更造成的前提失效，不得改變其他斷言語意：
- :455「列表顯示學員姓名/Email…討論徽章」（L455–L501）：原本依賴「預設看得到 A、B、C 三列」，L490–L500 斷言 C → B → A 的排序；預設待批改時 B（fail）不在列表，idxB = -1 必紅。改法：表格出現後先點狀態篩選「全部」，等資料列（tr.ant-table-row）數為 3 再往下；其餘斷言一字不動。
- :573「批改通過並寫評語後，列表狀態與統計即時更新」（L573–L658）：原本依賴「批改後的那一列仍在列表裡」，L646–L649 斷言 rowC 顯示「通過」；預設待批改時 C 批改後會離開列表。改法：只改 L646–L649——先斷言 rowC 從目前列表消失（toHaveCount(0)），再點「全部」後斷言 rowC 顯示「通過」。L630–L644 的 gradedAt 斷言不得改（它是下方第 2 點修好的證據）；L651–L657 的統計斷言不得改。
- 其餘 5 支（:359、:378、:407、:507、:664）、beforeAll / afterAll、helper、檔頭註解一律不動。你認為還有別的 test 受影響 → 停下回報，不要自行擴大。
- 不得刪除或放寬任何既有斷言（包括調大 timeout 以外的任何改寫）。
- 回報第 4 段逐行列出這兩處的修改前後對照。主窗口會以 git diff e520fd7e 逐行審查。
本波新 spec（assignment-thread-import.spec.ts）與其他任何測試檔仍不得修改。

【紅燈回報（主窗口貼入原文）】
{{貼上：W3-R 回報第 1～3 段}}

【你負責讓它綠的測試】assignment-thread-import.spec.ts 第 0 條（預設篩選與預設匯出範圍）；assignment-grading.spec.ts 改完後的 7 支
【必須維持綠】01-admin/smoke.spec.ts
【必須維持紅】assignment-thread-import.spec.ts 第 1～5 條（後兩棒）

【範圍】
1. index.tsx：useState<TStatusFilter>( SUBMISSION_STATUS.PENDING )（常數已在 types/index.ts）；handleCourseChange 目前不重置狀態篩選，維持現狀
2. 詳情抽屜快照：index.tsx L66–L74 的 detailRecord 在該筆被目前篩選排除時會退回開啟當下的快照，批改後抽屜因此顯示「尚未批改」（:573 的 L638 會紅）。改為批改成功後用 PATCH 回應（後端回 Grading::grade()->to_array()，含 status、feedback、graded_by、graded_at）更新 selected（例如 useGradeSubmission 的 onSuccess 把回應資料傳給 onGraded，index.tsx 以 setSelected 合併）。只改資料流，不改抽屜版面與 GradeForm 的表單狀態邏輯
3. 上方兩處 test 修改
4. 不需要新字串；若有，照 i18n 規則處理
5. 變更集中在上述範圍、獨立成一組：主窗口會把它做成獨立 commit，必要時 cherry-pick 回第一波分支 feat/issue-131-assignment-grading。回報第 4 段列出每個變更檔與行號範圍

【E2E】跑兩輪，每輪拆成兩個前景指令（W2:E2E-RUN；合在一起約 17 支 test 會超過 10 分鐘），共四個指令：① smoke + assignment-thread-import.spec.ts ② assignment-grading.spec.ts。判準 W2:E2E-JUDGE，assignment-grading 改過的兩支適用第 4 點

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W3-G0 -->

### W3-G0C：小檢查 [T]

1. CMD-W2-HASH 驗證（對 `w3-rg`：新 spec 未被改）；CMD-FREEZE（此時印 `THAWED=1（tests/e2e/01-admin/assignment-grading.spec.ts）`，其餘乾淨）。
2. **例外範圍審查**：`cd "$PC" && git diff e520fd7e -- tests/e2e/01-admin/assignment-grading.spec.ts`，逐行確認：
   - 變更只落在 `:455` 本體（L455–L501 內，新增「點全部 + 等 3 列」）與 `:573` 的 L646–L649 一段
   - 沒有任何既有 `expect` 被刪除或放寬（L630–L644 gradedAt、L651–L657 統計、`:455` 的欄位與排序斷言都還在且原樣）
   - 其他 test、beforeAll / afterAll、helper、檔頭無變更
   任一不符 → 退回 W3-G0（附 diff），不得 `--refreeze`。
3. `cd "$PC" && git status --short --untracked-files=all -- js tests`：產品變更只在 `js/src/pages/admin/Assignments/`（預期 `index.tsx`、`hooks/index.ts`，可能 `components/SubmissionDetail.tsx`），測試變更只在 `assignment-grading.spec.ts`——這組變更之後要能單獨 commit。
4. CMD-TSC exit 0；確認無 PHPUnit / E2E 在跑 → `pnpm run build`；CMD-DIST。
5. CMD-E2E-W2（`w3-g0c`，一輪）：`01-admin/smoke.spec.ts 01-admin/assignment-thread-import.spec.ts 01-admin/assignment-grading.spec.ts` → smoke 綠；assignment-grading 7 支全綠；新 spec 第 0 條綠、第 1～5 條仍紅；CMD-LC-W2。
6. 全部通過後：`cd "$PC" && node "$LOG/freeze.mjs" --refreeze tests/e2e/01-admin/assignment-grading.spec.ts`（印 `REFREEZE_OK … → refrozen <新 sha1>`）→ 再跑一次 CMD-FREEZE：`R=1 THAWED=0 SHA_FAIL=0`。
7. progress：`phases.W3.wave1_deviation_fix` 記錄 `status = done`、變更檔與行號（供 W3-CK 拆 commit）。
8. **立即 commit（主窗口已同意，2026-10-01；commit 政策「各 Phase Gate 才 commit」的唯一例外）**：第 1～7 項全部通過、`--refreeze` 完成後，馬上把 W3-G0 的全部變更自成一個 commit——`fix(admin): 批改頁預設狀態篩選改為待批改（對齊參考實作）`，內容 = 產品（`index.tsx` 預設值與抽屜快照、`hooks/index.ts`、必要時 `components/SubmissionDetail.tsx`）＋ `assignment-grading.spec.ts` 的 `:455`、`:573` 調整，**不得夾帶**任何其他檔案（`git add` 逐檔指定，先 `git diff --cached --stat` 確認）。理由：它日後可能要 cherry-pick 到第一波分支（§10 W5-CK），必須和 W3-G1 / G2 的討論串、匯入變更分得乾淨；同一個 commit 內放產品與它直接造成的測試前提調整，cherry-pick 後第一波分支每個 commit 都是綠的。progress 記 `phases.W3.wave1_deviation_fix.commits`。這個 commit 完成前不得派 W3-G1。

### W3-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `w3-g1` | `W3 綠燈一：批改頁討論串` |

<!-- BEGIN CARD:W3-G1 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W3 綠燈（第 1 / 2 棒）：批改頁詳情抽屜的討論串（DEC-W2-17）。

{{S-GREEN-REACT}}
{{W2:GREEN-REACT}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【紅燈回報（主窗口貼入原文）】
{{貼上：W3-R 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文；W3-G0 修正第一波偏離）】
{{貼上：W3-G0 回報}}

【你負責讓它綠的測試】assignment-thread-import.spec.ts 的討論串三條（徽章歸零、回覆、XSS）
【必須維持】01-admin/smoke.spec.ts 綠；新 spec 第 0 條（預設篩選，W3-G0 已綠）綠；01-admin/assignment-grading.spec.ts 7 支綠（回報前單獨一個前景指令跑一輪）；匯入兩條仍紅（下一棒）
【注意】預設篩選已是「待批改」（W3-G0）：不要改回或改動 index.tsx 的預設值與 detailRecord 邏輯。

【範圍】
- js/src/pages/admin/Assignments/components/SubmissionThread.tsx（新）：放 SubmissionDetail 抽屜最後一段；開抽屜即載入，key 綁 submission id；載入成功後 refetch 列表讓「討論」徽章歸零；訊息顯示「老師 / 學員」、時間、mine && read_at 時顯示「已讀」；單行 Input、Enter 送出、前端 trim 後為空不可送；回覆後訊息就地附加
- components/index.ts、components/SubmissionDetail.tsx；hooks/index.ts（useSubmissionThread、usePostThreadMessage）；types/index.ts（TAssignmentMessage；更新「第一波恆為 0」的註解）
- i18n：計劃 §4.1 UI 討論串字串（Teacher 用 _x( 'Teacher', 'assignment thread sender' )、Read 用 _x( 'Read', 'assignment message status' )；Student 重用既有）

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W3-G1 -->

### W3-G1C：小檢查 [T]

CMD-W2-HASH 驗證；CMD-DIST；CMD-E2E-W2（`w3-g1c`，一輪）smoke + 新 spec + `01-admin/assignment-grading.spec.ts` → smoke、新 spec 第 0 條與討論串三條綠、匯入兩條紅、assignment-grading 7 支綠；CMD-LC-W2。

### W3-G2（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `w3-g2` | `W3 綠燈二：批改頁匯入 CSV` |

<!-- BEGIN CARD:W3-G2 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W3 綠燈（第 2 / 2 棒）：批改頁工具列的匯入 CSV 與結果顯示（DEC-W2-18）。

{{S-GREEN-REACT}}
{{W2:GREEN-REACT}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【紅燈回報（主窗口貼入原文）】
{{貼上：W3-R 回報第 1～3 段}}

【上一棒回報（主窗口貼入原文）】
{{貼上：W3-G0 回報}}
{{貼上：W3-G1 回報}}

【你負責讓它綠的測試】assignment-thread-import.spec.ts 的匯入兩條（匯入結果、往返）
【必須維持綠】smoke、新 spec 第 0～3 條、01-admin/assignment-grading.spec.ts 7 支（回報前單獨一個前景指令跑一輪）
【注意】預設篩選是「待批改」：匯入後被改成通過 / 未通過的列會離開目前列表，這是預期行為；匯入完成後的 refetch 不要切換篩選。

【範圍】
- components/ImportButton.tsx、ImportResult.tsx（新）：ExportButtons 旁；antd Upload（accept=".csv,text/csv"、beforeUpload 攔下不走 antd 自己的上傳）→ FormData（field file）+ useCustomMutation（沿用 useSubtitles.tsx 的 multipart 寫法）；結果用頁內可關閉 Alert；完成後 refetch 列表、統計、課程分頁
- index.tsx 工具列；hooks/index.ts（useImportGradingCsv）；types/index.ts（TImportSummary）
- i18n：計劃 §4.1 UI 匯入字串（Import grading CSV、Import result: %1$d total, %2$d updated, %3$d skipped, %4$d failed、Row %1$d: %2$s、_n( '%d more error', '%d more errors' )、Failed to import CSV），含 placeholder 一律 sprintf + translators 註解

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W3-G2 -->

### W3-GG：Green Gate [T]

CMD-W2-HASH 驗證；CMD-DIST `NO_DEV_SERVER_JSON`；CMD-E2E-W2（`w3-gg`，一輪）smoke + 新 spec + `01-admin/assignment-grading.spec.ts` → 全綠（W2:E2E-JUDGE）；CMD-LC-W2。

### W3-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:react-master` | `w3-f` | `W3 重構：討論串查詢 key 與型別` |

<!-- BEGIN CARD:W3-F -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W3 Refactor。不新增行為、不改 E2E spec。

{{S-GREEN-REACT}}
{{W2:GREEN-REACT}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段 Green 回報（主窗口貼入原文）】
{{貼上：W3-G0 回報}}
{{貼上：W3-G1 回報}}
{{貼上：W3-G2 回報}}

【本階段 Refactor 項目】
- 不得改動 W3-G0 的偏離修正（預設篩選與 detailRecord 快照更新）：那組變更可能要單獨 cherry-pick 回第一波分支，混入重構會讓它拆不開
- 討論串的 query key 統一（列表徽章 refetch 與討論串載入用同一組 key 常數）
- 匯入成功只 invalidate 列表、統計、課程分頁
- props 型別集中到 types/；移除 any
- 完成後：npx eslint <W3 所有變更的 ts/tsx> --fix、pnpm run type-check、pnpm run build、E2E 兩輪、每輪兩個前景指令（① smoke + 新 spec ② 01-admin/assignment-grading.spec.ts，W2:E2E-RUN）、W2:LC

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W3-F -->

### W3-PG：Phase Gate（前端版）[T]

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-ESLINT（`w3-pg`） | errors ≤ W0-6 基線；另跑 `npx eslint <W3 新增檔>` → 0 error |
| 2 | CMD-TSC（`w3-pg`） | exit 0 |
| 3 | 確認無 PHPUnit / E2E 在跑 → `pnpm run build`（log `w3-pg-build.log`） | exit 0；log 中 zh_TW ≤ 5、ja ≤ 199；`languages/` 7 檔有變更 |
| 4 | CMD-DIST | `NO_DEV_SERVER_JSON`，manifest 為剛剛 |
| 5 | CMD-E2E-W2（`w3-pg`，**兩輪**，**主窗口執行**（不派給 sub-agent；單輪預估超過 10 分鐘時背景執行並自己掛 Monitor 盯結果檔摘要行，見 CMD-E2E-W2））：`01-admin/smoke.spec.ts 01-admin/assignment-thread-import.spec.ts 01-admin/assignment-grading.spec.ts`，每輪後 CMD-LC-W2 | W2:E2E-JUDGE；assignment-grading 為第一波回歸（含本波修改的 `:455`、`:573`，適用 E2E-JUDGE 第 4 點） |
| 5' | 人工核對 `js/src/pages/admin/Assignments/index.tsx` | 預設篩選為 `SUBMISSION_STATUS.PENDING`；W3-G1 / G2 / F 沒有改動 W3-G0 的那組變更（對照 `phases.W3.wave1_deviation_fix` 的檔案與行號） |
| 6 | CMD-GIT-SCOPE 篩 `*.php` | W3 期間有 PHP 變更 → 追加 CMD-PU-W2、CMD-PU-W1REG、CMD-PU-FULL、CMD-PHPSTAN-W2（TOTAL ≤ 95、ASSIGNMENT_SCOPE = 0）、CMD-PHPCS-W2 |
| 7 | CMD-GIT-SCOPE | W3 範圍外、且基線時乾淨的檔案被改 → 藍圖一 UC-lint（L453） |
| 8 | CMD-W2-HASH 驗證、CMD-FREEZE | 乾淨 |

### W3-CK

progress 寫入；**UC-W2-3n**（依 commit 政策）。修正第一波偏離已在 W3-G0C 第 8 項自成一個 commit（主窗口同意的提前 commit），W3-CK 只 commit 其餘變更：
1. `feat(admin): 批改頁新增討論串與匯入批改 CSV`
2. `test(e2e): 新增批改頁討論串與匯入 E2E`（含新 spec 第 0 條）

先確認 W3-G1 / G2 / F 沒有動到 W3-G0 commit 的那些 hunk（W3-PG 第 5' 項）。可喚醒 `@zenbu-powers:react-reviewer`（opt-in）。

---

## 9. W4：前台教室討論串 [T]

- 計劃：§11 W4；DEC-W2-19；R-W2-02、09、11、16、17
- 派發 3 次：W4-R → W4-G1 → W4-F；前置：W3 checkpointed（序列，ADJ-W2-15）

### W4-R（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:test-creator` | `w4-r` | `W4 紅燈：教室討論串 E2E` |

<!-- BEGIN CARD:W4-R -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W4（教室作業卡片的討論串）E2E 紅燈：新增 tests/e2e/02-frontend/022-classroom-assignment-thread.spec.ts。不改第一波 021。

{{S-RED-E2E}}
{{W2:RED-E2E}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段必讀】計劃 §11 W4 全段、DEC-W2-19；specs/ui/教室作業區塊.md（討論串（第二波））；specs/api/api.yml L7758；tests/e2e/02-frontend/021-classroom-assignments.spec.ts（教室頁定位、章節 URL 組法——只看寫法）、008-classroom-chapters.spec.ts
WP Rocket：前台看到舊頁時，依 memory reference_local_frontend_verify_playbook 第 2、3 點清快取；不得用 git checkout -- plugin.php 還原（該 memory 舊版第 1 點的寫法，2026-10-01 已更正），LC 一律照 W2:LC。

【要寫的測試】（教室頁 URL 用 chapterSlugs 組）
1. 有繳交的卡片有「與老師討論」、沒繳交的卡片沒有；管理員預覽模式（data-readonly）沒有討論串
2. 老師訊息以管理員 REST 準備 → 學員展開 → 看到標示「老師」的訊息 → 再以學員 REST 讀一次，老師訊息 read_at 不為空（展開 = 標已讀）；展開前先以管理員 REST 確認該訊息 read_at 為 null
3. 學員送出 → 出現「我」的訊息；管理員 REST 讀取後，學員重新整理並展開 → 自己的訊息顯示「已讀」
4. 頁內首次繳交成功後，不重新整理就出現「與老師討論」且可送出訊息
5. 狀態為通過（API 批改）後重新載入：繳交表單隱藏、討論串仍在
6. XSS：學員訊息 <img src=x onerror="window.__pcXss=1"> 以文字顯示、討論串內沒有 img、window.__pcXss 為 undefined
（選做）線性觀看：先在未開啟時繳交，再開啟線性觀看使該章節鎖定 → 討論串仍可用（D29）
全部預期紅（元素不存在）。

【canary】02-frontend/008-classroom-chapters.spec.ts

{{S-REPORT-RED}}
{{W2:REPORT-RED}}
```
<!-- END CARD:W4-R -->

### W4-RG：Red Gate [T]

1. CMD-GIT-SCOPE：只有 022 spec（與申報的新增 helper 函式）。
2. `npx playwright test 02-frontend/022-classroom-assignment-thread.spec.ts --list` → Total = 申報數。
3. 確認無 PHPUnit / E2E 在跑 → `cd "$PC" && pnpm run build:wp > "$LOG/w4-rg-buildwp.log" 2>&1; echo "EXIT_CODE=$?"`。
4. CMD-TUNNEL → CMD-E2E-W2（`w4-rg`，一輪）：`02-frontend/008-classroom-chapters.spec.ts 02-frontend/022-classroom-assignment-thread.spec.ts` → canary 綠、022 全紅且原因為元素不存在（出現舊頁面 → 清 WP Rocket 後重跑，屬環境修復）；CMD-LC-W2。
5. CMD-W2-HASH 記錄（`w4-rg`）；CMD-FREEZE。

### W4-G1（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w4-g1` | `W4 綠燈：教室討論串模板與互動` |

<!-- BEGIN CARD:W4-G1 -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W4 綠燈：教室作業卡片的討論串（PHP 模板 + vanilla TS）。你同時負責模板與 TS，確保兩邊的 data 屬性一致。

{{S-GREEN-PHP}}
{{W2:GREEN-PHP}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段額外必讀】
- .claude/rules/react.rule.md（TS 慣例）、.claude/rules/e2e-testing.rule.md
- 計劃 §11 W4 全段、DEC-W2-19、§6.2 教室相關列；specs/ui/教室作業區塊.md
- inc/templates/components/assignment/card.php、inc/assets/src/events/assignments/{index,submit}.ts 與同目錄其他檔、inc/assets/src/main.ts、vite.config-for-wp.ts（@wordpress/i18n shim alias）、inc/classes/Templates/Ajax.php（前台 script 翻譯載入）
- 參考實作：C:\Users\User\DEV\zenbu\zenbu-site-2.0\apps\web\app\(public)\[locale]\account\courses\[slug]\AssignmentReviewView.tsx（SubmissionCard、Thread）
- Memory（絕對路徑）：
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_frontend_assets_build.md
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\reference_local_frontend_verify_playbook.md（LC 一律照 W2:LC，不得用 git checkout -- plugin.php）
  C:\Users\User\.claude\projects\C--Users-User-LocalSites-turbo-app-public-wp-content-plugins-power-course\memory\project_e2e_helper_traps.md

【紅燈回報（主窗口貼入原文）】
{{貼上：W4-R 回報第 1～3 段}}

【你負責讓它綠的 spec】tests/e2e/02-frontend/022-classroom-assignment-thread.spec.ts
【必須維持綠】02-frontend/008-classroom-chapters.spec.ts、02-frontend/021-classroom-assignments.spec.ts、PHPUnit --group issue-131-w2 與 --group issue-131 --exclude-group issue-131-w2

【步驟】（DEC-W2-19）
1. card.php：永遠輸出 data-submission-id（無繳交為空字串）與討論串區塊（切換鈕「與老師討論」、訊息清單、輸入列、訊息節點的 <template>）；無繳交時整塊 hidden；通過時繳交表單隱藏但討論串照常顯示；管理員預覽（data-readonly="yes"）不輸出討論串；可抽 thread.php partial；模板用 esc_html__ / esc_attr__
2. inc/assets/src/events/assignments/thread.ts（新）：展開才載入（GET = 標老師訊息已讀）、送出（POST）；X-WP-Nonce: pc_data.nonce、credentials: 'same-origin'；訊息由 <template> clone 後 textContent 填值，禁止 innerHTML；時間顯示 created_at 去掉秒；index.ts 綁定
3. submit.ts：首次繳交成功後寫入 data-submission-id 並移除討論串 hidden
4. 線性觀看鎖定不影響討論串（D29）
5. i18n：計劃 §4.1 UI 討論串字串（與 W3 共用的 msgid 不重複新增）

【固定順序補充】寫碼 → manual.json → 確認無 E2E / PHPUnit 在跑 → pnpm run i18n:build → pnpm run build:wp → grep "Discuss with teacher" inc/assets/dist/ 確認 dist 新鮮 → E2E（canary 008 + 022 連跑兩輪，W2:E2E-RUN）→ PHPUnit --group issue-131-w2 與第一波回歸
注意：inc/assets 不在 tsconfig，vite build 不做型別檢查——型別錯誤只能靠你自己與 E2E。

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W4-G1 -->

### W4-GG：Green Gate [T]

1. CMD-W2-HASH 驗證；CMD-FREEZE。
2. 確認無 PHPUnit / E2E 在跑 → `pnpm run build:wp`（log `w4-gg-buildwp.log`）exit 0；`cd "$PC" && grep -rlF "Discuss with teacher" inc/assets/dist/ | head -n 3` 有輸出。
3. CMD-E2E-W2（`w4-gg`，一輪）：008 + 022 → 全綠（W2:E2E-JUDGE）；CMD-LC-W2。
4. CMD-PU-W2、CMD-PU-W1REG（`w4-gg`）：Tests 與 W2-PG 相同（W4 不新增 PHPUnit）。

### W4-F（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `general-purpose` | `w4-f` | `W4 重構：訊息節點單一來源` |

<!-- BEGIN CARD:W4-F -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波 W4 Refactor。不新增行為。

{{S-REFACTOR-PHP}}
{{W2:REFACTOR-PHP}}
{{W2:E2E-RUN}}
{{W2:E2E-JUDGE}}
{{W2:LC}}

【本階段 Green 回報（主窗口貼入原文）】
{{貼上：W4-G1 回報}}

【本階段 Refactor 項目】
- 訊息節點只來自 PHP <template>；TS 不拼 HTML 字串
- 貼 grep -rnF "innerHTML" inc/assets/src/events/assignments; echo GREP_DONE（GREP_DONE 前必須無輸出）
- 完成後：確認無 E2E / PHPUnit 在跑 → pnpm run build:wp → E2E 一輪、拆兩個前景指令（① 008 + 022 ② 021，W2:E2E-RUN；合在一起約 18 支 test 會超過 10 分鐘）→ PHPUnit --group issue-131-w2 與第一波回歸

{{S-REPORT-GREEN}}
{{W2:REPORT-GREEN}}
```
<!-- END CARD:W4-F -->

### W4-PG：Phase Gate [T]

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-W2、CMD-PU-W1REG、CMD-PU-FULL（`w4-pg`） | 全綠；Tests 與 W2-PG（W3 有 PHP 變更則 W3-PG）相同 |
| 2 | CMD-PHPSTAN-W2、CMD-PHPCS-W2（`w4-pg`） | TOTAL ≤ 95、ASSIGNMENT_SCOPE = 0（本階段改的 `inc/templates/components/assignment/**` 在範圍內）；PHPCS 0 error |
| 3 | CMD-I18N（`w4-pg`） | zh_TW ≤ 5、ja ≤ 199；`languages/` 7 檔有變更 |
| 4 | `pnpm run build:wp` + `grep -rlF "Discuss with teacher" inc/assets/dist/` | exit 0；有輸出 |
| 5 | `cd "$PC" && grep -rnF "innerHTML" inc/assets/src/events/assignments; echo GREP_DONE` | 無輸出 |
| 6 | CMD-E2E-W2（`w4-pg`，**兩輪**，**主窗口執行**（不派給 sub-agent；單輪預估超過 10 分鐘時背景執行並自己掛 Monitor 盯結果檔摘要行，見 CMD-E2E-W2））：`02-frontend/008-classroom-chapters.spec.ts 02-frontend/022-classroom-assignment-thread.spec.ts 02-frontend/021-classroom-assignments.spec.ts`，每輪後 CMD-LC-W2 | W2:E2E-JUDGE；021 為第一波回歸 |
| 7 | CMD-W2-HASH 驗證、CMD-FREEZE | 乾淨 |

### W4-CK

progress 寫入；**UC-W2-4n**：`feat(classroom): 教室作業卡片新增討論串`、`test(e2e): 新增教室討論串 E2E`。附帶 **UC-W2-sec**：可喚醒 `@zenbu-powers:security-reviewer` 審 XSS 與學員端 ownership（opt-in）。

---

## 10. W5：收尾驗收與文件同步

### W5-FG：最終關卡（主窗口親跑，派 doc-updater 之前）

| # | 命令 | 判準 |
|---|------|------|
| 1 | CMD-PU-FULL（`w5-fg`） | 0 failure / 0 error；Tests = 1638 + W1～W4 全套增量合計 |
| 2 | CMD-PU-W2（`w5-fg`） | `OK (`；Tests = `counters.w2_group_total`；Incomplete / Skipped / Risky = 0 |
| 3 | CMD-PU-W1REG（`w5-fg`） | `OK (287 tests, 1398 assertions)` |
| 4 | CMD-PHPSTAN-W2、CMD-PHPCS-W2 | TOTAL ≤ 95、ASSIGNMENT_SCOPE = 0、NORM_OK=true；PHPCS 0 error |
| 5 | CMD-ESLINT、CMD-TSC | errors ≤ 基線；exit 0 |
| 6 | CMD-I18N | zh_TW ≤ 5、ja ≤ 199 |
| 7 | 確認無 PHPUnit / E2E 在跑 → `pnpm run build`、`pnpm run build:wp`；CMD-DIST | 兩者 exit 0；`NO_DEV_SERVER_JSON` |
| 8 [T] | CMD-E2E-W2（`w5-fg`，**兩輪**，**主窗口執行**（不派給 sub-agent；單輪約 30～50 分鐘：背景執行（timeout 7200000）並自己掛 Monitor 盯結果檔摘要行，見 CMD-E2E-W2））：`01-admin/smoke.spec.ts 02-frontend/008-classroom-chapters.spec.ts 03-integration/015-e2e-helper-contract.spec.ts 01-admin/assignment-grading.spec.ts 02-frontend/021-classroom-assignments.spec.ts 03-integration/016-assignment-http-contract.spec.ts 03-integration/017-assignment-thread-import-http-contract.spec.ts 01-admin/assignment-thread-import.spec.ts 02-frontend/022-classroom-assignment-thread.spec.ts`，每輪後 CMD-LC-W2 | W2:E2E-JUDGE（約 48 支 test，單輪預估 30～50 分鐘） |
| 9 | CMD-LC-W2 | 乾淨（最後一輪結束、無其他 run） |
| 10 | CMD-FREEZE | 乾淨：`A=36 R=1 THAWED=0 SHA_FAIL=0 CONTENT_DIFF=0`（凍結 A 其餘 36 檔從頭到尾沒動過；`assignment-grading.spec.ts` 與 W3-G0C 重新凍結時相同）；另跑 `git diff e520fd7e -- tests/e2e/01-admin/assignment-grading.spec.ts`，變更仍只在 `:455`、`:573` 兩處 |
| 11 | `cd "$PC" && grep -rnF "sender_email" inc/classes/Resources/Assignment/Core; grep -rnF "dangerouslySetInnerHTML" js/src/pages/admin/Assignments; grep -rnF "innerHTML" inc/assets/src/events/assignments; echo GREP_DONE` | 第一個只出現在寫入路徑（人工確認）；後兩個無輸出 |
| 12 | `.progress-wave2.json` | W1～W4 皆 `checkpointed`；`deferred` 項目（UploadedFile 抽取、UC-W2-T）列入最終回報 |

任一不符 → §3.4，修復後從本表第 1 項重跑。

### W5-SS：截圖 [T]

用 `playwright-cli` skill 截：批改頁抽屜討論串（含「已讀」）、匯入結果（含錯誤列）、教室卡片討論串（收合 / 展開 / 已讀）。前置：LC bypass 只靠 helper 的 apply / revert 成對（CMD-LC-W2），**不得** `git checkout -- plugin.php`；WP Rocket 照 playbook 第 3 點；`confirm_admin_email` 攔截頁照 `loginAs()` 的處理。截圖存 `$LOG`，不進 repo。

### W5-D（派發）

| subagent_type | name | description |
|---------------|------|-------------|
| `zenbu-powers:doc-updater` | `w5-d` | `W5 文件同步：作業討論串與匯入` |

<!-- BEGIN CARD:W5-D -->
```text
{{S-CONTEXT}}
{{W2:CONTEXT}}

【任務】Issue #131 第二波完成後的專案文件同步。只改文件，不改程式碼與測試。

【必讀】計劃 §6.3、§10、§11 W5、§14 R-W2-03 / 05 / 13；git diff --stat e520fd7e 與 git status 列出的所有變更；inc/classes/Resources/Assignment/** 結構；.claude/CLAUDE.md；.claude/rules/{wordpress,i18n}.rule.md；specs/entity/erm.dbml；specs/api/api.yml；specs/features/assignment/句型.md

【用戶裁決（已定）】UC-W2-1 照參考實作（空檔 / 只有表頭 / 超過 5000 列 → row 0）；UC-W2-2 = A（整份重匯不防、不加提醒）；UC-W2-3 不做學員端未讀徽章；UC-W2-4 Big5 → CSV 解析失敗

【要更新】
1. .claude/CLAUDE.md：Assignment Resource 段補討論串（讀時標對方訊息已讀、mine 依端別、無通知）與匯入（依表頭名稱與別名、逐列 Grading::grade、不開交易、單批 5000 列 / 10MB）
2. .claude/rules/wordpress.rule.md：Assignment 結構補 Model/Message、Service/Thread、Service/CsvImport、Utils/CsvParser（與 W2-F 實際產物一致，若有 Utils/UploadedFile 一併）；端點清單 +5；匯入語意與 DEC-W2-15 的後果（整份重匯會重寄批改信、pending 列寫入 graded_at、缺回饋欄清空評語）；批改頁預設篩選為「待批改」（對齊參考實作；匯出沿用目前篩選，所以預設匯出只含待批改列，這是「不防重匯」成立的前提；待批改為 0 時「匯出單一作業」選單為空，切「全部」即可）；R-W2-13（多位管理員彼此訊息皆為 mine）
3. .claude/rules/i18n.rule.md：確認術語表含討論串 / 老師 / 已讀 / 匯入
4. 規格疑義（計劃 §10）依裁決修正：S-01 在匯入 feature「CSV 無法解析」Rule 補空檔 / 只有表頭 / 超過 5000 列 3 個 Example（訊息照計劃 DEC-W2-12）；S-03 在「表頭接受欄位名稱別名」Rule 補一句「亦接受匯出時使用的表頭（含各語系）」；S-05 erm pc_assignment_messages Note 補「不檢查作業狀態」；S-09 修正句型.md「查詢作業討論串」Then 欄位；S-10 api.yml 管理端 messages 補 401NotLoggedIn。S-02、S-04、S-06、S-08 不改；S-07 不改規格
5. 在 specs/plan/issue-131-assignment-grading/execution-plan.md 既有段落補註後續事項：學員端未讀徽章（UC-W2-3，建議另開 issue）、Big5 友善提示（UC-W2-4）、017 / 016 的 Cache-Control 斷言只證明 stack 未剝除標頭（WP 核心對已登入請求本來就送）、tests/e2e/README.md 的 revertLcBypass 指令壞掉（import .js 不存在，需 --experimental-strip-types 與 .ts）；不新建檔

【禁止】commit / push；改程式碼或測試（含 tests/e2e/README.md——那是 tests/ 下的檔案，只在第 5 點記錄）；建立新的報告型 .md

【回報】每個檔案的變更摘要（前後對照重點句）、git status --short 原文
```
<!-- END CARD:W5-D -->

### W5-DG：文件檢查（主窗口）

- `git diff --stat` 只有文件類檔案；CMD-W2-HASH 驗證與 CMD-FREEZE 乾淨；`git diff --name-only` 無新的 `inc/`、`js/`、`tests/` 變更。
- 對照 W5-D 的 5 項逐條確認；specs 修改後跑一次 CMD-PU-W2（feature 檔不影響測試，但確認沒有誤改到 tests）。

### W5-CK

- progress：W5 `checkpointed`。
- 依 commit 政策提出最終 commit 分組與分支處置（`/zenbu-powers:finishing-branch`）。**全部待用戶同意**。
- **分支順序提醒（UC-W2-branch）**：若用戶決定第一波（`feat/issue-131-assignment-grading`）先單獨合進 master，**W3-G0 應先落在第一波分支，再 merge 進第二波分支**：W3-G0 的「預設篩選改回待批改」修正（W3-G0C 第 8 項提前做的那一個 commit：產品變更 + `assignment-grading.spec.ts` 兩支 test）在第一波分支 cherry-pick、跑一次第一波的 `01-admin/assignment-grading.spec.ts` 兩輪與 smoke（W2:E2E-JUDGE），再把第一波分支 merge 進第二波分支。否則偏離會隨第一波出貨：已上線的站預設「全部」，管理員整份匯出再匯回會對已批改學員重寄通知信（第二波一上線匯入功能就踩到）。若兩波一起合併則不需要另外處理。
- 主窗口最終回報：分支與各階段 checkpoint / commit；PHPUnit 基線 → 最終（Tests / Assertions / Skipped）、w2 group 數、第一波回歸 287；PHPStan、PHPCS、ESLint、type-check；i18n 未翻譯數；E2E 9 支兩輪結果與 flaky 清單分類；截圖路徑；`deferred` 項目；非功能量測；建議另開 issue（S-07 學員端未讀、UC-W2-4 Big5 提示、README 還原指令、第一波 016 Cache-Control 斷言的證據力）。

---

## 11. Opt-in 深度審查（用戶喚醒才派）

| Agent | 建議時機 | 理由 |
|-------|---------|------|
| `@zenbu-powers:security-reviewer` | W2-CK 之後（上傳層、解析器、權限矩陣）、W4-CK 之後（XSS、學員端 ownership） | WordPress 專案；檔案上傳、CSV 解析、使用者輸入原樣存再顯示 |
| `@zenbu-powers:react-reviewer` | W3-CK 之後 | 討論串 GET 的副作用與 React Query 設定、匯入 multipart |

審查 FAIL 時沿用藍圖一 L2110 的處理（用戶決定是否修，最多 3 輪，每輪重跑受影響階段 PG）。

---

## 附錄 A：第一波測試檔凍結清單（2026-10-01 於 `e520fd7e` working tree 實測）

sha1 原文存在 `.progress-wave2.json` 的 `freeze.wave1_tests.sha1`（36 行）與 `freeze.shared_infra.sha1`（11 行），CMD-FREEZE 直接從那裡重建檢查檔。

**凍結 A（硬凍結，37 檔；其中 `assignment-grading.spec.ts` 依 ADJ-W2-28 由 W3-G0 起解凍、W3-G0C 重新凍結）**：`tests/Integration/Assignment/` 下 27 檔（AssignmentApiContractTest、AssignmentCPTStructureTest、AssignmentDueReminderTest、AssignmentEmailReplaceTest、AssignmentGradedEmailTest、AssignmentStudentOverviewTest、AssignmentSubmissionStatsTest、AssignmentTablesMigrationTest、AssignmentTestCase、ChapterAssignmentCascadeTest、CourseDuplicateExcludesAssignmentsTest、CoursesWithSubmissionsTest、CreateAssignmentTest、DeleteAssignmentTest、DownloadAssignmentAttachmentTest、ExportAssignmentSubmissionsCsvTest、ExportGradingCsvTest、GradeAssignmentSubmissionTest、ListAssignmentSubmissionsTest、QueryAssignmentsTest、QueryMyAssignmentsTest、RemoveStudentKeepsSubmissionsTest、SortAssignmentsTest、SubmissionListCsvOrderConsistencyTest、SubmitAssignmentTest、UpdateAssignmentTest、UploadAssignmentAttachmentTest）；`tests/Integration/Email/EmailTriggerAtTest.php`、`SendNowTest.php`（第一波以方法層級 `@group issue-131` 修改）；第一波 4 支 spec（`01-admin/assignment-chapter-manage`、`01-admin/email-template`、`02-frontend/021-classroom-assignments`、`03-integration/016-assignment-http-contract`）；3 支 canary（`01-admin/smoke`、`02-frontend/008-classroom-chapters`、`03-integration/015-e2e-helper-contract`）。canary 列入凍結：它們是「環境壞 vs 功能壞」的參照，波中改動會讓這個區分失效。

**核准的例外（ADJ-W2-13 / 28）**：`tests/e2e/01-admin/assignment-grading.spec.ts`（`e520fd7e` 時 sha1 `495246207b1f1e040b1175d5ec26a3b84de514c8`），只限 **W3-G0** 修改 `:455`、`:573` 兩處，限於預設篩選變更造成的前提失效、不得改變其他斷言語意。生命週期記在 progress `freeze.thaw`：W0～W3-RG `frozen`（照驗）→ W3-G0 派發前 `--thaw` → W3-G0C 通過後 `--refreeze`（新 sha1），之後任何人不得再動。

**凍結 B（軟凍結，11 檔）**：`tests/Integration/TestCase.php`、`tests/bootstrap.php`、`phpunit.xml.dist`、`tests/e2e/playwright.config.ts`、`global-setup.ts`、`global-teardown.ts`、`helpers/{admin-page,api-client,frontend-setup,lc-bypass,wc-checkout}.ts`。只允許新增函式且需申報；改既有行為 → STOP 問用戶。

**判讀**：主判 sha1（抓得到「被重寫」），輔判 `git diff e520fd7e`（分得出「內容是否真的變了」；repo `core.autocrlf=true`，working tree 為 CRLF）。完整判讀表見 §3.2 CMD-FREEZE。

---

## 附錄 B：prompt 組裝器（CMD-PROMPT 取出此區塊）

<!-- BEGIN TOOL:ASSEMBLE -->
```js
// assemble.mjs：第二波派發 prompt 組裝器。只讀兩份藍圖與進度檔，輸出 $LOG/prompt-<CARD>.txt
// 用法：PC=<專案根> node assemble.mjs --check | <CARD> <輸出目錄>
import fs from 'node:fs'
import path from 'node:path'

const [, , card, outDir] = process.argv
const PC = process.env.PC
if (!PC) { console.error('需要環境變數 PC（專案根目錄）'); process.exit(1) }
const DIR = path.join(PC, 'specs/plan/issue-131-assignment-grading')
const BP1 = path.join(DIR, 'tdd-blueprint.md')
const BP2 = path.join(DIR, 'wave2-tdd-blueprint.md')
const PROG = path.join(DIR, '.progress-wave2.json')

// 藍圖一共用區塊：[起行, 迄行, 開頭錨點, 結尾錨點]
const W1 = {
	'S-CONTEXT': [468, 487, '【專案與分支】', '- PHPUnit 9 對 incomplete'],
	'S-RED-PHP': [493, 535, '【你的角色】紅燈測試作者', '- git status --short --untracked-files=all（只能出現測試檔變更）'],
	'S-RED-E2E': [541, 574, '【你的角色】E2E 紅燈測試作者', '- git status --short --untracked-files=all'],
	'S-GREEN-PHP': [580, 619, '【你的角色】WordPress / PHP 8 後端工程師', '- git status --short --untracked-files=all'],
	'S-REFACTOR-PHP': [625, 644, '【你的角色】同階段的 PHP 工程師', '【回報】S-REPORT-GREEN 格式'],
	'S-GREEN-REACT': [650, 685, '【你的角色】React 18', '- git status --short --untracked-files=all'],
	'S-REPORT-RED': [691, 698, '【回報格式（缺一段就會被退回）】', '6. 疑義'],
	'S-REPORT-GREEN': [704, 709, '【回報格式】', '5. 未解問題'],
}

const l1 = fs.readFileSync(BP1, 'utf8').split(/\r?\n/)
for (const [k, [a, b, head, tail]] of Object.entries(W1)) {
	if (!(l1[a - 1] || '').startsWith(head) || !(l1[b - 1] || '').startsWith(tail)) {
		console.error(`ANCHOR_FAIL ${k}: L${a}「${(l1[a - 1] || '').slice(0, 30)}」 L${b}「${(l1[b - 1] || '').slice(0, 30)}」`)
		process.exit(2)
	}
}
if ('--check' === card) { console.log('ANCHORS_OK'); process.exit(0) }
if (!card || !outDir) { console.error('用法：node assemble.mjs <CARD> <輸出目錄>'); process.exit(1) }

const l2 = fs.readFileSync(BP2, 'utf8').split(/\r?\n/)
const prog = JSON.parse(fs.readFileSync(PROG, 'utf8'))
const block = (name) => {
	const b = l2.indexOf(`<!-- BEGIN ${name} -->`)
	const e = l2.indexOf(`<!-- END ${name} -->`)
	if (b < 0 || e < 0 || e < b) throw new Error(`BLOCK_NOT_FOUND ${name}`)
	return l2.slice(b + 1, e).filter((x) => !x.startsWith('```'))
}
const fromJson = (p) => p.split('.').reduce((o, k) => (null == o ? o : o[k]), prog)
const expand = (lines, depth = 0) => lines.flatMap((line) => {
	const t = line.trim()
	let m = t.match(/^\{\{(S-[A-Z0-9-]+)\}\}$/)
	if (m && W1[m[1]]) { const [a, b] = W1[m[1]]; return l1.slice(a - 1, b) }
	m = t.match(/^\{\{(W2:[A-Z0-9-]+)\}\}$/)
	if (m) { if (depth > 3) throw new Error('區塊巢狀過深'); return expand(block(m[1]), depth + 1) }
	return [line.replace(/\{\{JSON:([\w.]+)\}\}/g, (all, p) => { const v = fromJson(p); return null == v ? all : String(v) })]
})

const out = expand(block(`CARD:${card}`))
const file = path.join(outDir, `prompt-${card}.txt`)
fs.writeFileSync(file, out.join('\n') + '\n', 'utf8')
const left = [...new Set(out.join('\n').match(/\{\{[^}]+\}\}/g) || [])]
console.log(`WROTE ${file} (${out.length} lines)`)
console.log(left.length ? 'MANUAL_PLACEHOLDERS:\n  ' + left.join('\n  ') : 'MANUAL_PLACEHOLDERS: none')
```
<!-- END TOOL:ASSEMBLE -->

---

## 附錄 C：PHPStan 範圍比對（CMD-PHPSTAN-W2 取出此區塊）

<!-- BEGIN TOOL:PHPSTAN -->
```js
// phpstan-scope.mjs：比對 PHPStan JSON（基線 vs 目前），輸出總數、作業範圍錯誤數、相對基線新增數
// 用法：node phpstan-scope.mjs <基線 json> <目前 json>
// 路徑分隔符用 String.fromCharCode(92)，避免經命令列傳遞時反斜線被吃掉（ADJ-W2-26）
import fs from 'node:fs'

const BS = String.fromCharCode(92)
const SCOPE = ['inc/classes/Resources/Assignment/', 'inc/templates/components/assignment/']

const load = (file) => {
	const json = JSON.parse(fs.readFileSync(file, 'utf8'))
	const rows = []
	for (const [path, value] of Object.entries(json.files || {})) {
		const rel = path.split(BS).join('/').replace(/^.*\/power-course\//, '')
		for (const m of value.messages) rows.push(`${rel} | ${m.message}`)
	}
	return { total: json.totals ? json.totals.file_errors : null, rows }
}
const count = (rows) => rows.reduce((map, key) => map.set(key, (map.get(key) || 0) + 1), new Map())

const base = load(process.argv[2])
const cur = load(process.argv[3])
const baseCount = count(base.rows)
let added = 0
for (const [key, n] of count(cur.rows)) {
	const diff = n - (baseCount.get(key) || 0)
	if (diff > 0) {
		added += diff
		console.log(`+${diff}  ${key}`)
	}
}
const scoped = cur.rows.filter((row) => SCOPE.some((prefix) => row.startsWith(prefix)))
scoped.forEach((row) => console.log(`SCOPE  ${row}`))
const normOk = cur.rows.every((row) => row.startsWith('inc/') || row.startsWith('plugin.php'))
console.log(`TOTAL=${cur.total} ROWS=${cur.rows.length} NORM_OK=${normOk} ASSIGNMENT_SCOPE=${scoped.length} NEW_VS_BASELINE=${added}`)
```
<!-- END TOOL:PHPSTAN -->

---

## 附錄 D：第一波測試凍結檢查（CMD-FREEZE 取出此區塊）

<!-- BEGIN TOOL:FREEZE -->
```js
// freeze.mjs：第一波測試凍結檢查（CMD-FREEZE）。在專案根目錄執行
// 用法：node freeze.mjs                 檢查
//       node freeze.mjs --thaw <檔案>    標記解凍（W3-G0 派發時，主窗口執行）
//       node freeze.mjs --refreeze <檔案> 以目前內容重新凍結（W3-G0C 通過時，主窗口執行）
//       node freeze.mjs --amend-b <檔案> <改了什麼> <理由>  接受凍結 B 某檔的變更並更新基準（只限主窗口核可的環境修復；記入 freeze.amendments）
import fs from 'node:fs'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

const PROG = process.env.PROG || 'specs/plan/issue-131-assignment-grading/.progress-wave2.json'
const p = JSON.parse(fs.readFileSync(PROG, 'utf8'))
const thaw = p.freeze.thaw || []
const sha1 = (file) => crypto.createHash('sha1').update(fs.readFileSync(file)).digest('hex')
const parse = (line) => { const i = line.indexOf(' *'); return { hash: line.slice(0, i), file: line.slice(i + 2) } }
const save = () => fs.writeFileSync(PROG, JSON.stringify(p, null, '\t') + '\n', 'utf8')

const [, , action, target] = process.argv
if ('--thaw' === action || '--refreeze' === action) {
	const t = thaw.find((x) => x.file === target)
	if (!t) { console.error(`NOT_IN_THAW_LIST ${target}`); process.exit(1) }
	if ('--thaw' === action) {
		t.state = 'thawed'
		t.thawed_at = new Date().toISOString()
	} else {
		if ('thawed' !== t.state) { console.error(`REFREEZE_REFUSED：${target} 目前狀態為 ${t.state}，不是 thawed`); process.exit(1) }
		t.state = 'refrozen'
		t.sha1_refrozen = `${sha1(target)} *${target}`
		t.refrozen_at = new Date().toISOString()
	}
	save()
	console.log(`${action.slice(2).toUpperCase()}_OK ${target} → ${t.state}${t.sha1_refrozen ? ' ' + t.sha1_refrozen : ''}`)
	process.exit(0)
}

if ('--amend-b' === action) {
	const change = process.argv[4]
	const reason = process.argv[5]
	if (!target || !change || !reason) { console.error('用法：node freeze.mjs --amend-b <檔案> <改了什麼> <理由>'); process.exit(1) }
	const idx = p.freeze.shared_infra.sha1.findIndex((line) => parse(line).file === target)
	if (idx < 0) { console.error(`NOT_IN_B ${target}`); process.exit(1) }
	const before = p.freeze.shared_infra.sha1[idx]
	p.freeze.shared_infra.sha1[idx] = `${sha1(target)} *${target}`
	p.freeze.amendments = p.freeze.amendments || []
	p.freeze.amendments.push({ at: new Date().toISOString(), by: '主窗口', file: target, old: parse(before).hash, new: parse(p.freeze.shared_infra.sha1[idx]).hash, change, reason })
	save()
	console.log(`AMEND_B_OK ${target}`)
	process.exit(0)
}

const state = (file) => (thaw.find((x) => x.file === file) || { state: 'frozen' }).state
const check = (label, lines) => {
	console.log(`[${label}]`)
	let fail = 0
	for (const line of lines) {
		const { hash, file } = parse(line)
		if (!fs.existsSync(file)) { fail++; console.log(`${file}: MISSING`); continue }
		const now = sha1(file)
		if (now !== hash) { fail++; console.log(`${file}: FAILED（凍結 ${hash.slice(0, 12)}，目前 ${now.slice(0, 12)}）`) }
	}
	return fail
}

const aLines = p.freeze.wave1_tests.sha1.filter((line) => 'frozen' === state(parse(line).file))
const thawed = thaw.filter((t) => 'thawed' === t.state).map((t) => t.file)
const refrozen = thaw.filter((t) => 'refrozen' === t.state).map((t) => t.sha1_refrozen)
const bLines = p.freeze.shared_infra.sha1

let fail = check('A 第一波測試（凍結中）', aLines)
fail += check('B 共用測試基礎', bLines)
fail += check('R 重新凍結', refrozen)
console.log('[內容差異 vs e520fd7e（A + B）]')
const amended = new Set((p.freeze.amendments || []).map((x) => x.file))
const files = [...aLines, ...bLines].map((line) => parse(line).file).filter((file) => !amended.has(file))
const diff = execFileSync('git', ['diff', '--name-only', p.freeze.anchor_commit, '--', ...files], { encoding: 'utf8' }).trim()
if (diff) console.log(diff)
console.log('FREEZE_CHECK_DONE')
console.log(`A=${aLines.length} B=${bLines.length} R=${refrozen.length} AMENDED=${amended.size} THAWED=${thawed.length}${thawed.length ? '（' + thawed.join('、') + '）' : ''} SHA_FAIL=${fail} CONTENT_DIFF=${diff ? diff.split('\n').length : 0}`)
```
<!-- END TOOL:FREEZE -->
