# Issue #216 — 子章節拖曳不生效 + 編輯子章節後父子關係消失（實作計劃）

> 範圍模式：**HOLD SCOPE**（Bug fix；範圍由 Q1–Q7 釐清結果鎖死，不擴張）
> 預估影響：~10 個檔案（3 PHP / 1 TSX / 1 文件 / 5 測試）
> 對應規格：`specs/features/chapter/排序章節.feature`、`更新章節.feature`、`章節CPT結構與層級.feature`
> 對應釐清：`specs/clarify/2026-05-04-issue216-chapter-drag-sort-fix.md`

---

## 1. 問題重述（Recap）

兩個獨立但相關的後台 bug：

| Bug | 症狀 | 根因 |
|----|----|----|
| #1a | 拖曳排序後 UI 彈回舊位置 | `@ant-design/pro-editor` `<SortableTree>` 的內部 state 與 React `treeData` 脫節，下次拖曳用舊 `from_tree` 計算 diff 失效 |
| #1b | F5 後仍可能拿到舊資料 | LiteSpeed / Cloudflare 邊緣快取了 `GET /wp-json/power-course/chapters` 回應 |
| #1c | reload 後 drag library 用空資料初始化 | 同 #1a，需要在 chapters API 真正抵達後才初始化 SortableTree |
| #2 | 編輯子章節後 `post_parent` 變回 0 | `Utils::sort_chapters()` 用 raw `$wpdb->query()` 寫入 `post_parent`，未呼叫 `clean_post_cache()`，導致下游 `wp_get_post_parent_id()` 從 stale object cache 讀到 `0`，`wp_insert_post_data` filter 因而清空 parent |

修復策略（依釐清 Q1=A、Q2=A、Q3=D、Q4=A、Q5=C、Q6=D、Q7=B）：
- 後端 — `clean_post_cache()` 對所有 batch 異動的章節逐筆清 cache；REST callbacks 注入 `nocache_headers()`
- 前端 — `<SortableTree key={treeVersion}>` 排序成功後遞增重置；`onError` 還原 `originTree`；編輯子章節後自動展開父章節

---

## 2. 範圍模式判定

| 維度 | 結果 |
|---|---|
| 任務類型 | Bug fix（已有規格、已有根因） |
| Greenfield? | No |
| 預估檔案數 | 10（3 PHP + 1 TSX + 5 test + 1 doc） |
| 模式 | **HOLD SCOPE** |
| 模式漂移風險 | 低 — 規格已凍結 7 個釐清答案；切勿擴張到「重寫整個 Chapter Resource」 |

---

## 3. 資料流分析（Bug #2 根因鏈）

```
[1] sort_chapters( from_tree, to_tree )
       │
       ├── $wpdb->query("UPDATE wp_posts SET post_parent = CASE ... WHERE ID IN (...)")
       │     ↑ 直接寫 DB，未走 wp_update_post，未觸發 clean_post_cache()
       │
       ├── (現況) wp_cache_flush_group('posts')      ← 部分 object cache 實作為 no-op
       └── (現況) wp_cache_flush_group('post_meta')  ← 同上
                                                       ↓
[2] 使用者立刻在同一 session 編輯該子章節
       │
       └── wp_update_post( ['ID'=>201, 'post_title'=>'…'] )
              │
              └── apply_filters('wp_insert_post_data', $data, $postarr)
                    │
                    └── （WP core / Powerhouse / 第三方）讀取 wp_get_post_parent_id(201)
                          │
                          └── get_post(201)  →  wp_cache_get(201, 'posts')
                                                 ↑ STALE: 仍持有 post_parent = 0
                          回傳 0
                    Filter 判斷「沒有 parent」→ 回寫 post_parent = 0   ❌

[3] 修復：在 sort_chapters() 的 raw UPDATE 與 COMMIT 後逐筆呼叫
       clean_post_cache( $id )
       這會：
         - 清 'posts' / 'post_meta' / children cache
         - bump last_changed
         - do_action('clean_post_cache', $id, $post)  ← 讓其他外掛同步
       之後 [2] 的 wp_get_post_parent_id 會直接走 DB，拿到正確的 200。
```

---

## 4. 實作計劃（依依賴順序）

### Phase A：後端修復（PHP）— 優先

> 修復 Bug #1b（nocache_headers）+ Bug #2（clean_post_cache）
> 這是整個修復的根因；前端任何補強都依賴這層先正確。

#### A1. `inc/classes/Resources/Chapter/Utils/Utils.php`

**`sort_chapters()` 補上 `clean_post_cache()`**

具體修改（位置：line ~410，TRANSACTION COMMIT 之後）：

```php
// 提交事務
$wpdb->query('COMMIT');

// 【新增】逐筆清除 post object cache
// 對所有被異動的 post_id（含每一個 batch 的 50 筆）呼叫 clean_post_cache，
// 觸發 'clean_post_cache' action，讓 WP core / Powerhouse / 第三方 hook 同步。
$all_updated_ids = array_map(
    static fn ( array $node ): int => (int) $node['id'],
    $new_to_tree
);
foreach ( $all_updated_ids as $updated_id ) {
    \clean_post_cache( $updated_id );
}

// 【保留】flush group 作為 group cache 的補強（部分 object cache 實作不支援 group flush）
\wp_cache_flush_group('posts');
\wp_cache_flush_group('post_meta');
```

**注意事項：**
- 必須在 `COMMIT` 之後才清，避免 race condition 中 cache 又被 stale-read 寫回
- 對 `$delete_ids`（被 `wp_trash_post` 移除的 ID）不需要額外清 cache，`wp_trash_post()` 內部已呼叫 `clean_post_cache()`
- 既有的 `wp_cache_flush_group()` 不刪除（保留作為 group cache 的補強，例如 Memcached 不支援個別 cache key 失效時的 fallback）

**對 `update_chapter()` 不需異動**：`wp_update_post()` 內部會自動觸發 `clean_post_cache()`，故 Bug #2 只需修 `sort_chapters()`。

#### A2. `inc/classes/Resources/Chapter/Core/Api.php`

**所有 6 個 callback 開頭注入 `nocache_headers()`**

涵蓋：
- `get_chapters_callback`
- `post_chapters_callback`
- `delete_chapters_callback`
- `post_chapters_sort_callback`
- `post_chapters_with_id_callback`
- `delete_chapters_with_id_callback`
- `post_toggle_finish_chapters_with_id_callback`

實作方式（**選 B** — 集中在 callback 開頭呼叫，不改 `ApiBase`）：

```php
public function get_chapters_callback( $request ) {
    \nocache_headers();   // ← 新增於每個 callback 第一行
    // ... 原本邏輯
}
```

**為什麼不改 ApiBase？**
- `ApiBase` 來自 `vendor/j7-dev/wp-utils`（被多個外掛共用），動 vendor 風險大
- 集中在 6 個 callback 第一行，diff 清晰、易審查、易回滾

#### A3. `inc/classes/Resources/Chapter/Core/SubtitleApi.php`

**同 A2** — 字幕 API 同樣屬於 `power-course` namespace，也需注入 `nocache_headers()`。
（待 wordpress-master 確認此檔案 callback 數量；若僅 1-2 個 callback，照辦即可）

#### A4. （可選）`inc/classes/Api/*.php` 其他 power-course REST 端點

範圍判斷：
- 規格 `章節CPT結構與層級.feature` 的 nocache rule 寫的是「power-course namespace 的 REST API」
- 嚴格遵守需擴及 `Api/Course.php`、`Api/Comment.php`、`Api/Product.php`、`Api/Option.php`、`Api/Shortcode.php`、`Api/Upload.php`、`Api/User.php`、`Api/Mcp/RestController.php`、`Api/Reports/Revenue/Api.php`、`Resources/Settings/Core/Api.php`、`Resources/Student/Core/Api.php`、`Resources/ChapterProgress/Core/Api.php`、`PowerEmail/Resources/Email/Api.php`（共 13 個 API 類）

**HOLD SCOPE 選擇：本 issue 範圍只涵蓋章節相關 API**
- A2 (Chapter Api) + A3 (SubtitleApi) 為必做
- 其他 API 留待後續 issue（會在交接時明確標記為 deferred）
- 文件補強同步註明站長可在 LiteSpeed 排除整個 `/wp-json/power-course/*` 作為 cache 雙保險

---

### Phase B：前端修復（React/TypeScript）

> 依賴 Phase A 完成才有意義（後端不修，前端再怎麼遞增 key 都會拿到 stale 資料）。

#### B1. `js/src/components/course/SortableChapters/index.tsx`

**新增：`treeVersion` state + key 重置機制（Bug #1a / 1c）**

```tsx
const SortableChaptersComponent = () => {
    // ...
    // 【新增】SortableTree 的版本號，用於每次排序成功後強制 unmount/remount
    const [treeVersion, setTreeVersion] = useState(0)
    // ...

    const handleSave = (data) => {
        // ...
        mutate({ /* ... */ }, {
            onSuccess: () => {
                message.success({ /* ... */ })
            },
            onError: () => {
                message.error({                            // 【改】loading → error（Q4）
                    content: __('Failed to save sort order', 'power-course'),
                    key: 'chapter-sorting',
                })
                setTreeData(originTree)                    // 【新增】還原視覺位置
            },
            onSettled: () => {
                invalidate({ /* ... */ })
                setTreeVersion(v => v + 1)                 // 【新增】強制 SortableTree 重 mount
            },
        })
    }

    // ...
    {!isListLoading && (
        <SortableTree
            key={treeVersion}                              // 【新增】key 變動觸發 remount
            // ... 其餘 props 不變
        />
    )}
}
```

**為什麼 `onSettled` 而非 `onSuccess`？**
- 失敗時也要 remount，避免 library 內部 state 殘留拖曳中的暫態
- 失敗 + remount + `setTreeData(originTree)` 一起，能確保視覺與資料完全對齊

**展開狀態保留**：
- 既有的 `restoreOriginCollapsedState(chapterTree, openedNodeIds)` 仍然會在 `useEffect([isListFetching])` 中跑，把展開節點 ID 回填
- key 重置觸發 SortableTree 重新 mount 後，新一輪 effect 會把 `collapsed = false` 套用到對應節點

#### B2. `js/src/components/course/SortableChapters/index.tsx`（同檔案，續）

**新增：編輯子章節後自動展開父章節（Q5）**

`ChapterEdit` 儲存後會 `invalidate(['chapters'])` → `useList` refetch → `useEffect([isListFetching])` 重跑。在 effect 內補上：

```tsx
useEffect(() => {
    if (!isListFetching) {
        const chapterTree = chapters?.map(chapterToTreeNode)

        // 【新增】編輯子章節儲存後，把父章節加入 openedNodeIds
        const extraOpenedIds: string[] = []
        if (selectedChapter?.parent_id && selectedChapter.parent_id !== String(courseId)) {
            extraOpenedIds.push(String(selectedChapter.parent_id))
        }

        const newChapterTree = restoreOriginCollapsedState(
            chapterTree,
            [...openedNodeIds, ...extraOpenedIds]   // 【改】合併編輯前的展開狀態 + 父章節
        )

        setTreeData(newChapterTree)
        setOriginTree(chapterTree)
        // ...
    }
}, [isListFetching])
```

**為什麼這樣寫？**
- 用戶編輯章節 201 → 必先點章節 200 展開 → 200 已在 `openedNodeIds` → 已自然保留
- 此補強處理 **edge case**：使用者展開 200 後，又手動 collapse → 點章節 201（仍可從 chapter list 點到）→ 編輯 → 儲存。此時若不主動展開 200，使用者會看不到「子章節仍在原位」的視覺確認

#### B3. `js/src/components/chapters/Edit.tsx`

**不需修改**。`useForm` 已設定 `invalidates: ['list', 'detail']`，會自動觸發 SortableChapters 的 useList refetch。

---

### Phase C：測試覆蓋（PHPUnit + Playwright）

> 對應 Q6=D（PHPUnit + E2E + cache 行為驗證）、Q7=B（覆蓋 2 層與 3 層巢狀）。

#### C1. PHPUnit — `tests/Integration/Chapter/ChapterSortCacheTest.php`（新增）

**目標：** 驗證 `sort_chapters()` 對所有異動 ID 觸發 `clean_post_cache`，且後續讀取拿到最新值。

| 測試方法 | 對應規格 |
|---|---|
| `test_sort_chapters_calls_clean_post_cache_for_each_updated_id` | 排序章節.feature line 67-73 |
| `test_clean_post_cache_action_fired_for_each_id` | 排序章節.feature line 73 |
| `test_post_parent_returned_immediately_after_sort` | 排序章節.feature line 71-72 |
| `test_batch_size_60_chapters_all_caches_cleared` | 排序章節.feature line 75-79 |
| `test_three_level_nested_clean_post_cache_propagates` | 排序章節.feature line 99-109 |

實作要點：
- 使用 `did_action('clean_post_cache')` 計數驗證觸發次數
- 透過 `add_action('clean_post_cache', ...)` 收集被清除的 post ID 列表
- 排序前 `get_post($id)` 預熱 cache → 排序 → 直接呼叫 `wp_get_post_parent_id($id)` → assert 等於新值

#### C2. PHPUnit — `tests/Integration/Chapter/ChapterParentPersistenceTest.php`（新增）

**目標：** 驗證 Bug #2 修復後，「排序成為子章節 → 立刻 wp_update_post → post_parent 持久」。

| 測試方法 | 對應規格 |
|---|---|
| `test_edit_child_chapter_preserves_post_parent_two_levels` | 更新章節.feature line 57-65 |
| `test_edit_child_chapter_preserves_post_parent_three_levels` | 更新章節.feature line 67-77 |
| `test_sort_then_immediately_edit_in_same_session` | 更新章節.feature line 79-85 |

實作要點：
- 直接呼叫 `Utils::sort_chapters()` 把章節 202 拖到 200 之下
- 緊接著 `wp_update_post(['ID' => 202, 'post_title' => '新標題'])`
- 重新讀 DB（**繞過 cache**）：`$wpdb->get_var("SELECT post_parent FROM {$wpdb->posts} WHERE ID = 202")` 應為 200

#### C3. PHPUnit — `tests/Integration/Chapter/ChapterApiNocacheHeadersTest.php`（新增）

**目標：** 驗證 Chapter REST callbacks 都會注入 `nocache_headers()`。

| 測試方法 | 對應規格 |
|---|---|
| `test_get_chapters_returns_no_cache_headers` | 章節CPT結構與層級.feature line 91-96 |
| `test_post_chapters_sort_returns_no_cache_headers` | 排序章節.feature line 91-93 |
| `test_post_chapters_with_id_returns_no_cache_headers` | 章節CPT結構與層級.feature line 96 |

實作要點：
- 用 `WP_REST_Request` + `rest_do_request()` 觸發 callback
- 用 `xdebug_get_headers()` 或 hook `rest_post_dispatch` 攔截 response
- assert header 包含 `Cache-Control: no-cache, must-revalidate, max-age=0, no-store` 與 `Expires: Wed, 11 Jan 1984 05:00:00 GMT`

⚠ 注意：PHPUnit 環境下 `header()` 不會真的送出（CLI mode），需用 `rest_post_dispatch` filter 攔截 response 物件，或使用 `WP_REST_Server` 的 `send_headers()` 機制。具體方式參考 WP core test suite。

#### C4. Playwright E2E — `tests/e2e/01-admin/api-chapter-crud.spec.ts`（修改現有）

**目標：** 在現有的「Chapter CRUD API」test suite 補上 nocache header 驗證。

新增測試：
```ts
test('排序 API 回傳 nocache 標頭', async () => {
    const resp = await api.pcPost('chapters/sort', { from_tree: [...], to_tree: [...] })
    expect(resp.headers['cache-control']).toContain('no-store')
    expect(resp.headers['cache-control']).toContain('no-cache')
})

test('GET /chapters 回傳 nocache 標頭', async () => {
    const resp = await api.pcGet('chapters', { post_parent: String(courseId) })
    expect(resp.headers['cache-control']).toContain('no-store')
})
```

**新增整合測試：拖曳成為子章節 → 編輯 → post_parent 持久**

```ts
test('排序成為子章節後立即編輯，post_parent 不被重置（Bug #2）', async () => {
    // 1. 建立 parent + child（initially flat）
    const parentId = await createChapter('父章節', courseId, 0)
    const childId = await createChapter('子章節', courseId, 1)

    // 2. 透過 sort API 把 child 拖到 parent 之下
    await api.pcPost('chapters/sort', {
        from_tree: [
            { id: parentId, depth: 0, menu_order: 0, parent_id: courseId, ... },
            { id: childId, depth: 0, menu_order: 1, parent_id: courseId, ... },
        ],
        to_tree: [
            { id: parentId, depth: 0, menu_order: 0, parent_id: courseId, ... },
            { id: childId, depth: 1, menu_order: 0, parent_id: parentId, ... },
        ],
    })

    // 3. 立刻編輯 child 標題
    await api.pcPostForm(`chapters/${childId}`, { post_title: '編輯後' })

    // 4. 驗證 child 的 parent_id 仍為 parentId（不是 0）
    const resp = await api.pcGet('chapters', { post_parent: String(parentId) })
    expect(resp.data.map(c => Number(c.id))).toContain(childId)
})
```

#### C5. Playwright E2E — `tests/e2e/01-admin/chapter-drag-sort.spec.ts`（新增；可選）

**目標：** 真實瀏覽器操作驗證 Bug #1a/#1c 的 UX 修復（key 重置、失敗還原）。

⚠ 限制：`@ant-design/pro-editor` 的 SortableTree 用 `dnd-kit`，Playwright 模擬拖曳難度較高（需 `mouse.down/move/up` 並要對 dnd-kit 內部閾值精準觸發）。**建議分兩階段**：
- 第一階段：只新增「失敗時 toast 為紅色錯誤、列表還原原順序」的測試（透過攔截 API 強制 500）
- 第二階段：拖曳互動測試 — 若 dnd-kit 模擬不穩定，**降級為手動回歸（PR 描述列 checklist）**，不阻擋 PR

---

### Phase D：文件補充

#### D1. `.claude/rules/wordpress.rule.md` 或新增 `.claude/rules/cache.rule.md`

新增 Cache & nocache_headers 章節（具體文案由 doc-updater 生成）：

```markdown
## REST API 快取控制

- 所有 `power-course` namespace 的 REST callback 必須在開頭呼叫 `nocache_headers()`
- 如此能確保 LiteSpeed Cache / WP Rocket / Cloudflare 等邊緣快取不會錯誤地快取 API 回應
- 若使用 raw SQL 直接更新 wp_posts 或 wp_postmeta，**必須**對所有異動的 post_id 呼叫 `clean_post_cache()`，否則：
  - WordPress object cache 會持有 stale 值
  - `wp_get_post_parent_id()` 等 cache-aware API 會回傳更新前的舊值
  - 後續 `wp_update_post` 在 `wp_insert_post_data` filter 中可能誤判 parent 不存在而清空 `post_parent`（見 Issue #216）
- 站長層面建議：在 LiteSpeed Cache 設定排除 `/wp-json/power-course/*`，作為雙保險
```

#### D2. CHANGELOG / Release notes

於下一個 patch release 註明：
- 修復 Issue #216：拖曳排序後 UI 彈回、編輯子章節後父子關係消失
- 後端：`Utils::sort_chapters()` 補上 `clean_post_cache()`；Chapter REST API 注入 `nocache_headers()`
- 前端：SortableTree 排序成功後強制重新初始化內部 state；失敗時自動還原視覺位置

---

## 5. 實作順序與依賴

```
A1 (Utils.php clean_post_cache)        ← 必做，Bug #2 根因
  │
  ├─→ C1 (ChapterSortCacheTest)         ← 驗收 A1
  └─→ C2 (ChapterParentPersistenceTest) ← 驗收 A1 對 Bug #2 的修復

A2 (Chapter Api nocache_headers)       ← 必做，Bug #1b
A3 (SubtitleApi nocache_headers)       ← 必做
  │
  ├─→ C3 (ChapterApiNocacheHeadersTest) ← 驗收 A2/A3
  └─→ C4 (api-chapter-crud.spec.ts)     ← 驗收 A2/A3 + 整合驗收 Bug #2

B1 (treeVersion + onError)             ← 必做，Bug #1a/#1c/Q4
B2 (展開父章節)                          ← 必做，Q5
  │
  └─→ C5 (chapter-drag-sort.spec.ts)    ← 可選，視 dnd-kit 模擬可行性

D1 (文件)                              ← 收尾
D2 (CHANGELOG)                         ← 收尾
```

**TDD 紅綠重構順序（給 tdd-coordinator）：**

1. **Red 1：** 寫 C1（ChapterSortCacheTest）+ C2（ChapterParentPersistenceTest）→ 預期 fail（因為 A1 還沒做）
2. **Green 1：** 實作 A1 → C1 + C2 通過
3. **Red 2：** 寫 C3（ChapterApiNocacheHeadersTest）→ 預期 fail
4. **Green 2：** 實作 A2 + A3 → C3 通過
5. **Red 3：** 寫 C4（E2E API tests）→ 預期 fail
6. **Green 3：** （A2/A3 已做）C4 應已通過；若失敗檢視整合
7. **Refactor：** 實作 B1 + B2（前端無 PHPUnit 紅綠，由 react-master 直接做 + react-reviewer 審）
8. **整合驗證：** 全套測試（lint:php、phpstan、lint:ts、build、test、test:e2e:admin）通過
9. **D1 + D2 收尾**

---

## 6. 錯誤處理登記表

| 場景 | 期望行為 | 對應 spec |
|---|---|---|
| `sort_chapters()` 任一 batch UPDATE 失敗 | TRANSACTION ROLLBACK；不執行 `clean_post_cache`；回傳 `WP_Error` | 排序章節.feature 一般失敗 rule |
| `sort_chapters()` 成功但 `clean_post_cache()` 拋例外 | 不影響 sort 結果（cache 清除是 best-effort）；以 try/catch 包住，記 log | （新增 — 見下面注意事項） |
| 編輯章節時 `wp_get_post_parent_id` 仍回傳舊值（理論不該發生） | 現有 wp_update_post 行為兜底；單元測試覆蓋 | 更新章節.feature line 79-85 |
| Chapter REST callback 處於 cache plugin 強制覆寫狀態 | 文件提示站長設定排除路徑（雙保險） | 章節CPT結構與層級.feature line 87-96 |
| 前端排序 API 回 500 / 網路斷線 | toast 紅色錯誤；`setTreeData(originTree)`；`setTreeVersion +1` 強制 remount | 排序章節.feature line 136-143（Q4） |
| 編輯子章節 API 回錯誤 | 既有 `useForm` 的 onError 不變；不觸發父章節展開 | 更新章節.feature line 99-103 |

---

## 7. 風險評估與注意事項

### 後端

1. **`clean_post_cache()` 對 60 章節 batch 的效能**
   - 風險：低
   - 評估：`clean_post_cache()` 內部是 `wp_cache_delete()` 多筆 + 1 次 `do_action()`；60 筆 ≈ 1ms 級
   - 緩解：仍在 try/catch 之外（COMMIT 後），即使 cache 清除失敗也不影響資料正確性

2. **`do_action('clean_post_cache')` 觸發其他外掛 hook 的副作用**
   - 風險：中
   - 評估：可能觸發 SEO 外掛、cache 外掛的同步邏輯；正常情況下都是設計來吃這個 action 的
   - 緩解：PR review 階段檢查專案內所有 `add_action('clean_post_cache', ...)` 是否有重邏輯（grep 已確認專案內目前無）；若有第三方外掛問題屬合理代價

3. **`nocache_headers()` 影響 Cloudflare Tiered Cache**
   - 風險：低
   - 評估：`nocache_headers()` 寫的是標準 HTTP Cache-Control，Cloudflare 預設遵守；若站長有自訂 Cache Rules，文件已提示排除路徑
   - 緩解：Q3=D 雙保險（headers + 文件）

4. **HOLD SCOPE 邊界**
   - 規格 nocache rule 寫的是「power-course namespace」，但本 issue 只修 Chapter API + SubtitleApi
   - **明確 deferred**：其他 11 個 power-course API 類的 nocache 注入留待後續 issue
   - tdd-coordinator 不應自動擴張範圍

### 前端

5. **`treeVersion` 變動造成展開狀態重置**
   - 風險：低
   - 評估：既有 `restoreOriginCollapsedState` 機制會在 effect 中跑，把展開節點回填到新 tree
   - 緩解：B2 補強了「編輯子章節後展開父章節」的 edge case；B1 + B2 配合下，使用者展開狀態應穩定

6. **`<SortableTree key={treeVersion}>` 觸發 unmount 過程中的 race condition**
   - 風險：低-中
   - 評估：`onSettled` 在 mutate 完成後觸發 → 此時 React state 已更新；`setTreeVersion` 與 `invalidate` 同時 schedule，但 React 18 batching 會把它們合併到同一 render
   - 緩解：先做 invalidate（更新 useList data），再 setTreeVersion（觸發 SortableTree remount）— 順序已對；保留 0.3 秒視覺彈跳作為已知限制（用戶可接受）

7. **失敗還原 (`setTreeData(originTree)`) 與 useList refetch 衝突**
   - 風險：低
   - 評估：`onSettled` 仍會 invalidate，後續 effect 會用 server 端最新資料覆蓋；中間 0.3 秒可能短暫顯示 originTree → server data 兩次
   - 緩解：`isSorting || isListFetching` 期間 `pointer-events: none` 已防止使用者再次操作

### 測試

8. **PHPUnit 測 REST header 的可行性**
   - 風險：中
   - 評估：CLI 模式下 `header()` 不會送出，需透過 `rest_post_dispatch` filter 攔截
   - 緩解：若實作太複雜，**降級**為 E2E（C4）作為主要驗收，PHPUnit 改測「callback 內呼叫了 nocache_headers」（用 `Patchwork` 或重構為可注入 callable）；或 PHPUnit 直接測 `nocache_headers()` 設定 `wp_cache_set_last_changed` 等內部副作用
   - 簡化方案：寫 reflection-based 檢查 — assert callback 第一行確實呼叫 `nocache_headers`（PHP 8 支援 `ReflectionFunction::getStaticVariables()`）

9. **Playwright dnd-kit 模擬不穩定**
   - 風險：中
   - 評估：`@ant-design/pro-editor` 的 SortableTree 內部用 dnd-kit，需要精準的 mouse 事件序列觸發 sortable
   - 緩解：C5 列為「可選 / 第二階段」；主要透過 C4 的 API 級驗收 + 手動回歸 checklist 兜底

### 整體

10. **i18n**
    - `__('Failed to save sort order', 'power-course')` 已存在於現有程式碼，**不新增 msgid**
    - 若新增 msgid（如錯誤訊息調整），須同步 `scripts/i18n-translations/manual.json` + `pnpm run i18n:build`

11. **PHPStan level 9**
    - `clean_post_cache()` 的 `$id` 必須是 `int`，需確保 `intval($node['id'])` 已轉型
    - `nocache_headers()` 無回傳值，呼叫端不需檢查

12. **PHPCS WordPress standards**
    - 新增 `clean_post_cache()` 呼叫前綴 `\` 命名空間（與檔案內其他 WP 函式一致）
    - `array_map` 配合 static fn 的格式遵循專案既有風格

---

## 8. 待 tdd-coordinator 注意事項

### 派發給 wordpress-master 的批次

**Batch 1（必做）：**
- 修改 `inc/classes/Resources/Chapter/Utils/Utils.php`
- 修改 `inc/classes/Resources/Chapter/Core/Api.php`
- 修改 `inc/classes/Resources/Chapter/Core/SubtitleApi.php`

**Batch 2（測試）：**
- 新增 `tests/Integration/Chapter/ChapterSortCacheTest.php`
- 新增 `tests/Integration/Chapter/ChapterParentPersistenceTest.php`
- 新增 `tests/Integration/Chapter/ChapterApiNocacheHeadersTest.php`

### 派發給 react-master 的批次

**Batch 3（必做）：**
- 修改 `js/src/components/course/SortableChapters/index.tsx`
  - 加 `treeVersion` state + `<SortableTree key={treeVersion}>`
  - `mutate.onError` 還原 `originTree`
  - `mutate.onSettled` 遞增 `treeVersion`
  - `useEffect([isListFetching])` 補上「編輯子章節後展開父章節」邏輯

### 派發給 doc-updater 的批次

**Batch 4：**
- 文件 D1（cache rule）
- 文件 D2（CHANGELOG）

### 審查迴圈

- `wordpress-reviewer` 審 Batch 1 + Batch 2 — 重點：`clean_post_cache` 是否覆蓋所有 ID、PHPStan level 9 通過、PHPCS 通過、TRANSACTION 邊界正確
- `react-reviewer` 審 Batch 3 — 重點：`treeVersion` 不會造成 effect 無窮迴圈、展開父章節邏輯不破壞既有的 `restoreOriginCollapsedState`
- `acceptance-evaluator` 最終驗收 — 對照本計劃的 Q1-Q7 答案逐項勾選

---

## 9. 驗收檢查清單（給最終 evaluator）

- [ ] Q1 修復：`Utils::sort_chapters()` 在 COMMIT 後對所有 batch 異動的 post_id 呼叫 `clean_post_cache()`
- [ ] Q1 驗證：`clean_post_cache` action 觸發次數 = 異動的 post_id 數量
- [ ] Q1 驗證：排序後立刻 `wp_get_post_parent_id($id)` 拿到新 parent，不是 stale 的舊值
- [ ] Q2 修復：`<SortableTree key={treeVersion}>`，`onSettled` 遞增 treeVersion
- [ ] Q3 修復：Chapter `Api.php` 與 `SubtitleApi.php` 的所有 REST callback 第一行呼叫 `nocache_headers()`
- [ ] Q3 驗證：`GET /wp-json/power-course/chapters` 回應 header 包含 `Cache-Control: no-store`
- [ ] Q4 修復：`mutate.onError` 顯示紅色 error toast + `setTreeData(originTree)`
- [ ] Q5 修復：編輯子章節後 SortableChapters effect 把父章節加入 openedNodeIds
- [ ] Q6 驗證：`pnpm run test`（PHPUnit）全綠；至少新增 3 個測試類；含 `clean_post_cache` 行為驗證
- [ ] Q6 驗證：`pnpm run test:e2e:admin` 全綠；新增 nocache header 與 sort+edit 整合測試
- [ ] Q7 驗證：測試案例至少覆蓋 2 層（parent → child）與 3 層（grandparent → parent → child）巢狀
- [ ] Q7 驗證：`MAX_DEPTH = 5` 未變更
- [ ] `pnpm run lint:php` 全綠（PHPCS + PHPStan level 9）
- [ ] `pnpm run lint:ts` 全綠
- [ ] `pnpm run build:wp` 通過
- [ ] 文件 D1 已更新；CHANGELOG 已記錄

---

## 10. 假設（ASM）與缺口（GAP）

- **ASM-1**：專案內無第三方掛 `wp_insert_post_data` filter 自行清空 `post_parent`，根因確實在 stale object cache 而非 filter 邏輯。已 grep 專案 `wp_insert_post_data` 確認無自家 filter；推測為 WP core 自身或 Powerhouse vendor 內的 filter（不可改 vendor）。
- **ASM-2**：LiteSpeed Cache / Cloudflare 預設遵守 `Cache-Control: no-store` 標頭。若站長強制覆寫，由 D1 文件指引兜底。
- **GAP-1**：未直接測試「LiteSpeed Cache 環境下 nocache_headers 是否生效」— 因 PHPUnit / wp-env 沒有 LiteSpeed。改以 header 內容驗證 + 手動 staging 回歸。
- **GAP-2**：dnd-kit 拖曳互動的 Playwright 模擬可行性未驗證 — C5 列為可選；C4 API 級覆蓋為主。

---

## 文件版本

- 建立日期：2026-05-04
- 釐清來源：`specs/clarify/2026-05-04-issue216-chapter-drag-sort-fix.md`
- Spec 驅動：`specs/features/chapter/{排序章節,更新章節,章節CPT結構與層級}.feature`
- 計劃作者：planner agent
- 下游執行：`@zenbu-powers:tdd-coordinator`
