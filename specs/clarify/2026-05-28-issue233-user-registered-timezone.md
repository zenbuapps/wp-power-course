# Clarify Session 2026-05-28 — Issue #233

## Idea

### 標題：學員列表「註冊於」與匯出 CSV 直接輸出 user_registered（UTC），未轉換為 WP 設定時區

學員列表的「註冊於」欄位、以及學員匯出 CSV 的 `user_registered` 欄位，直接輸出 `wp_users.user_registered` 原始值（UTC），未轉成 WordPress 設定時區。當主機 PHP `date.timezone = UTC` 而 WP 設定 `Asia/Taipei` 時，顯示時間少 8 小時，與同畫面其他已轉時區的時間欄位（學習紀錄／訂單時間軸）互相矛盾，導致站長誤判時間錯亂（真實案例：`zizizimusic.com` 學員 #202 退費爭議）。

### 現況掃描

| 觀察項目 | 內容 |
|---------|------|
| 寫入慣例 | `wp_users.user_registered` 由 WP 核心以 **UTC** 寫入（`wp_insert_user()` → `gmdate('Y-m-d H:i:s')`） |
| 修法 | 統一改用 `\get_date_from_gmt()` 把 UTC 字串轉成 WP 設定時區（預設輸出格式 `Y-m-d H:i:s`，僅換時區不換格式） |
| 前端渲染 | 學員列表前端把 `user_registered` 當**純字串**直接渲染（`<p>{user_registered}</p>`），JS 端不再 parse 時區 → 後端轉站台時區後**不會** double-shift |

### 呼叫鏈追蹤（修正 issue 與前一輪留言的不精確處）

| 輸出點 | 檔案:行 | 真正消費者 |
|--------|---------|-----------|
| ① 學員列表 API | `Resources/Student/Core/Api.php` `get_students_callback`（:289 後處理） | **前台後台學員列表**（資料源 Powerhouse `User::to_array('list')`，vendor） |
| ② 單一學員查詢 | `Resources/Student/Service/Query.php:368`（`Query::get`） | **僅 MCP `StudentGetTool`**（**非**公告 API — 公告用自己的 `Announcement\Service\Query`） |
| ③ 單一課程匯出 CSV | `Resources/Student/Service/ExportCSV.php:122` | 後台單一課程學員 CSV |
| ④ 全域匯出 CSV | `Resources/Student/Service/ExportAllCSV.php:161` | 後台全域學員 CSV |
| ⑤ MCP 學員列表 | `Api/Mcp/Tools/Student/StudentListTool.php:180` | AI Agent 透過 MCP |
| ⑥ MCP 學員匯出 | `Api/Mcp/Tools/Student/StudentExportCsvTool.php:193` | AI Agent 透過 MCP |

> issue 原把 `Query.php:368` 標為「學員 REST API 回應（前台列表）」並不正確 —— 前台列表實際來自 Powerhouse `to_array('list')`；`Query::get` 只餵 MCP StudentGetTool。前一輪留言又把公告 API 也算進 `Query::get` 的消費者，實際公告用的是不同的 `Announcement\Service\Query`。

### Double-shift 風險評估（Q7=A 的關鍵前提）

Q7=A 假設「前台列表目前顯示 UTC（Powerhouse `to_array` 未自行轉時區）」，故需在 `get_students_callback` 補轉。若假設錯誤（Powerhouse 已轉），再轉一次會 **+16 小時**。

- CI 環境無 Powerhouse vendor 原始碼，無法直接讀 `to_array('list')` 實作。
- **交叉驗證**：issue 自身真實案例 — `zizizimusic.com` 後台**學員列表**顯示 #202「註冊於 `2026-05-17 13:07:33`」即為 **UTC 原值**（台灣時間應 21:07:33）。此畫面正是走 `to_array('list')` 的列表，證明 Powerhouse **未**轉時區 → Q7=A 安全，不會 double-shift。
- 仍於 feature 加「不得 double-shift」防回歸護欄（僅 +8 而非 +16），鎖住此假設。

## Q&A

### 第一輪（用戶確認 `B A A A A A`）

- **Q1 [工程] 修正範圍**：**B — 連 MCP `StudentListTool` / `StudentExportCsvTool` 一起修**
  - 同一個 bug 從不同出口冒出，分開修會讓 AI Agent 透過 MCP 拿到的時間又與後台不一致。
- **Q2 [工程] 輸出格式**：**A — 維持 `Y-m-d H:i:s`（只換時區、格式不變）**
  - issue 明確指出問題只在時區；`get_date_from_gmt()` 預設即此格式，CSV 也能被試算表正確解析。
- **Q3 [情境] CSV 欄位標頭**：**A — 不改標頭，只修時區值**
  - 修正後全站時間口徑統一站台時區，不需單獨替此欄加註。
- **Q4 [工程] 空值 / 異常**：**A — 有值才轉換，空值原樣輸出空字串**
  - 避免 `get_date_from_gmt('')` 產生 `1970-01-01 08:00:00` 誤導值。
- **Q5 [工程] 測試**：**A — 補 PHPUnit 測試覆蓋各處轉換**
  - 時區 bug 易回歸；mock 站台時區 `Asia/Taipei` + server UTC 驗證各輸出點。
- **Q6 [情境] changelog**：**A — 一起加 changelog 提醒既有用戶**
  - 顯示值會從 UTC 變站台時區，主動告知避免再次誤判。

### 第二輪（用戶確認 `A`）

- **Q7 [工程] 前台列表（Powerhouse 資料源）處理**：**A — 列表目前顯示 UTC，於 `get_students_callback` 取得 `to_array('list')` 結果後額外轉時區，並照原計畫修 5 點**
  - 經 issue 真實案例交叉驗證 Powerhouse `to_array` 未轉時區，確認 A 不會 double-shift。

## 實作方案摘要

### 統一轉換 helper（建議）

於 Student 模組新增一個小工具（例如 `Utils::to_site_timezone( ?string $gmt ): string`）：

```php
// 空 / 全零原樣回傳，避免 get_date_from_gmt('') → 1970；其餘轉站台時區
public static function to_site_timezone( ?string $gmt ): string {
    $gmt = (string) $gmt;
    if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
        return $gmt;
    }
    return (string) \get_date_from_gmt( $gmt ); // 預設輸出 'Y-m-d H:i:s'
}
```

六個輸出點一律改走此 helper（取代裸 `(string) $user->user_registered`），確保空值守則與格式一致：

1. `Resources/Student/Core/Api.php` `get_students_callback`：取得 `$formatted_users` 後，對每筆 `user_registered` 套 helper（**新增**，Q7=A）。
   - `user_registered_human`（相對時間）由 `human_time_diff` 計算，TZ offset 對相減無影響，**不需調整**。
2. `Resources/Student/Service/Query.php:368`
3. `Resources/Student/Service/ExportCSV.php:122`
4. `Resources/Student/Service/ExportAllCSV.php:161`
5. `Api/Mcp/Tools/Student/StudentListTool.php:180`
6. `Api/Mcp/Tools/Student/StudentExportCsvTool.php:193`

### 測試（Q5=A）

`tests/Integration/Student/`（新增目錄）以 PHPUnit 覆蓋：
- mock WP 時區 `Asia/Taipei`、server UTC。
- 驗證各輸出點 UTC `2026-05-17 13:07:33` → 站台 `2026-05-17 21:07:33`、跨日 `18:30:00` → 隔日 `02:30:00`。
- 空值 / `0000-00-00` 不變成 1970。
- double-shift 護欄：列表後處理對 UTC 原值僅 +8。

### changelog（Q6=A）

於發布說明 / README 變更紀錄加註（繁中）：
> 學員列表／匯出 CSV 的「註冊於」時間，從顯示 UTC 修正為顯示 WordPress 設定時區。先前看到的時間若與直覺不符屬顯示 bug，已修正；資料庫內容不變。

## 不在本 Issue 範圍

- Powerhouse vendor 內部程式碼（禁止修改 vendor；採在 PowerCourse 端後處理）。
- 其他模組的時間欄位（學習紀錄／訂單時間軸已正確轉換）。
- CSV 欄位標頭加註時區（Q3=A 決定不加）。
- 套用站台日期/時間格式設定（Q2=A 決定維持 `Y-m-d H:i:s`）。

## 產出規格檔案

- `specs/features/student/學員註冊時間時區轉換.feature`（新增）
- `specs/clarify/2026-05-28-issue233-user-registered-timezone.md`（本檔）
