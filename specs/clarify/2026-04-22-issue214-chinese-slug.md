# Clarify Session 2026-04-22 — Issue #214

## Idea

### 標題：中文網址儲存後變更問題

原本已經有章節叫做「新章節」，此時再新增一個章節。第一次儲存時，網址會是 `%e6%96%b0%e7%ab%a0%e7%af%80-2`，第二次儲存後，網址會變成 `2-3`，導致用戶連結看到 404。

### 根因分析

Chapter API（`inc/classes/Resources/Chapter/Core/Api.php`）的 `separator()` 方法中，`$skip_keys` 缺少 `'post_name'`，導致 `sanitize_text_field_deep()` 對中文 slug 進行 sanitize 時破壞了 URL 編碼的中文字元。

Course API（`inc/classes/Api/Course.php`）的 `$skip_keys` 已包含 `'slug'`，因此課程端不受影響。

### 影響範圍

- 僅影響 Chapter API（課程 API 已有修復）
- 僅影響包含非 ASCII 字元（中文、日文等）的 slug
- 英文 slug 不受影響

## Q&A

- Q1 (課程中文 slug 狀態): A — 課程 API 已有修復（`$skip_keys` 包含 `'slug'`），問題僅在章節端
- Q2 (已損壞 slug 處理): A — 不自動修復，管理員可自行重新編輯章節名稱來修正 slug
- Q3 (301 重新導向): A — 不做重新導向，修完 bug 後問題不再發生，重新導向增加過多複雜度
- Q4 (未修改標題時 slug 行為): A — slug 和標題獨立管理，管理員未修改 slug 欄位時，無論儲存幾次都不應變更
- Q5 (修復範圍): A — 只修 Chapter API，其他 API 不涉及 slug 處理
- Q6 (測試方式): B — 新增 PHPUnit 測試驗證 API 層的 slug 處理邏輯

## 修復方案

在 `inc/classes/Resources/Chapter/Core/Api.php` 的 `separator()` 方法中，將 `'post_name'` 加入 `$skip_keys` 陣列：

```php
$skip_keys = [
    'chapter_video',
    'post_content',
    'post_name',  // 防止中文 slug 被 sanitize_text_field_deep() 破壞
];
```

注意：Chapter API 使用 `ChapterUtils::converter()` 將前端的 `'slug'` 欄位映射為 WordPress 的 `'post_name'`，因此 skip key 必須是 `'post_name'` 而非 `'slug'`。
