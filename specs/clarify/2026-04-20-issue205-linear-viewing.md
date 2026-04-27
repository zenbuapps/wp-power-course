# Clarify Session 2026-04-20 — Issue #205

## Idea

### 標題：課程線性觀看功能（循序學習模式）

管理員可以針對個別課程開啟「線性觀看」，學員必須依照章節排列順序完成前面的章節才能觀看後面的章節。

延續 #175（人工驗收未通過），本次重新定義需求規格。

### 核心功能
- 管理員在課程「其他設定」分頁啟用/關閉線性觀看（預設關閉）
- 開啟後，學員依 menu_order 平攤一維順序觀看
- 第一個章節永遠解鎖
- 採用「最遠進度模式」計算解鎖範圍
- 鎖定章節有明確提示、URL 導向、API 403 雙層驗證
- 管理員預覽不受限制

## Q&A

- Q1 (父章節處理): **B** — 包含父章節。所有章節（含父章節與子章節）平攤為一維順序，父章節也算在線性序列中，學員必須手動完成才能繼續。`get_flatten_post_ids()` 現有邏輯不變。
- Q2 (側邊欄即時更新): **A** — DOM 操作方式。保持 PHP 渲染側邊欄，toggle-finish API 回傳解鎖的章節 ID，前端用 jQuery 操作 DOM 移除/加上鎖定樣式與圖示。改動最小、風險最低。
- Q3 (鎖定狀態 API): **A** — 擴充現有 API。初始狀態由 PHP 渲染時輸出到 HTML `data-locked` 屬性；toggle-finish API 回傳 `unlocked_chapter_ids`（新解鎖的章節 ID 列表）。不新增獨立端點。
- Q4 (Ended 倒數畫面): **C** — 智慧判斷。影片看完 ≥ 95% 會自動完成 → 下一章即時解鎖 → 正常倒數跳轉（體驗不中斷）。未達 95% 時不顯示倒數、改顯示引導提示「請點擊『完成章節』以解鎖下一章」。
- Q5 (URL 直接存取導向): **A** — Query 參數方式。`single-pc_chapter.php` PHP 層 redirect 並加上 `?linear_locked=1`，目標頁 JS 偵測參數後顯示 toast，用 `history.replaceState` 清除參數。
- Q6 (章節排序變更影響): **B** — 即時重算 + 警告管理員。排序變更後，下次學員進入教室自動根據新順序重算解鎖狀態。管理員調整排序時，若課程已啟用線性觀看，顯示提醒「此課程已啟用線性觀看，調整排序將影響學員的章節解鎖狀態」。
- Q7 (i18n 硬編碼修正): **A** — 一併修正。在本次 PR 中將 `Ended.tsx` 第 67 行硬編碼中文字串改為 `sprintf(__('Next chapter will auto-play in %d seconds', 'power-course'), countdown)`。

## 已確認的設計決策

| 編號 | 決策 | 確認值 |
|------|------|--------|
| Q1 | 父章節處理 | B — 包含，需手動完成 |
| Q2 | 側邊欄更新 | A — DOM 操作 |
| Q3 | API 設計 | A — 擴充現有 toggle-finish |
| Q4 | Ended 畫面 | C — 智慧判斷 |
| Q5 | URL 導向 | A — Query 參數 |
| Q6 | 排序影響 | B — 重算 + 警告 |
| Q7 | i18n 修正 | A — 同 PR |

## 資料模型變更

- `courses` 表新增 `enable_linear_viewing` postmeta（`'yes'` / `'no'`，預設 `'no'`）
- toggle-finish API 回應新增 `unlocked_chapter_ids` 欄位
- 側邊欄 HTML 新增 `data-locked`、`data-lock-message` 屬性
