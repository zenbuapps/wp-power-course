@ignore @i18n
Feature: 多語系日文翻譯支援

  作為 Power Course 外掛使用者（站長 / 學員 / 管理員），
  當我所屬 WordPress 站台或個人偏好的介面語言為日文時，
  我希望看到完整、自然、術語一致的日文使用者介面，
  以便在日本市場零摩擦地使用 LMS 經營課程或學習。

  Background:
    Given Power Course 已啟用 WordPress text domain `power-course`
    And 翻譯來源檔 `scripts/i18n-translations/manual.json` 已升級為多語系結構
    And 翻譯 pipeline 已支援 locales: ["zh_TW", "ja"]
    And 已執行 `pnpm run i18n:build` 產出最新 .pot / .po / .mo / .json

  # ========== 後台日文介面 ==========

  Rule: 後台日文介面 — 站台語言設為日文時，後台 SPA 全日文呈現

    Example: 田中先生（日本站長）在日文 WordPress 後台建立課程
      Given WordPress 站台語言設定為「日本語」(`WPLANG=ja`)
      And 田中先生以管理員身份登入
      When 田中先生進入「Power Course → 課程管理」頁面
      Then 頁面 Header、Sidebar、Tab 標題、按鈕、表單 label、placeholder 全部以日文顯示
      And 不應出現繁體中文字串
      And 不應出現英文原文字串（Header / Sidebar / 主要 CTA 按鈕等高曝光位置）
      And 「Create course」按鈕應顯示為「コースを作成」
      And 「Chapter」標籤應顯示為「チャプター」
      And 「Student」標籤應顯示為「受講生」
      And 「Lesson」標籤應顯示為「レッスン」

  Rule: 後台日文介面 — 表單驗證錯誤訊息以日文顯示

    Example: 必填欄位未填時錯誤訊息為日文
      Given WordPress 站台語言設定為「日本語」
      And 田中先生在課程建立表單
      When 田中先生未填「課程名稱」就點擊「保存」
      Then 表單錯誤訊息以日文顯示
      And 訊息語意應為「コース名を入力してください」或同等敬語表達

  # ========== 前台教室日文介面 ==========

  Rule: 前台教室日文介面 — 學員 locale 為日文時，教室全日文呈現

    Example: 佐藤さん（日本學員）在教室觀看課程
      Given WordPress 用戶 `sato@example.jp` 的個人 locale 為「日本語」
      And 佐藤さん已購買並登入課程「JavaScript 入門」
      When 佐藤さん進入該課程的教室頁面
      Then 章節列表、進度提示、播放器 overlay 文字全部以日文顯示
      And 「Add to cart」應顯示為「カートに追加」
      And 「Loading video...」應顯示為「動画を読み込んでいます...」
      And 「Replay chapter」應顯示為「このチャプターをもう一度見る」
      And 「Back to My Courses」應顯示為「マイコースに戻る」

  Rule: 前台教室日文介面 — 章節完成提示為日文

    Example: 章節結束後的下一節倒數
      Given 佐藤さん完成一個章節
      When 系統顯示「下個章節將在 X 秒後自動播放」倒數
      Then 倒數文字以日文呈現（例：「次のチャプターは X 秒後に自動再生されます」）
      And 日文無單複數區分，singular 與 plural 顯示相同訊息（複數規則 nplurals=1）

  # ========== 前台銷售頁日文介面 ==========

  Rule: 前台銷售頁日文介面 — 課程介紹與購買流程全日文

    Example: 佐藤さん瀏覽課程銷售頁
      Given WordPress 站台語言為「日本語」
      And 佐藤さん尚未購買課程「Python 基礎」
      When 佐藤さん進入該課程的銷售頁
      Then 課程卡片、價格標籤、購物車按鈕、徽章（精選 / 熱門）以日文顯示
      And 「Featured course」應顯示為「おすすめコース」或符合術語表的譯法
      And 「Popular course」應顯示為「人気コース」或符合術語表的譯法
      And 「Free course」應顯示為「無料コース」
      And 「Enroll now」應顯示為「今すぐ申し込む」

  # ========== 缺翻譯 fallback ==========

  Rule: 缺翻譯 fallback — 未翻譯字串以英文 msgid 顯示，不漏為其他語言

    Example: 某條字串未在 manual.json 補齊 msgstr_ja
      Given `scripts/i18n-translations/manual.json` 中某 entry 的 `msgstr_ja` 為空字串
      And 站台語言為「日本語」
      When 該字串於前台被 render
      Then 應顯示英文 msgid 原文（例：「Failed to delete course data」）
      And 不應顯示繁體中文翻譯
      And 不應顯示空字串或 placeholder（如 [missing translation]）

  # ========== 翻譯覆蓋率 ==========

  Rule: 翻譯覆蓋率 100% — 首次上線時 1,314 條 msgid 全部翻譯完成

    Example: build pipeline 產出後檢查覆蓋率
      Given Power Course 共有 N 條可翻譯字串（以最新 `.pot` 為準，初版約 1,314 條）
      When 執行 `pnpm run i18n:build`
      Then `languages/power-course-ja.po` 中所有非 plugin header 的 entries 都有非空的 msgstr
      And `languages/power-course-zh_TW.po` 中所有非 plugin header 的 entries 都有非空的 msgstr
      And build 腳本 console 輸出 `未翻譯（空 msgstr）: 0` 對兩個 locales 皆成立

  # ========== 多語系 pipeline 一致性 ==========

  Rule: 多語系 pipeline 一致性 — 一個指令同時產出多語系翻譯

    Example: 開發者執行 i18n:build
      Given 開發者在本地修改了 PHP / JSX 內的某個 msgid 並補上 manual.json 的兩語系翻譯
      When 執行 `pnpm run i18n:build`
      Then 應依序執行 i18n:pot → i18n:merge → i18n:mo → i18n:json
      And `languages/power-course.pot` 應包含新 msgid
      And `languages/power-course-zh_TW.po` 與 `languages/power-course-ja.po` 應同時更新
      And `languages/power-course-zh_TW.mo` 與 `languages/power-course-ja.mo` 應同時產出
      And `languages/power-course-zh_TW.json` 與 `languages/power-course-ja.json` 應同時產出
      And 整體 pipeline 不應 hardcode 任何單一 locale

  Rule: 多語系 pipeline 一致性 — 新增第三語系（韓文）的擴展性

    Example: 假想未來新增韓文支援
      Given 開發者要新增 ko_KR 支援
      When 開發者只新增 manual.json 的 `msgstr_ko_KR` 欄位 + 修改 build 腳本的 `LOCALES` 常數加入 `'ko_KR'`
      Then 不需要新增另一隻 build 腳本
      And `pnpm run i18n:build` 應自動產出 `languages/power-course-ko_KR.{po,mo,json}`

  # ========== 多語系並存 ==========

  Rule: 多語系並存無干擾 — 站台語言與用戶 locale 各自獨立生效

    Example: 陳先生（台灣管理員）與佐藤さん（日本學員）同站台
      Given 站台預設 locale 為「繁體中文」(`WPLANG=zh_TW`)
      And 陳先生的個人 locale 為「繁體中文」
      And 佐藤さん的個人 locale 為「日本語」
      When 陳先生登入後台
      Then 陳先生看到的後台介面為繁體中文
      When 佐藤さん登入前台教室
      Then 佐藤さん看到的前台教室介面為日文
      And 陳先生與佐藤さん的介面不互相干擾

  Rule: 多語系並存無干擾 — 新增日文不破壞既有繁中翻譯

    Example: 既有 zh_TW 153 條人工翻譯不被覆寫
      Given `scripts/i18n-translations/manual.json` 中既有 153 條 `msgstr_zh_TW` 為人工翻譯
      When 本 issue 完成後 manual.json 新增 `msgstr_ja` 欄位 + LLM 補齊全部 1,314 條
      Then 既有 153 條 `msgstr_zh_TW` 內容應與本 issue 開工前完全相同（無修改）
      And 新補的 1,161 條 zh_TW msgstr 應由 LLM 產出並可辨識（後續可由人工 review）

  # ========== 用戶語言偏好 ==========

  Rule: 用戶語言偏好 — WordPress User locale 機制正確生效

    Example: 用戶在個人資料切換為日文
      Given 用戶 `tanaka@example.jp` 在 WordPress 後台「使用者 → 個人資料」
      When 用戶將「網站語言（語言設定）」改為「日本語」並儲存
      Then 該用戶下次重新整理任何 Power Course 頁面時應顯示日文介面
      And 其他用戶不受影響

  # ========== 發版整合 ==========

  Rule: 發版整合 — release zip 必須包含日文翻譯檔

    Example: 執行 release 時 zip 內容檢查
      Given 開發者執行 `pnpm run zip` 或 `pnpm run release`
      When 產出的 zip 解壓
      Then `languages/power-course-ja.po`、`languages/power-course-ja.mo`、`languages/power-course-ja.json` 三個檔皆存在於 zip 中
      And 既有 `languages/power-course-zh_TW.{po,mo,json}` 三個檔仍存在於 zip 中
      And `languages/power-course.pot` 仍存在於 zip 中
