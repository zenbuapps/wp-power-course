# Issue #232 實作計畫 — 撤銷課程權限後重新寄送開通信 + 允許重複寄送開關

> 規格來源：
> - `specs/clarify/2026-05-28-issue232-email-repeat-send.md`（最終決議 `Q1=B Q2=A Q3=B Q4=A Q5=A Q6=A Q7=B Q8=A`）
> - `specs/features/email/設定信件允許重複寄送.feature`
> - `specs/features/email/撤銷課程權限後重新寄送開通信.feature`

## 範圍模式：HOLD SCOPE（bug 修復為主）+ 小幅 EXPANSION（新增 switch）

- 主目標：修復「撤銷後重購收不到開通信」。
- 附帶：每個郵件模板新增 `allow_repeat_send` 開關（預設 true、缺值視為 true）。
- 預估影響檔案：**8 個**（後端 3 + 前端 2 + i18n 1 + 測試 2），遠低於 15 檔閾值，維持 HOLD SCOPE。

## 行為矩陣（最終決議）

| 情境 | allow_repeat_send=true（預設） | allow_repeat_send=false |
|------|------|------|
| 首次取得權限（無歷史） | 寄 | 寄 |
| 同一報名期間重複觸發（未撤銷，mark_as_sent=1） | 寄（無視 mark_as_sent） | **不寄** |
| 撤銷 → 重購（mark_as_sent 已重置 0、AS group pending 已清） | 寄 ✓ | 寄 ✓（因 mark_as_sent=0） |
| 單一事件重入、已有 pending action | 不重排（防爆信） | 不重排（防爆信） |

---

## 三個協同修法

| # | 修法 | 檔案 | 對應 Q |
|---|------|------|--------|
| 1 | 排程去重只看 `pending`/`in-progress` | `At::schedule_email()` | Q8=A |
| 2 | 寄送放行依 `allow_repeat_send` 開關 | `At::trigger_condition()` + `Email` 模型 | Q1=B / Q7=B |
| 3 | 撤銷時清掉 `course_granted` 的 AS group（pending）+ 既有 mark_as_sent 重置 | `Course\LifeCycle` | Q3=B / Q4=A / Q5=A |
| 4 | UI 開關 | `SendCondition/Condition.tsx` | Q6=A |

---

## 修改檔案清單與具體內容

### 後端 PHP

#### F1. `inc/classes/PowerEmail/Resources/Email/Email.php`（模型，新增開關屬性）

1. 新增 public 屬性（class 屬性區，約 line 43 後）：
   ```php
   /** @var bool 是否允許重複寄送（預設 true；缺值/'yes' → true，僅 'no' → false） */
   public bool $allow_repeat_send = true;
   ```
2. 在建構子內 **早期 return（現行 line 84-87 `if (!$condition_array ...) return;`）之前** 讀 meta（建議放在 line 77 `$this->date_modified` 之後）：
   ```php
   // 允許重複寄送：升級前既有模板無此 meta → 視為 true；僅明確 'no' → false
   $allow_repeat_send_meta  = \get_post_meta( (int) $this->id, 'allow_repeat_send', true );
   $this->allow_repeat_send = 'no' !== $allow_repeat_send_meta;
   ```
   > ⚠️ **必須在早期 return 之前**，否則無 `condition` 的模板讀不到值（雖然仍有預設 true，但 API 序列化與表單回填需要正確值）。
   > 此屬性為 public → 透過 `WP_REST_Response` 自動序列化，`GET /power-email/v1/emails/{id}` 會回傳 `allow_repeat_send: true|false`（boolean），供前端表單回填。**無需改 API**。

#### F2. `inc/classes/PowerEmail/Resources/Email/Trigger/At.php`（排程 + 寄送放行）

1. **修法 1** — `schedule_email()`（line 239-245）查詢加 status 限縮：
   ```php
   $scheduled_actions = \as_get_scheduled_actions(
       [
           'hook'   => $hook,
           'group'  => $group,
           'status' => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
       ],
       'ids'
   );
   ```
   `complete`/`failed`/`canceled` 歷史不再被視為「已排程」；仍擋同 group 的 pending/in-progress（防單一事件重入爆信）。

2. **修法 2** — `trigger_condition()`（line 91-96）依開關 gate `is_sent`：
   ```php
   $post_id = $chapter_id ? $chapter_id : $course_id;

   // allow_repeat_send=false 才檢查是否已寄；true 時無視 mark_as_sent
   if ( ! $email->allow_repeat_send && $email->is_sent( $post_id, $user_id ) ) {
       return false;
   }
   ```
   true：略過 `is_sent`（仍須通過 `condition->can_trigger()`）；false：維持「已寄過就不寄」。

#### F3. `inc/classes/Resources/Course/LifeCycle.php`（撤銷時清 AS group）

1. 新增 import（檔頭 use 區，line 14-15 附近，AtHelper / EmailRecord 已存在）：
   ```php
   use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
   use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;
   ```
2. 在 `__construct()`（line 70-71 附近）掛新 method，priority 30（接在現有 `save_meta_remove_student`=10、`update_email_mark_as_sent`=20 之後）：
   ```php
   \add_action(self::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, [ __CLASS__, 'clear_course_granted_scheduled_actions' ], 30, 2);
   ```
3. 新增 method（與 `update_email_mark_as_sent` 同責任群組）：
   ```php
   /**
    * 撤銷學員後，清掉 course_granted 對應的 Action Scheduler group（pending）
    * 主要價值：取消尚未觸發的 send-later 延遲信；並與修法 1 形成雙保險
    *
    * @param int $user_id 用戶 id
    * @param int $course_id 課程 id
    */
   public static function clear_course_granted_scheduled_actions( int $user_id, int $course_id ): void {
       if ( ! \function_exists( 'as_unschedule_all_actions' ) ) {
           return;
       }

       /** @var array<int> $email_ids */
       $email_ids = \get_posts(
           [
               'post_type'      => EmailCPT::POST_TYPE,
               'posts_per_page' => -1,
               'post_status'    => 'publish',
               'fields'         => 'ids',
               'meta_key'       => 'trigger_at',
               'meta_value'     => AtHelper::COURSE_GRANTED,
           ]
       );

       $hook = ( new AtHelper(AtHelper::COURSE_GRANTED) )->hook; // power_email_send_course_granted

       foreach ( $email_ids as $email_id ) {
           $email = new EmailResource( (int) $email_id );
           // 與 schedule_email() 一致：course_granted 排程時 chapter_id=0，get_identifier 會 array_filter 掉
           $group = $email->get_identifier( [ $course_id ], $user_id );
           \as_unschedule_all_actions( $hook, [], $group );
       }
   }
   ```
   > 既有的 `update_email_mark_as_sent()`（line 502-514，一律把 `mark_as_sent`=0）**不變**（Q3=B）。

### 前端 TSX

#### F4. `js/src/components/emails/SendCondition/Condition.tsx`（新增開關）

1. import 補上 `Switch`（現行 antd import 無 Switch）。
2. 在「Configure send timing」分頁內、`Specific`/sending `Space.Compact` 之後新增一列：
   ```tsx
   <Item
     label={__('Allow repeat send', 'power-course')}
     name={['allow_repeat_send']}
     tooltip={__(
       'When disabled, each student receives this email at most once per enrollment period; re-granting access after revocation starts a new round.',
       'power-course',
     )}
     initialValue={true}
     getValueProps={(value) => ({ checked: value !== 'no' && value !== false })}
     normalize={(checked) => (checked ? 'yes' : 'no')}
   >
     <Switch
       checkedChildren={__('Enable', 'power-course')}
       unCheckedChildren={__('Disable', 'power-course')}
     />
   </Item>
   ```
   - `getValueProps` 同時容許 API 回傳的 boolean（`true`/`false`）與 meta 字串（`'yes'`/`'no'`）：只有 `'no'` 或 `false` 視為關。
   - `normalize` 把 Switch 的 boolean 轉成 `'yes'`/`'no'` 存檔 → 經 `Api::separator()` → `WP::separator($body, 'post')` → 自動寫入 post_meta（與 `trigger_at` 同機制，**無需改 API**）。
   - `checkedChildren`/`unCheckedChildren` 沿用既有的 `Enable`/`Disable` msgid，避免新增多餘字串。
   - 命名為頂層欄位 `['allow_repeat_send']`（**不是** `['condition', ...]`），確保 separator 拆成獨立 post_meta `allow_repeat_send`。

> 備註：專案已有 `js/src/components/formItem/FiSwitch`（`getValueProps: value==='yes'`、`initialValue:false`），但其預設 false 且不處理 API boolean，故本案採上述明確寫法較穩；若 reviewer 偏好沿用 FiSwitch，需 override `initialValue`、`getValueProps` 兩個 prop。

#### F5. `js/src/pages/admin/Emails/types/index.ts`（型別）

- `TEmailRecord` 加：`allow_repeat_send?: boolean | 'yes' | 'no'`
- `TFormValues` 加：`allow_repeat_send?: 'yes' | 'no'`

### i18n

#### F6. `scripts/i18n-translations/manual.json`（+ 跑 pipeline）

新增 2 條（label + tooltip）：
```json
{ "msgid": "Allow repeat send", "msgstr_zh_TW": "允許重複寄送", "msgstr_ja": "繰り返し送信を許可", "context": "js/src/components/emails/SendCondition/Condition.tsx" },
{ "msgid": "When disabled, each student receives this email at most once per enrollment period; re-granting access after revocation starts a new round.", "msgstr_zh_TW": "關閉後，每位學員在同一報名期間內最多只會收到一次此信；撤銷權限後重新取得權限會視為新的一輪。", "msgstr_ja": "無効にすると、各受講者は同一の登録期間中にこのメールを最大1回のみ受信します。アクセス権を取り消した後に再付与すると、新しいラウンドとして扱われます。", "context": "js/src/components/emails/SendCondition/Condition.tsx" }
```
然後執行 `pnpm run i18n:build`，一起 commit `languages/power-course.pot` / `-zh_TW.po` / `.mo` / `.json`。**禁止手改 .po**。

---

## 實作順序（依賴）

```
1. F1 Email 模型 allow_repeat_send（後續 F2/F4 都依賴此屬性與 API 回傳）
2. F2 At.php 修法 1（schedule 去重）+ 修法 2（trigger 放行）  ← 依賴 F1 的屬性
3. F3 LifeCycle 撤銷清 AS group                              ← 與 F2 獨立，可並行
4. F5 前端型別 → F4 前端 Switch UI                          ← 依賴 F1 的 API 回傳
5. F6 i18n manual.json + pnpm run i18n:build                ← 依賴 F4 的新 msgid
6. 測試（T1/T2）可在 F1-F3 完成後即撰寫（TDD：先紅後綠者見下）
```

---

## 測試策略

### PHP Integration（PHPUnit，主力）

沿用 `tests/Integration/TestCase.php` 與 `Email/*Test.php` 慣例（`factory()->post->create` 建 `pe_email`、`update_post_meta` 設 `trigger_at`/`condition`、`AtHelper`、`as_get_scheduled_actions`）。

**T1. `tests/Integration/Email/AllowRepeatSendTest.php`** — 對應 `設定信件允許重複寄送.feature`
- 缺 `allow_repeat_send` meta → `(new Email($id))->allow_repeat_send === true`
- meta `'yes'` → true；meta `'no'` → false
- （前置參數）由 `Api::post_emails_with_id_callback` 送 `allow_repeat_send:'yes'/'no'` → 驗證 post_meta 實際寫入 `'yes'`/`'no'`（驗證 separator → meta_input 路徑）

**T2. `tests/Integration/Email/RevokeRescheduleTest.php`** — 對應 `撤銷課程權限後重新寄送開通信.feature`
- 建 `pe_email`（trigger_at=course_granted、condition=`{trigger_condition:'each', course_ids:[$course], sending:{type:'send_now'}}`）。
- **修法 1**：
  - 先塞一筆 `complete` action（同 group）→ 呼叫 `At::instance()->schedule_course_granted_email($user,$course,0)` → assert 該 group 出現 1 筆 `pending`（`as_get_scheduled_actions(['hook'=>$hook,'group'=>$group,'status'=>'pending'])` 非空）。
  - 先塞一筆 `pending` action → 再排程 → assert 不新增重複（pending 仍只有 1 筆）。
- **修法 2**（直接呼叫 `At::instance()->trigger_condition($can=true,$email,$user,$course,0)`）：
  - true + record `mark_as_sent=1` → 回 `true`
  - false + `mark_as_sent=1` → 回 `false`
  - false + `mark_as_sent=0` → 回 `true`
- **修法 3**：
  - `do_action(LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $user, $course)` → assert `pc_email_records` 中 course_granted 該筆 `mark_as_sent=0`（回歸既有行為）。
  - 先排一筆 send-later **pending** 延遲 action（group 對應 email/user/course）→ 撤銷 → assert 該 group 已無 pending（`as_next_scheduled_action`/status=pending 查詢為空）。
  - 另建 `chapter_finish` 模板與 record → 撤銷 → assert 其 `mark_as_sent` 仍為 1（只清 course_granted）。
- **端到端**：已收信（mark_as_sent=1 + 有 complete action）→ 撤銷 → 再 `schedule_course_granted_email` → assert 產生新 pending action（true 與 false 兩個案例都應產生）。

> `schedule_email()` 為 private，測試一律走 public 入口 `schedule_course_granted_email()`（或 `do_action(ADD_STUDENT_TO_COURSE_ACTION)`）。`At::instance()` 在 plugin bootstrap 已註冊 hook。

### 前端
- 無 unit test；以 `pnpm run lint:ts` + `pnpm run build`（TS strict 編譯）把關。
- 可選：playwright-cli 於 `local-turbo.powerhouse.tw` 手動驗證開關存在、可切換、儲存後回填正確（開→存→重整仍為開；關→存→重整仍為關）。

### i18n 驗收
- `pnpm run i18n:build` 後 `git diff languages/` 應含 2 條新 msgid，`.pot/.po/.mo/.json` 四檔皆 commit。

### 品質關卡
- `pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）、`composer run test`、`pnpm run lint:ts`、`pnpm run build` 全綠。

---

## 風險評估與注意事項

1. **🚩 GAP — `.feature` 措辭 vs `as_unschedule_all_actions` 行為（需 tdd-coordinator/reviewer 留意）**
   `撤銷課程權限後重新寄送開通信.feature` 寫「撤銷後 course_granted 的歷史 action 被清除（**含 complete**）」「該 group 下的**所有** action 應被清除」。但 `as_unschedule_all_actions()` 只會把 **pending** action 標記為 `canceled`，**不會刪除 `complete` 歷史列**。
   - 本 issue 的根因解法其實是**修法 1**（status 限縮），complete 不再卡住重排，故**不需要**物理刪除 complete 列。
   - 撤銷清理（修法 3）的真正價值是「取消尚未觸發的 send-later pending 延遲信」+ 雙保險（見 clarify line 132）。
   - **建議**：測試斷言改為「行為結果」——撤銷後 (a) 重排能成功產生 pending、(b) 既有 pending 延遲 action 被取消——**不**斷言 complete 列被物理刪除。若 PO 仍要求物理刪除 complete，需改用直接操作 `ActionScheduler::store()`（逐 status 查 id 後 cancel/delete）或 raw SQL，風險與成本較高，建議另案。

2. **group slug 對齊**：撤銷清理與排程都走同一個 `Email::get_identifier()`。當 `trigger_condition != 'each'` 時，`get_identifier` 會改用 `required_ids`（忽略傳入的 post_ids），兩端皆然 → slug 自然一致，無需特別處理。

3. **Email 模型早期 return**：`allow_repeat_send` 必須在 line 84 早期 return **之前**讀取/賦值，否則無 `condition` 的模板雖有預設 true 但 API 回傳值可能不符預期。

4. **型別（PHPStan level 9）**：屬性宣告 `bool`，`get_post_meta` 回 mixed，用 `'no' !== $meta` 比較產生 bool，安全；勿把 `allow_repeat_send` 加進 `$meta_keys`（該迴圈 cast 成 string，會破壞 bool 型別）。

5. **前端 boolean/字串雙來源**：API 回傳 boolean、表單 normalize 後為字串，`getValueProps` 已同時容錯（`!== 'no' && !== false`）。

6. **快取**：本案不直寫 `wp_posts`/`wp_postmeta`（走 `wp_update_post`/`get_post_meta`），無需額外 `clean_post_cache`。

7. **不在本 issue 範圍**（明確 defer）：退款 / 訂閱取消 / 訂閱到期等其他「失去權限」路徑；`course_launch` / `course_finish` / `chapter_*` 的撤銷清理（只處理 `course_granted` + 管理員手動撤銷）。

---

## 驗收標準（Definition of Done）

- [ ] `allow_repeat_send` 開關在郵件編輯頁可見、可切換、儲存後回填正確，預設為「開」。
- [ ] post_meta 實際儲存為 `'yes'`/`'no'`；舊模板（無 meta）解析為 true。
- [ ] `schedule_email()` 只擋 pending/in-progress；complete 歷史不再阻擋重排。
- [ ] allow_repeat_send=true 時無視 mark_as_sent；false 時依 mark_as_sent。
- [ ] 撤銷時 course_granted 的 `mark_as_sent`=0、pending 延遲 action 被取消；chapter_finish 等不受影響。
- [ ] 端到端：撤銷 → 重購 → 再次產生 pending action（true / false 皆可重寄）。
- [ ] PHP 整合測試（T1/T2）全綠；`lint:php` / `lint:ts` / `build` / `i18n:build` 全綠並 commit 四個語系檔。
