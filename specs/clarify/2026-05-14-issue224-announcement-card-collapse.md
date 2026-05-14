# Clarify Session 2026-05-14 — Issue #224

## Idea

### 標題：[UI] 課程銷售頁公告卡片內文折疊

公告內文太長時（> 3 行）應該要有收合 / 開啟的功能。如果小於等於 3 行，就直接顯示，不顯示收合 / 開啟功能。

### 現況掃描

| 觀察項目 | 內容 |
|---------|------|
| 公告卡片渲染入口 | `inc/templates/pages/course-product/announcement.php`（前台 PHP 模板，foreach 直接渲染所有卡片，**無任何折疊邏輯**） |
| 公告區塊層級 feature | `specs/features/announcement/銷售頁公告區塊顯示.feature`（**只規範多則之間的手風琴**，未規範單張卡片內文折疊） |
| 既有可參考元件 | `inc/templates/components/typography/paragraph/expandable.php` + `inc/assets/src/events/toggleContent.ts`（**用固定 px 高度判斷**，不是行數判斷） |
| 既有 i18n msgid | `Expand content` / `Collapse`（已存在於 `languages/power-course.pot`，可直接複用） |

### 與既有 feature 的關係

- `銷售頁公告區塊顯示.feature`：**多則公告之間**的手風琴（最新展開、其餘折疊）
- 本 Issue 處理：**單則公告卡片內文**的行數折疊（line-clamp）
- 兩者層級不同，獨立成新 feature 檔避免混淆

## Q&A（用戶確認 `A A A A A A B`）

- **Q1 [情境] 「3 行」判斷依據**：**A — 視覺行**（CSS `line-clamp: 3` + JS `scrollHeight > clientHeight` 量測）
  - 理由：截圖場景明顯是「視覺上太長」；line-clamp 是現代標準解法，行為穩定
  - 否決 C（固定 px 高度）：不同字級 / 設備行數會跑掉
- **Q2 [情境] 預設狀態**：**A — 預設折疊**，只顯示 3 行 + 「展開全文」按鈕
  - 理由：Issue 字面就是想避免「一進頁面就被長文淹沒」
  - 注意：這是「單張卡片內文」的預設狀態，與「多則之間手風琴」的預設狀態（最新展開、其餘折疊）是兩個獨立層級
- **Q3 [情境] 切換按鈕文案與位置**：**A — 沿用 `expandable.php` 設計**，「Expand content / Collapse」+ 漸層遮罩 fade-out，按鈕置中下方
  - 理由：與既有元件視覺一致；i18n msgid 已存在可直接複用
- **Q4 [情境] ≤ 3 行時行為**：**A — 完全不渲染按鈕**，無漸層遮罩，卡片高度 fit content
  - 理由：Issue 明確規定「不顯示收合 / 開啟功能」，A 是最直接的字面實作
- **Q5 [情境] 套用範圍**：**A — 只套用課程銷售頁** `inc/templates/pages/course-product/announcement.php`
  - 理由：Issue 標題明確指定 [UI] 課程銷售頁公告；教室公告 / Email 不在範圍內
- **Q6 [工程] 實作技術**：**A — CSS `line-clamp` + 純 vanilla TS（jQuery）**
  - 新增 `inc/assets/src/events/announcementToggle.ts`，沿用既有 Bootstrap 註冊模式
  - 理由：與既有前台架構（PHP 模板 + jQuery 風格 vanilla TS）一致；line-clamp 是 3 行折疊的標準作法
- **Q7 [情境] 行數常數策略**：**B — 預設 3 行 + `apply_filters('pc_announcement_collapse_lines', 3)`**
  - 理由：預設符合 Issue 需求，同時用 filter 給進階站長 / 開發者留調整空間（成本極低）
  - 否決 C（後台設定 UI）：過度設計，本 Issue 未要求

## 實作方案摘要

### PHP 端（`inc/templates/pages/course-product/announcement.php`）

1. 取得行數常數：`$collapse_lines = (int) apply_filters('pc_announcement_collapse_lines', 3);`
2. 每張卡片內文容器加上：
   - `class="pc-announcement-content"`
   - `data-collapse-lines="<?php echo esc_attr($collapse_lines); ?>"`
   - `aria-expanded="false"`
   - 唯一 `id` 供 `aria-controls` 指向
   - inline style `--pc-collapse-lines: <?php echo $collapse_lines; ?>` 給 CSS line-clamp 用
3. 內文容器後方輸出切換按鈕 + 漸層遮罩，但**只在 DOM 上輸出**，初始 `display: none`；JS 量測後若 scrollHeight > clientHeight 才顯示

### CSS（隨同 `announcement.php` 或 `inc/assets/src/scss/`）

```css
.pc-announcement-content {
  display: -webkit-box;
  -webkit-line-clamp: var(--pc-collapse-lines, 3);
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.pc-announcement-content[aria-expanded="true"] {
  -webkit-line-clamp: unset;
  overflow: visible;
}
```

### JS 端（新增 `inc/assets/src/events/announcementToggle.ts`）

1. DOM ready 後遍歷所有 `.pc-announcement-content`
2. 量測 `el.scrollHeight > el.clientHeight + 1`（+1 容差）判定是否超出
3. 若超出 → 顯示對應切換按鈕；若沒超出 → 直接移除按鈕與漸層遮罩 DOM 節點
4. 切換按鈕 click handler：toggle `aria-expanded`，更新按鈕文字（`__('Expand content' / 'Collapse', 'power-course')`）
5. 沿用既有 `index.ts` 的 Bootstrap 註冊模式
6. 監聽 `window.resize`（debounced 200ms）重新量測，避免轉向後行數變化

### i18n

- 不引入新 msgid，沿用既有 `Expand content` / `Collapse`
- `inc/assets/src/events/announcementToggle.ts` import `__` from `@wordpress/i18n`

## 不在本 Issue 範圍

- 教室頁面的公告區塊（如有）
- Email 通知摘要的公告呈現
- 後台公告預覽 / 列表頁
- 後台設定 UI 讓站長調整行數（用 `apply_filters` 替代）

## 產出規格檔案

- `specs/features/announcement/銷售頁公告卡片內文折疊.feature`（新增）
- `specs/clarify/2026-05-14-issue224-announcement-card-collapse.md`（本檔）
