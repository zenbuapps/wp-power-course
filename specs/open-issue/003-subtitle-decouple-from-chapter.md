# 003 字幕上傳功能與章節解耦

## 功能需求

將字幕管理功能從章節（`pc_chapter`）專屬改為通用的 post 級別功能，支援課程（`product`）的封面影片和免費試看影片也能上傳與管理字幕。字幕以 post ID + video slot 為 key 進行存儲，不同影片位置各自獨立管理字幕。

### 目前行為

- 字幕 API 路徑為 `chapters/{id}/subtitles`，僅支援章節
- 後端 `Subtitle Service` 硬編碼檢查 `post_type === 'pc_chapter'`，非章節 ID 回傳「章節不存在」
- 前端 `SubtitleManager` 元件接受 `chapterId` prop
- 前端 `Bunny.tsx` 將表單的 `id` 欄位直接傳給 `SubtitleManager` 的 `chapterId`
- 在課程編輯頁面，`id` 是課程 ID（WooCommerce product ID），導致字幕功能報錯
- meta key 為 `chapter_subtitles`，所有字幕存在同一個 meta 中

### 期望行為

- 字幕 API 路徑改為 `posts/{id}/subtitles/{videoSlot}` 和 `posts/{id}/subtitles/{videoSlot}/{srclang}`
- 後端接受 `pc_chapter` 和 `product` 兩種 post type
- 後端驗證 post type 與 video slot 的合法搭配
- 前端 `SubtitleManager` 接受 `postId` + `videoSlot` 兩個 prop
- 前端 `Bunny.tsx` 根據上下文傳入正確的 `postId` 和 `videoSlot`
- meta key 改為 `pc_subtitles_{videoSlot}`，每個影片位置獨立存儲字幕
- 不做資料遷移（用戶尚未使用字幕功能，無既有資料）

## 設計決策

### Video Slot 定義

| Video Slot | 對應 Post Type | 說明 |
|------------|---------------|------|
| `chapter_video` | `pc_chapter` | 章節影片 |
| `feature_video` | `product` | 課程封面影片 |
| `trial_video` | `product` | 課程免費試看影片 |

### Post Type 與 Video Slot 對應規則

| Post Type | 允許的 Video Slot | 不符時的錯誤 |
|-----------|------------------|-------------|
| `pc_chapter` | `chapter_video` | 400：「無效的影片位置」 |
| `product` | `feature_video`, `trial_video` | 400：「無效的影片位置」 |
| 其他 post type | 無 | 404：「文章不存在或類型不支援」 |

### Meta Key 命名

- 舊：`chapter_subtitles`（一個章節一個 meta，存所有字幕）
- 新：`pc_subtitles_{videoSlot}`（每個影片位置一個 meta）
  - `pc_subtitles_chapter_video`
  - `pc_subtitles_feature_video`
  - `pc_subtitles_trial_video`

### 資料遷移策略

不遷移，直接切換新的 meta key。原因：字幕功能用戶尚未開始使用，無既有資料。

## 影響範圍

### 後端

| 檔案 | 修改內容 |
|------|---------|
| `inc/classes/Resources/Chapter/Core/SubtitleApi.php` | 路由從 `chapters/{id}/subtitles` 改為 `posts/{id}/subtitles/{videoSlot}`；新增 `videoSlot` 路徑參數驗證；移除章節專屬耦合 |
| `inc/classes/Resources/Chapter/Service/Subtitle.php` | 移除 `pc_chapter` post type 硬編碼檢查；新增 post type 白名單驗證；新增 post type 與 video slot 搭配驗證；meta key 從 `chapter_subtitles` 改為 `pc_subtitles_{videoSlot}` |
| `inc/classes/Resources/Chapter/Core/Loader.php` | 確認 SubtitleApi 的載入方式不受影響（若 SubtitleApi 移出 Chapter namespace 則需更新） |
| `inc/classes/Resources/Chapter/Utils/Utils.php` | `chapter_subtitles` meta 引用改為 `pc_subtitles_chapter_video` |
| `inc/templates/components/video/vidstack/index.php` | 字幕讀取邏輯從讀取 `chapter_subtitles` 改為根據影片位置讀取對應的 `pc_subtitles_{videoSlot}` |

### 前端

| 檔案 | 修改內容 |
|------|---------|
| `js/src/components/formItem/VideoInput/SubtitleManager.tsx` | `chapterId` prop 改為 `postId` + `videoSlot`；API 路徑從 `/chapters/{id}/subtitles` 改為 `/posts/{id}/subtitles/{videoSlot}` |
| `js/src/components/formItem/VideoInput/Bunny.tsx` | 傳入 `SubtitleManager` 的 props 從 `chapterId={recordId}` 改為 `postId={recordId} videoSlot={...}`；需要從 `formItemProps.name` 推斷 videoSlot |
| `js/src/components/formItem/VideoInput/types/index.ts` | 可能需要新增 `TVideoSlot` 型別定義 |

### Namespace 考量

目前 `SubtitleApi.php` 和 `Subtitle.php` 位於 `Resources\Chapter` namespace 下。由於功能已泛化為 post 級別，建議考慮以下兩種方案之一：

1. **保持原位**：不移動檔案，僅修改內部邏輯。避免大量引用更新，但 namespace 與實際職責不符。
2. **移至新 namespace**：移至 `Resources\Subtitle` 或 `Resources\Post`。語義更清晰，但需更新所有引用。

（此為實作層面的決策，可在開發時決定，不影響功能規格。）

## API 規格

### GET 取得字幕列表

```
GET /wp-json/power-course/v2/posts/{id}/subtitles/{videoSlot}
```

**Path Parameters**:
- `id` (integer, required): Post ID
- `videoSlot` (string, required): `chapter_video` | `feature_video` | `trial_video`

**Response 200**:
```json
[
  {
    "srclang": "zh-TW",
    "label": "繁體中文",
    "url": "https://example.com/uploads/subtitle-zh-TW.vtt",
    "attachment_id": 123
  }
]
```

**Response 400** (無效的 videoSlot 或 post type 搭配不符):
```json
{
  "code": "invalid_video_slot",
  "message": "無效的影片位置"
}
```

**Response 404** (post 不存在或 post type 不支援):
```json
{
  "code": "post_not_found",
  "message": "文章不存在或類型不支援"
}
```

### POST 上傳字幕

```
POST /wp-json/power-course/v2/posts/{id}/subtitles/{videoSlot}
```

**Path Parameters**: 同上

**Request Body** (multipart/form-data):
- `file` (file, required): .srt 或 .vtt 字幕檔案
- `srclang` (string, required): BCP-47 語言代碼

**Response 201**:
```json
{
  "srclang": "zh-TW",
  "label": "繁體中文",
  "url": "https://example.com/uploads/subtitle-zh-TW.vtt",
  "attachment_id": 123
}
```

**Response 400** (缺少檔案 / 無效語言代碼 / 無效格式 / 無效 videoSlot):
```json
{
  "code": "missing_file|invalid_argument|invalid_video_slot",
  "message": "具體錯誤訊息"
}
```

**Response 404** (post 不存在):
```json
{
  "code": "post_not_found",
  "message": "文章不存在或類型不支援"
}
```

**Response 422** (該語言字幕已存在):
```json
{
  "code": "subtitle_exists",
  "message": "該語言字幕已存在，請先刪除再上傳"
}
```

### DELETE 刪除字幕

```
DELETE /wp-json/power-course/v2/posts/{id}/subtitles/{videoSlot}/{srclang}
```

**Path Parameters**:
- `id` (integer, required): Post ID
- `videoSlot` (string, required): `chapter_video` | `feature_video` | `trial_video`
- `srclang` (string, required): BCP-47 語言代碼

**Response 200**:
```json
{
  "deleted": true
}
```

**Response 400** (無效的 srclang / 無效的 videoSlot):
```json
{
  "code": "invalid_argument|invalid_video_slot",
  "message": "具體錯誤訊息"
}
```

**Response 404** (post 不存在 / 字幕不存在):
```json
{
  "code": "post_not_found|not_found",
  "message": "具體錯誤訊息"
}
```

## 前端元件介面變更

### SubtitleManager Props

```typescript
// Before
type TSubtitleManagerProps = {
  chapterId: number
}

// After
type TVideoSlot = 'chapter_video' | 'feature_video' | 'trial_video'

type TSubtitleManagerProps = {
  postId: number
  videoSlot: TVideoSlot
}
```

### Bunny.tsx 呼叫方式

```tsx
// Before
<SubtitleManager chapterId={recordId} />

// After - 需從 formItemProps.name 推斷 videoSlot
// 章節編輯頁面：name={['chapter_video']} → videoSlot="chapter_video"
// 課程編輯頁面：name={['feature_video']} → videoSlot="feature_video"
// 課程編輯頁面：name={['trial_video']} → videoSlot="trial_video"
<SubtitleManager postId={recordId} videoSlot={videoSlot} />
```

videoSlot 的推斷方式：從 `formItemProps.name` 陣列的最後一個元素取得（例如 `['chapter_video']` 取 `'chapter_video'`，`['feature_video']` 取 `'feature_video'`）。

### 前台模板變更

`inc/templates/components/video/vidstack/index.php` 需要新增 `$video_slot` 參數，用於決定讀取哪個 meta key：

```php
// Before
$raw_subtitles = \get_post_meta( $chapter_id, 'chapter_subtitles', true );

// After
$meta_key = 'pc_subtitles_' . $video_slot;
$raw_subtitles = \get_post_meta( $post_id, $meta_key, true );
```

呼叫模板時需傳入 `video_slot` 參數（預設值可為 `'chapter_video'` 以保持向後相容）。

## 驗收標準

### 需求 2-1：章節字幕功能正常運作（回歸測試）

1. 在章節編輯頁面，Bunny 影片下方顯示字幕管理區塊
2. 可正常上傳 .srt / .vtt 字幕檔案
3. 上傳成功後字幕出現在已上傳列表中
4. 可正常刪除已上傳的字幕
5. 前台播放章節影片時正確載入字幕

### 需求 2-2：課程封面影片字幕功能

1. 在課程編輯頁面的「課程封面影片」（feature_video）區塊，選擇 Bunny 影片後，下方顯示字幕管理區塊
2. 可正常上傳、列出、刪除字幕
3. 字幕存儲在 `pc_subtitles_feature_video` meta key 下
4. 與同課程的試看影片字幕互不干擾

### 需求 2-3：課程免費試看影片字幕功能

1. 在課程編輯頁面的「課程免費試看影片」（trial_video）區塊，選擇 Bunny 影片後，下方顯示字幕管理區塊
2. 可正常上傳、列出、刪除字幕
3. 字幕存儲在 `pc_subtitles_trial_video` meta key 下
4. 與同課程的封面影片字幕互不干擾

### 需求 2-4：後端驗證

1. 對 `pc_chapter` post 傳入 `feature_video` 或 `trial_video` 時，回傳 400 錯誤
2. 對 `product` post 傳入 `chapter_video` 時，回傳 400 錯誤
3. 傳入不存在的 post ID 時，回傳 404 錯誤
4. 傳入不支援的 post type（如 `post`、`page`）時，回傳 404 錯誤
5. 傳入不在白名單中的 videoSlot 值時，回傳 400 錯誤
