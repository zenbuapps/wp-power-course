---
name: wordpress-i18n
description: |
  WordPress 國際化（i18n）完整 API 參考，涵蓋 PHP 端與 JavaScript 端的雙語翻譯系統。
  當程式碼涉及以下任何項目時必須使用此 SKILL：
  - PHP i18n 函式：__()、_e()、_x()、_ex()、_n()、_nx()、_n_noop()、_nx_noop()
  - Escape 變體：esc_html__()、esc_html_e()、esc_html_x()、esc_attr__()、esc_attr_e()、esc_attr_x()
  - Text domain 設定與 load_plugin_textdomain()
  - JavaScript i18n：@wordpress/i18n、wp.i18n、__()、_x()、_n()、_nx()、sprintf()
  - PHP 端接線：wp_set_script_translations()
  - 工具鏈：wp i18n make-pot、wp i18n make-json、wp i18n make-php、msgfmt
  - 翻譯檔案格式：.pot、.po、.mo、.json、.l10n.php
  - Poedit、Loco Translate 工作流程
  - WordPress 6.5+ Performant Translations
  - WordPress 6.7+ just-in-time 載入限制與 _doing_it_wrong 警告
when_to_use: |
  - 程式碼涉及 __()、_e()、_x()、_n()、esc_html__()、esc_attr__() 等翻譯函式時
  - 設定或維護 text domain、load_plugin_textdomain() 時
  - 使用 wp_set_script_translations() 設定 JavaScript 翻譯時
  - 匯入或使用 @wordpress/i18n 套件時
  - 執行 make-pot、make-json、make-php 等工具時
  - 處理 .pot / .po / .mo / .json 翻譯檔案格式時
  - 遇到 WordPress 6.7 i18n early loading 警告時
  - 需要了解翻譯工作流程（Poedit / Loco Translate）時
---

# WordPress i18n 完整 API 參考

> 適用版本：WordPress 4.6+（本文標注 6.5+、6.7+ 的差異）
> 資料來源：WordPress Plugin Handbook、WP-CLI 文件、@wordpress/i18n（Gutenberg）、make.wordpress.org dev notes
> 面向 AI Agent：精準、密集，省略入門動機說明。

---

## Section 1：PHP i18n API

### 1.1 基礎翻譯函式

#### `__()`

```php
__( string $text, string $domain = 'default' ): string
```

回傳翻譯字串，不直接輸出。需搭配 `echo` 或賦值使用。

```php
// 範例 1：基本使用
echo __( 'Save Changes', 'power-course' );

// 範例 2：搭配 sprintf 使用 placeholder
printf(
    /* translators: %s: 課程名稱 */
    __( 'Course "%s" has been saved.', 'power-course' ),
    $course_name
);

// 範例 3：多個 placeholder（允許譯者重排順序）
printf(
    /* translators: 1: 學生姓名, 2: 課程名稱 */
    __( '%1$s enrolled in "%2$s".', 'power-course' ),
    $student_name,
    $course_name
);
```

**禁止事項**：
- 不可在 `$domain` 參數使用變數：`__( 'text', $domain )` — make-pot 靜態掃描無法解析
- 不可將變數嵌入字串：`__( "Hello $name", 'domain' )` — 翻譯查找在 runtime 會失敗

---

#### `_e()`

```php
_e( string $text, string $domain = 'default' ): void
```

直接 echo 翻譯字串，等同 `echo __()` 的語法糖。

```php
// 範例 4
_e( 'Add New Course', 'power-course' );
// 等同於：echo __( 'Add New Course', 'power-course' );
```

---

#### `_x()`

```php
_x( string $text, string $context, string $domain = 'default' ): string
```

帶有 context 的翻譯，用於同一英文字詞在不同情境需要不同翻譯（例如 "Post" 作名詞 vs. 動詞）。context 不會顯示給終端用戶，只顯示給譯者。

```php
// 範例 5：區分 context
echo _x( 'Draft', 'post status', 'power-course' );
echo _x( 'Draft', 'button label', 'power-course' );
// 某些語言中，這兩個「Draft」可能有不同翻譯
```

---

#### `_ex()`

```php
_ex( string $text, string $context, string $domain = 'default' ): void
```

`_x()` 的 echo 版本。

```php
// 範例 6
_ex( 'Post', 'noun', 'power-course' );
_ex( 'Post', 'verb', 'power-course' );
```

---

#### `_n()`

```php
_n( string $single, string $plural, int $number, string $domain = 'default' ): string
```

依據數量回傳單數或複數形式。支援多語言的複雜複數規則（某些語言有 6 種複數形式）。

參數：
- `$single`：單數形式（注意：不一定只用於數量 1，某些語言有特殊規則）
- `$plural`：複數形式
- `$number`：決定使用哪種形式的數字
- `$domain`：text domain

```php
// 範例 7：基本複數
printf(
    _n(
        '%s student enrolled',
        '%s students enrolled',
        $count,
        'power-course'
    ),
    number_format_i18n( $count )
);

// 範例 8：在條件式中特別處理數量 1（某些情境有意義）
if ( 1 === $count ) {
    printf( esc_html__( 'Last chapter!', 'power-course' ) );
} else {
    printf(
        esc_html( _n( '%d chapter remaining.', '%d chapters remaining.', $count, 'power-course' ) ),
        $count
    );
}
```

**重要**：`$count` 通常需要使用兩次——第一次傳入 `_n()` 決定使用哪種形式，第二次傳入 `printf()` 替換 placeholder。

---

#### `_nx()`

```php
_nx( string $single, string $plural, int $number, string $context, string $domain = 'default' ): string
```

複數 + context 的組合版本。

```php
// 範例 9
printf(
    _nx(
        '%s comment',
        '%s comments',
        $count,
        'post comments count label',
        'power-course'
    ),
    number_format_i18n( $count )
);
```

---

#### `_n_noop()`

```php
_n_noop( string $singular, string $plural, string $domain = null ): array
```

建立延遲翻譯的複數結構，稍後由 `translate_nooped_plural()` 載入。適合在全域常數或靜態屬性中預先定義翻譯字串，避免在不需要時就載入翻譯。

```php
// 範例 10：預先定義（不立即翻譯）
$messages = [
    'chapters' => _n_noop(
        '%s chapter',
        '%s chapters',
        'power-course'
    ),
    'students' => _n_noop(
        '%s student',
        '%s students',
        'power-course'
    ),
];

// 稍後在需要翻譯時
printf(
    translate_nooped_plural(
        $messages['chapters'],
        $chapter_count,
        'power-course'
    ),
    number_format_i18n( $chapter_count )
);
```

---

#### `_nx_noop()`

```php
_nx_noop( string $singular, string $plural, string $context, string $domain = null ): array
```

帶有 context 的延遲複數翻譯。

```php
// 範例 11
$label = _nx_noop(
    '%s item in cart',
    '%s items in cart',
    'shopping cart label',
    'power-course'
);
```

---

#### `translate_nooped_plural()`

```php
translate_nooped_plural( array $nooped_plural, int $count, string $domain = 'default' ): string
```

搭配 `_n_noop()` 或 `_nx_noop()` 使用，執行實際翻譯。

```php
// 範例 12
$text = translate_nooped_plural( $nooped_plural, $count, 'power-course' );
printf( $text, number_format_i18n( $count ) );
```

---

### 1.2 Escape 整合翻譯函式

**原則**：翻譯字串若要輸出到 HTML 屬性或 HTML 內容，必須同時 escape。直接使用 escape 整合函式比手動組合 `echo esc_html( __() )` 更安全且簡潔。

| 函式 | 等同於 | 適用場景 |
|------|--------|---------|
| `esc_html__( $text, $domain )` | `esc_html( __( $text, $domain ) )` | HTML 內文，回傳值 |
| `esc_html_e( $text, $domain )` | `echo esc_html( __( $text, $domain ) )` | HTML 內文，直接輸出 |
| `esc_html_x( $text, $ctx, $domain )` | `esc_html( _x( $text, $ctx, $domain ) )` | HTML 內文 + context |
| `esc_attr__( $text, $domain )` | `esc_attr( __( $text, $domain ) )` | HTML 屬性，回傳值 |
| `esc_attr_e( $text, $domain )` | `echo esc_attr( __( $text, $domain ) )` | HTML 屬性，直接輸出 |
| `esc_attr_x( $text, $ctx, $domain )` | `esc_attr( _x( $text, $ctx, $domain ) )` | HTML 屬性 + context |

```php
// 範例 13：HTML 屬性中使用
?>
<input
    type="text"
    placeholder="<?php esc_attr_e( 'Enter course title', 'power-course' ); ?>"
    title="<?php echo esc_attr_x( 'Course Title', 'input field label', 'power-course' ); ?>"
>
<?php

// 範例 14：HTML 內文使用
?>
<h2><?php esc_html_e( 'Student Progress', 'power-course' ); ?></h2>
<p><?php echo esc_html__( 'No chapters found.', 'power-course' ); ?></p>
<?php

// 範例 15：搭配 printf 的正確方式
printf(
    '<p>%s</p>',
    esc_html__( 'Course saved successfully.', 'power-course' )
);
```

**何時用哪個**：
- 輸出到 HTML 標籤之間（`<p>`, `<span>`, `<h2>` 等內文）→ `esc_html__` / `esc_html_e`
- 輸出到 HTML 屬性值（`placeholder`, `title`, `aria-label`, `value` 等）→ `esc_attr__` / `esc_attr_e`

---

### 1.3 `sprintf` 與 `printf` 的 Placeholder 規範

```php
// 單個 placeholder
printf( __( 'Hello, %s!', 'power-course' ), $name );

// 多個 placeholder（建議用編號格式，允許譯者重排語序）
printf(
    /* translators: 1: 使用者名稱, 2: 課程名稱 */
    __( '%1$s is enrolled in %2$s.', 'power-course' ),
    $user_name,
    $course_name
);

// 數字 placeholder
printf(
    /* translators: %d: 章節數量 */
    __( 'This course has %d chapters.', 'power-course' ),
    $chapter_count
);
```

Placeholder 類型：
- `%s` — 字串
- `%d` — 整數
- `%f` — 浮點數
- `%1$s`、`%2$d` — 帶位置編號，允許譯者重新排列順序（強烈建議多 placeholder 時使用）

---

### 1.4 Translator Comments 慣例

Translator comments 必須：
1. 以 `/* translators:` 開頭（固定英文前綴，make-pot 工具依此識別）
2. 是 gettext 函式呼叫前的最後一個 PHP 注釋

```php
// 範例 16：標準格式
/* translators: %s: 城市名稱 */
printf( __( 'Your city is %s.', 'power-course' ), $city );

/* translators: 1: WordPress 版本號, 2: bug 數量（複數） */
_n_noop(
    '<strong>Version %1$s</strong> addressed %2$s bug.',
    '<strong>Version %1$s</strong> addressed %2$s bugs.',
    'power-course'
);

/* translators: draft saved date format, see http://php.net/date */
$saved_date_format = __( 'g:i:s a', 'power-course' );
```

---

## Section 2：Text Domain 生命週期

### 2.1 Plugin Header 宣告

```php
<?php
/**
 * Plugin Name: Power Course
 * Text Domain: power-course
 * Domain Path: /languages
 */
```

規則：
- `Text Domain`：必須與 plugin slug 一致，使用連字號（dash）不用底線，全小寫，無空格
- `Domain Path`：翻譯檔案相對於 plugin 根目錄的路徑，必須以 `/` 開頭
- WordPress 4.6+ 起，`Text Domain` header 可省略（slug 即為 domain），但建議保留

---

### 2.2 `load_plugin_textdomain()`

```php
load_plugin_textdomain(
    string $domain,
    string|false $deprecated = false,
    string|false $plugin_rel_path = false
): bool
```

參數：
- `$domain`：text domain 字串（必填）
- `$deprecated`：已棄用的第二參數，固定傳 `false`
- `$plugin_rel_path`：相對於 `WP_PLUGIN_DIR` 的路徑，指向 `.mo` 檔案所在目錄

回傳：`bool`，成功載入回傳 `true`

Changelog：
- `6.7.0`：翻譯不再立即載入，改由 just-in-time 機制處理（呼叫此函式仍有效，但載入被延遲）
- `4.6.0`：優先從 wp-content/languages/plugins/ 目錄載入
- `1.5.0`：引入

```php
// 範例 17：標準用法（掛到 init action）
add_action( 'init', 'power_course_load_textdomain' );

function power_course_load_textdomain(): void {
    load_plugin_textdomain(
        'power-course',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}
```

**WordPress.org 插件說明**：
- WordPress 4.6+ 起，WordPress.org 插件不需要手動呼叫 `load_plugin_textdomain()`
- WordPress 自動從 translate.wordpress.org Language Pack 載入翻譯
- 若要使用自訂翻譯路徑（不從 translate.wordpress.org 載入），仍需手動呼叫

---

### 2.3 WordPress 6.7+ Just-in-Time 載入限制

**關鍵破壞性變更（WordPress 6.7.0，2024-11）**

WordPress 6.7 在翻譯被過早觸發時發出 `_doing_it_wrong` 警告：

```
PHP Notice: Function _load_textdomain_just_in_time was called incorrectly.
Translation loading for the {domain} domain was triggered too early.
Translations should be loaded at the init action or later.
```

**觸發原因**：在 `after_setup_theme` 或 `init` action 之前呼叫翻譯函式，包括：
1. 在 plugin 主檔案頂層直接呼叫 `__()`、`_e()` 等
2. 在 class constructor 中使用翻譯，且 class 在頂層被立即實例化
3. 呼叫 `get_plugin_data()` 而沒有設定 `$translate = false`

**錯誤範例（會觸發警告）**：

```php
// 錯誤 A：在頂層立即實例化含翻譯的 class
class My_Plugin {
    public string $name;
    public function __construct() {
        $this->name = __( 'My Plugin', 'power-course' ); // 過早！
    }
}
$plugin = new My_Plugin(); // 在 init 之前執行

// 錯誤 B：get_plugin_data 預設會翻譯 plugin header
$plugin_data = get_plugin_data( __FILE__ ); // $translate 預設 true，觸發過早翻譯
```

**正確做法**：

```php
// 正確 A：延遲實例化至 init 之後
add_action( 'init', function(): void {
    $plugin = new My_Plugin();
} );

// 正確 B：延遲翻譯呼叫（在需要時才呼叫）
class My_Plugin {
    public function get_name(): string {
        return __( 'My Plugin', 'power-course' ); // 方法被呼叫時才翻譯
    }
}

// 正確 C：get_plugin_data 關閉翻譯
$plugin_data = get_plugin_data( __FILE__, false, false ); // 第三個參數 $translate = false
```

**除錯工具**：

```php
// 追蹤觸發早期翻譯的程式碼路徑
add_action(
    'doing_it_wrong_run',
    static function ( string $function_name ): void {
        if ( '_load_textdomain_just_in_time' === $function_name ) {
            debug_print_backtrace();
        }
    }
);
// 或使用 Query Monitor 外掛
```

---

### 2.4 其他 Text Domain 相關函式

```php
// 檢查 text domain 是否已載入
is_textdomain_loaded( string $domain ): bool

// 卸載 text domain（釋放記憶體）
unload_textdomain( string $domain, bool $reloadable = false ): bool

// WP 6.7+ 新增：不載入翻譯，僅檢查翻譯是否存在於記憶體
has_translation( string $single, string $context = '', string $domain = 'default' ): bool
```

---

## Section 3：JavaScript i18n（@wordpress/i18n）

### 3.1 安裝

```bash
npm install @wordpress/i18n --save
# 或透過 @wordpress/scripts 使用（wp-i18n 作為全域依賴）
```

### 3.2 函式 API

#### `__()`

```typescript
__( text: string, domain?: string ): string
```

```javascript
import { __ } from '@wordpress/i18n';

// 範例 18
const label = __( 'Save Changes', 'power-course' );
const title = __( 'Course Manager', 'power-course' );
```

#### `_x()`

```typescript
_x( text: string, context: string, domain?: string ): string
```

```javascript
import { _x } from '@wordpress/i18n';

// 範例 19
const status = _x( 'Draft', 'post status', 'power-course' );
const button = _x( 'Draft', 'button label', 'power-course' );
```

#### `_n()`

```typescript
_n( single: string, plural: string, number: number, domain?: string ): string
```

```javascript
import { _n, sprintf } from '@wordpress/i18n';

// 範例 20
const message = sprintf(
    _n( '%d student', '%d students', count, 'power-course' ),
    count
);
```

#### `_nx()`

```typescript
_nx( single: string, plural: string, number: number, context: string, domain?: string ): string
```

```javascript
import { _nx, sprintf } from '@wordpress/i18n';

// 範例 21
const label = sprintf(
    _nx( '%d item', '%d items', count, 'cart item count', 'power-course' ),
    count
);
```

#### `sprintf()`

```typescript
sprintf( format: string, ...args: any[] ): string
```

```javascript
import { __, sprintf } from '@wordpress/i18n';

// 範例 22：帶 placeholder 的完整範例
const text = sprintf(
    /* translators: 1: 使用者名稱, 2: 課程名稱 */
    __( '%1$s enrolled in %2$s', 'power-course' ),
    userName,
    courseName
);
```

#### `setLocaleData()`

```typescript
setLocaleData( data?: LocaleData, domain?: string ): void
```

合併 JED 格式的 locale 資料到 Tannin 實例。通常由 WordPress core 自動處理，手動呼叫用於測試或特殊情境。

```javascript
import { setLocaleData } from '@wordpress/i18n';

// 範例 23：手動載入翻譯
setLocaleData(
    {
        '': {
            domain: 'power-course',
            lang: 'zh_TW',
            'plural-forms': 'nplurals=1; plural=0;',
        },
        'Save Changes': [ '儲存變更' ],
        '%d student': [ '%d 位學生' ],
    },
    'power-course'
);
```

#### `isRTL()`

```typescript
isRTL(): boolean
```

```javascript
import { isRTL } from '@wordpress/i18n';

// 範例 24
const direction = isRTL() ? 'rtl' : 'ltr';
```

#### 其他 API

```typescript
// 回傳 JED 格式的 locale 資料
getLocaleData( domain?: string ): LocaleData

// 重置所有 locale 資料並設定新資料
resetLocaleData( data?: LocaleData, domain?: string ): void

// 訂閱 locale 資料變更
subscribe( callback: SubscribeCallback ): UnsubscribeCallback

// 檢查特定字串是否有翻譯（WP 6.7+ 對應）
hasTranslation( single: string, context?: string, domain?: string ): boolean
```

---

### 3.3 PHP 端接線：`wp_set_script_translations()`

```php
wp_set_script_translations(
    string $handle,
    string $domain = 'default',
    string $path = ''
): bool
```

參數：
- `$handle`：`wp_register_script()` / `wp_enqueue_script()` 使用的 script handle（必填）
- `$domain`：text domain，預設 `'default'`
- `$path`：包含翻譯 JSON 檔案的目錄絕對路徑（可選，省略則使用 WordPress 語言目錄）

回傳：`bool`，成功回傳 `true`

Changelog：
- `5.1.0`：`$domain` 參數改為可選
- `5.0.0`：引入

**重要**：必須在 script 已 registered 之後呼叫。

```php
// 範例 25：完整的 JS 翻譯設定流程
add_action( 'admin_enqueue_scripts', 'power_course_enqueue_scripts' );

function power_course_enqueue_scripts(): void {
    // 1. 先 register script（wp-i18n 為必要依賴）
    wp_register_script(
        'power-course-admin',
        plugin_dir_url( __FILE__ ) . 'js/dist/admin.js',
        [ 'wp-i18n' ],
        '1.0.0',
        true
    );

    // 2. 設定翻譯（必須在 register 之後）
    wp_set_script_translations(
        'power-course-admin',     // handle
        'power-course',           // domain
        plugin_dir_path( __FILE__ ) . 'languages'  // 翻譯 JSON 目錄絕對路徑
    );

    wp_enqueue_script( 'power-course-admin' );
}
```

---

### 3.4 翻譯檔案命名規則（JS JSON）

```
{domain}-{locale}-{handle-md5}.json
```

- `{domain}`：text domain（例如 `power-course`）
- `{locale}`：語言代碼（例如 `zh_TW`、`de_DE`）
- `{handle-md5}`：script 相對路徑的 MD5 hash（由 WordPress 自動計算）

WordPress 在呼叫 `wp_set_script_translations()` 時，自動計算 script 檔案相對於 plugin 根目錄的路徑，計算其 MD5，並在 `$path` 目錄（或 WordPress 語言目錄）中尋找對應的 JSON 檔案。

範例：
```
power-course-zh_TW-a1b2c3d4e5f6789012345678901234ab.json
```

`wp i18n make-json` 自動生成正確命名的檔案，不需手動計算 MD5。

---

### 3.5 JED 1.x JSON 格式

```json
{
    "translation-revision-date": "2024-01-01T00:00:00+0000",
    "generator": "GlotPress/4.0.0",
    "domain": "messages",
    "locale_data": {
        "messages": {
            "": {
                "domain": "messages",
                "plural-forms": "nplurals=1; plural=0;",
                "lang": "zh-tw"
            },
            "Save Changes": [
                "儲存變更"
            ],
            "%d student": [
                "%d 位學生"
            ],
            "post status\u0004Draft": [
                "草稿"
            ],
            "%d item\u0004%d item\u0005%d items": [
                "%d 個項目"
            ]
        }
    }
}
```

格式說明：
- `""`（空字串 key）：後設資料，含語言代碼、複數規則
- 一般字串：`"原文": ["翻譯"]`
- context 字串：`"context\u0004原文": ["翻譯"]`（`\u0004` 是 EOT 字元，U+0004）
- 複數字串：`["單數翻譯", "複數翻譯", ...]`（依語言複數規則可能有多個形式）

---

## Section 4：工具鏈

### 4.1 `wp i18n make-pot`

從 PHP、JavaScript、block.json、theme.json 等檔案提取可翻譯字串，生成 `.pot` 模板。

```bash
# 基本語法
wp i18n make-pot <source> [<destination>] [options]

# 範例 26：為 power-course 外掛生成 POT
wp i18n make-pot . languages/power-course.pot

# 指定 slug 與 domain
wp i18n make-pot . languages/power-course.pot \
    --slug=power-course \
    --domain=power-course

# 排除特定目錄
wp i18n make-pot . languages/power-course.pot \
    --exclude=node_modules,vendor,js/dist,.git

# 只掃描特定路徑
wp i18n make-pot . languages/power-course.pot \
    --include=inc,js/src

# 跳過 JS 掃描（若另有建置步驟處理 JS）
wp i18n make-pot . languages/power-course.pot --skip-js

# CI 環境（跳過字串審查、不寫位置資訊）
wp i18n make-pot . languages/power-course.pot \
    --skip-audit \
    --no-location
```

重要選項一覽：

| 選項 | 說明 |
|------|------|
| `--slug=<slug>` | 插件 slug，預設為來源目錄名稱 |
| `--domain=<domain>` | 要掃描的 text domain，預設從 plugin header 讀取 |
| `--ignore-domain` | 忽略 domain 過濾，提取所有字串 |
| `--exclude=<paths>` | 逗號分隔的排除路徑，支援 glob；預設排除 node_modules、.git、vendor、*.min.js |
| `--include=<paths>` | 逗號分隔的包含路徑，只掃描這些路徑 |
| `--skip-js` | 跳過 JavaScript 字串提取 |
| `--skip-php` | 跳過 PHP 字串提取 |
| `--skip-blade` | 跳過 Blade-PHP 字串提取 |
| `--skip-block-json` | 跳過 block.json 字串提取 |
| `--skip-theme-json` | 跳過 theme.json 字串提取 |
| `--skip-audit` | 跳過字串審查（CI 環境建議加上） |
| `--no-location` | 不寫 `#: filename:line` 位置注釋 |
| `--merge[=<paths>]` | 合併其他 POT 檔案內容 |
| `--subtract=<paths>` | 排除指定 POT 檔案中已有的字串（避免重複） |
| `--headers=<json>` | 自訂 POT header（JSON 格式） |
| `--package-name=<name>` | 覆蓋 Project-Id-Version header 中的名稱 |

---

### 4.2 `wp i18n make-json`

從 `.po` 檔案提取 JavaScript 翻譯字串，生成 WordPress 所需的 JED JSON 檔案。每個 JS 檔案對應一個獨立的 JSON 檔案。

```bash
# 基本語法
wp i18n make-json <source> [<destination>] [options]

# 範例 27：為目錄中所有 PO 檔案生成 JSON
wp i18n make-json languages

# 為單一 PO 生成 JSON，輸出到指定目錄，保留 PO 不修改
wp i18n make-json languages/power-course-zh_TW.po languages --no-purge

# 使用 build mapping（原始碼路徑 → build 後路徑）
wp i18n make-json languages --use-map=build/map.json

# 格式化輸出（便於人工閱讀）
wp i18n make-json languages --pretty-print
```

重要選項：

| 選項 | 說明 |
|------|------|
| `--domain=<domain>` | 覆蓋從 PO 檔案提取的 domain（影響輸出檔名） |
| `--purge` / `--no-purge` | 是否從 PO 移除已提取到 JSON 的字串（預設 true） |
| `--update-mo-files` | 搭配 `--purge` 一起更新 MO 檔案 |
| `--use-map=<paths>` | 指定來源 → build 路徑的 mapping（解決 Vite/webpack 改變路徑的問題） |
| `--pretty-print` | 美化 JSON 輸出 |
| `--extensions=<ext>` | 額外的 JS 副檔名，逗號分隔（預設 .js, .min.js） |

---

### 4.3 `wp i18n make-php`（WordPress 6.5+）

從 `.po` 生成 `.l10n.php` 格式翻譯，搭配 WordPress 6.5+ Performant Translations 使用。

```bash
# 為目錄中所有 PO 生成 PHP 翻譯
wp i18n make-php .

# 為單一 PO 生成，輸出到指定目錄
wp i18n make-php languages/power-course-zh_TW.po languages
```

---

### 4.4 `msgfmt`（gettext 套件）

從 `.po` 編譯二進位 `.mo` 檔案：

```bash
# 基本用法
msgfmt -o filename.mo filename.po

# 特定外掛
msgfmt -o languages/power-course-zh_TW.mo languages/power-course-zh_TW.po

# 批次處理（Unix/Linux/macOS）
for file in $(find . -name "*.po"); do
    msgfmt -o "${file/.po/.mo}" "$file"
done
```

---

### 4.5 Poedit 工作流程

1. **生成 POT**：執行 `wp i18n make-pot . languages/power-course.pot`
2. **開啟 Poedit**，選「從 POT 檔案新建翻譯」→ 選擇目標語言
3. **翻譯字串**：逐一翻譯，Poedit 顯示原文、譯者注釋、使用位置
4. **儲存**：存為 `languages/power-course-zh_TW.po`，Poedit 自動同時生成 `.mo`
5. **生成 JSON**：執行 `wp i18n make-json languages`

Poedit Pro 支援一鍵從原始碼重新掃描字串（等同重新執行 make-pot）。

---

### 4.6 Loco Translate 工作流程

Loco Translate 是 WordPress 後台外掛，適合非技術用戶：

1. 安裝 Loco Translate 外掛
2. 在 Loco Translate → 外掛 → 找到目標外掛
3. 點擊「新增語言」→ 選擇目標語言 → 選擇儲存位置
4. Loco Translate 自動掃描並呈現可翻譯字串
5. 翻譯後點擊「儲存」
6. Loco Translate 自動生成 `.po`、`.mo`（以及 JS `.json` 如果有設定）

注意：Loco Translate 生成的 JS JSON 檔案需確認命名格式符合 WordPress 的 MD5 hash 規則。

---

## Section 5：檔案格式

### 5.1 格式關係圖

```
原始碼（PHP + JS）
    │
    ▼ wp i18n make-pot
{slug}.pot              ← POT: 可翻譯字串模板（英文原文）
    │
    ▼ 翻譯（Poedit / GlotPress / Loco Translate）
{slug}-{locale}.po      ← PO: 翻譯結果（人類可讀文字格式）
    │
    ├──▼ msgfmt 或 Poedit
    │  {slug}-{locale}.mo            ← MO: 二進位，PHP gettext 函式使用
    │
    ├──▼ wp i18n make-php（WP 6.5+）
    │  {slug}-{locale}.l10n.php      ← PHP 陣列格式，OPCache 加速
    │
    └──▼ wp i18n make-json
       {slug}-{locale}-{md5}.json    ← JED JSON，JavaScript 端 @wordpress/i18n 使用
```

### 5.2 檔案命名規則

| 檔案類型 | 命名格式 | 範例 |
|---------|---------|------|
| POT（模板） | `{slug}.pot` | `power-course.pot` |
| PO（翻譯原始） | `{slug}-{locale}.po` | `power-course-zh_TW.po` |
| MO（二進位） | `{slug}-{locale}.mo` | `power-course-zh_TW.mo` |
| PHP（6.5+） | `{slug}-{locale}.l10n.php` | `power-course-zh_TW.l10n.php` |
| JSON（JS 端） | `{slug}-{locale}-{md5}.json` | `power-course-zh_TW-a1b2c3....json` |

### 5.3 PO/POT 檔案格式示例

```po
# Plugin Name: Power Course
# Copyright (C) 2024 Power Course
msgid ""
msgstr ""
"Project-Id-Version: Power Course 1.0.0\n"
"Report-Msgid-Bugs-To: https://example.com\n"
"POT-Creation-Date: 2024-01-01 00:00+0000\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"PO-Revision-Date: 2024-01-01 00:00+0000\n"
"Last-Translator: Translator Name <email@example.com>\n"
"Language: zh_TW\n"
"Plural-Forms: nplurals=1; plural=0;\n"

#. translators: %s: 課程名稱
#: inc/classes/Api/CourseController.php:123
#, php-format
msgid "Course \"%s\" not found."
msgstr "找不到課程「%s」。"

#: inc/classes/Api/CourseController.php:145
msgid "Save Changes"
msgstr "儲存變更"

#: inc/classes/Api/StudentController.php:67
#, php-format
msgid "%d student"
msgid_plural "%d students"
msgstr[0] "%d 位學生"
```

---

## Section 6：WordPress 6.5+ Performant Translations

### 6.1 概述（2024-04 引入）

WordPress 6.5 引入全新翻譯載入系統（源自 Performant Translations feature plugin）：

- 更快的二進位 `.mo` 讀取（純 PHP 實作，不依賴 `ext/intl` 擴充）
- 更低記憶體使用量
- 支援同時載入多個 locale（locale 切換更快）
- 新的 `.l10n.php` 格式：以 PHP 陣列儲存翻譯，可利用 OPCache 大幅加速

### 6.2 對 Plugin 開發者的影響

**通常無需特別處理**：
- 從 WordPress.org 下載的 Language Pack 自動包含 `.l10n.php`
- 現有的 `.mo` 檔案繼續有效（使用新的更快載入器）
- 完全向後相容

**可選的主動優化（商業外掛）**：
```bash
# 生成 PHP 翻譯檔（比 MO 更高效能）
wp i18n make-php languages/power-course-zh_TW.po languages
```

### 6.3 `.l10n.php` 格式範例

```php
<?php
return [
    'project-id-version' => 'Power Course 1.0.0',
    'messages' => [
        'Save Changes'         => '儲存變更',
        'Add New Course'       => '新增課程',
        // Context 字串：使用 EOT（"\4"）作為 context 與原文的分隔符
        "post status\4Draft"   => '草稿',
        // 複數字串：使用 NUL（"\0"）分隔各複數形式
        '%d student'           => '%d 位學生',
    ],
];
```

### 6.4 WordPress 6.5 新增的 Filter

```php
// 強制指定翻譯格式（通常不需要）
add_filter(
    'translation_file_format',
    static function (): string {
        return 'mo'; // 或 'php'
    }
);

// 過濾翻譯檔案路徑（php 和 mo 都適用，取代舊的 load_textdomain_mofile）
add_filter(
    'load_translation_file',
    function ( string $file, string $domain ): string {
        return $file; // 可回傳自訂路徑
    },
    10,
    2
);
```

---

## Section 7：常見陷阱

### 7.1 text domain 參數不可用變數

```php
// 錯誤：make-pot 靜態掃描無法解析變數
$domain = 'power-course';
__( 'Hello', $domain ); // 不會被提取到 POT

// 正確：直接使用字串字面值
__( 'Hello', 'power-course' );
```

### 7.2 `__()` 的回傳值必須再 escape

```php
// 錯誤：翻譯者可能在翻譯中注入 HTML
echo __( 'Welcome!', 'power-course' ); // 若譯文含 HTML 標籤，XSS 風險

// 正確：輸出到 HTML 前使用對應的 escape 函式
echo esc_html__( 'Welcome!', 'power-course' );
echo esc_attr__( 'Enter name', 'power-course' );
```

### 7.3 `printf` vs `sprintf` 的選擇

```php
// printf：直接輸出（相當於 echo sprintf()）
printf( __( 'Hello, %s!', 'power-course' ), esc_html( $name ) );

// sprintf：回傳字串（需手動 echo，適合後續還要處理的場合）
$text = sprintf( __( 'Hello, %s!', 'power-course' ), esc_html( $name ) );
echo $text;

// 注意：printf 本身不 escape，需在 placeholder 傳入前 escape
printf(
    '<p>%s</p>',
    sprintf(
        esc_html__( 'Welcome, %s!', 'power-course' ),
        esc_html( $user_name )
    )
);
```

### 7.4 多 Placeholder 應使用編號格式

```php
// 不好：譯者無法重排語序
printf( __( 'Hello %s, you have %d messages.', 'power-course' ), $name, $count );

// 好：使用 %1$s 編號允許譯者重排
printf(
    /* translators: 1: 使用者名稱, 2: 訊息數量 */
    __( 'Hello %1$s, you have %2$d messages.', 'power-course' ),
    $name,
    $count
);
// 某些語言的譯者可寫：'%2$d 則訊息，%1$s 您好。'
```

### 7.5 不要把整段 HTML 塞入翻譯字串

```php
// 錯誤：HTML 結構難以翻譯，且有 XSS 風險
_e( 'Please <a href="/login">login</a> to continue.', 'power-course' );

// 正確：用 placeholder 分離 HTML 結構
printf(
    /* translators: %s: 登入連結的 HTML */
    __( 'Please %s to continue.', 'power-course' ),
    '<a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'login', 'power-course' ) . '</a>'
);
```

### 7.6 避免字串串接翻譯

```php
// 錯誤：拆開的片段在其他語言中語序不同，無法正確翻譯
echo __( 'Your city is ', 'power-course' ) . $city . __( '.', 'power-course' );

// 正確：完整句子用 printf
printf(
    /* translators: %s: 城市名稱 */
    __( 'Your city is %s.', 'power-course' ),
    esc_html( $city )
);
```

### 7.7 空字串不要翻譯

```php
// 錯誤：gettext 保留空字串作為 PO header 使用
__( '', 'power-course' ); // 永遠不要這樣做

// 若確實需要空值情境，加入 context
_x( '', 'empty placeholder for some UI purpose', 'power-course' );
```

### 7.8 `plugins_loaded` vs `init` hook 的錯誤（WP 6.7+）

```php
// 錯誤（WordPress 6.7+ 會在翻譯被提前使用時警告）
add_action( 'plugins_loaded', function(): void {
    load_plugin_textdomain( 'power-course', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// 正確：使用 init hook
add_action( 'init', function(): void {
    load_plugin_textdomain( 'power-course', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

// 最佳（WordPress.org 插件）：不需要手動呼叫，確保 plugin header 正確即可
```

### 7.9 JS `wp_set_script_translations` 必須在 register 之後呼叫

```php
// 錯誤：script 尚未 registered
wp_set_script_translations( 'power-course-js', 'power-course' ); // 失敗，回傳 false
wp_register_script( 'power-course-js', ... );

// 正確：先 register，再設定翻譯
wp_register_script( 'power-course-js', ... );
wp_set_script_translations(
    'power-course-js',
    'power-course',
    plugin_dir_path( __FILE__ ) . 'languages'
);
```

### 7.10 JS 翻譯路徑應使用 `plugin_dir_path()` 而非 `plugin_basename()`

```php
// 錯誤：plugin_basename 回傳相對路徑（如 "power-course/plugin.php"），不是絕對路徑
wp_set_script_translations(
    'handle', 'domain',
    plugin_basename( __DIR__ ) . '/languages/' // 錯誤！
);

// 正確：plugin_dir_path 回傳帶尾部斜線的絕對路徑
wp_set_script_translations(
    'handle', 'domain',
    plugin_dir_path( __FILE__ ) . 'languages'  // 正確（注意已含尾部斜線，不需再加 /）
);
```

---

## Power Course 專屬 Cheatsheet

### 基本設定

```
Text Domain：power-course
Domain Path：/languages
Plugin 主檔：plugin.php
```

```php
// plugin.php Plugin Header
/**
 * Plugin Name: Power Course
 * Text Domain: power-course
 * Domain Path: /languages
 */
```

### PHP 翻譯常用模式

```php
// 基本字串（回傳值）
__( 'Your string', 'power-course' )

// 基本字串（直接輸出）
_e( 'Your string', 'power-course' )

// HTML 內文安全輸出
esc_html__( 'Your string', 'power-course' )
esc_html_e( 'Your string', 'power-course' )

// HTML 屬性安全輸出
esc_attr__( 'placeholder text', 'power-course' )
esc_attr_e( 'placeholder text', 'power-course' )

// 帶 context
_x( 'string', 'context description', 'power-course' )
_ex( 'string', 'context description', 'power-course' )

// 複數
printf(
    _n( '%s course', '%s courses', $count, 'power-course' ),
    number_format_i18n( $count )
)

// 完整範例（多 placeholder + escape）
printf(
    /* translators: 1: 學生姓名, 2: 課程名稱 */
    __( '%1$s enrolled in "%2$s".', 'power-course' ),
    esc_html( $student_name ),
    esc_html( $course_name )
);
```

### 載入翻譯（PHP）

```php
// 如果需要手動載入（掛到 init）
add_action( 'init', function(): void {
    load_plugin_textdomain(
        'power-course',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
} );
```

### JavaScript 翻譯

```php
// PHP 端：enqueue + 設定翻譯
add_action( 'admin_enqueue_scripts', function(): void {
    wp_register_script(
        'power-course-admin',
        plugin_dir_url( __FILE__ ) . 'js/dist/admin.js',
        [ 'wp-i18n' ],
        '1.0.0',
        true
    );
    wp_set_script_translations(
        'power-course-admin',
        'power-course',
        plugin_dir_path( __FILE__ ) . 'languages'
    );
    wp_enqueue_script( 'power-course-admin' );
} );
```

```javascript
// JS 端
import { __, _n, _x, _nx, sprintf } from '@wordpress/i18n';

const title    = __( 'Course Manager', 'power-course' );
const status   = _x( 'Draft', 'post status', 'power-course' );
const label    = sprintf(
    _n( '%d course', '%d courses', count, 'power-course' ),
    count
);
const enrolled = sprintf(
    /* translators: 1: 使用者名稱, 2: 課程名稱 */
    __( '%1$s enrolled in %2$s', 'power-course' ),
    userName,
    courseName
);
```

### 工具鏈指令（從 plugin 根目錄執行）

```bash
# 1. 生成 POT 模板
pnpm run i18n:pot
# 等同：wp i18n make-pot . languages/power-course.pot --slug=power-course --domain=power-course --exclude=node_modules,vendor,tests,release,js/dist,languages

# 2. 翻譯（使用 Poedit 開啟 POT，翻譯後存為 .po）
# languages/power-course-en_US.po

# 3. 編譯 MO 二進位（Poedit 儲存 .po 時自動產生）
msgfmt -o languages/power-course-en_US.mo languages/power-course-en_US.po

# 4. 生成 JS JSON（Vite build 後執行）
pnpm run i18n:json
# 等同：wp i18n make-json languages --no-purge --pretty-print

# 5.（可選）生成 PHP 翻譯（WP 6.5+，更高效能）
wp i18n make-php languages/power-course-en_US.po languages
```

### 翻譯檔案目錄結構

```
power-course/
└── languages/
    ├── power-course.pot                              # 模板（交給譯者）
    ├── power-course-en_US.po                         # 英文原始翻譯
    ├── power-course-en_US.mo                         # 英文二進位（PHP gettext）
    ├── power-course-en_US.l10n.php                   # 英文 PHP 格式（WP 6.5+）
    └── power-course-en_US-{md5hash}.json             # 英文 JS 端翻譯
```

### WP 6.7+ Early Loading 快速診斷

```php
// 臨時加入除錯，追蹤觸發過早翻譯的堆疊
add_action(
    'doing_it_wrong_run',
    static function ( string $function_name ): void {
        if ( '_load_textdomain_just_in_time' === $function_name ) {
            error_log( print_r( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), true ) );
        }
    }
);
// 除錯完畢記得移除此段
```

---

*資料來源（已爬取驗證）：*
- *WordPress Plugin Handbook — Internationalization (developer.wordpress.org)*
- *WordPress Code Reference — load_plugin_textdomain、wp_set_script_translations*
- *make.wordpress.org — i18n improvements in 6.5（Performant Translations）*
- *make.wordpress.org — i18n improvements in 6.7（just-in-time loading warnings）*
- *make.wordpress.org — New JavaScript i18n support in WordPress 5.0*
- *WP-CLI — wp i18n make-pot、make-json 文件*
- *GitHub Gutenberg — packages/i18n README（@wordpress/i18n API）*
