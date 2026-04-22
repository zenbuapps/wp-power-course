# Step G — Playwright E2E 補測

> 對應 Spec §6 Step G｜依賴：Step F｜風險：低｜預估：2.0h｜建議執行者：`@zenbu-powers:test-creator`
>
> **可延後至 Phase 06 獨立 PR**，不阻擋 Step A~F 的合併。

## 目標

依 `specs/features/student/透過彈窗挑選用戶加入課程.feature` 補 Playwright E2E，覆蓋最關鍵的成功路徑與邊界情況。

## 變更檔案

- `tests/e2e/admin/`（新增測試檔，建議命名 `course-students-add-flow.spec.ts`）

## 測試案例（至少 2 條）

### G-1：成功加入（含到期日）

> 對應 feature 後置狀態 Rule 1/2/3（清空 UI + invalidate + success message）

```
GIVEN 以管理員登入
AND  有一門 publish 課程 100，有學員 Bob（未加入本課程）
WHEN 打開 /admin courses edit 100 的「課程學員」tab
AND  在 Filter 搜尋 "Bob"
AND  勾選 Bob 這個 row
AND  選 DatePicker 為 2027-01-01 00:00
AND  點「Add 1 students to this course」
THEN 顯示 message「Students added successfully」（或 zh_TW「已成功加入學員」）
AND  上方 UserTable 看不到 Bob
AND  下方 StudentTable 看得到 Bob，且到期日顯示 2027-01-01
AND  勾選區為空，DatePicker 為空
```

### G-2：未選 DatePicker = 永久開通

> 對應 feature 前置參數 Rule 3（`expire_date=0`）

```
GIVEN 以管理員登入
AND  有一門 publish 課程 100，有學員 Carol（未加入本課程）
WHEN 打開 /admin courses edit 100 的「課程學員」tab
AND  搜尋並勾選 Carol
AND  不動 DatePicker
AND  攔截 POST /power-course/v2/courses/add-students 的 request
AND  點按鈕
THEN 攔截到的 payload.expire_date === 0
AND  下方 StudentTable 顯示 Carol 的到期日為「永久」或等價 UI
```

## 驗收指令

```bash
pnpm run test:e2e:admin                  # 執行 admin E2E suite
# 或僅跑本檔
npx playwright test tests/e2e/admin/course-students-add-flow.spec.ts
```

## 驗收條件

- [ ] 兩條新測試全綠
- [ ] `tests/e2e/admin/` 既有測試未受影響
- [ ] 測試命名遵 `kebab-case.spec.ts` 慣例
- [ ] 使用既有 fixtures（WP Admin login、測試課程 seed）若有；無則補 `beforeEach` 做必要前置

## 可選擴充（若時間允許）

- G-3：失敗路徑（mock 500 或斷網）驗保留勾選 + DatePicker
- G-4：DatePicker 過去日期 disabled（UI 驗）
- G-5：零勾選時按鈕 disabled（UI 驗）
- G-6：`courseId` 尚未解析時不發 API（通常 Refine 會自動處理，可略）

## 延後理由（若推到 Phase 06）

- Step F 完成後，Step A~F 已可獨立交付並在 dev 環境手動驗收
- E2E 測試要做 seed / fixture / login helper 維護，時間成本高
- 本 Issue 優先交付 UI + mutation，E2E 作為補強

> 若選擇推後，**Spec §3.4 E2E 驗收在 Phase 06 Issue 中記錄為待補**，不列入本 PR checklist。
