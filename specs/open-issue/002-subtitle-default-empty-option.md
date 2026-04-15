# 002 字幕語言下拉選單預設空選項

## 功能需求

字幕管理元件的語言下拉選單（Select）應新增一個空的預設選項，讓用戶明確選擇語言後才能上傳字幕。

### 目前行為

- `selectedLang` 初始值為 `'zh-TW'`（繁體中文）
- 下拉選單直接顯示「繁體中文」，沒有空選項
- 用戶可能在未注意語言選擇的情況下直接上傳

### 期望行為

- `selectedLang` 初始值為 `undefined`（或空字串）
- 下拉選單預設顯示 placeholder 文字「請選擇字幕語言」
- 未選擇語言時，上傳按鈕應為 disabled 狀態
- 選擇語言後，上傳按鈕恢復可用

## 影響範圍

### 前端

| 檔案 | 修改內容 |
|------|---------|
| `js/src/components/formItem/VideoInput/SubtitleManager.tsx` | 修改 `selectedLang` 初始值、Select 元件 props、Upload disabled 條件 |

### 後端

無影響。

### 具體修改點

1. **`selectedLang` 初始值**：從 `'zh-TW'` 改為 `undefined`
   ```typescript
   // Before
   const [selectedLang, setSelectedLang] = useState<string>('zh-TW')
   // After
   const [selectedLang, setSelectedLang] = useState<string | undefined>(undefined)
   ```

2. **Select 元件**：移除 `value` prop 的硬綁定，改用 `placeholder`
   - `value={selectedLang}` 保持不變（undefined 時顯示 placeholder）
   - `placeholder="請選擇字幕語言"` 已存在，無需修改
   - `allowClear` 可選擇是否加入

3. **上傳按鈕 disabled 條件**：加入 `!selectedLang` 判斷
   ```typescript
   // Before
   disabled={uploading || availableLanguages.length === 0}
   // After
   disabled={uploading || availableLanguages.length === 0 || !selectedLang}
   ```

4. **上傳成功後的自動選擇下一個語言**：邏輯保持不變，因為上傳成功後 `setSelectedLang(nextAvailable.value)` 仍有效。若無下一個可用語言，`selectedLang` 會保持上一次的值（已被過濾的語言），此時下拉選單自然不會有該選項可選。

## 驗收標準

1. 開啟字幕管理區塊時，語言下拉選單顯示「請選擇字幕語言」，而非「繁體中文」
2. 未選擇語言時，上傳按鈕為 disabled 狀態，無法點擊上傳
3. 選擇任一語言後，上傳按鈕恢復為 enabled 狀態
4. 上傳成功後，下拉選單自動切換至下一個可用語言（若有）
5. 所有語言皆已上傳時，下拉選單顯示 disabled 狀態
