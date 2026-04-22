# 實作計畫：Issue #206 — Ended 遮罩新增取消與重看出口

> **範圍模式**：HOLD SCOPE（維持範圍，專注防彈架構與邊界情況）
> **澄清來源**：`specs/open-issue/206-ended-overlay-cancel.md`（Q1–Q10 決策已固化）
> **關聯 Issue**：GitHub Issue #206 — 課程影片觀看完後無法重複觀看
> **關聯主 Spec**：`specs/open-issue/004-resume-playback-position.md`（本 issue 是其衍生 bug）

---

## 1. 概述

學員從「重看」CTA 重新進入已完成章節時，因續播至接近片尾（`last_position_seconds ≥ 0.95 × duration`），影片 20 秒內即觸發 `ended` 事件，`<Ended />` 全屏遮罩蓋住整個 Player、進度條與控制列，學員無法暫停也無法重播，5 秒後被強制跳到下一章。

本計畫以**純前端修復**解決此問題：為 `Ended.tsx` 新增「取消自動跳轉」與「重看本章」兩顆按鈕，讓 `Player.tsx` 監聽 `onSeeking` 作為隱性備援，並順便修掉 `setInterval` 的 unmount/cancel 競態（`isCancelledRef` 守衛 `window.location.href`）。不涉及後端、資料庫、API 或 Actor 任何變更。

---

## 2. 需求重述

1. **遮罩仍全屏顯示**（Q2=C 決策），維持視覺乾淨；但必須提供明確出口。
2. **兩顆按鈕**（Q1=C 決策）：
   - 「取消自動跳轉」：遮罩消失，影片停在片尾（paused at ended，Q3=A）
   - 「重看本章」：`currentTime = 0` 並 `play()`，遮罩消失
3. **`onSeeking` 隱性備援**（Q5=A）：倒數期間若 VidStack 觸發 `seeking`（用戶拖拉進度條），且 `isEnded === true`，自動呼叫 `onCancel`。
4. **首次觀看自然播完的行為照舊**（Q4=A）：5 秒倒數結束仍自動跳 `next_post_url`。
5. **修復 `setInterval` 競態**（Q9=A）：按下取消後即使殘留 interval 執行，也不執行 `window.location.href`。
6. **最後一章無遮罩**（維持現有 `if (!next_post_url) return null` 行為，不改）。
7. **i18n**：兩顆按鈕文案用 `@wordpress/i18n __()`，text domain = `'power-course'`，msgid 英文（符合 `.claude/rules/i18n.rule.md`）。
8. **測試**（Q7=A, Q10=A）：Playwright E2E 4 條路徑（3 新 + 1 回歸）。

### 成功的樣子

- 已完成章節從「重看」CTA 進入 → seek 到 580s → 20 秒內 ended → 遮罩顯示 + 倒數 5s + 2 顆按鈕
- 按「取消自動跳轉」→ 遮罩消失、影片停在 600s、**不跳下一章**
- 按「重看本章」→ `currentTime = 0` + play、遮罩消失、**不跳下一章**
- 倒數期間拖進度條 → `onSeeking` 觸發 → 遮罩消失、倒數中止、**不跳下一章**
- 首次觀看自然播完 → 5 秒後仍自動跳下一章（回歸保護不破）

---

## 3. 已知風險（來自研究 + `specs/open-issue/206-ended-overlay-cancel.md`）

| # | 風險 | 緩解措施 |
|---|------|---------|
| R1 | **VidStack `seeking` 事件在初始 seek 時也會觸發**（`onLoadedMetadata` / `onCanPlay` 的 `trySeekToInitialPosition`），會誤觸 `onCancel` | 在 `Player.tsx` 的 `onSeeking` handler 內以 `isEnded === true` 守衛，只有在 ended 狀態下才呼叫 `onCancel` |
| R2 | **`setInterval` 競態**：`useEffect` 的 cleanup 執行之後仍有一個 queued tick 可能執行 `window.location.href` | 新增 `isCancelledRef`（來自 Q9=A 決策），跳轉前 `if (isCancelledRef.current) return` |
| R3 | **遮罩 z-index 不夠高**：VidStack `<DefaultVideoLayout>` 的 controller layer 可能壓過既有的 `z-10` | 實作時用 Playwright 驗證按鈕可點擊；必要時調高到 `z-20` 或 `z-30`（與 VidStack 的 `media-layout` 階層搭配） |
| R4 | **按鈕點擊被 VidStack 原生 play-toggle 攔截**：VidStack 對覆蓋層可能有 click-through 行為 | 按鈕 `onClick` 以 `(e) => { e.stopPropagation(); ... }` 阻擋冒泡；遮罩 wrapper 自身也 `stopPropagation` 避免觸發 VidStack play toggle |
| R5 | **VidStack 在 `ended` 狀態下設 `currentTime = 0` 的行為不定**：某些 provider 可能不會自動重新進入 play 狀態 | `重看本章` 實作先 `playerRef.current.currentTime = 0`，再 `await playerRef.current.play()`（同時 `setIsEnded(false)` 避免遮罩卡住） |
| R6 | **`hasAutoFinishedRef` 被誤重置**：若「重看」重置了 flag，95% 會再次 dispatch API（已完成章節變成再 toggle-finish = 標示為未完成） | **嚴格不動** `hasAutoFinishedRef`；並以 Example「已完成的章節重新觀看不觸發」（已存在於 `影片進度自動完成章節.feature`）的 `is_finished === 'true'` 守衛兜底 |
| R7 | **倒數期間 React unmount**（學員切換頁籤、關閉頁面）：`beforeunload` 可能先觸發 `handleEnded` 再 unmount | `useChapterProgress` 既有的 `beaconProgress` 已處理，**不動**該 hook |
| R8 | **用戶連點「取消」與「重看」**：race condition 造成狀態不一致 | 兩顆按鈕內部讀取 `isCancelledRef.current`，第二次點擊直接 early return；React 18 自動批次 state 更新亦會吸收大部分 race |
| R9 | **Controlled seeking 不觸發 `seeking` event**：呼叫 `playerRef.current.currentTime = 0`（「重看本章」流程內）也會觸發 `onSeeking`，導致 `onCancel` 被二次呼叫 | `onCancel` 實作為冪等（只 `setIsEnded(false)` + `isCancelledRef.current = true`），二次呼叫無副作用 |
| R10 | **三個 provider 對 `seeking` 行為差異**：YouTube / Vimeo 的 `seeking` 可能在滑鼠鬆開才 fire | 明確按鈕是主出口，`onSeeking` 只是隱性備援；不把它當唯一依賴 |

---

## 4. 架構變更（檔案影響清單）

### 4.1 要動的檔案

| 檔案 | 變更 |
|------|------|
| `js/src/App2/Ended.tsx` | 新增 `onCancel` / `onReplay` props；新增 `isCancelledRef`；新增 2 顆按鈕 UI（i18n 英文 msgid）；保留現有 circle progress 倒數動畫；跳轉前守衛 `isCancelledRef` |
| `js/src/App2/Player.tsx` | 定義 `handleCancelCountdown` / `handleReplay` 兩個 callback；傳入 `<Ended />`；新增 `onSeeking` handler（守衛 `isEnded === true`） |
| `languages/power-course.pot` | 跑 `pnpm run i18n:pot` 後自動新增 `'Cancel auto-skip'`、`'Replay chapter'` 兩條 msgid |
| `languages/power-course-zh_TW.po` | 手動補繁體中文 msgstr：「取消自動跳轉」、「重看本章」 |
| `tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts`（新檔） | 4 條 E2E（3 新 + 1 回歸） |

### 4.2 明確不動的檔案

| 檔案 | 不動理由 |
|------|---------|
| `js/src/App2/hooks/useChapterProgress.ts` | Q6=A 決策：接近 duration 的 `last_position_seconds` 照原秒數 seek，不做夾擠；本 hook 的 GET / POST 行為完全維持 |
| `inc/` 所有 PHP | 本 issue 純前端修復 |
| `specs/features/progress/*.feature` | 已於澄清階段更新；planner 不再動 |

### 4.3 i18n 新字串（英文 msgid）

| msgid | 使用位置 | zh_TW 翻譯 | translator comment |
|-------|---------|-----------|-------------------|
| `Cancel auto-skip` | `Ended.tsx` 取消按鈕 | 取消自動跳轉 | `/* translators: Ended 倒數遮罩「取消自動跳轉到下一章節」按鈕 */` |
| `Replay chapter` | `Ended.tsx` 重看按鈕 | 重看本章 | `/* translators: Ended 倒數遮罩「重新從頭播放本章節影片」按鈕 */` |
| `Next chapter will play in %d seconds` | `Ended.tsx` 倒數文字 | 下個章節將在 %d 秒後自動播放 | `/* translators: %d: 倒數剩餘秒數 */` — 原本硬編碼「下個章節將在 {countdown} 秒後自動播放」順便 i18n 化（否則違反 `i18n.rule.md`） |

> ⚠️ 原本 `Ended.tsx` 第 67 行的「下個章節將在 {countdown} 秒後自動播放」為硬編碼中文違反 i18n.rule.md §1，既然要動這檔，一併用 `sprintf(__('...'), countdown)` 修正。

---

## 5. 資料流與狀態機分析

### 5.1 狀態流程圖（Ended 遮罩的生命週期）

```
                    ┌──────────────────┐
                    │  Player idle      │
                    │  isEnded = false  │
                    └──────────┬────────┘
                               │ VidStack onEnded
                               ▼
                    ┌──────────────────────────┐
                    │  Ended overlay mounted   │
                    │  isEnded = true           │
                    │  countdown = 5            │
                    │  isCancelledRef = false   │
                    └────┬────┬────┬──────┬────┘
                         │    │    │      │
       ┌─────────────────┘    │    │      └─────────────┐
       │ click               │    │ onSeeking          │ countdown === 0
       │ "Cancel auto-skip"  │    │ (isEnded===true)   │ && !isCancelledRef
       │                     │    │                    │
       ▼                     ▼    ▼                    ▼
  ┌──────────────┐   ┌─────────────────┐   ┌───────────────────────┐
  │ onCancel()   │   │ onCancel()       │   │ window.location.href  │
  │ setIsEnded(  │   │ (equivalent)     │   │ = next_post_url       │
  │   false)     │   │                  │   │                       │
  │ isCancelled  │   │                  │   │                       │
  │   Ref = true │   │                  │   │                       │
  └──────┬───────┘   └─────────┬────────┘   └───────────┬───────────┘
         │                     │                         │
         ▼                     ▼                         ▼
    ┌────────────────┐   ┌────────────────┐    ┌─────────────────┐
    │ Paused at      │   │ Resumed from   │    │ Navigation to   │
    │ ended (600s)   │   │ seeked pos     │    │ next chapter    │
    └────────────────┘   └────────────────┘    └─────────────────┘

       另一條出口：
       click "Replay chapter"
       ────▶ onReplay():
             isCancelledRef = true
             setIsEnded(false)
             playerRef.currentTime = 0
             await playerRef.play()
             ────▶ Playing from 0s
```

### 5.2 onSeeking 隱性備援的資料流（含 shadow paths）

```
VidStack seeking event
        │
        ▼
┌────────────────────────────┐
│ Player.onSeeking handler   │
└─────┬──────────────────────┘
      │
      ▼
  [ isEnded? ]
      │
  ┌───┴────────────┐
  │ false          │ true
  ▼                ▼
 (ignore —    ┌─────────────────┐
  normal      │ onCancel()      │
  seek,       │ setIsEnded(false)│
  e.g. init   │ isCancelledRef  │
  seek from   │   = true         │
  useChapter  └─────────┬───────┘
  Progress)             │
                        ▼
                   ┌────────────────────────┐
                   │ Ended unmounts          │
                   │ VidStack continues to   │
                   │ seek to user's position │
                   └────────────────────────┘

  Shadow paths:
  - playerRef null (Player unmounted mid-event) ──▶ React handler detach，不會觸發
  - playerRef.paused === true + seeking ──▶ 允許（用戶在遮罩下拖曳後放開）
  - seeking fired but currentTime unchanged ──▶ 不做特殊處理（冪等）
```

### 5.3 setInterval 競態（Q9=A 修復路徑）

```
useEffect mount:
  ├─ interval = setInterval(tick, 1000)
  ├─ if (countdown === 0 && next_post_url) window.location.href = ...  ← 既有 bug
  └─ cleanup: clearInterval(interval)

Bug sequence:
  t0: countdown = 0，useEffect re-run，觸發 window.location.href   ← X
  t0: useEffect cleanup 還來不及在同一 tick 清 interval
      若有殘留 queued setState tick，也可能在 unmount 前再次進入 useEffect

修復：
  t0: 按下「取消」 → setIsEnded(false)
  t1: <Ended /> unmount
      useEffect cleanup 清 interval
      isCancelledRef.current = true（同步設置，不依賴 React state）
  t2: 若有 queued useEffect tick 再 run，守衛：
      if (isCancelledRef.current) return
```

---

## 6. 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|----------|-------------|---------|---------|------------|
| `Ended.tsx` useEffect 倒數 | `setInterval` cleanup 競態 | Race | `isCancelledRef` 守衛 + `countdown === 0` && `!isCancelledRef.current` | 否（純內部） |
| `Ended.tsx` 按鈕 click | 連點造成多次 callback | Race | Callback 冪等；內部讀 `isCancelledRef.current` early return | 否 |
| `Player.tsx onSeeking` | 初始 seek 誤觸 `onCancel` | Logic bug | `isEnded === true` 守衛 | 否（若誤觸會造成遮罩不出現，有 E2E 驗收） |
| `Player.tsx handleReplay` | `playerRef.current` 為 null | Null pointer | `if (!playerRef.current) return` | 否（靜默） |
| `Player.tsx handleReplay` | `play()` 被瀏覽器自動播放政策阻擋 | DOMException | `.catch(() => {})` 靜默；影片停在 0s 等用戶按播放鍵 | 是（影片停在 0s paused，用戶需手動點擊，但遮罩已消失） |
| `Player.tsx onSeeking` | VidStack 不觸發（YouTube/Vimeo 早期版本） | 功能降級 | 明確按鈕作為主出口 | 否（用戶仍能用按鈕） |
| 跳轉 `window.location.href` | `isCancelledRef.current === true` | 正常守衛 | 直接 `return` | 否（正常行為） |
| i18n `__()` 找不到翻譯 | `.mo` 未載入或 msgid 打錯 | Fallback | WordPress 預設 fallback 到 msgid（英文） | 是（顯示英文按鈕文案） |

> **Critical gaps 檢查**：所有「處理方式=無」且「使用者可見=靜默」的組合 = 0。無 critical gap。

---

## 7. 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|----------|---------|--------|--------|------------|---------|
| `Ended.tsx` 倒數結束跳轉 | `isCancelledRef` 守衛失效 | ✅ ref-based sync write | ✅ E2E Test 1（Cancel 後等 5s） | 是（誤跳下一章） | 手動退回上一頁 |
| `Ended.tsx` 按鈕點擊 | click event 冒泡到 VidStack | ✅ `e.stopPropagation()` | ✅ E2E Test 1/2 會失敗若按鈕被 toggle-play 攔截 | 是 | 重整頁面 |
| `Player.tsx onSeeking` | 初始 seek 誤觸 onCancel | ✅ `isEnded === true` 守衛 | ✅ E2E Test 4（回歸）驗證遮罩正常出現 | 是（遮罩不出現） | 重整頁面 |
| `Player.tsx handleReplay` | `play()` promise 被 autoplay policy reject | ✅ `.catch(() => {})` | ⚠️ 難以在 E2E 模擬 | 是（需手動按播放） | 用戶手動 click play |
| `Player.tsx handleReplay` | VidStack 在 ended 狀態不接受 currentTime = 0 | ⚠️ 需實作時驗證 | ✅ E2E Test 2 驗證 currentTime ≤ 2 | 是（重看失敗） | 按「取消自動跳轉」後手動拖 |
| `<Ended />` 在最後一章不渲染 | `next_post_url` 傳空字串 | ✅ 既有 `if (!next_post_url) return null` | ⚠️ 本次未新增該 E2E（依賴 Q7=A 決策已涵蓋 3 條，最後一章不屬 3 條內，但 feature Example 已定義） | 是（影片播完就停） | N/A 正常行為 |
| `hasAutoFinishedRef` 在 Replay 後被重置 | 已完成章節被 toggle-finish 成未完成 | ✅ 嚴格不重置（見 R6） | ⚠️ 需在 E2E Test 2 加 assertion（check finished_at 未變） | 是（章節變成未完成） | 手動點「標示為已完成」 |

---

## 8. 實作步驟（TDD 友善：Red → Green → Refactor）

> **給 `@zenbu-powers:tdd-coordinator` 的提醒**：本任務為前端修復 + E2E 驗收，建議 TDD 順序為「先寫 E2E（Red） → 實作 `Ended.tsx` + `Player.tsx`（Green） → lint/format/i18n 同步（Refactor）」。無 PHPUnit 路線。

### Phase A — `Ended.tsx` 元件改造

**目標**：新增兩顆按鈕 + `isCancelledRef` 守衛 + i18n 化硬編碼中文。

#### A-1. 新增 props 型別

檔案：`js/src/App2/Ended.tsx`

```ts
type TEndedProps = {
  next_post_url: string
  onCancel: () => void
  onReplay: () => void
}
```

- **行動**：將原本 inline props 改為 type；`const Ended = ({ next_post_url, onCancel, onReplay }: TEndedProps) => {`
- **原因**：新增兩個 callback，型別集中
- **依賴**：無
- **風險**：低

#### A-2. 引入 i18n

```tsx
import { __, sprintf } from '@wordpress/i18n'
```

- **行動**：新 import，順序遵守 ESLint 的 builtin > external > internal 規則
- **依賴**：無
- **風險**：低

#### A-3. 新增 `isCancelledRef`

```tsx
const isCancelledRef = useRef<boolean>(false)
```

- **行動**：在 `useState` 下方宣告 `useRef`，import `useRef`
- **原因**：Q9=A 決策，守衛 `window.location.href`
- **依賴**：A-1
- **風險**：低

#### A-4. 改寫 `useEffect` 邏輯

```tsx
useEffect(() => {
  if (isCancelledRef.current) return

  const interval = setInterval(() => {
    if (countdown > 0) {
      setCountdown((c) => c - 1)
    }
  }, 1000)

  if (countdown === 0 && next_post_url && !isCancelledRef.current) {
    window.location.href = next_post_url
  }

  return () => clearInterval(interval)
}, [countdown, next_post_url])
```

- **行動**：
  1. effect 開頭 early return 若已取消
  2. 跳轉條件加上 `!isCancelledRef.current`
  3. `setCountdown` 改 functional update 避免 stale closure
  4. deps 補上 `next_post_url`
- **原因**：Q9=A 修復競態 + R2 風險消除 + react-hooks/exhaustive-deps 通過
- **依賴**：A-3
- **風險**：中（改動倒數核心邏輯，依賴 E2E Test 4 回歸保護）

#### A-5. 新增兩顆按鈕（主 UI 改動）

在 circle progress 下方、倒數文字上方（或倒數文字下方 — UIUX 實作時 fine-tune）新增：

```tsx
<div className="flex gap-3 mt-6">
  <button
    type="button"
    className="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded transition"
    onClick={(e) => {
      e.stopPropagation()
      if (isCancelledRef.current) return
      isCancelledRef.current = true
      onCancel()
    }}
  >
    {__('Cancel auto-skip', 'power-course')}
  </button>
  <button
    type="button"
    className="px-4 py-2 bg-white/90 hover:bg-white text-black rounded transition"
    onClick={(e) => {
      e.stopPropagation()
      if (isCancelledRef.current) return
      isCancelledRef.current = true
      onReplay()
    }}
  >
    {__('Replay chapter', 'power-course')}
  </button>
</div>
```

- **行動**：新增兩顆按鈕，`stopPropagation` 阻止冒泡到 VidStack
- **原因**：Q1=C 決策；R4 緩解
- **依賴**：A-2, A-3
- **風險**：中（z-index 與 VidStack 互動，需瀏覽器驗證）

#### A-6. i18n 化既有倒數文字

```tsx
<div className="text-white text-base font-thin">
  {sprintf(
    // translators: %d: 倒數剩餘秒數
    __('Next chapter will play in %d seconds', 'power-course'),
    countdown
  )}
</div>
```

- **行動**：把硬編碼「下個章節將在 {countdown} 秒後自動播放」改為 `sprintf` + `__()`
- **原因**：違反 `.claude/rules/i18n.rule.md` §1（msgid 必須英文）+ §4（必用 sprintf）
- **依賴**：A-2
- **風險**：低

#### A-7. 遮罩 wrapper 也 stopPropagation

```tsx
<div
  className="absolute top-0 left-0 w-full h-full bg-black/50 flex flex-col items-center justify-center z-10"
  onClick={(e) => e.stopPropagation()}
>
```

- **行動**：最外層 div 加 `onClick={(e) => e.stopPropagation()}`
- **原因**：R4 緩解，避免點 circle progress 或倒數文字時冒泡觸發 VidStack play-toggle
- **依賴**：A-1
- **風險**：低

#### A-8. 保留既有 circle progress 點擊跳轉（但加守衛）

```tsx
<div
  className="w-12 h-12 p-2 bg-white/70 rounded-full mb-8 relative cursor-pointer"
  onClick={(e) => {
    e.stopPropagation()
    if (isCancelledRef.current) return
    window.location.href = next_post_url
  }}
>
```

- **行動**：既有「點播放圖標立刻跳下一章」功能保留；加 `isCancelledRef` 守衛（實際上點此圖標不會先觸發 cancel，但為一致性守一下）
- **原因**：維持向後相容
- **依賴**：A-3
- **風險**：低

**Phase A 成功標準**：
- TypeScript compile 通過
- 按鈕在頁面上可見且可點擊（手動 dev 驗證）
- `Ended.tsx` 無硬編碼中文字串

---

### Phase B — `Player.tsx` 整合

**目標**：傳入 callback、新增 `onSeeking` 隱性備援。

#### B-1. 定義 `handleCancelCountdown` callback

```tsx
const handleCancelCountdown = useCallback(() => {
  setIsEnded(false)
  // 影片停在片尾，不呼叫 pause／play，僅隱藏遮罩（Q3=A）
}, [])
```

- **行動**：useCallback 包裝，`setIsEnded(false)` 讓 `<Ended />` unmount
- **原因**：Q3=A 決策（影片停在片尾 paused）；VidStack 在 ended 狀態已經是 paused，不需額外 pause
- **依賴**：無
- **風險**：低

#### B-2. 定義 `handleReplay` callback

```tsx
const handleReplay = useCallback(() => {
  if (!playerRef.current) return
  setIsEnded(false)
  playerRef.current.currentTime = 0
  // play() 回傳 Promise，需捕捉 autoplay policy 的 DOMException
  void playerRef.current.play().catch(() => {
    // 靜默：若被 autoplay policy 阻擋，影片停在 0s paused，用戶可手動點擊
  })
}, [])
```

- **行動**：seek 到 0 + play；`setIsEnded(false)` 先隱藏遮罩避免 flash
- **原因**：Q1=C 決策「重看本章」；R5 風險緩解
- **依賴**：無
- **風險**：中（R5 — VidStack 行為待實作驗證）

#### B-3. 新增 `onSeeking` handler 到 `<MediaPlayer>`

```tsx
onSeeking={() => {
  // 僅在 Ended 遮罩顯示期間才中止倒數（Q5=A 隱性備援）
  // isEnded === false 時為正常 seek（例如 useChapterProgress 初始 seek），不處理
  if (isEnded) {
    handleCancelCountdown()
  }
}}
```

- **行動**：在 `onEnded` 附近加 `onSeeking` prop
- **原因**：Q5=A 決策；R1 以 `isEnded === true` 守衛消除誤觸
- **依賴**：B-1
- **風險**：中（R10 — 三個 provider 行為差異，作為備援不是主流程）

#### B-4. 傳遞 callback 到 `<Ended />`

```tsx
{isEnded && (
  <Ended
    next_post_url={next_post_url}
    onCancel={handleCancelCountdown}
    onReplay={handleReplay}
  />
)}
```

- **行動**：新增兩個 props
- **依賴**：Phase A, B-1, B-2
- **風險**：低

#### B-5. 確認 `hasAutoFinishedRef` 不被動

- **行動**：**不改**。R6 風險的關鍵就是不重置這個 ref；若 TDD 期間 green master 誤動這條，reviewer 要擋下。
- **依賴**：無
- **風險**：低（靠 review 守護）

**Phase B 成功標準**：
- TypeScript compile 通過
- 手動驗證：自然播完 → 遮罩出現；按 Cancel → 遮罩消失；按 Replay → 影片從 0s 播放；倒數中拖進度 → 遮罩消失

---

### Phase C — i18n 同步

**目標**：`.pot` / `.po` / `.mo` / JED JSON 全部同步。

#### C-1. 跑 `pnpm run i18n:pot`

- **行動**：執行 `pnpm run i18n:pot`，驗證 `languages/power-course.pot` 新增三條 msgid：
  - `Cancel auto-skip`
  - `Replay chapter`
  - `Next chapter will play in %d seconds`
- **依賴**：Phase A 完成
- **風險**：低（若 `@wp-blocks/make-pot` 未掃到，需確認 `Ended.tsx` 路徑在 `package.json` 的 scripts 對應的 include glob 內）

#### C-2. 手動更新 `languages/power-course-zh_TW.po`

新增三條 `msgstr`：

```
#: js/src/App2/Ended.tsx:??
msgid "Cancel auto-skip"
msgstr "取消自動跳轉"

#: js/src/App2/Ended.tsx:??
msgid "Replay chapter"
msgstr "重看本章"

#. translators: %d: 倒數剩餘秒數
#: js/src/App2/Ended.tsx:??
msgid "Next chapter will play in %d seconds"
msgstr "下個章節將在 %d 秒後自動播放"
```

- **行動**：手動在 `.po` 檔補上對應 msgstr（遵守術語表：沒有對應條目就自訂）
- **依賴**：C-1
- **風險**：低

#### C-3. 產生 `.mo` 與 JED JSON

```bash
pnpm run i18n:mo
pnpm run i18n:json
```

或一次跑：`pnpm run i18n:build`

- **行動**：執行指令，驗證 `languages/power-course-zh_TW.mo` 與 `languages/power-course-zh_TW-<handle>.json` 更新
- **依賴**：C-2
- **風險**：低

**Phase C 成功標準**：
- `pnpm run i18n:pot` 新字串已在 `.pot`
- zh_TW 翻譯載入時按鈕顯示「取消自動跳轉」「重看本章」

---

### Phase D — Playwright E2E

**目標**：4 條 E2E（3 新 + 1 回歸）。

#### D-1. 新建 spec 檔案

檔案：`tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts`

**檔案骨架**（完整 assertion 在 TDD coordinator 的 Red 階段撰寫，此處只列測試路徑）：

```ts
/**
 * 測試目標：Issue #206 — Ended 遮罩新增取消自動跳轉與重看本章按鈕
 * 對應規格：
 *   - specs/features/progress/續播至上次觀看秒數.feature（Rule: 續播至片尾 Ended 遮罩）
 *   - specs/features/progress/影片進度自動完成章節.feature（Rule: Ended 倒數跳轉的時序 Issue #206）
 * 澄清：specs/open-issue/clarify/2026-04-20-1748.md（Q1–Q10）
 */

import { test, expect } from '@playwright/test'
import { setupApiFromBrowser } from '../helpers/api-client.js'
import { loadFrontendTestData, loginAs } from '../helpers/frontend-setup.js'
import { WP_ADMIN } from '../fixtures/test-data.js'

test.describe('Ended 遮罩出口 — Issue #206', () => {
  test('續播到片尾按「取消自動跳轉」停留當前章節', async ({ page, browser }) => {
    // 前置：設 finished_at + last_position_seconds = 580（0.95 × 600 以上）
    // 進入章節頁，等遮罩出現（verify 按鈕可見）
    // 點擊「取消自動跳轉」
    // 斷言：遮罩消失、URL 不變、currentTime 接近 duration
    // 再等 6 秒（超過原倒數 5 秒）確認仍沒跳轉
  })

  test('續播到片尾按「重看本章」從 0 開始播放', async ({ page, browser }) => {
    // 前置：同上
    // 點擊「重看本章」
    // 斷言：遮罩消失、currentTime <= 2（±2 秒容忍）、影片 playing、URL 不變
    // 額外：確認 finished_at 未被重置（R6 保護）
  })

  test('倒數期間拖曳進度條自動中止倒數', async ({ page, browser }) => {
    // 前置：同上
    // 等遮罩出現，倒數顯示中
    // 透過 VidStack 進度條 drag 到 200s（或用 page.evaluate 直接設 currentTime）
    // 斷言：遮罩消失、倒數停止、URL 不變
  })

  test('首次觀看自然播完 5 秒倒數後自動跳下一章（回歸 Q10）', async ({ page, browser }) => {
    // 前置：清除 finished_at 與 last_position_seconds
    // 進入章節頁，播放影片（可用 playbackRate 加速或直接 seek 到 595）
    // 等自然 ended → 遮罩顯示倒數
    // 不按任何按鈕、不拖進度
    // 等 5.5 秒後斷言：URL 已變為 next_post_url
  })
})
```

#### D-2. 按鈕定位策略

用 `getByRole('button', { name: /取消自動跳轉|Cancel auto-skip/i })` 混合中英文避免 i18n 未載入時的失敗。

- **行動**：每個按鈕定位器都寫成支援雙語的 regex
- **依賴**：Phase A-C
- **風險**：低

#### D-3. 時間軸控制

- 使用 `page.evaluate` 直接呼叫 VidStack `currentTime` 屬性加速（比 playbackRate 穩定）
- 遮罩等待用 `await expect(locator).toBeVisible({ timeout: 30000 })`
- 倒數期間等待用 `await page.waitForTimeout(1500)` 等

#### D-4. 測試執行

```bash
pnpm run test:e2e:frontend -- --grep "Ended 遮罩出口"
```

**Phase D 成功標準**：4 條 tests 全綠。

---

### Phase E — Lint / Build / 驗收

#### E-1. Lint

```bash
pnpm run lint:ts
```

- 修正 exhaustive-deps 警告、import order、unused imports 等
- **風險**：低

#### E-2. TypeScript 編譯

```bash
pnpm run build
```

- 驗證無型別錯誤
- **風險**：低

#### E-3. Playwright 全跑

```bash
pnpm run test:e2e:frontend
```

- 驗證既有 015/016/017 續播測試仍綠，確認本次變更無 regression
- **風險**：低

#### E-4. PHPStan / PHPCS

- 本次無 PHP 變更，理論上不影響；但 CI pipeline 會跑，確認全綠

---

## 9. 測試策略

| 類型 | 檔案 | 覆蓋內容 |
|------|------|---------|
| E2E（新增） | `tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts` | 取消按鈕、重看按鈕、onSeeking 中止、首次觀看回歸 |
| E2E（回歸保護） | `tests/e2e/02-frontend/015-resume-playback-basic.spec.ts` 等既有續播測試 | 確認 Ended 遮罩改動未破壞基本續播 |
| E2E（回歸保護） | `tests/e2e/02-frontend/009-finish-chapter.spec.ts` | 確認 95% 自動完成 + ended 事件兩者只呼叫一次 API 的既有行為 |
| 手動 QA | — | 三個 provider（Bunny / YouTube / Vimeo）在 dev 環境各操作一次，確認 `onSeeking` 備援行為 |

### 測試執行指令

```bash
pnpm run test:e2e:frontend                                           # 全部 frontend
pnpm run test:e2e:frontend -- --grep "Issue #206"                    # 只跑本次新增
pnpm run test:e2e:frontend -- --grep "Ended 遮罩出口"                 # 同上（中文）
pnpm run test:e2e:frontend -- tests/e2e/02-frontend/018-*.spec.ts    # 指定檔案
```

### 關鍵邊界情況

- 按鈕連點（冪等性）
- 按下「重看本章」後，影片從 0 播到 95%，`hasAutoFinishedRef` 是否阻止再次 dispatch（需 R6 保護）
- 倒數剛好 `countdown === 0` 的那一 tick 按下「取消」（競態保護）
- 最後一章 `next_post_url === ''` 不顯示遮罩（既有行為）

---

## 10. 依賴項目

| 外部依賴 | 版本 | 用途 |
|---------|------|------|
| `@vidstack/react` | 已在用 | `MediaPlayer` 的 `onSeeking` event + `currentTime` 屬性 + `play()` API |
| `hls.js` | 已在用 | Bunny HLS 播放（不動） |
| `@wordpress/i18n` | 已在用 | 按鈕文案與倒數文字 |
| Playwright | 已在用 | E2E |
| `@wp-blocks/make-pot` | 已在用 | 產 .pot |

**不引入任何新 library。**

---

## 11. 風險與緩解措施

| 等級 | 風險 | 緩解 |
|------|------|------|
| 高 | R3 遮罩 z-index 被 VidStack 覆蓋 | 實作時以 Playwright 驗證按鈕可點；必要時加到 `z-20`/`z-30` |
| 中 | R5 VidStack `ended` 狀態下 `currentTime = 0` 行為不定 | 先設 `setIsEnded(false)`、再 `currentTime = 0`、最後 `play().catch()`；E2E Test 2 驗收 |
| 中 | R6 `hasAutoFinishedRef` 被誤重置 | 嚴格不動該 ref；E2E Test 2 加斷言驗證 `finished_at` 未變 |
| 中 | R1 初始 seek 觸發 `onSeeking` 誤關遮罩 | `isEnded === true` 守衛；E2E Test 4 驗證首次觀看遮罩正常出現 |
| 低 | R2 `setInterval` 競態 | `isCancelledRef` 守衛 |
| 低 | R4 按鈕 click 冒泡到 VidStack play-toggle | `stopPropagation` 雙層防護（按鈕 + wrapper） |
| 低 | R7 unmount flush | 已被 `useChapterProgress` 既有 `beforeunload` handler 處理 |
| 低 | R8 按鈕連點 | 按鈕 handler 冪等 |
| 低 | R9 controlled seeking 觸發 onSeeking 二次呼叫 | `onCancel` 冪等 |
| 低 | R10 YouTube/Vimeo 的 `onSeeking` 時機差異 | 明確按鈕為主出口，`onSeeking` 僅為備援 |

---

## 12. 錯誤處理策略

- **使用者意圖錯誤**（連點、誤觸）：callback 冪等 + `isCancelledRef` 守衛，不拋錯、不彈 dialog。
- **VidStack API 失敗**（`play()` reject）：`.catch(() => {})` 靜默；用戶仍可手動點播放。
- **setInterval 競態**：ref-based 守衛跳轉前檢查。
- **i18n 未載入**：WordPress fallback 到 msgid（英文），按鈕仍可見可點，不影響功能。
- **最後一章**：既有 `if (!next_post_url) return null` 保護，遮罩不渲染。

---

## 13. 限制條件（本計劃不會做的事）

- ❌ **不動** `useChapterProgress.ts`（Q6=A 決策）
- ❌ **不動**任何 PHP / API / DB（本 issue 純前端）
- ❌ **不重置** `hasAutoFinishedRef`（R6 保護）
- ❌ **不引入**新前端 library（Ant Design 的 Modal 或 custom component）；用純 Tailwind class 維持與現有 `Ended.tsx` 風格一致
- ❌ **不改** VidStack `z-index` 層級（除非 E2E 失敗強制要調）
- ❌ **不新增**後端設定項（遮罩倒數秒數繼續 hardcode 為 `COUNTDOWN = 5`）
- ❌ **不動**已更新的 feature 檔（Q8=C 已於澄清階段完成）
- ❌ **不為**最後一章補新 E2E（Q7=A 只要求 3 條新 + 1 條回歸，最後一章由 feature Example 文件覆蓋即可）

---

## 14. 建議的 Commit 切分（3–5 commits）

1. `feat(player): Ended 遮罩新增取消自動跳轉與重看本章按鈕` — Phase A（`Ended.tsx` 的 UI 與 props）
2. `fix(player): 修復 Ended setInterval 在取消後仍跳轉的競態` — Phase A 的 `isCancelledRef` 守衛（可與 #1 合併成一個，視情況）
3. `feat(player): 倒數期間拖曳進度條自動中止自動跳轉` — Phase B（`Player.tsx` 的 `onSeeking` + callback 整合）
4. `test(e2e): 新增 Ended 遮罩互動與首次觀看回歸測試` — Phase D
5. `chore(i18n): 同步 pot 與 zh_TW 翻譯，i18n 化 Ended 倒數文字` — Phase C

> 若 tdd-coordinator 採「小步」策略可拆成 5 commits；若採「Phase 級」可合併 1+2、3，總共 3 commits。不強制。

---

## 15. 成功標準（可逐項勾選）

- [ ] `Ended.tsx` 新增 `onCancel` / `onReplay` props 與兩顆按鈕 UI
- [ ] 按鈕文案走 `@wordpress/i18n __()`，text domain `'power-course'`，msgid 為英文
- [ ] `Ended.tsx` 原本的「下個章節將在 X 秒後自動播放」已 i18n 化
- [ ] 新增 `isCancelledRef` 守衛 `window.location.href`
- [ ] 按鈕 wrapper 與遮罩外層皆 `stopPropagation`（R4 緩解）
- [ ] `Player.tsx` 定義 `handleCancelCountdown` / `handleReplay` 並傳給 `<Ended />`
- [ ] `Player.tsx` `<MediaPlayer>` 新增 `onSeeking` handler，內部 `isEnded === true` 守衛
- [ ] `hasAutoFinishedRef` 嚴格不動
- [ ] `pnpm run i18n:pot` 後三條新字串出現在 `languages/power-course.pot`
- [ ] `languages/power-course-zh_TW.po` 已補三條繁體中文 `msgstr`
- [ ] `pnpm run i18n:mo` + `pnpm run i18n:json` 產出 `.mo` 與 JED JSON
- [ ] `tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts` 新建，4 條 tests 全綠
- [ ] 既有 015/016/017 續播 E2E 仍綠（無 regression）
- [ ] 既有 009-finish-chapter E2E 仍綠
- [ ] `pnpm run lint:ts` 全綠（無 exhaustive-deps 警告遺留）
- [ ] `pnpm run build` 全綠
- [ ] PR description 附上 VidStack `ended` 狀態下 `currentTime = 0` + `play()` 的手動驗證紀錄（Bunny / YouTube / Vimeo 三個 provider）

---

## 16. 預估複雜度：**中**

- 程式碼改動：2 個前端檔案，約 +50/-20 行
- i18n：3 條新字串
- 測試：1 個新 E2E 檔案（4 tests）+ 回歸既有測試
- 風險集中點：VidStack 的 `ended` 狀態下 `currentTime = 0` / `play()` 行為（R5）與 z-index 層疊（R3），需實作時瀏覽器驗證

---

## 17. 下一步

本計畫完成後，**直接交接** `@zenbu-powers:tdd-coordinator`：

1. **Red** 階段：先建立 `tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts` 的 4 條完整 tests（含 assertion），確認 4 條皆 fail。
2. **Green** 階段：依 Phase A → B → C 順序實作，每 Phase 結束跑 lint + E2E 相關 grep，直到 4 條新測 + 既有回歸測試全綠。
3. **Refactor** 階段：清理 exhaustive-deps 警告、確認 i18n 字串、確認 commit 切分乾淨、補 PR description。

若 tdd-coordinator 實作途中發現本計畫任何步驟與實況有落差（例如 VidStack `onSeeking` 行為不如預期、z-index 需要更高），直接回報，planner 會 patch 計畫而不是硬推進。

---

## Post-Implementation Review (2026-04-20)

**類型**：Post-test adjustment（人工瀏覽器測試後的計畫修訂）

### 人工測試反饋

Phase A/B/C/D 完成並通過 lint:ts + build 後，進入人工瀏覽器驗證時發現：

- **BUG 2-1：播放按鈕消失** — 按下「取消自動跳轉」後遮罩消失但 VidStack 在 `ended` 狀態下不顯示控制列播放按鈕，用戶被鎖在 paused at ended 無法繼續操作。
- **BUG 2-2：拖拉進度條觸發 progress API 把進度條 seek 回片尾** — 即使繞過 2-1，用戶拖拉進度條時，`useChapterProgress` 的續播 GET 會重新取得 `last_position_seconds`（接近片尾），VidStack seek 邏輯立刻把進度條拉回片尾，形成「拖一下就彈回」的死循環。

兩個 BUG 的根因都落在 VidStack 在 `ended` 狀態下的行為，而非應用層可輕易修復的層次。

### Q1 C→B 的根因分析

- **C（雙按鈕）**無法脫離 ended 狀態；「取消自動跳轉」僅隱藏遮罩，媒體層仍 ended。
- **B（僅重看）**可立即 `currentTime = 0 + play()` 脫離 ended，天然繞過 2-1 與 2-2。
- **Q2/Q3/Q5 連動廢止**：遮罩範圍策略（Q2）、取消後播放狀態（Q3）、onSeeking 隱性備援（Q5）均建立在 Q1=C 的前提上。Q5 若保留會在「重看本章」流程中誤觸（VidStack 內部 seek 也會觸發 onSeeking），或用戶拖拉時形成 2-2 的 BUG 面。

### 既有風險重新評估

| 風險 | 原評估 | post-test 評估 |
|------|--------|-----------------|
| R1（初始 seek 誤觸 onSeeking） | 中 | **N/A** — `onSeeking` 監聽已移除 |
| R2（`setInterval` 競態） | 低 | **仍適用** — `isCancelledRef` 守衛用於「重看本章」流程 |
| R3（遮罩 z-index 被 VidStack 覆蓋） | 高 | 人工測試驗證 `z-10` 足夠，維持原設定 |
| R4（按鈕點擊冒泡到 VidStack play-toggle） | 低 | **仍適用** — `stopPropagation` 雙層防護維持 |
| R5（VidStack ended 下 `currentTime = 0` 行為不定） | 中 | 人工測試驗證 `currentTime = 0 + play()` 可成功脫離 ended，但 YouTube provider 偶有 autoplay policy 阻擋（已有 `.catch(() => {})` 靜默兜底） |
| R6（`hasAutoFinishedRef` 被誤重置） | 中 | **仍適用** — 「重看本章」邏輯嚴格不動此 ref，E2E Test 1（重看後驗 finished_at）覆蓋 |
| R7 / R8 | 低 | 維持 |
| R9（controlled seeking 觸發 onSeeking 二次呼叫） | 低 | **N/A** — onSeeking 已移除 |
| R10（三個 provider onSeeking 時機差異） | 低 | **N/A** — onSeeking 已移除 |

### 影響的交付物

| 檔案 | 調整 |
|------|------|
| `js/src/App2/Ended.tsx` | 移除 `onCancel` prop、取消按鈕 JSX、`Cancel auto-skip` msgid；「重看本章」改用 daisyui v4 `btn btn-primary btn-outline` 樣式 |
| `js/src/App2/Player.tsx` | 移除 `handleCancelCountdown` callback 與 `<MediaPlayer onSeeking>` 監聽；`<Ended />` 只傳 `onReplay` |
| `tests/e2e/02-frontend/018-ended-overlay-cancel.spec.ts` | 檔案更名為 `018-ended-overlay-replay.spec.ts`；刪除 Test 1/3/5，保留 Test 2/4（改為 Test 1/2） |
| `languages/*` | 刪除 `Cancel auto-skip` msgid 與 zh_TW 翻譯（.po/.mo/.json） |
| `.claude/rules/i18n.rule.md` | 術語表刪除「取消自動跳轉」條目 |
| `specs/features/progress/*.feature` | 刪除「取消自動跳轉」與「onSeeking 中止」Examples |
| `specs/open-issue/206-ended-overlay-cancel.md` | Q1/Q2/Q3/Q5/Q7 決策更新；AC 條目縮減 |
| `specs/open-issue/clarify/2026-04-20-1748.md` | 追加「後續調整（人工測試後）」段落 |

### 最終 E2E 數量

從原計畫的 4 條（3 新增 + 1 回歸）縮減為 **2 條**：
- Test 1：「續播到片尾按『重看本章』從 0 開始播放」（含 R6 finished_at 驗證）
- Test 2：「首次觀看自然播完 5 秒倒數後自動跳下一章（回歸 Q10）」

### 成功標準調整

原成功標準清單中與「取消自動跳轉」與「onSeeking」相關的條目（第 5 條之 z-index 驗證、第 6 條之「新增 onCancel prop」、第 7 條之「onSeeking handler」）已不再適用。新版可檢核項：

- [x] `Ended.tsx` 移除 `onCancel` prop；`onReplay` 與 `isCancelledRef` 守衛保留
- [x] 「重看本章」改 daisyui v4 `btn btn-primary btn-outline`
- [x] `Player.tsx` 移除 `handleCancelCountdown` 與 `<MediaPlayer onSeeking>` 監聽
- [x] `hasAutoFinishedRef` 嚴格不動
- [x] i18n 資產移除 `Cancel auto-skip`（pot/po/mo/json）
- [x] 術語表移除「取消自動跳轉」條目
- [x] E2E 縮減為 2 條
- [x] `pnpm run lint:ts` 與 `pnpm run build` 全綠
