#!/bin/bash
set -e

WORKSPACE_DIR=$(pwd)
PARENT_DIR=$(dirname "$WORKSPACE_DIR")

# ---------- PHP 設定 ----------

# Codespaces 預設 memory_limit=128M，phpstan 需要更多記憶體
PHP_INI_DIR=$(php -r "echo php_ini_scanned_dir();" 2>/dev/null || echo "/usr/local/etc/php/conf.d")
echo "memory_limit = 4096M" | sudo tee "$PHP_INI_DIR/99-devcontainer.ini" > /dev/null
echo "→ PHP memory_limit set to 4096M"

# 安裝 pnpm
npm install -g pnpm@10.32.0

# ---------- PHP 依賴 ----------

# 若 powerhouse 不存在（standalone 模式，例如 GitHub Codespaces），則 clone 它
# phpstan.neon bootstrapFiles 引用 ../powerhouse/plugin.php 與 ../powerhouse/vendor/autoload.php
if [ ! -d "$PARENT_DIR/powerhouse" ]; then
  echo "→ Cloning powerhouse (needed by phpstan)..."
  git clone --depth 1 https://github.com/p9-cloud/wp-powerhouse.git "$PARENT_DIR/powerhouse"
fi

echo "→ Installing powerhouse composer dependencies..."
cd "$PARENT_DIR/powerhouse"
composer install --no-interaction

echo "→ Installing power-course composer dependencies..."
cd "$WORKSPACE_DIR"
composer install --no-interaction

# ---------- JS 依賴 ----------

echo "→ Installing JS dependencies..."
cd "$WORKSPACE_DIR"
pnpm install --no-frozen-lockfile

echo "✓ Setup complete"
