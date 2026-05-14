@ignore @query @ui
Feature: 銷售頁公告卡片內文折疊

  延伸自 Issue #224：課程銷售頁的公告卡片，當內文渲染後超過 3 行視覺行時，
  應折疊只顯示前 3 行並附「展開全文 / 收合」切換；≤ 3 行時直接完整顯示，
  不渲染任何折疊控制元件。

  **與「銷售頁公告區塊顯示.feature」的層級區隔：**
  - `銷售頁公告區塊顯示.feature` 處理「多則公告之間」的手風琴（最新展開、其餘折疊）
  - 本 feature 處理「單則公告卡片內文」的行數折疊（line-clamp）
  - 兩者可同時生效：例如最新一則公告手風琴展開，但其內文若 > 3 行仍會被內部折疊起來

  **Issue #224 設計決策（已確認 A A A A A A B）：**
  - Q1 「3 行」判斷依據：**視覺行**（CSS `line-clamp: 3` + JS `scrollHeight > clientHeight` 量測）
  - Q2 預設狀態：**預設折疊**，只顯示 3 行 + 「展開全文」按鈕
  - Q3 切換文案與位置：沿用 `expandable.php` 設計，「Expand content / Collapse」+ 漸層遮罩 fade-out，按鈕置中下方
  - Q4 ≤ 3 行時行為：**完全不渲染按鈕**，無漸層遮罩，卡片高度 fit content
  - Q5 套用範圍：**僅課程銷售頁** `inc/templates/pages/course-product/announcement.php`
  - Q6 實作技術：**CSS `line-clamp` + 純 JS vanilla TS**（jQuery），新增 `inc/assets/src/events/announcementToggle.ts`
  - Q7 行數常數：**預設 3 行 + `apply_filters('pc_announcement_collapse_lines', 3)`** 留客製空間

  Background:
    Given 系統中有以下用戶：
      | userId | name      | email                | role          |
      | 10     | EnrolledA | enrolledA@test.com   | subscriber    |
      | 99     | Guest     | guest@test.com       | subscriber    |
    And 系統中有以下課程：
      | courseId | name       | _is_course | status  | type     |
      | 100      | PHP 基礎課 | yes        | publish | simple   |
      | 101      | 外部課程   | yes        | publish | external |
    And 學員 EnrolledA 已被加入課程 100
    And 系統當下時間為 "2026-05-14 10:00:00"

  # ========== 折疊觸發條件（按渲染後視覺行數判定） ==========

  Rule: 後置（顯示）- 公告內文渲染後 ≤ 3 行時直接完整顯示，不渲染折疊控制元件

    Example: 單行短文
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content      | post_date           | post_status |
        | 300            | 簡短公告   | <p>今日休館</p>   | 2026-05-13 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 300 的卡片內文應完整顯示
      And 公告 300 的卡片中不應出現 `.pc-announcement-toggle` 切換按鈕
      And 公告 300 的卡片內文容器不應套用 `line-clamp` style

    Example: 剛好 3 行
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content                                   | post_date           | post_status |
        | 301            | 三行公告   | <p>第一行<br>第二行<br>第三行</p>              | 2026-05-13 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      And 渲染後內文容器 scrollHeight 等於 clientHeight
      Then 公告 301 的卡片中不應出現 `.pc-announcement-toggle` 切換按鈕
      And 公告 301 的卡片內文應完整顯示

  Rule: 後置（顯示）- 公告內文渲染後 > 3 行時應折疊只顯示前 3 行並渲染「展開全文」按鈕

    Example: 四行公告觸發折疊
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content                                                   | post_date           | post_status |
        | 302            | 四行公告   | <p>第一行<br>第二行<br>第三行<br>第四行</p>                    | 2026-05-13 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      And 渲染後內文容器 scrollHeight 大於 clientHeight
      Then 公告 302 的卡片內文應套用 CSS `-webkit-line-clamp: 3`
      And 公告 302 的卡片應渲染 `.pc-announcement-toggle` 切換按鈕
      And 切換按鈕文字應為 `Expand content`（i18n key 沿用既有 `expandable.php`）
      And 內文下方應有漸層遮罩 `bg-gradient-to-t from-base-100` 元素

    Example: 長段落 wrap 後超過 3 行也應折疊
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content                            | post_date           | post_status |
        | 303            | 長段落公告 | <p>很長的單一段落不換行但寬度自然 wrap...（內容約佔 5 視覺行）</p> | 2026-05-13 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      And 渲染後內文容器 scrollHeight 大於 clientHeight
      Then 公告 303 的卡片應渲染 `.pc-announcement-toggle` 切換按鈕

  # ========== 預設狀態 ==========

  Rule: 後置（顯示）- 超過 3 行的公告，首次進頁面時預設折疊（只顯示前 3 行）

    Example: 多則公告各自獨立判定預設狀態
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content                                   | post_date           | post_status |
        | 310            | 短公告 A   | <p>一行</p>                                    | 2026-05-13 11:00:00 | publish     |
        | 311            | 長公告 B   | <p>第一行<br>第二行<br>第三行<br>第四行<br>第五行</p> | 2026-05-13 10:00:00 | publish     |
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 310 的卡片內文應完整顯示且無切換按鈕
      And 公告 311 的卡片內文應折疊（只顯示前 3 行）
      And 公告 311 的切換按鈕應顯示 `Expand content`
      And 公告 311 的內文容器 `aria-expanded` 應為 `false`

  # ========== 互動行為 ==========

  Rule: 後置（顯示）- 點擊「展開全文」按鈕後切換為完整顯示並更新按鈕文字

    Example: 點擊展開
      Given 課程 100 有公告 312（內文 5 行，目前折疊）
      When 訪客點擊公告 312 的 `.pc-announcement-toggle` 按鈕
      Then 公告 312 的內文容器應移除 `line-clamp` 限制
      And 公告 312 的內文容器 `aria-expanded` 應為 `true`
      And 切換按鈕文字應變為 `Collapse`
      And 漸層遮罩應移除（或淡出）

    Example: 點擊收合
      Given 課程 100 有公告 313（內文 5 行，目前已展開）
      When 訪客點擊公告 313 的 `.pc-announcement-toggle` 按鈕
      Then 公告 313 的內文容器應重新套用 `-webkit-line-clamp: 3`
      And 公告 313 的內文容器 `aria-expanded` 應為 `false`
      And 切換按鈕文字應變回 `Expand content`
      And 漸層遮罩應重新顯示

  Rule: 後置（顯示）- 多則公告的卡片折疊狀態彼此獨立

    Example: 同時展開兩張卡片
      Given 課程 100 有以下公告：
        | announcementId | post_title | post_content                            | post_date           | post_status |
        | 320            | 公告 X     | <p>X1<br>X2<br>X3<br>X4<br>X5</p>       | 2026-05-13 11:00:00 | publish     |
        | 321            | 公告 Y     | <p>Y1<br>Y2<br>Y3<br>Y4<br>Y5</p>       | 2026-05-13 10:00:00 | publish     |
      And 訪客瀏覽課程 100 的銷售頁，兩張卡片內文預設折疊
      When 訪客先後點擊公告 320 與公告 321 的 `.pc-announcement-toggle`
      Then 公告 320 與公告 321 兩張卡片內文都應為完整顯示
      And 兩張卡片的切換按鈕都應顯示 `Collapse`

  # ========== 套用範圍 ==========

  Rule: 後置（顯示）- 行數折疊只套用於課程銷售頁，不影響其他位置的公告顯示

    Example: 銷售頁套用折疊
      Given 課程 100 有公告 330（內文 5 行）
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 330 的卡片應渲染 `.pc-announcement-toggle` 切換按鈕

    Example: 後台預覽 / Email 通知摘要不套用折疊
      Given 公告 330 同時出現在後台公告列表或 Email 摘要
      When 系統渲染這些非銷售頁的場景
      Then 公告 330 在這些場景的內文應完整顯示（不套用 `line-clamp`）
      And 不渲染 `.pc-announcement-toggle` 切換按鈕

  Rule: 後置（顯示）- 外部課程（External Course）的銷售頁同樣套用折疊規則

    Example: 外部課程銷售頁
      Given 課程 101 有公告 340（內文 5 行）
      When 訪客瀏覽課程 101 的銷售頁
      Then 公告 340 的卡片內文應折疊
      And 公告 340 的卡片應渲染 `.pc-announcement-toggle` 切換按鈕

  # ========== 行數常數可客製 ==========

  Rule: 後置（顯示）- 預設行數為 3，並可透過 `apply_filters('pc_announcement_collapse_lines', 3)` 客製

    Example: 預設 3 行
      Given 主題 / 外掛未掛載 `pc_announcement_collapse_lines` filter
      When 訪客瀏覽含長公告的銷售頁
      Then 卡片內文容器應套用 `-webkit-line-clamp: 3`

    Example: 客製為 5 行
      Given 主題在 `functions.php` 掛載 filter `pc_announcement_collapse_lines` 回傳 5
      And 課程 100 有公告 350（內文 6 行）
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 350 的卡片內文容器應套用 `-webkit-line-clamp: 5`
      And 因 6 行 > 5 行，公告 350 仍渲染 `.pc-announcement-toggle` 切換按鈕

    Example: 客製為 10 行（公告內文 5 行）
      Given 主題掛載 filter `pc_announcement_collapse_lines` 回傳 10
      And 課程 100 有公告 351（內文 5 行）
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 351 的卡片中不應出現 `.pc-announcement-toggle` 切換按鈕
      And 公告 351 的內文應完整顯示

  # ========== 響應式與可及性 ==========

  Rule: 後置（顯示）- 折疊狀態在手機端與桌面端皆正常運作

    Example: 手機端折疊與展開
      Given 課程 100 有公告 360（內文桌面 4 行、手機 8 行）
      When 訪客在手機端（width=375px）瀏覽課程 100 的銷售頁
      Then 公告 360 的卡片內文應折疊
      And 公告 360 的切換按鈕應可點擊並切換折疊/展開狀態

  Rule: 後置（顯示）- 切換按鈕具備鍵盤可及性與 ARIA 屬性

    Example: ARIA 屬性
      Given 課程 100 有公告 370（內文 5 行）
      When 訪客瀏覽課程 100 的銷售頁
      Then 公告 370 的 `.pc-announcement-toggle` 切換按鈕應為 `<button>` 元素
      And 切換按鈕應綁定 `aria-controls` 指向內文容器 id
      And 內文容器應有 `aria-expanded="false"` 屬性
      And 切換按鈕應可透過 Tab 鍵聚焦並以 Enter / Space 觸發

  # ========== i18n ==========

  Rule: 後置（顯示）- 切換按鈕文案沿用既有 i18n msgid，可直接複用翻譯

    Example: msgid 沿用 expandable.php
      Given 系統已存在 i18n msgid `Expand content` 與 `Collapse`（domain `power-course`）
      When 切換按鈕渲染文字
      Then 折疊狀態時按鈕文字 = `__('Expand content', 'power-course')`
      And 展開狀態時按鈕文字 = `__('Collapse', 'power-course')`
      And 不應引入新的 i18n msgid
