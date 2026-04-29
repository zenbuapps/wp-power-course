# Issue #217 實作計畫 — MCP 預設唯讀 + AI 設定 Tab

> Planner 產出（CI 自動）。執行人：tdd-coordinator。
> 規格來源：`specs/open-issue/217-mcp-permission-control-ai-tab.md`。

## 0. 計畫摘要

把 MCP 的「修改 / 刪除」權限**從伺服器環境變數搬到後台 UI**：

- 後端：`pc_mcp_settings` 加兩個 bool 欄位 `allow_update` / `allow_delete`，`AbstractTool::is_operation_allowed()` 改讀 Settings；環境變數 `ALLOW_UPDATE` / `ALLOW_DELETE` 完全廢棄。
- 前端：新增第 5 個 Tab「AI」，內含 `MCP 權限控制` 區塊，兩個 Switch + 教學連結。Tab 採與既有 MCP Tab 相同的「獨立 form / 自帶 Save 按鈕」模式（**不**走父層 `useSave`）。
- 保險：`ChapterToggleFinishTool` 顯式覆寫 `get_operation_type()` → `OP_UPDATE`。
- 測試：PHP Integration Test 覆蓋三條決策路徑、E2E 驗證 Tab 渲染。

## 1. 任務拆解（執行順序）

依依賴關係由底層往上：

1. **Settings 擴充**（純資料層） → unblocks 後續所有改動
2. **AbstractTool 改寫**（依賴 Settings）
3. **ChapterToggleFinishTool 覆寫**（行為保險）
4. **RestController 擴充欄位**（前端要 GET / POST 進出）
5. **前端 type 擴充 + AI Tab 元件 + Tabs 註冊**
6. **文件同步**（README / mcp.zh-TW.md / mcp.md）
7. **Integration Test + E2E**
8. **i18n build**（`pnpm run i18n:build` + `manual.json` 補翻譯）

> 第 1–3 步是 PHP 純後端，可同時開工。第 4 步要等 1。第 5 步可在第 4 步進行中平行展開（API 介面已固定）。

## 2. 檔案清單與具體修改內容

### 2.1 新建檔案

| # | 路徑 | 用途 |
|---|------|------|
| N1 | `js/src/pages/admin/Settings/Ai/index.tsx` | AI Tab 主元件 |
| N2 | `js/src/pages/admin/Settings/Ai/PermissionControl.tsx` | 「MCP 權限控制」區塊（兩個 Switch + 教學） |
| N3 | `tests/Integration/Mcp/AbstractToolPermissionTest.php` | 三條決策路徑的 PHPUnit 測試 |
| N4 | `tests/e2e/01-admin/ai-tab.spec.ts` | AI Tab 渲染 + Switch 顯示驗證 |

### 2.2 修改檔案

#### B1. `inc/classes/Api/Mcp/Settings.php`

- 將 `$defaults` 加上 `allow_update => false`, `allow_delete => false`
- `get_all()` 的 PHPDoc 回傳型別補上兩個新欄位
- 新增 4 個 public method：
  - `is_update_allowed(): bool`
  - `is_delete_allowed(): bool`
  - `set_update_allowed(bool $allowed): bool`
  - `set_delete_allowed(bool $allowed): bool`
- 注意：`update_option` 在「值未變更」時會回 `false`，沿用 `set_server_enabled()` 既有寫法 `(bool) update_option(...)`。

#### B2. `inc/classes/Api/Mcp/AbstractTool.php`

- **第 19 行**：更新 class docblock，把「環境變數 ALLOW_UPDATE / ALLOW_DELETE 控制」改為「`pc_mcp_settings` 中的 `allow_update` / `allow_delete` 控制」。
- **第 123–139 行**：改寫 `is_operation_allowed()`，移除 `getenv()`，改讀 `Settings`：
  ```php
  final protected function is_operation_allowed(): bool {
      $op = $this->get_operation_type();
      if ( self::OP_READ === $op ) {
          return true;
      }
      $settings = new Settings();
      if ( self::OP_UPDATE === $op ) {
          return $settings->is_update_allowed();
      }
      if ( self::OP_DELETE === $op ) {
          return $settings->is_delete_allowed();
      }
      return false;
  }
  ```
- **第 188–200 行**：把 `$env_key` 與訊息中的「environment variable」字串改為人類可讀的 UI 指引：
  ```text
  Operation "%2$s" is disabled for MCP tool "%1$s". Please enable "Allow update" or "Allow delete" in WordPress Admin → Power Course → Settings → AI.
  ```
  保留 `error code = 'mcp_operation_not_allowed'` 與 `status = 403` 不變（外部呼叫者依賴 error code）。

#### B3. `inc/classes/Api/Mcp/Tools/Chapter/ChapterToggleFinishTool.php`

- 在 `get_category()` 之後新增 `get_operation_type()` 覆寫，**回傳 `self::OP_UPDATE`**：
  ```php
  /**
   * 顯式覆寫為 OP_UPDATE：toggle 會寫入學員進度，不應在唯讀模式被允許
   * （預設規則靠關鍵字推導，但 toggle_finish 仍應明確覆寫保險）
   */
  public function get_operation_type(): string {
      return self::OP_UPDATE;
  }
  ```
  > 預設規則檢查 `_toggle_` 雖**會**落到 OP_UPDATE（既有預設不命中 read/delete 模式），但 Issue 要求「明確覆寫」以防未來改規則。

#### B4. `inc/classes/Api/Mcp/RestController.php`

- **第 89–125 行 `post_mcp_settings_callback`**：在 `enabled_categories` 之後加入兩段：
  ```php
  if ( array_key_exists( 'allow_update', $params ) ) {
      $settings->set_update_allowed( (bool) $params['allow_update'] );
  }
  if ( array_key_exists( 'allow_delete', $params ) ) {
      $settings->set_delete_allowed( (bool) $params['allow_delete'] );
  }
  ```
- **第 388–397 行 `get_settings_payload()`**：加入兩個欄位 + 更新型別：
  ```php
  return [
      'enabled'            => $settings->is_server_enabled(),
      'enabled_categories' => $settings->get_enabled_categories(),
      'rate_limit'         => $settings->get_rate_limit(),
      'allow_update'       => $settings->is_update_allowed(),
      'allow_delete'       => $settings->is_delete_allowed(),
  ];
  ```
  並更新 `@return` 型別。
- 第 80–88 行的 docblock「可接受欄位」清單補上 `allow_update`, `allow_delete`。

#### B5. `js/src/types/mcp.ts`

- `TMcpSettings` 加入：
  ```ts
  /** 允許 AI 修改資料 */
  allow_update?: boolean
  /** 允許 AI 刪除資料 */
  allow_delete?: boolean
  ```
  欄位用 `?:` 而非必填，避免 fallback 物件相容性破壞既有 `useMcpSettings` 中 `?? { enabled: false, enabled_categories: [] }`。讀取端用 `?? false`。

#### B6. `js/src/pages/admin/Settings/Mcp/hooks/useMcpSettings.tsx`

- `useMcpSettings()` 的 fallback 物件補 `allow_update: false, allow_delete: false`：
  ```ts
  const settings: TMcpSettings = queryResult.data?.data?.data ?? {
      enabled: false,
      enabled_categories: [],
      allow_update: false,
      allow_delete: false,
  }
  ```
- `useSaveMcpSettings()` **不需改動**：`mutate` 直接送 `values`，型別已涵蓋。

#### B7. `js/src/pages/admin/Settings/index.tsx`

- 用 `lazy` 載入 `Ai`，比照 `McpTab` 寫法：
  ```tsx
  const AiTab = lazy(() => import('./Ai'))
  const AiTabLoader = () => (
      <Suspense fallback={<div className="flex justify-center py-16"><Spin /></div>}>
          <AiTab />
      </Suspense>
  )
  ```
- `getItems()` 在 `mcp` 後追加第 5 項：
  ```tsx
  {
      key: 'ai',
      label: __('AI', 'power-course'),
      children: <AiTabLoader />,
  },
  ```

#### B8. `js/src/pages/admin/Settings/Ai/index.tsx`（新建）

- 採用與 `Mcp/index.tsx` 相同模式：**獨立 state + 自帶 Save 按鈕**，不依賴外層 `Form`。
- 使用既有 `useMcpSettings` / `useSaveMcpSettings` hooks（兩個 Tab 共用同一個 `pc_mcp_settings` option）。
- 結構：
  ```tsx
  const Ai = () => {
      const { settings, isLoading, isFetching, refetch } = useMcpSettings()
      const { save, isLoading: isSaving } = useSaveMcpSettings()

      const [allowUpdate, setAllowUpdate] = useState(false)
      const [allowDelete, setAllowDelete] = useState(false)
      const [isDirty, setIsDirty] = useState(false)

      useEffect(() => {
          if (isFetching) return
          setAllowUpdate(settings.allow_update ?? false)
          setAllowDelete(settings.allow_delete ?? false)
          setIsDirty(false)
      }, [isFetching, settings.allow_update, settings.allow_delete])

      const handleSave = useCallback(() => {
          // 注意：必須帶上既有 enabled / enabled_categories，否則會被覆寫掉
          save({
              enabled: settings.enabled,
              enabled_categories: settings.enabled_categories,
              ...(typeof settings.rate_limit === 'number' ? { rate_limit: settings.rate_limit } : {}),
              allow_update: allowUpdate,
              allow_delete: allowDelete,
          }, () => { setIsDirty(false); refetch() })
      }, [allowUpdate, allowDelete, settings, save, refetch])

      // ... loading / render
  }
  ```
  > **重要陷阱**：`POST /mcp/settings` 會逐欄位 `set_xxx()`，但若前端只送 `allow_update`，後端不會動 `enabled`；不過為防止「使用者在 AI Tab 改了 allow_update 之後 enabled 在 GET 期間已被別處覆蓋」這種競態，前端把目前已知的 `enabled / enabled_categories` 一併送出最安全。

#### B9. `js/src/pages/admin/Settings/Ai/PermissionControl.tsx`（新建）

- 兩個 Ant Design `Switch` + 上方標題、下方說明 + 教學連結。版型如規格 §技術方案/前端/2。
- 教學連結：`<a href="https://github.com/zenbuapps/wp-power-course/blob/master/mcp.zh-TW.md" target="_blank" rel="noopener noreferrer">{__('How to use MCP →', 'power-course')}</a>`
- 文字一律走 `__('...', 'power-course')`，msgid 為英文。

#### B10. `mcp.zh-TW.md` / `mcp.md`

- 移除「環境變數權限控制」整節（line 90-110, 260-280, 310-340 區段）。
- 新增「在後台管理 AI 權限」章節，描述「設定 → AI → 允許修改 / 允許刪除」流程 + 截圖位置（暫留 TODO 待 PR 附圖）。
- Troubleshooting 表中 `403 "Operation not allowed"` 的解法改為「到 設定 → AI 開啟對應開關」。

### 2.3 不修改的檔案

- 既有 MCP Tab（`js/src/pages/admin/Settings/Mcp/`）：除了 `useMcpSettings.tsx` fallback object 補兩個欄位，其餘**完全不動**。Issue 確認後台沒有那麼多 Tab 也保留 MCP Tab。
- 41 個具體 tool class（除 `ChapterToggleFinishTool`）：**不動**。
- `ActivityLogger`：拒絕時不寫 log（Q5 決策 B）。

## 3. 測試策略

### 3.1 PHP Integration Test

#### 3.1.1 `tests/Integration/Mcp/SettingsTest.php`（修改既有）

新增三個 test：
- `test_allow_update_default_false()`
- `test_allow_delete_default_false()`
- `test_set_and_get_allow_update_delete()`

#### 3.1.2 `tests/Integration/Mcp/AbstractToolPermissionTest.php`（新建）

> 既有 `AbstractToolTest.php` 不測 `is_operation_allowed`。為避免污染既有 test class，獨立檔。

```php
class AbstractToolPermissionTest extends IntegrationTestCase {
    public function set_up(): void {
        parent::set_up();
        delete_option( Settings::OPTION_KEY );
        // 給 admin 通過 capability
        $admin_id = $this->factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user( $admin_id );
    }

    private function make_tool( string $op ): AbstractTool { /* anon class 覆寫 get_operation_type() 回傳指定 op */ }

    public function test_read_op_always_allowed(): void { /* 不論 settings 為何，OP_READ 必通過 */ }
    public function test_update_op_blocked_when_setting_false(): void { /* 預設 allow_update = false → run() 回 WP_Error */ }
    public function test_update_op_allowed_when_setting_true(): void { /* set_update_allowed(true) → run() 通過 */ }
    public function test_delete_op_blocked_when_setting_false(): void { /* 同上對 OP_DELETE */ }
    public function test_delete_op_allowed_when_setting_true(): void { /* 同上 */ }
    public function test_update_setting_does_not_affect_delete_op(): void { /* allow_update=true, allow_delete=false → OP_DELETE 仍被擋 */ }
    public function test_error_code_is_mcp_operation_not_allowed(): void { /* 拒絕時 error code 必須是 mcp_operation_not_allowed，外部呼叫者依賴 */ }
    public function test_error_message_mentions_settings_path(): void { /* 訊息必須含「Settings → AI」字樣，給站長指引 */ }
    public function test_environment_variables_are_ignored(): void {
        // putenv('ALLOW_UPDATE=1'); 但 settings 為 false → 應被擋
        // 防止 regression 把 getenv 加回來
    }
}
```

#### 3.1.3 `tests/Integration/Api/Mcp/Tools/Chapter/ChapterToggleFinishToolTest.php`（修改）

新增 test：
- `test_toggle_finish_classified_as_op_update()`：直接斷言 `(new ChapterToggleFinishTool())->get_operation_type() === AbstractTool::OP_UPDATE`
- `test_toggle_finish_blocked_when_allow_update_false()`：完整呼叫 `run()`，預設應被擋

#### 3.1.4 `tests/Integration/Api/Mcp/RestControllerTest.php`（修改）

- `test_get_settings_payload_includes_allow_update_delete()`
- `test_post_settings_persists_allow_update()`
- `test_post_settings_persists_allow_delete()`

### 3.2 E2E（Playwright）

#### `tests/e2e/01-admin/ai-tab.spec.ts`（新建）

```ts
test.describe('AI 設定 Tab', () => {
    test.use({ storageState: '.auth/admin.json' })

    test('AI Tab 出現在第 5 個位置且可點擊', async ({ page }) => {
        await navigateToAdmin(page, '/settings')
        await waitForFormLoaded(page)
        await clickTab(page, 'AI')
        await expect(page.locator('.ant-tabs-tabpane-active')).toBeVisible()
    })

    test('AI Tab 顯示兩個 Switch 預設關閉', async ({ page }) => {
        await navigateToAdmin(page, '/settings')
        await clickTab(page, 'AI')
        const switches = page.locator('.ant-tabs-tabpane-active .ant-switch')
        await expect(switches).toHaveCount(2)
        // 預設都未勾選
        await expect(switches.nth(0)).not.toHaveClass(/ant-switch-checked/)
        await expect(switches.nth(1)).not.toHaveClass(/ant-switch-checked/)
    })

    test('教學連結指向 mcp.zh-TW.md', async ({ page }) => {
        await navigateToAdmin(page, '/settings')
        await clickTab(page, 'AI')
        const link = page.getByRole('link', { name: /How to use MCP|如何使用 MCP/ })
        await expect(link).toHaveAttribute('href', /mcp\.zh-TW\.md/)
    })
})
```

> Smoke E2E。實際持久化 + run() 受阻的「黑箱」邏輯靠 PHPUnit 覆蓋，避免 E2E 拖慢 CI。

### 3.3 i18n 驗證

執行 `pnpm run i18n:build`，確認以下新字串進入 `.pot`：
- `'AI'`
- `'How to use MCP →'`
- `'Allow update'` / `'Allow delete'`
- `'Enable to let AI create / update courses, chapters, etc.'`（範例）
- `'Enable to let AI delete courses, chapters, reset progress, etc.'`（範例）
- `'MCP permission control'`

並把繁中翻譯加到 `scripts/i18n-translations/manual.json`。

### 3.4 lint / static check

- `composer run phpstan`（level 9）：`Settings::is_update_allowed` 等新方法必須通過
- `pnpm run lint:php`：phpcs / phpcbf
- `pnpm run lint:ts` / `pnpm run format`
- `pnpm run build`：TS 編譯通過

## 4. 風險評估與注意事項

### 4.1 環境變數移除的相容性風險

- **影響**：升級後環境變數不再生效。原本依賴 `ALLOW_UPDATE=1` 的站台會「靜默退回唯讀」。
- **緩解**：
  - Q3 決議「完全忽略」 → 不做向下相容
  - 在 `mcp.zh-TW.md` / `mcp.md` 升級提示明顯標註
  - **不**在程式碼中加 deprecation log（規格原本提到，但會誤導 PHPStan，且 release notes 已足夠）
- **驗證**：寫一個 test 明確檢查「即便 `putenv('ALLOW_UPDATE=1')` 設定，`Settings::allow_update = false` 仍會擋住」，防止未來 regression 把 getenv fallback 加回來。

### 4.2 兩個 Tab 共用同一個 option 的競態

- AI Tab 與 MCP Tab 都讀寫 `pc_mcp_settings`。若使用者：
  1. 在 MCP Tab 改 `enabled_categories` 但沒按 Save
  2. 切到 AI Tab 改 `allow_update`，按 AI 的 Save
  3. 切回 MCP Tab，發現「未存的 enabled_categories 還在」（其實沒被洗掉，因為各自有 form state）
- **後端行為**：`POST /mcp/settings` 是 PATCH 語意（只更新傳入欄位），所以兩個 Tab 各自 Save 不會互相洗掉欄位。OK。
- **前端 AI Tab Save** 仍應一併送 `enabled / enabled_categories`（B8 已說明），避免未來把 RestController 改成 PUT 全替換時破裂。

### 4.3 `useMcpSettings` 是否要改成回 `allow_update` 必填

- 不要。改成必填會讓 fallback object 必須帶兩欄，breaking change 風險高且沒實質好處。`?: boolean` + 讀取端 `?? false` 即可。

### 4.4 `chapter_toggle_finish` 顯式覆寫的測試
- `make-pot` 不會掃 PHP 屬性，純 method 覆寫，無 i18n 影響。
- 既有 `ChapterToggleFinishToolTest` 跑得過代表 OP_UPDATE 預設行為原本也合理；新增的「顯式覆寫」test 是 belt-and-suspenders。

### 4.5 i18n 規範

- 既有 MCP Tab 內**有大量繁中 hardcoded 字串**（如「啟用 MCP Server」、「儲存 MCP 設定」），**不在本 PR scope**。本 PR 新增的所有 user-facing 字串必須用英文 msgid + `__('...', 'power-course')`，並把翻譯加到 `manual.json`。
- AI Tab 內若引用既有 hardcoded 中文字串組件（如 `<Heading>`），保持原樣，不額外翻譯，避免 PR 範圍擴散。

### 4.6 PHPStan level 9

- `Settings::get_all()` 的 PHPDoc 回傳型別 `array{enabled: bool, ...}` 必須加上兩個新欄位，否則 `is_update_allowed()` 內 `(bool) $settings['allow_update']` 會 PHPStan error。
- 同理 RestController `get_settings_payload()` 的 `@return` 型別。

### 4.7 既有 SettingsTest.php 的歷史不一致

`test_empty_categories_returns_false_for_any` 與 `is_category_enabled` 程式碼註釋「空 = 全部啟用」相矛盾——這是**既有 bug，不在本 PR scope**。tdd-coordinator 應只新增 test，不修改既有。

## 5. 驗收檢查（給 tdd-coordinator 對照規格 + 計畫）

- [ ] 設定頁出現第 5 個 Tab「AI」，順序為一般 / 外觀 / 自動授權 / MCP / **AI**
- [ ] AI Tab 內顯示「MCP 權限控制」單一區塊，含「允許修改」+「允許刪除」兩個 Switch
- [ ] 全新安裝兩個開關預設為 `false`
- [ ] 升級安裝時即使原 `ALLOW_UPDATE=1` 環境變數存在，兩開關仍為 `false`（PHPUnit 覆蓋）
- [ ] 兩開關下方/旁邊有教學連結指向 `mcp.zh-TW.md`（E2E 覆蓋）
- [ ] 開關需按 Save 才寫入，重新整理後保留設定（Q4 決策 B）
- [ ] 開啟「允許修改」後，所有 `OP_UPDATE` 類 tool 可執行（含 `chapter_toggle_finish`）
- [ ] 關閉「允許修改」時，`OP_UPDATE` 類 tool 回傳 `mcp_operation_not_allowed`，訊息指向「設定 → AI」
- [ ] 開啟「允許刪除」後，所有 `OP_DELETE` 類 tool 可執行
- [ ] 關閉「允許刪除」時，`OP_DELETE` 類 tool 回傳 `mcp_operation_not_allowed`
- [ ] 讀取類 tool 不受兩開關影響（PHPUnit 覆蓋 `test_read_op_always_allowed`）
- [ ] 拒絕操作**不**寫入 ActivityLog
- [ ] 環境變數已從 `AbstractTool` 移除（grep `getenv\|ALLOW_UPDATE` 在 `inc/` 內無命中）
- [ ] PHPUnit 三條決策路徑全綠
- [ ] `pnpm run i18n:build` 後 `.pot / .po / .mo / .json` 有新字串並 commit
- [ ] `composer run phpstan` 通過 level 9
- [ ] `pnpm run lint:ts` / `pnpm run lint:php` 全綠
- [ ] `pnpm run test:e2e:admin -- ai-tab.spec.ts` 通過

## 6. 交付物（PR 內容）

1. PHP：`Settings.php` / `AbstractTool.php` / `ChapterToggleFinishTool.php` / `RestController.php`
2. React：`types/mcp.ts` / `Mcp/hooks/useMcpSettings.tsx` / `Settings/index.tsx` / 新建 `Ai/index.tsx` + `Ai/PermissionControl.tsx`
3. 文件：`mcp.zh-TW.md` / `mcp.md`
4. 測試：1 個新 PHP test class + 3 個既有 test 擴充 + 1 個新 E2E spec
5. i18n：`languages/power-course.pot` / `power-course-zh_TW.po` / `.mo` / `.json` + `scripts/i18n-translations/manual.json`
6. 規格：本 plan 檔（`specs/plans/217-mcp-permission-control-ai-tab.plan.md`）

## 7. 對下一棒（tdd-coordinator）的提醒

- TDD 順序建議：**B3 → B1 → 3.1.1 → B2 → 3.1.2 → 3.1.3 → B4 → 3.1.4 → B5/B6/B7/B8/B9 → 3.2 → B10 → i18n build**
- 先把 `chapter_toggle_finish` 的覆寫加上（B3 + 3.1.3 紅綠）讓既有測試先穩，避免 B2 改完後 ChapterToggleFinish 行為變動但沒測試保護
- B2 改完前先跑全套 PHPUnit，確認既有 41 個 tool test 沒誤判（既有 test 應該都有 `wp_set_current_user(admin)` + `getenv` 沒被設，所以原本 OP_UPDATE / OP_DELETE 類 test 應該本來就會走 `WP_Error`；如果有 test 假設 `getenv` 設定為 1 就要改）
- B8 寫前端時不要直接複製 MCP Tab 的所有 Card，只放一個 PermissionControl Card 即可
- E2E 跑前先 `pnpm run build` 確保前端 bundle 是最新
