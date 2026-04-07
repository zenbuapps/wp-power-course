# Power Course 整合測試指南

PHPUnit 9 + wp-phpunit + wp-env 整合測試，直接操作 WordPress 資料庫，驗證業務邏輯。

## 目錄

- [環境需求](#環境需求)
- [快速開始](#快速開始)
- [執行方式](#執行方式)
- [測試分組](#測試分組)
- [常用參數](#常用參數)
- [測試結構](#測試結構)
- [撰寫新測試](#撰寫新測試)

---

## 環境需求

| 工具 | 版本 |
|------|------|
| PHP | >= 8.0 |
| Composer | 任意 |
| Node.js | 20+ |
| wp-env | 已安裝（`@wordpress/env`） |
| Docker | 執行 wp-env 的必要條件 |

確認 wp-env tests 環境已啟動：

```bash
npx wp-env start
```

tests 環境 port：`8892`（HTTP）、`8893`（MySQL via tests）

停止 wp-env：
```bash
npx wp-env stop
```

---

## 快速開始

```bash
# 方法一：透過 pnpm（推薦）
pnpm run test:phpunit

# 方法二：直接透過 wp-env
npx wp-env run tests-cli composer run test

# 方法三：本地直接執行（需手動設定 DB 環境變數）
composer run test
```

---

## 執行方式

### 全部測試

```bash
pnpm run test:phpunit
```

### 指定 test suite

`phpunit.xml.dist` 目前定義了 `integration` suite：

```bash
npx wp-env run tests-cli -- vendor/bin/phpunit --testsuite integration
```

### 指定測試群組（`--group`）

```bash
# 只跑 smoke 測試
npx wp-env run tests-cli -- vendor/bin/phpunit --group smoke

# 只跑 happy path + edge cases
npx wp-env run tests-cli -- vendor/bin/phpunit --group happy,edge

# 排除特定群組
npx wp-env run tests-cli -- vendor/bin/phpunit --exclude-group security
```

### 指定測試類別或方法（`--filter`）

```bash
# 跑指定類別
npx wp-env run tests-cli -- vendor/bin/phpunit --filter AddStudentToCourseTest

# 跑指定方法（支援 regex）
npx wp-env run tests-cli -- vendor/bin/phpunit --filter test_成功新增學員到課程

# 跑指定目錄
npx wp-env run tests-cli -- vendor/bin/phpunit tests/Integration/Student/
```

### 產生 coverage 報告

```bash
# 文字摘要（需要 Xdebug 或 PCOV）
npx wp-env run tests-cli composer run test:coverage
```

---

## 測試分組

測試透過 PHPDoc `@group` 標記分類，在 `phpunit.xml.dist` 中宣告：

| 群組 | 用途 | 說明 |
|------|------|------|
| `smoke` | 冒煙測試 | 驗證最基本的系統連通性，hooks 是否已註冊等。CI 必跑 |
| `happy` | 快樂路徑 | 正常流程、預期成功的情境 |
| `error` | 錯誤處理 | 非法輸入、業務規則拒絕等情境 |
| `edge` | 邊緣案例 | 邊界值、重複操作、格式極端值等 |
| `security` | 安全測試 | 權限、SQL injection、XSS 等安全驗證 |

在測試方法上標記：

```php
/**
 * @test
 * @group smoke
 */
public function test_lifecycle_hooks_are_registered(): void { ... }

/**
 * @test
 * @group happy
 */
public function test_成功新增學員到課程(): void { ... }
```

---

## 常用參數

| 參數 | 說明 | 範例 |
|------|------|------|
| `--group <name>` | 執行指定群組 | `--group smoke` |
| `--exclude-group <name>` | 排除群組 | `--exclude-group security` |
| `--filter <pattern>` | 過濾類別或方法（支援 regex） | `--filter AddStudent` |
| `--testsuite <name>` | 指定 test suite | `--testsuite integration` |
| `--testdox` | 輸出人類可讀的測試名稱 | |
| `--verbose` | 詳細輸出 | |
| `--colors=always` | 強制彩色輸出 | |
| `--stop-on-failure` | 第一個失敗就停止 | |
| `--stop-on-error` | 第一個錯誤就停止 | |
| `--coverage-text` | 輸出 coverage 摘要 | |
| `--log-junit <file>` | 輸出 JUnit XML（CI 用） | `--log-junit report.xml` |

組合範例：

```bash
# CI 冒煙測試：彩色 + 詳細 + testdox
npx wp-env run tests-cli -- vendor/bin/phpunit \
  --group smoke \
  --colors=always \
  --testdox \
  --verbose

# 開發時：快速定位失敗
npx wp-env run tests-cli -- vendor/bin/phpunit \
  --filter CourseProgress \
  --stop-on-failure \
  --verbose
```

---

## 測試結構

```
tests/Integration/
├── TestCase.php                    # 基礎類別（必須繼承）
├── Course/
│   ├── CourseAvailabilityTest.php  # 課程可用性
│   └── CourseCRUDTest.php          # 課程 CRUD
├── Student/
│   ├── AddStudentToCourseTest.php  # 新增學員
│   └── UpdateStudentExpireDateTest.php # 更新到期日
├── Progress/
│   ├── CourseProgressTest.php      # 課程進度
│   └── ChapterToggleFinishedTest.php # 章節完成切換
└── Order/
    └── OrderAutoGrantCourseTest.php # 訂單自動開通
```

### `TestCase` 提供的 Helper

**資料建立：**

```php
$this->create_course(array $args = [])           // 建立測試課程（WC Product）
$this->create_chapter(int $course_id, array $args) // 建立測試章節
$this->enroll_user_to_course(int $user_id, int $course_id, int|string $expire_date = 0)
$this->set_chapter_finished(int $chapter_id, int $user_id, string $finished_at)
```

**查詢：**

```php
$this->get_course_meta(int $course_id, int $user_id, string $meta_key)
$this->get_chapter_meta(int $chapter_id, int $user_id, string $meta_key)
$this->user_has_course_access(int $user_id, int $course_id)
```

**斷言：**

```php
$this->assert_operation_succeeded()                        // lastError === null
$this->assert_operation_failed()                           // lastError !== null
$this->assert_operation_failed_with_type(string $type)     // 例外類型
$this->assert_operation_failed_with_message(string $msg)   // 錯誤訊息包含
$this->assert_action_fired(string $action_name)            // WP action 觸發
$this->assert_user_has_course_access(int $user_id, int $course_id)
$this->assert_user_has_no_course_access(int $user_id, int $course_id)
```

**狀態容器：**

```php
$this->lastError    // ?Throwable，catch 後存入，用於驗證失敗情境
$this->queryResult  // mixed，Query 操作結果
$this->ids          // array<string, int>，名稱到 ID 的映射表
$this->repos        // stdClass，Repository 容器
$this->services     // stdClass，Service 容器
```

---

## 撰寫新測試

### 基本骨架

```php
<?php
/**
 * 功能名稱 整合測試
 *
 * @group feature-name
 * @group smoke
 */
declare(strict_types=1);

namespace Tests\Integration\MyFeature;

use Tests\Integration\TestCase;

class MyFeatureTest extends TestCase {

    protected function configure_dependencies(): void {
        // 初始化 $this->repos 和 $this->services
        $this->services->myService = new MyService();
    }

    /** @test @group smoke */
    public function test_基本功能可運作(): void {
        // Given ... When ... Then
    }

    /** @test @group happy */
    public function test_正常流程(): void { ... }

    /** @test @group edge */
    public function test_邊緣案例(): void { ... }
}
```

### 注意事項

1. **資料庫隔離**：每個 `@test` 方法結束後，WordPress 標準表由 `WP_UnitTestCase` 自動 rollback；4 張自訂表（`pc_avl_coursemeta` 等）由 `TestCase::clean_custom_tables()` 在 `tear_down()` 中手動清理。
2. **每個測試必須繼承 `TestCase`** 並實作 `configure_dependencies()`。
3. **失敗情境**：catch `\Throwable` 後賦值給 `$this->lastError`，再用 `assert_operation_failed*()` 驗證。
4. **不要 mock 資料庫**：整合測試必須打真實 WordPress DB，這是為了在過去遭遇 mock/prod 不一致問題後的規範。

---

## 常見問題（Troubleshooting）

### Port 衝突：`Bind for 0.0.0.0:XXXX failed: port is already allocated`

主環境 port（預設 `8895`）或 tests 環境 port（`8892`/`8893`）被其他服務佔用。

**解法：**

```bash
# 方法一：找出並停掉佔用 port 的服務
# Windows
netstat -ano | findstr :8895
# macOS / Linux
lsof -i :8895

# 方法二：修改 .wp-env.json 的 port 為未佔用的值
```

### Docker 未啟動

wp-env 依賴 Docker，若 Docker Desktop 未執行會報錯。

**解法：** 啟動 Docker Desktop，等待 Docker Engine 就緒後再執行 `npx wp-env start`。

### `service "tests-cli" is not running`

wp-env 環境未啟動或啟動失敗。

**解法：**

```bash
# 確認 wp-env 狀態
npx wp-env status

# 如果未啟動，啟動它
npx wp-env start

# 如果啟動失敗，嘗試完全重建
npx wp-env destroy
npx wp-env start
```

> **注意：** `wp-env destroy` 會清除所有資料（資料庫、上傳檔案等），僅在測試環境使用。
