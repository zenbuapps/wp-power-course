# 課程影片觀看完後無法重複觀看（Ended Overlay Cancel）

## Idea

**Issue #206**：學員觀看過的章節，若 `last_position_seconds` 落在「≥ FINISH_THRESHOLD (0.95) × duration」的接近片尾位置（例如 580/600 秒），從「重看」CTA 重新進入章節後無法重新觀看。

### 使用者觀察到的現象

1. 點擊「重看 第 X 章 09:40」進入章節
2. Player 依 `004-resume-playback-position.md` 規格 seek 到 580 秒（Q12：已完成後保留 `last_position_seconds`）
3. 影片 20 秒內播放到 ended
4. `<Ended />` 倒數遮罩（`absolute inset-0 w-full h-full bg-black/50`）完整蓋住 Player
5. **進度條、控制列、播放按鈕全被遮蔽**，學員無法拖曳回開頭、無法暫停、無法重播
6. 5 秒後 `window.location.href = next_post_url` 強制跳到下一章節

### 根因分析

**程式碼面**：`js/src/App2/Ended.tsx` 只提供「倒數 → 跳轉」的單一出口，無「取消自動跳轉」或「重看本章」的按鈕，且遮罩未讓出任何互動空間。

**規格面**：`續播至上次觀看秒數.feature` 定義了「已完成章節秒數接近片尾仍正常續播」的行為，但沒有進一步定義「到了片尾 ended 之後 UX 該怎麼辦」；`影片進度自動完成章節.feature` 的「Ended 倒數跳轉」Rule 預設是無條件倒數跳轉，沒有出口。

---

## 澄清結果（Q1–Q10 一頁摘要）

| # | 項目 | 最終決策 |
|---|------|---------|
| 1 | 取消遮罩的 UX 主軸 | **B — 僅提供「重看本章」按鈕（post-test 2026-04-20 調整，原為 C）** |
| 2 | 遮罩範圍策略 | **（廢止，post-test 2026-04-20）** — 原 C 因 Q1 改為 B 而連動失效 |
| 3 | 「取消自動跳轉」後的播放狀態 | **（廢止，post-test 2026-04-20）** — 「取消自動跳轉」按鈕已移除 |
| 4 | 續播到片尾仍 5 秒自動跳下一章 | A — 照舊 5 秒倒數後自動跳下一章（首次觀看與續播行為一致） |
| 5 | 倒數期間拖拉進度條自動中止倒數 | **（廢止，post-test 2026-04-20）** — onSeeking 隱性備援已移除（詳見下方 Post-Implementation Adjustments） |
| 6 | `last_position_seconds` 極接近 duration 的處理 | A — 照原秒數 seek，ended 後顯示遮罩 |
| 7 | 測試策略 | A — Playwright E2E 新增 2 條路徑（重看本章 + 首次觀看回歸；post-test 從 3 條縮減為 2 條） |
| 8 | 是否 patch `影片進度自動完成章節.feature` | C — 兩個 feature 都要更新 |
| 9 | 順便修 `Ended.tsx` setInterval 競態條件 | A — 順便修（加 `isCancelledRef` 守衛 `window.location.href`） |
| 10 | 回歸保護 | A — 首次觀看自然播放完的 5 秒自動跳轉必須加 Playwright 測試保護 |

## Post-Implementation Adjustments (2026-04-20)

**變更類型**：Post-test refinement（人工測試後的決策調整）
**影響決策**：Q1 由 C → B；Q2 / Q3 / Q5 連動廢止
**未受影響決策**：Q4 / Q6 / Q7（條數調整）/ Q8 / Q9 / Q10

### 調整理由

人工測試 Phase A/B 完成後的實作版本時發現：
VidStack 在影片 `ended` 狀態下「取消自動跳轉」的 BUG 難解，具體觀察到的問題：

1. **BUG 2-1：播放按鈕消失** — 按下「取消自動跳轉」後遮罩雖消失，但 VidStack 原生控制列的播放按鈕在 ended 狀態下不顯示，用戶無法從 paused at ended 繼續操作。
2. **BUG 2-2：拖拉進度條觸發 progress API 把進度條 seek 回片尾** — 即使成功取消倒數並暴露控制列，用戶拖拉進度條時，現有 `useChapterProgress` 的 GET 續播秒數 + VidStack seek 邏輯會把進度條拉回 `last_position_seconds`（接近片尾），形成無限迴圈。

### 新決策

- **Q1：C → B** — 僅保留「重看本章」作為唯一明確出口。重看可繞過 2-1/2-2，因為它將 `currentTime = 0` 並立即 play，直接脫離 ended 狀態。
- **Q2 / Q3 / Q5 連動廢止** — 既然不提供「取消自動跳轉」按鈕，遮罩範圍策略（Q2）、取消後的播放狀態（Q3）、onSeeking 隱性備援（Q5）均無意義。尤其 Q5 的 `onSeeking` guard 若保留，會在遮罩未消失但媒體層仍 ended 時造成新 BUG 面。
- **Q7：3 條 → 2 條** — E2E 測試從「取消按鈕 / 拖進度條 / 最後一章無遮罩」縮減為「重看本章 + 首次觀看回歸」。

### 未受影響決策

- **Q4**（5 秒自動跳下一章）：維持 A，自動跳轉是首次觀看的預期行為，由 E2E Test 2 保護。
- **Q6**（極接近 duration 照原秒數 seek）：維持 A，屬續播 hook 的範疇，不動 `useChapterProgress.ts`。
- **Q9**（`setInterval` 競態修復）：維持 A，`isCancelledRef` 守衛仍有價值（防止「重看本章」後殘留 interval tick 跳轉）。
- **Q10**（首次觀看回歸保護）：維持 A，由縮減後的 E2E Test 2 承擔。

### Q2 × Q5 整合

- **視覺層**：遮罩 `absolute inset-0` 全屏蓋住 VidStack 控制列，符合 Q2=C 的乾淨視覺
- **互動層**：
  - 「取消自動跳轉」按鈕 → 遮罩消失，影片 paused at ended
  - 「重看本章」按鈕 → seek 到 0 + play，遮罩消失
  - VidStack `onSeeking` event → 自動 `onCancel()`，遮罩消失（隱性備援，符合 Q5=A）
  - 5 秒倒數結束且上述皆未觸發 → 自動跳 `next_post_url`

---

## 規格相關檔案

| 類型 | 路徑 | 變更 |
|------|------|------|
| 澄清紀錄（新） | `specs/open-issue/clarify/2026-04-20-1748.md` | Q1–Q10 完整紀錄 |
| Open Issue 總結（新） | `specs/open-issue/206-ended-overlay-cancel.md` | 本檔 |
| Feature（更新） | `specs/features/progress/續播至上次觀看秒數.feature` | 於 `Rule: 章節已完成後，重新進入仍以 last_position_seconds 續播（重看）` 追加 3 個 Examples（取消按鈕 / 重看按鈕 / `onSeeking` 中止） |
| Feature（更新） | `specs/features/progress/影片進度自動完成章節.feature` | 於 `Rule: 自動完成 API 呼叫為 fire-and-forget，不影響 Ended 倒數跳轉` 修改描述並追加出口 Examples（取消按鈕、`onSeeking` 中止、最後一章無遮罩） |

---

## 涉及的現有程式碼

| 檔案 | 說明 |
|------|------|
| `js/src/App2/Ended.tsx` | 倒數遮罩元件。新增 `onCancel` / `onRewatch` props、兩顆按鈕 UI、`isCancelledRef` 守衛 `window.location.href`（修 setInterval 競態）；遮罩保持 `absolute inset-0 w-full h-full bg-black/50` 全屏，但倒數期間遇 `onCancel` 立即停止 |
| `js/src/App2/Player.tsx` | 管理 `isEnded` state 與 seek 邏輯。追加：(1) 傳遞 `onCancel` / `onRewatch` 給 `<Ended />`；(2) 監聽 VidStack `onSeeking` 事件，若 `isEnded === true` 則呼叫 `onCancel`（中止倒數 + 隱藏遮罩）；(3) `onRewatch` 實作：`seek(0)` + `play()` + 重置 `isEnded` |
| `js/src/App2/hooks/useChapterProgress.ts` | 續播秒數 GET / POST；本次**不動**（`last_position_seconds` 保留接近片尾的行為維持 Q6=A） |
| `tests/e2e/frontend/chapter/` 目錄 | 新增 3+1 條 Playwright 路徑：(1) 續播到片尾 → 按「取消自動跳轉」→ 停留片尾；(2) 續播到片尾 → 按「重看本章」→ seek 0；(3) 倒數期間拖拉進度條 → 遮罩消失；(4) 回歸：首次觀看自然播完 → 5 秒後自動跳下一章（Q10） |

---

## 技術依賴

- **VidStack Player**（已在用）— 使用 `useMediaState('currentTime')` + VidStack 原生 `onSeeking` 事件（hls.js / youtube-provider / vimeo-provider 共通）
- **hls.js**（已在用）— 續播 seek 的 Bunny HLS 播放
- **`@wordpress/i18n`**（已在用）— 兩顆按鈕文案使用 `__()`，text domain = `power-course`
- **Playwright**（已在用）— E2E 測試
- **不引入新 library**

---

## 驗收標準（Acceptance Criteria，post-test 2026-04-20 調整後）

1. **續播到片尾 — 重看本章**：學員從「重看 第 1 章 09:40」進入章節 200（`last_position_seconds=580`, `duration=600`），Player seek 到 580s → 20 秒內 ended → 遮罩出現並顯示倒數 `5` 秒 + 「重看本章」按鈕。按「重看本章」後：影片 `currentTime` 回到 0、播放狀態為 playing、遮罩消失、未跳到下一章。
2. **最後一章無遮罩**：`next_post_url` 為空（課程最後一章）的章節播放到 ended，**不顯示遮罩**（既有行為維持）。
3. **首次觀看自然播完自動跳下一章（回歸）**：`last_position_seconds = null`（首次觀看），影片從 0 播到 ended，遮罩顯示 5 秒倒數後自動 `window.location.href = next_post_url` 跳到下一章。若中途按「重看本章」則不跳轉（由 `isCancelledRef` 守衛）。
4. **`setInterval` 競態修復**：按下「重看本章」後，即使殘留的 `setInterval` 仍在執行，`isCancelledRef.current === true` 守衛 `window.location.href` 不被呼叫。
5. **Playwright E2E**：上述 1 與 3 對應的 2 條 E2E 路徑全綠（1 條新增 + 1 條回歸；post-test 已從 4 條縮減為 2 條）。

> **Post-test 刪除的條目**：原 AC1（取消自動跳轉按鈕行為）、AC3（onSeeking 中止）因 Q1 C→B 連動失效；最後一章無遮罩改為 AC2，由既有 `if (!next_post_url) return null` 覆蓋（E2E 不強制覆蓋）。

---

## 風險與待決議

### 風險

1. **VidStack `onSeeking` 事件穩定性**：不同 provider（hls.js / YouTube / Vimeo）觸發 `onSeeking` 的時機可能不同；YouTube / Vimeo 可能只在使用者鬆開滑鼠時才 fire。若隱性備援不可靠，仍有「取消自動跳轉」按鈕作為明確出口，不影響主流程。
2. **遮罩 z-index 與 VidStack 控制列**：`absolute inset-0` 若 z-index 設太低可能被 VidStack 的 `<media-layout>` 蓋住；若太高可能影響其他 overlay。實作時需在瀏覽器驗證。
3. **按鈕點擊與 VidStack 原生點擊（toggle play/pause）衝突**：遮罩內按鈕 click 需 `stopPropagation` 避免觸發 VidStack 的 play toggle。

### 待決議（實作階段決定）

1. **按鈕文案最終版**：「取消自動跳轉」vs「留在本章」vs「暫停跳轉」；「重看本章」vs「從頭播放」vs「重新觀看」。由 React-master + UIUX-reviewer 實作時決定，需走 `@wordpress/i18n` 的 `__()` 並同步 `i18n:pot`。
2. **按鈕樣式**：Primary / Secondary 優先級由 UIUX-reviewer 決定；既有設計系統以 Ant Design 為主。
3. **「重看本章」是否觸發自動完成重置**：`影片進度自動完成章節.feature` 的 `hasAutoFinished` flag 是否因為點「重看本章」而重置？由於本次的「重看」是已完成章節（`finished_at` 已有值），再觸發一次 `toggle-finish` 會變成「標示為未完成」，可能不符期望。**建議維持 flag 不重置**，實作時再確認。
