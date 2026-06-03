# 實作計劃：學員「註冊於 / user_registered」時區轉換修正 (Issue #233)

## 概述

後台/前台學員列表的「註冊於」、學員明細、匯出 CSV，以及 MCP 工具輸出的 `user_registered`，目前**直接吐 `wp_users.user_registered` 原值（UTC）**，未轉成 WordPress 設定時區。當主機 PHP `date.timezone = UTC` 而 WP 設定 `Asia/Taipei` 時，顯示時間少 8 小時，與同畫面其他已轉時區的時間欄位（學習紀錄／訂單時間軸）互相矛盾，導致站長誤判時間錯亂（真實案例：`zizizimusic.com` 學員 #202 退費爭議）。

修法：**6 個輸出點一律經統一 helper 轉成 WP 設定時區**（內部用 `\get_date_from_gmt()`，輸出格式維持 `Y-m-d H:i:s`，僅換時區、不套站台日期格式），空值 / `0000-00-00` 原樣保留以避免 1970 epoch。

對應規格：

- `specs/features/student/學員註冊時間時區轉換.feature`
- `specs/clarify/2026-05-28-issue233-user-registered-timezone.md`

## 範圍模式：HOLD SCOPE

bug 修復，需求已由 clarifier 收斂為 7 個明確決策（**Q1-Q6 = `B A A A A A`、Q7 = `A`**），`.feature` 與 clarify 紀錄均已寫入 `./specs`。預估影響 **1 個新工具檔 + 6 個生產檔編輯 + 1 個測試新檔**（無 i18n / 無前端改動）。範圍不再擴張。

> ⚠️ 前端「學員列表」是把 `user_registered` 當**純字串**直接渲染（`<p>{user_registered}</p>`），JS 端不做時區 parse → 後端改成站台時區後**不會** double-shift，**前端無需改動**。

## 需求重述

1. 6 個輸出點的 `user_registered` 一律輸出 WP 設定時區（非 DB 的 UTC 原值）。
2. 輸出格式維持 `Y-m-d H:i:s`（**不**套用站台「日期/時間格式」設定）。— Q2=A
3. 空字串 / `0000-00-00 00:00:00` 等異常值原樣保留，**不得**變成 `1970-01-01 08:00:00`。— Q4=A
4. CSV 欄位標頭維持原樣（`Student registration date`），不額外加註時區。— Q3=A
5. MCP 學員列表 / 匯出工具同步修正（同 bug 不同出口，一次清乾淨）。— Q1=B
6. 前台/後台學員列表 API（資料源為 Powerhouse `User::to_array('list')`，vendor）於 PowerCourse 端**後處理**轉時區，且**不得 double-shift**（僅 +8 而非 +16）。— Q7=A
7. 補 PHPUnit 測試鎖住行為，防回歸。— Q5=A
8. changelog 提醒既有用戶：顯示值從 UTC 改為站台時區，DB 內容不變。— Q6=A

## 已確認的設計決策

| 編號 | 問題 | 決策 |
| --- | --- | --- |
| Q1 | 修正範圍是否含 MCP 工具 | **B** — 連 `StudentListTool` / `StudentExportCsvTool` 一起修 |
| Q2 | 輸出格式 | **A** — 維持 `Y-m-d H:i:s`，只換時區不換格式 |
| Q3 | CSV 欄位標頭 | **A** — 不改標頭，只修時區值 |
| Q4 | 空值 / 異常處理 | **A** — 有值才轉，空值原樣輸出空字串 |
| Q5 | 測試 | **A** — 補 PHPUnit 覆蓋各輸出點 |
| Q6 | changelog | **A** — 一起加 changelog 提醒既有用戶 |
| Q7 | 前台列表（Powerhouse 資料源） | **A** — 於 `get_students_callback` 取得 `to_array('list')` 結果後額外轉時區，並照原計畫修其餘 5 點 |

## 已知風險（來自程式碼研究）

| 風險 | 嚴重度 | 緩解措施 |
| --- | --- | --- |
| **Double-shift（+16 小時）**：Q7=A 假設 Powerhouse `to_array('list')` 回傳 UTC 原值。若 Powerhouse 其實已自行轉時區，後處理再轉一次 → +16h（`2026-05-18 05:07:33`） | 高 | issue 真實案例已交叉驗證：`zizizimusic.com` 後台**學員列表**顯示 #202「13:07:33」即 UTC 原值，證明 Powerhouse **未**轉 → Q7=A 安全。`.feature` 已加「不得 double-shift」防回歸 Example（後處理結果須為 `21:07:33`、不得為 `05:07:33`），實作須加對應測試鎖住此假設。**禁止修改 vendor**，僅在 PowerCourse 端後處理。 |
| 空值 / `0000-00-00` 丟給 `get_date_from_gmt('')` → `1970-01-01 08:00:00` 誤導值 | 高 | helper 前置守則：`'' === $gmt` 或 `str_starts_with($gmt, '0000-00-00')` 直接原樣回傳，不進 `get_date_from_gmt()`。測試覆蓋空字串與 `0000-00-00`。 |
| `get_date_from_gmt()` 預設輸出 `Y-m-d H:i:s`，但若日後有人傳第二參數 `$format` 會破壞 Q2=A「格式不變」 | 中 | helper 固定只呼叫 `\get_date_from_gmt( $gmt )`（單參數，採預設 `Y-m-d H:i:s`）；`.feature` 已有「即使後台日期格式設為 `F j, Y` 仍輸出 `Y-m-d H:i:s`」的 Example，測試須鎖住此行為。 |
| `to_array('list')` 回傳的 key 名稱（`user_registered` 是否存在、是否另有 `user_registered_human`）在 CI 環境無 vendor 原始碼可確認 | 中 | 後處理用 `isset($row['user_registered'])` 守衛再轉；**不得**碰 `user_registered_human`（相對時間由 `human_time_diff` 算，TZ offset 對相減無影響）。實作時於本地 `local-turbo` 以 `var_dump` 確認 key 後再下手。 |
| `get_date_from_gmt()` 依賴 `wp_timezone()`（讀 `timezone_string` / `gmt_offset` option），與 PHP `date.timezone` 無關 | 低（正向） | 測試以 `update_option('timezone_string', 'Asia/Taipei')` 設定即可，結果與 server PHP tz 無關 → 測試穩定可重現。 |
| 6 個輸出點分散在 3 個 namespace（`Resources\Student\Service`、`Resources\Student\Core`、`Api\Mcp\Tools\Student`） | 低 | 統一 helper 放在通用 `J7\PowerCourse\Utils` namespace（非 Student 專屬），三處皆可 `use` 匯入，避免重複邏輯。 |
| PHPStan level 9 / PHPCS（WP 標準） | 低 | helper 用 `?string` 參數、回傳 `string`；6 處呼叫一律 `(string)` 強轉輸入；跑 `pnpm run lint:php` 過關。 |

## 架構變更

### 新增：統一時區轉換 helper（1 個新檔）

| # | 檔案 | 變更類型 | 說明 |
| --- | --- | --- | --- |
| 0 | `inc/classes/Utils/Datetime.php` | **新增** | 新 `abstract class Datetime`，namespace `J7\PowerCourse\Utils`（與既有 `Utils\User` / `Utils\Course` 同層，通用、可跨模組 reuse）。提供 `to_site_timezone()` 靜態方法。 |

helper 內容（規格摘要，實作時照此語意）：

```php
<?php
declare(strict_types=1);

namespace J7\PowerCourse\Utils;

/** 時間相關工具 */
abstract class Datetime {

	/**
	 * 把 GMT/UTC 時間字串轉成 WordPress 設定時區（格式維持 Y-m-d H:i:s）
	 *
	 * 空字串 / 0000-00-00 等異常值原樣回傳，避免 get_date_from_gmt('') → 1970-01-01 08:00:00。
	 *
	 * @param string|null $gmt DB 內的 UTC 時間字串（如 wp_users.user_registered）。
	 * @return string 站台時區字串；空 / 異常值原樣回傳。
	 */
	public static function to_site_timezone( ?string $gmt ): string {
		$gmt = (string) $gmt;
		if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			return $gmt;
		}
		return (string) \get_date_from_gmt( $gmt ); // 預設輸出 'Y-m-d H:i:s'
	}
}
```

### 修改：6 個輸出點（一律改走 helper）

| # | 檔案 | 行號（現況） | 變更摘要 |
| --- | --- | --- | --- |
| 1 | `inc/classes/Resources/Student/Core/Api.php` | `get_students_callback` 迴圈 :287-290 | **新增後處理**（Q7=A）：`to_array('list')` 結果若 `isset($row['user_registered'])` 則套 helper 後再 push。新增 `use J7\PowerCourse\Utils\Datetime;`。**不得**碰 `user_registered_human`。 |
| 2 | `inc/classes/Resources/Student/Service/Query.php` | :368 | `'user_registered' => Datetime::to_site_timezone( $user->user_registered ),`，新增 import。（消費者：MCP `StudentGetTool`） |
| 3 | `inc/classes/Resources/Student/Service/ExportCSV.php` | :122 | 同上改 helper，新增 import。 |
| 4 | `inc/classes/Resources/Student/Service/ExportAllCSV.php` | :161 | 同上改 helper，新增 import。 |
| 5 | `inc/classes/Api/Mcp/Tools/Student/StudentListTool.php` | :180 | `'user_registered' => Datetime::to_site_timezone( (string) $user->user_registered ),`，新增 import。 |
| 6 | `inc/classes/Api/Mcp/Tools/Student/StudentExportCsvTool.php` | :193 | 同上改 helper，新增 import。 |

> 輸出點 1（列表 API）的範例後處理片段：
>
> ```php
> use J7\PowerCourse\Utils\Datetime; // 檔案頂部
>
> $formatted_users = [];
> foreach ( $user_ids as $user_id ) {
>     $formatted_user = User::instance( (int) $user_id )->to_array( 'list', $meta_keys );
>     if ( isset( $formatted_user['user_registered'] ) ) {
>         $formatted_user['user_registered'] = Datetime::to_site_timezone( (string) $formatted_user['user_registered'] );
>     }
>     $formatted_users[] = $formatted_user;
> }
> ```

### 不需改動

- 前端 `js/src/`（學員列表把 `user_registered` 當純字串渲染，後端轉好即正確）。
- CSV 欄位標頭（Q3=A）。
- `user_registered_human`（相對時間，TZ offset 對相減無影響）。
- 公告 API（用自己的 `Announcement\Service\Query`，不經 `Student\Service\Query::get`）。
- Powerhouse vendor（禁止修改 vendor）。

## 測試策略（Q5=A — PHPUnit Integration）

### 新增測試檔：`tests/Integration/Student/UserRegisteredTimezoneTest.php`

對應 `.feature`，以共用 Background fixture 覆蓋全部 6 輸出點 + helper + 邊界：

**Background fixture（每個 test 前）**
- `update_option( 'timezone_string', 'Asia/Taipei' )`（gmt_offset = 8）。
- 建 3 個用戶，建立後以 `$wpdb->update( $wpdb->users, [ 'user_registered' => '<UTC>' ], [ 'ID' => $id ] )` + `clean_user_cache( $id )` 寫入指定 UTC 原值：
  - Alice：`2026-05-17 13:07:33`（同日 → 站台 `2026-05-17 21:07:33`）
  - Bob：`2026-05-17 18:30:00`（跨日 → 站台 `2026-05-18 02:30:00`）
- 建 1 個課程（`_is_course=yes`, publish），Alice / Bob 開通課程（expire 0）。
- `configure_dependencies()` 空實作（沿用 `CourseStudentsFilterTest` 模式）。

**測試案例（對應 .feature 各 Rule / Example）**

| # | 測試方法 | 驗證點 | 對應 Example |
| --- | --- | --- | --- |
| T1 | `test_helper_同日時段轉台灣時間` | `Datetime::to_site_timezone('2026-05-17 13:07:33')` === `'2026-05-17 21:07:33'` | 共通規則 同日 |
| T2 | `test_helper_跨日時段轉隔日` | `...('2026-05-17 18:30:00')` === `'2026-05-18 02:30:00'` | 共通規則 跨日 |
| T3 | `test_helper_格式不套站台日期格式` | 設 `date_format=F j, Y`、`time_format=g:i a` 後仍輸出 `'2026-05-17 21:07:33'` | 格式維持 Y-m-d H:i:s |
| T4 | `test_helper_空字串原樣回傳` | `...('')` === `''` 且 !== `'1970-01-01 08:00:00'` | 空值守則 |
| T5 | `test_helper_全零日期原樣保留` | `...('0000-00-00 00:00:00')` !== `'1970-01-01 08:00:00'` | 0000-00-00 守則 |
| T6 | `test_列表API_user_registered_為站台時區` | `Api::instance()->get_students_callback($req)` 回應中 Alice=`21:07:33`、Bob=`02:30:00` | 列表 API Rule |
| T7 | `test_列表API_不得double_shift` | 列表回應 Alice 為 `21:07:33`，**不為** `2026-05-18 05:07:33` | double-shift 護欄 |
| T8 | `test_單一學員Query_get_為站台時區` | `Query::get($alice)['user_registered']` === `21:07:33` | Query::get Rule |
| T9 | `test_單一課程匯出CSV_為站台時區` | `ExportCSV` rows 中 Alice/Bob 為站台時區 | ExportCSV Rule |
| T10 | `test_全域匯出CSV_為站台時區` | `ExportAllCSV` rows 中 Alice 為 `21:07:33` | ExportAllCSV Rule |
| T11 | `test_MCP_StudentListTool_為站台時區` | `(new StudentListTool())->run([...])['students']` 中 Alice 為 `21:07:33` | StudentListTool Rule |
| T12 | `test_MCP_StudentExportCsvTool_為站台時區` | `StudentExportCsvTool` 匯出資料中 Alice 為 `21:07:33` | StudentExportCsvTool Rule |

> 設定 fixture 的關鍵：`get_date_from_gmt()` 讀 `wp_timezone()`（option），與 PHP `date.timezone` 無關，故只要 `update_option('timezone_string','Asia/Taipei')` 即可重現，測試穩定不受 CI server tz 影響。
>
> MCP 測試（T11/T12）若需 admin 權限，沿用 `tests/Integration/Mcp/IntegrationTestCase` 的 `create_admin_user()` / `enroll_user_to_course()`；可考慮把 T11/T12 拆到 `tests/Integration/Api/Mcp/Tools/Student/` 既有測試檔內新增 method，或於本檔內手動設定 admin。實作時擇一，避免重複 fixture。

### 不寫 E2E

時區轉換為純後端輸出邏輯，PHPUnit Integration 已能精準鎖定 6 個出口；E2E（瀏覽器）對「少 8 小時」這種值差驗證成本高且脆弱，本 Issue 不納入。

## changelog（Q6=A）

> ⚠️ 本 repo **無**獨立 changelog / readme.txt 檔；release 走 `pnpm run release`（release-it），release notes 由 conventional commit 自動生成。

故 changelog 提醒以下列方式落地（繁中）：

1. **commit message body** 與 **PR 描述**載明使用者可見變更說明：
   > 學員列表／匯出 CSV 的「註冊於」時間，從顯示 UTC 修正為顯示 WordPress 設定時區。先前看到的時間若與直覺不符屬顯示 bug，已修正；資料庫內容不變。
2. 若後續決定維護 `readme.txt` 的 `== Changelog ==` 區段，於該版本條目加入同段文字（目前 repo 無此檔，**不**在本 Issue 新建）。

## 實作順序（交給 tdd-coordinator）

> TDD：Red（先寫失敗測試）→ Green（最小實作）→ Refactor。helper 無外部依賴，先行。

1. **Red — helper**：建 `tests/Integration/Student/UserRegisteredTimezoneTest.php`，先寫 T1-T5（helper 直測）。此時 `Datetime` 不存在 → 失敗。
2. **Green — helper**：新增 `inc/classes/Utils/Datetime.php`（`to_site_timezone`）。跑 T1-T5 轉綠。
3. **Red — 6 輸出點**：補 T6-T12（呼叫各輸出點，斷言站台時區 + 不 double-shift）。此時 6 點仍吐 UTC → 失敗。
4. **Green — 6 輸出點**：依「架構變更」表逐一改 6 個檔案（各加 `use ... Datetime;` + 改該行）。先改 Service 三檔（2/3/4）與 MCP 兩檔（5/6），最後改列表 API（1，含 `isset` 守衛 + 不碰 `_human`）。跑 T6-T12 轉綠。
   - 改列表 API 前，先在本地 `local-turbo` 以 `var_dump( User::instance($id)->to_array('list') )` 確認 `user_registered` key 名稱與是否有 `user_registered_human`，再落實後處理。
5. **驗證品質**：`pnpm run lint:php`（phpcbf + phpcs + phpstan level 9）+ `composer run test`（或 `vendor/bin/phpunit` 指定本檔）全綠。
6. **Refactor**：檢查 6 處呼叫一致（皆 `Datetime::to_site_timezone((string) ...)`），無重複邏輯；確認 `user_registered_human` 未被動到。
7. **changelog / PR**：commit body + PR 描述加入站長提醒文字（見上節）。

## 驗收標準（Definition of Done）

- [ ] `inc/classes/Utils/Datetime.php` 新增，`to_site_timezone()` 通過 T1-T5（含空值 / `0000-00-00` 不變 1970、格式不套站台格式）。
- [ ] 6 個輸出點全改走 helper；列表 API 後處理含 `isset` 守衛且未動 `user_registered_human`。
- [ ] T6-T12 全綠：列表 API / Query::get / ExportCSV / ExportAllCSV / MCP List / MCP ExportCsv 皆輸出站台時區。
- [ ] double-shift 護欄測試（T7）通過：Alice 為 `21:07:33`，不為 `05:07:33`。
- [ ] `pnpm run lint:php`（含 PHPStan level 9）與 `composer run test` 全綠。
- [ ] 前端、CSV 標頭、vendor、公告 API 皆未改動。
- [ ] commit body / PR 描述含繁中站長提醒（顯示值改站台時區、DB 不變）。

## 不在本 Issue 範圍

- Powerhouse vendor 內部程式碼（禁止修改 vendor；採 PowerCourse 端後處理）。
- 其他模組時間欄位（學習紀錄／訂單時間軸已正確轉換）。
- CSV 欄位標頭加註時區（Q3=A）。
- 套用站台日期/時間格式設定（Q2=A，維持 `Y-m-d H:i:s`）。
- 新建 readme.txt / 獨立 changelog 檔（repo 現況無此檔）。
- E2E 測試。
