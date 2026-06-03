# Clarify Session 2026-05-28 — Issue #232

## Idea

### 標題：撤銷課程權限後重新購買，課程開通通知信不再寄出（Action Scheduler group 去重未清除）

當管理員手動撤銷某學員的課程權限後，同一位學員重新購買同一門課程完成下單時，「課程開通通知信」不會再次寄出（僅 debug log 一行 `already scheduled or sent, skip duplicate schedule`）。

### 根因（已驗證）

`At::schedule_email()` 用 `as_get_scheduled_actions()` 查詢時**未指定 status**，會查到所有狀態（含 `complete`）。第一次寄信後那筆 action 以 `complete` 永久留在 `wp_actionscheduler_actions`，掛在同一個 group slug 上。同一 user 對同一 course 再次觸發 `course_granted` 時，group slug 完全相同 → `$is_scheduled = true` → 跳過排程 → 信不再寄。

`LifeCycle::update_email_mark_as_sent()` 雖在撤銷時把 `pc_email_records.mark_as_sent` 重置為 0，但**沒有同步清掉 Action Scheduler 的歷史 action**，所以排程階段仍被擋。

### 現況掃描

| 觀察項目 | 內容 |
|---------|------|
| 排程入口 | `inc/classes/PowerEmail/Resources/Email/Trigger/At.php::schedule_email()`（status-agnostic 查詢是 bug 根因，行 239-261） |
| 寄送放行 | `At::trigger_condition()`（行 83-103）→ 呼叫 `Email::is_sent()` → 查 `pc_email_records.mark_as_sent = 1` |
| 是否已寄 | `Email::is_sent()`（`Email.php` 行 308-317），以 `get_identifier()` + `mark_as_sent='1'` 查 `EmailRecord` |
| 撤銷 hook | `LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION`（`power_course_after_remove_student_from_course`），目前只接 `save_meta_remove_student` + `update_email_mark_as_sent` |
| 撤銷重置 | `LifeCycle::update_email_mark_as_sent()`（行 502-514），目前只把 `trigger_at='course_granted'` 的 record `mark_as_sent` 設 0，**未清 AS group** |
| AS hook 命名 | `AtHelper::hook = "power_email_send_{slug}"` → `course_granted` 對應 `power_email_send_course_granted` |
| 信件 CPT | `pe_email`（`PowerEmail/Resources/Email/CPT.php`），form 欄位經 `Api.php::separator()` → `WP::separator($body_params, 'post')` 拆出 `meta_data` 自動寫入 post_meta |
| 信件 Model | `Email.php` 以 `$meta_keys` 與個別 `get_post_meta` 讀取 post_meta |
| 前端表單 | `js/src/components/emails/SendCondition/Condition.tsx`（「Configure send timing」分頁）；頂層欄位 name（如 `trigger_at`）即 post_meta key |

## Q&A

兩輪釐清，最終決議：**Q1=B Q2=A Q3=B Q4=A Q5=A Q6=A Q7=B Q8=A**

- **Q1 [情境] 「允許重複寄送=true」語意**：**B — 完全停用去重（搭配 Q8 保留最小防護）**
  - true → 寄送階段無視 `mark_as_sent`，只要觸發條件達成就寄
- **Q2 [情境] 舊模板（無此 meta）視為**：**A — 視為 true**
  - 讀不到 `allow_repeat_send` 時 fallback `true`，既有站台的 bug 一併修好
- **Q3 [工程] 撤銷時的清除是否受 switch 控制**：**B — 不論 switch 一律清除**
  - 撤銷時一律重置 `mark_as_sent=0` 並清 AS group，是否重寄交給寄送階段判斷
- **Q4 [工程] 撤銷時清哪些 trigger_at**：**A — 只清 `course_granted`**
  - 僅修復本 issue 實證案例；其他 trigger 不在本次範圍
- **Q5 [情境] 哪些失去權限情境觸發清除**：**A — 只處理「管理員手動撤銷」**
  - 沿用現有 `AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION` hook；退款 / 訂閱到期另議
- **Q6 [工程] switch 儲存與 UI**：**A — `allow_repeat_send` meta + SendCondition 的 Ant Design Switch**
  - 放「Configure send timing」分頁，值 `'yes'` / `'no'`
- **Q7 [情境] switch=false 在「撤銷→重購」後是否再寄**：**B — 照 Q3=B 字面**
  - 撤銷一律把 `mark_as_sent` 歸 0；switch=false 只防「同一報名期間內重複寄」，撤銷後重購因 `mark_as_sent` 已歸 0 → 仍會再寄一次
  - 即 **switch=false ≠ 終生只收一次**，而是「未撤銷時不重複寄」
- **Q8 [工程] switch=true 是否保留最小防護**：**A — 只擋 pending / in-progress**
  - `schedule_email()` 查詢限縮 `status = [pending, in-progress]`；`complete` 歷史不再阻擋（修好 bug），但單一事件重入（訂單狀態跳動 / retry）有 pending 時不重排，避免短時間爆信

## 最終行為矩陣

| 情境 | allow_repeat_send=true（預設） | allow_repeat_send=false |
|------|------|------|
| 首次取得權限（無歷史） | 寄 | 寄 |
| 同一報名期間重複觸發（未撤銷，mark_as_sent=1） | 寄（無視 mark_as_sent） | **不寄**（mark_as_sent=1） |
| 撤銷 → 重購（mark_as_sent 已被重置為 0、AS group 已清） | 寄 ✓ | 寄 ✓（因 mark_as_sent=0） |
| 單一事件重入，已有 pending action | 不重排（防爆信） | 不重排（防爆信） |

> 兩種開關在「撤銷→重購」後都會再寄（這是本 issue 要修的主目標）；差別只在「未撤銷的同一報名期間」是否容許重複寄。

## 實作方案摘要

### 1. 新增 `allow_repeat_send` 開關（後端 + 前端）

- **post_meta key**：`allow_repeat_send`，值 `'yes'` / `'no'`，預設與缺值皆視為 `'yes'`（true）
- **後端讀取**：`Email.php` 新增 `public bool $allow_repeat_send`（讀 `get_post_meta($id,'allow_repeat_send',true)`，空值 → `true`）；或提供 helper `is_allow_repeat_send(): bool`
- **後端寫入**：沿用 `Api.php::post_emails_with_id_callback()` 既有 meta_input 路徑（頂層 form 欄位自動入 post_meta），無需改 API
- **前端 UI**：`SendCondition/Condition.tsx`「Configure send timing」加一個 Ant Design `Switch`
  - `Form.Item name={['allow_repeat_send']}`，`valuePropName="checked"`，搭配 `getValueProps` / `normalize` 做 `'yes'|'no'` ↔ boolean 轉換（參考既有 `stringToBool` / antd-toolkit/wp 用法）
  - label：`Allow repeat send`（msgid 英文，i18n 規範）
  - tooltip：關閉後每位學員在同一報名期間內每封信最多寄一次；撤銷權限後重新取得權限會視為新的一輪

### 2. 修復排程去重 bug（Q8=A）— `At::schedule_email()`

`as_get_scheduled_actions()` 查詢加上 status 限縮：

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

- `complete` / `failed` / `canceled` 歷史不再被視為「已排程」→ 跨「撤銷→重購」週期可重新排程
- 仍擋「同一 group 已有 pending / in-progress」→ 防單一事件重入造成短時間爆信

### 3. 寄送放行依開關（Q1=B / Q7=B）— `At::trigger_condition()`

寄送階段判斷加入開關：

```php
// allow_repeat_send=true → 無視 mark_as_sent；false → 沿用 is_sent 判斷
if ( ! $email->allow_repeat_send ) {
    $post_id = $chapter_id ? $chapter_id : $course_id;
    if ( $email->is_sent($post_id, $user_id) ) {
        return false;
    }
}
```

- true：略過 `is_sent()` 檢查（仍須通過 `condition->can_trigger()`）
- false：維持現行「已寄過就不寄」

### 4. 撤銷時清理 AS group（方案 A，Q3=B / Q4=A / Q5=A）

於 `LifeCycle::update_email_mark_as_sent()`（或同 hook 新增方法）對所有 `trigger_at='course_granted'` 的 Power Email 模板，逐一以 `get_identifier([$course_id], $user_id)` 算出 group，清掉該 group 所有狀態的 action：

```php
$email_ids = \get_posts([
    'post_type'      => EmailCPT::POST_TYPE,
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'fields'         => 'ids',
    'meta_key'       => 'trigger_at',
    'meta_value'     => 'course_granted',
]);

$hook = ( new AtHelper(AtHelper::COURSE_GRANTED) )->hook; // power_email_send_course_granted
foreach ( $email_ids as $email_id ) {
    $email = new EmailResource((int) $email_id);
    $group = $email->get_identifier([ $course_id ], $user_id);
    \as_unschedule_all_actions( $hook, [], $group );
}
```

- 既有的 `mark_as_sent` 重置維持不變（一律重置，不論 switch — Q3=B）
- AS group 清除有兩重價值：(a) 與 Q8 雙保險；(b) 清掉尚未觸發的「send later」延遲 action，避免學員被撤銷後延遲信仍寄出
- 只處理 `course_granted`（Q4=A），只接 `AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION`（Q5=A）

> ⚠️ `get_identifier()` 對 `trigger_condition != 'each'` 會改用 `required_ids`；清除時的 group 推導需與 `schedule_email()` 排程時一致（皆走同一 `get_identifier`），確保 slug 完全對齊。

## i18n

- 新增 msgid（英文）：`Allow repeat send` 及其 tooltip 說明字串
- 繁中翻譯加入 `scripts/i18n-translations/manual.json`，跑 `pnpm run i18n:build`，一起 commit `.pot/.po/.mo/.json`

## 不在本 Issue 範圍

- 退款 / 訂閱取消 / 訂閱到期 / 跨年度重新報名等其他「失去權限」路徑（僅處理管理員手動撤銷）
- `course_launch` / `course_finish` / `chapter_enter` / `chapter_finish` 的撤銷清理（只處理 `course_granted`）

## 產出規格檔案

- `specs/features/email/設定信件允許重複寄送.feature`（新增）
- `specs/features/email/撤銷課程權限後重新寄送開通信.feature`（新增）
- `specs/clarify/2026-05-28-issue232-email-repeat-send.md`（本檔）
