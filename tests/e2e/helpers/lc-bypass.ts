/**
 * LC Bypass 工具
 *
 * 在測試期間自動注入 'lc' => false 到 plugin.php 的 init() 呼叫中，
 * 以跳過 License Check。測試結束後自動還原。
 *
 * ⚠️ 絕不 commit 修改過的 plugin.php
 */
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const PLUGIN_FILE = path.resolve(__dirname, '..', '..', '..', 'plugin.php')
const BACKUP_FILE = PLUGIN_FILE + '.e2e-backup'

// 用來匹配 init() 呼叫中的 capability 行（最後一個 key），在其後插入 'lc' => false
const CAPABILITY_LINE = "'capability'  => 'manage_woocommerce',"
const LC_LINE = "\t\t\t\t\t'lc'          => false,"

/**
 * 套用 LC bypass — 在 init() 的 capability 行後插入 'lc' => false
 */
export function applyLcBypass(): void {
	const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')

	// 已經套用過了
	if (content.includes("'lc'") && content.includes('=> false')) {
		console.log('[LC Bypass] Already applied, skipping.')
		return
	}

	// 備份原始檔案
	fs.writeFileSync(BACKUP_FILE, content, 'utf-8')

	// 插入 'lc' => false 在 capability 行之後
	const modified = content.replace(
		CAPABILITY_LINE,
		CAPABILITY_LINE + '\n' + LC_LINE,
	)

	if (modified === content) {
		throw new Error(
			'[LC Bypass] Failed to find capability line in plugin.php. File structure may have changed.',
		)
	}

	fs.writeFileSync(PLUGIN_FILE, modified, 'utf-8')
	console.log('[LC Bypass] Applied successfully.')
}

/**
 * 還原 LC bypass — 從備份還原 plugin.php
 */
export function revertLcBypass(): void {
	if (fs.existsSync(BACKUP_FILE)) {
		fs.copyFileSync(BACKUP_FILE, PLUGIN_FILE)
		fs.unlinkSync(BACKUP_FILE)
		console.log('[LC Bypass] Reverted successfully.')
	} else {
		// 沒有備份檔，嘗試直接移除 lc 行
		const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')
		const cleaned = content.replace(LC_LINE + '\n', '').replace(LC_LINE, '')
		fs.writeFileSync(PLUGIN_FILE, cleaned, 'utf-8')
		console.log('[LC Bypass] Reverted (no backup, removed lc line directly).')
	}
}

/**
 * 檢查 LC bypass 是否已套用
 */
export function isLcBypassApplied(): boolean {
	const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')
	return content.includes("'lc'") && content.includes('=> false')
}
