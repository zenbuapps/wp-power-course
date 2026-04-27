# Repo 遷移注意事項

從 `j7-dev` 遷移至 `zenbuapps` 組織時的完整檢查清單。

**遷移範圍：**
- `j7-dev/wp-power-course` → `zenbuapps/wp-power-course`
- `j7-dev/wp-powerhouse` → `zenbuapps/wp-powerhouse`
- `zenbuapps/zenbu-powers`（repo 已更名並遷移，plugin / marketplace id / agent namespace 一併更新）

**不在遷移範圍（保持 j7-dev）：**
- `j7-dev/wp-plugin-trait`
- `j7-dev/tgm-plugin-activation-forked`

---

## 🔴 遷移後立即損壞（優先處理）

### plugin.php — 外掛自動更新

```
行 78：'github_repo' => 'https://github.com/j7-dev/wp-power-course'
```
- [ ] 改為 `https://github.com/zenbuapps/wp-power-course`
- **影響**：Powerhouse GitHub Updater 會向舊 repo 查詢版本，導致用戶端無法自動更新外掛。

### plugin.php — Powerhouse 自動安裝 URL

```
行 69：'source' => 'https://github.com/j7-dev/wp-powerhouse/releases/latest/download/powerhouse.zip'
```
- [ ] 改為 `https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip`
- **影響**：新用戶啟用外掛時，TGM Plugin Activation 無法下載並安裝 Powerhouse，依賴安裝流程完全中斷。

---

## 🟠 開發環境損壞

### .wp-env.json — 開發環境 Powerhouse 來源

```json
行 8："https://github.com/j7-dev/wp-powerhouse/releases/latest/download/powerhouse.zip"
```
- [ ] 改為 `https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip`
- **影響**：`wp-env start` 時無法下載 Powerhouse，本地開發環境啟動失敗。

### .devcontainer/setup.sh — GitHub Codespaces 設定

```bash
行 23：git clone --depth 1 https://github.com/j7-dev/wp-powerhouse.git "$PARENT_DIR/powerhouse"
```
- [ ] 改為 `https://github.com/zenbuapps/wp-powerhouse.git`
- **影響**：Codespaces 初始化腳本無法 clone Powerhouse，開發容器建置失敗。

### .github/workflows/、.github/actions/、.github/templates/ — Claude Code Action Plugin Marketplace

```
pipe.yml / issue.yml / actions/claude-retry/action.yml / templates/acceptance-comment.md：
  plugin_marketplaces: https://github.com/zenbuapps/zenbu-powers.git
  plugins:             zenbu-powers@zenbu-powers
  agent namespace:     zenbu-powers:clarifier / :planner / :tdd-coordinator / :browser-tester / :issue-creator
```
- [x] `zenbuapps/zenbu-powers`（marketplace URL，已完成）
- [x] `zenbu-powers@zenbu-powers`（plugin@marketplace ref，已完成）
- [x] `zenbu-powers:*`（agent namespace，已完成）
- **影響**：原 repo 已更名並遷移至 `zenbuapps/zenbu-powers`，若未同步，CI 無法解析 marketplace 與 agent，整條 Claude Code pipeline（pipe.yml / issue.yml）失效。

---

## 🟡 文件與 Metadata 更新

### plugin.php — WordPress 外掛 Header

```php
行 5：Plugin URI: https://github.com/j7-dev/wp-power-course
行 11：Author URI: https://github.com/j7-dev
```
- [ ] `Plugin URI` 改為 `https://github.com/zenbuapps/wp-power-course`
- [ ] `Author URI` 視需求更新

### composer.json — 套件名稱

```json
行 2："name": "j7-dev/power-course"
```
- [ ] 改為 `"name": "zenbuapps/power-course"`（若此套件有透過 Packagist 發佈）
- **注意**：若未發佈至 Packagist，此項目影響有限，但建議同步更新保持一致性。

### README.md 與 README.zh-TW.md

以下連結在兩份 README 中各出現一次，需同步修改：

| 行號 | 內容 |
|------|------|
| 5 | Badge 連結 `j7-dev/wp-power-course/releases` |
| 119 | Powerhouse 連結 `j7-dev/wp-powerhouse` |
| 130 | 安裝說明下載連結 `j7-dev/wp-power-course/releases` |
| 381 | GitHub Repository 連結 |
| 382 | Author 連結 `github.com/j7-dev` |
| 383 | Powerhouse Plugin 連結 `j7-dev/wp-powerhouse` |

- [ ] 將上述所有 `j7-dev/wp-power-course` 替換為 `zenbuapps/wp-power-course`
- [ ] 將上述所有 `j7-dev/wp-powerhouse` 替換為 `zenbuapps/wp-powerhouse`
- [ ] `github.com/j7-dev` 作者連結視需求更新

### .github/ISSUE_TEMPLATE/需求模板.md

```yaml
assignees: j7-dev
```
- [ ] 改為新的 GitHub 帳號名稱
- **影響**：新建 Issue 時預設指派對象錯誤（非 blocker，但會造成通知遺漏）。

---

## ⚙️ GitHub 平台操作（無法用程式碼修改）

### 1. Repo Transfer 本身

操作路徑：`Settings → General → Danger Zone → Transfer ownership`

- [ ] 在 `j7-dev/wp-power-course` 執行 Transfer 至 `zenbuapps`
- [ ] 在 `j7-dev/wp-powerhouse` 執行 Transfer 至 `zenbuapps`

**GitHub Transfer 行為確認：**
| 項目 | 是否跟著搬移 |
|------|-------------|
| Issues、PRs、Wikis | ✅ 搬移 |
| Releases & 附件 zip | ✅ 搬移 |
| Stars、Watchers | ❌ **不搬移**，清零 |
| Forks | ❌ **不搬移**，與新 repo 斷開 |
| 舊 URL 301 重導向 | ✅ 自動建立，**有效期約 1 年** |

### 2. GitHub Actions Secrets & Variables

- [ ] 進入 `zenbuapps/wp-power-course` → Settings → Secrets and variables → Actions
- [ ] 重新設定所有 Secrets（不會自動複製）
- 常見需要重建的項目：`GH_TOKEN`、`COMPOSER_AUTH`、`SLACK_WEBHOOK`、Deploy Key 等

### 3. Branch Protection Rules

- [ ] 在新 repo 重新設定 `master` 的保護規則（Branch protection 不隨 Transfer 搬移）

### 4. Webhooks

- [ ] 檢查舊 repo 的 Webhooks 清單（Settings → Webhooks）
- [ ] 在新 repo 重新設定（如有串接 Slack、Discord、第三方 CI 等）

### 5. GitHub Pages（如有使用）

- [ ] 遷移後需重新啟用 Pages 設定

---

## 📋 確認不需修改的項目

以下項目**已確認不在修改範圍**，勿誤改：

| 項目 | 原因 |
|------|------|
| `composer.json` 的 `j7-dev/wp-plugin-trait` | 此套件留在 j7-dev |
| `composer.lock` 的 `j7-dev/tgm-plugin-activation-forked` | 此套件留在 j7-dev |
| PHP namespace `J7\PowerCourse` | Namespace 與 GitHub 組織名稱無關，不需修改 |

---

## ✅ 遷移完成驗證清單

- [ ] `wp-env start` 成功，Powerhouse 正常載入
- [ ] 前台安裝 Power Course 時，Powerhouse 可自動提示安裝
- [ ] GitHub Actions `pipe.yml` 正常執行（推送測試 commit）
- [ ] GitHub Actions `issue.yml` 正常執行（建立測試 Issue）
- [ ] 外掛後台顯示有可用更新（或確認無新版本可更新）





