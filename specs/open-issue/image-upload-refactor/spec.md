# 圖片上傳重構：從 FileUpload 遷移至 MediaLibraryModal

## 摘要

將 power-course 中所有表單欄位類型的圖片上傳，從現有的 `FileUpload` / `OnChangeUpload` / `UserAvatarUpload`（Ant Design Upload + antd-img-crop，透過 form-data 上傳二進制檔案）改為使用 `MediaLibraryModal`（WordPress Media Library 選圖器），與 power-shop 的 Gallery 組件一致。

連同 PHP API 端的圖片接收方式也一併修改，從接收二進制 `files` 改為接收 `image_id` / `gallery_image_ids` JSON 字段。

---

## 功能需求

### FR-1：前端組件替換

#### FR-1.1 課程封面圖（CourseDescription）

**現行實作**：
- 檔案：`js/src/pages/admin/Courses/Edit/tabs/CourseDescription/index.tsx`
- 組件：`<FileUpload />` + hidden `<Item name={['files']}>` + hidden `<Item name={['images']}>`
- 行為：使用者拖曳/點擊選擇本機檔案 → ImgCrop 裁剪 → File 物件存入 form `files` 字段 → 表單送出時以 FormData 夾帶二進制檔

**目標實作**：
- 使用 `MediaLibraryModal` + `useMediaLibraryModal` from `antd-toolkit/wp`
- 使用者點擊 "+" 按鈕 → 開啟 WordPress Media Library → 選擇/上傳圖片 → `TImage[]`（含 `id` 和 `url`）存入 form `images` 字段
- 移除 hidden `<Item name={['files']}>` 字段
- `limit` 設為 `1`（課程封面圖僅 1 張）
- 標籤改為「課程封面圖」

#### FR-1.2 用戶頭像（UserAvatarUpload）

**現行實作**：
- 檔案：`js/src/components/user/UserAvatarUpload/index.tsx`
- 組件：`Upload` picture-circle + ImgCrop 圓形裁剪 → POST 到 `upload` API → URL 存入 `user_avatar_url`
- 行為：onChange 時上傳到媒體庫取得 URL

**目標實作**：
- 使用 `MediaLibraryModal` + `useMediaLibraryModal`
- 選擇圖片後，將 `TImage` 的 `url` 存入 form `user_avatar_url` 字段
- `limit` 設為 `1`
- 保持圓形預覽外觀（使用 CSS `rounded-full`）

### FR-2：前端表單提交邏輯變更

**現行實作**（Course Edit `handleOnFinish`）：
- 使用 `toFormData()` 將整個 form values（包含 `files` 二進制檔）轉為 `FormData` 送出
- PHP 端透過 `$request->get_file_params()['files']` 接收二進制檔

**目標實作**（參考 power-shop Product Edit `handleOnFinish`）：
- 從 form values 取出 `images` 陣列（`TImage[]`）
- 將 `images[0]` 的 `id` 設為 `image_id`
- 將 `images[1:]` 的 `id` 陣列設為 `gallery_image_ids`（課程封面圖僅 1 張，此欄位通常為空陣列）
- 移除 `images` 和 `files` 欄位，改傳 `image_id` 和 `gallery_image_ids`
- 不再使用 `toFormData()`（或 `toFormData()` 不再夾帶二進制檔）

### FR-3：PHP API 端變更

**現行實作**（`inc/classes/Api/Course.php` separator 方法）：
```php
// L547: 接收二進制檔案
WP::separator( $body_params, 'product', $file_params['files'] ?? [] );
```
- `WP::separator()` 呼叫 `WP::upload_files()` 將二進制檔上傳到媒體庫
- 取得 `attachment_id` 後設為 `image_id`

**目標實作**（參考 power-shop `V2Api.php`）：
```php
// 不再傳入 files，改為直接從 body_params 讀取 image_id
WP::separator( $body_params, 'product' );
```
- 前端直接傳 `image_id`（attachment ID），PHP 端透過 `WP::separator()` 自動將其歸入 `$data['image_id']`
- `WP::separator()` 的第三個參數不再傳入，或傳空陣列
- `handle_save_course_data()` 中的 `set_image_id()` 由 WC Product setter 自動處理
- 移除 `handle_save_course_meta_data()` 中的 `unset( $meta_data['files'] )`

---

## 技術方案

### 前端變更清單

| # | 檔案 | 變更類型 | 說明 |
|---|------|---------|------|
| 1 | `js/src/pages/admin/Courses/Edit/tabs/CourseDescription/index.tsx` | **修改** | 移除 `<FileUpload />`，改用 Gallery-style MediaLibraryModal（limit=1） |
| 2 | `js/src/components/user/UserAvatarUpload/index.tsx` | **重寫** | 改用 MediaLibraryModal，保持圓形預覽 |
| 3 | `js/src/pages/admin/Courses/Edit/index.tsx` | **修改** | `handleOnFinish` / `parseData` 邏輯變更：images → image_id + gallery_image_ids |
| 4 | `js/src/components/post/FileUpload/index.tsx` | **廢棄** | 不再使用，可移除或標記 deprecated |
| 5 | `js/src/components/post/OnChangeUpload/index.tsx` | **廢棄** | 不再使用，可移除或標記 deprecated |

### 後端變更清單

| # | 檔案 | 變更類型 | 說明 |
|---|------|---------|------|
| 1 | `inc/classes/Api/Course.php` (separator) | **修改** | `WP::separator()` 不再傳入 `$file_params['files']`，改為不傳第三參數 |
| 2 | `inc/classes/Api/Course.php` (handle_save_course_meta_data) | **修改** | 移除 `unset( $meta_data['files'] )` 行 |

### 不修改的檔案

| # | 檔案 | 原因 |
|---|------|------|
| 1 | `js/src/pages/admin/Emails/Edit/EmailEditor/index.tsx` | 編輯器內部 `onUploadImage` 回調，由第三方庫控制 |
| 2 | `js/src/components/general/FileUpload/index.tsx` | CSV 檔案上傳，非圖片上傳 |
| 3 | `js/src/components/user/UserTable/CsvUpload/index.tsx` | CSV 批量匯入 |
| 4 | `inc/classes/Api/Upload.php` | 仍供 EmailEditor onUploadImage 使用 |

### 技術依賴

| 依賴 | 版本 | 狀態 |
|------|------|------|
| `antd-toolkit` (`MediaLibraryModal`, `useMediaLibraryModal`, `TImage`) | 1.3.221+ | 已安裝 |
| `antd-toolkit/wp` | 同上 | 已安裝 |
| `nanoid` | 已安裝 | Gallery 組件 key 生成用 |

### 參考實作

- **前端參考**：`C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\components\product\fields\Gallery\index.tsx`
- **前端已有先例**：`js/src/pages/admin/Courses/Edit/tabs/CourseBundles/Edit/Gallery.tsx`（power-course 內已使用 MediaLibraryModal）
- **後端參考**：`C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\powerhouse\inc\classes\Domains\Product\Core\V2Api.php` (separator 方法)
- **後端 handleOnFinish 參考**：`C:\Users\User\LocalSites\turbo\app\public\wp-content\plugins\power-shop\js\src\pages\admin\Product\Edit\index.tsx` (images → image_id 轉換)

---

## 資料流對比

### 現行資料流（FileUpload）

```
使用者選擇本機圖片
  → ImgCrop 裁剪
  → File 物件存入 form['files']
  → toFormData() 轉為 FormData（含二進制檔）
  → POST /power-course/v2/courses/{id}（Content-Type: multipart/form-data）
  → PHP: $request->get_file_params()['files'] 取得二進制檔
  → WP::separator() → WP::upload_files() → media_handle_upload() → attachment_id
  → $product->set_image_id( attachment_id )
```

### 目標資料流（MediaLibraryModal）

```
使用者點擊 "+" 按鈕
  → 開啟 WordPress Media Library Modal
  → 選擇已有圖片 或 上傳新圖片（在 Media Library 內完成）
  → 回傳 TImage { id: string, url: string }
  → 存入 form['images']
  → handleOnFinish 轉換：images[0].id → image_id
  → POST /power-course/v2/courses/{id}（Content-Type: application/json 或 form-data 無二進制檔）
  → PHP: $body_params['image_id'] 直接取得 attachment ID
  → WP::separator() 將 image_id 歸入 $data（WC Product data field）
  → $product->set_image_id( image_id )
```

---

## 驗收標準

### AC-1 課程封面圖

- [ ] 課程編輯頁「課程封面圖」區塊顯示 MediaLibraryModal 式的圖片選擇器
- [ ] 點擊 "+" 可開啟 WordPress Media Library
- [ ] 選擇圖片後，封面圖顯示在頁面上
- [ ] 可移除已選的封面圖
- [ ] 儲存後，課程封面圖正確保存（API 回傳的 images 陣列包含正確圖片）
- [ ] 重新載入編輯頁，封面圖正確回顯

### AC-2 用戶頭像

- [ ] 用戶頭像上傳區塊顯示 MediaLibraryModal 式的圖片選擇器
- [ ] 選擇圖片後，頭像正確顯示（保持圓形外觀）
- [ ] 儲存後，用戶頭像正確保存
- [ ] 重新載入，頭像正確回顯

### AC-3 向下相容

- [ ] 舊有已上傳的課程封面圖在編輯頁面正確回顯
- [ ] 舊有已上傳的用戶頭像在編輯頁面正確回顯
- [ ] EmailEditor 的圖片上傳功能不受影響

### AC-4 API 端

- [ ] 更新課程時，`image_id` 正確設定到 WC Product
- [ ] 不再有 `files` 相關的 form-data 二進制檔傳輸
- [ ] Upload API（`/power-course/v2/upload`）仍可正常運作（供 EmailEditor 使用）
