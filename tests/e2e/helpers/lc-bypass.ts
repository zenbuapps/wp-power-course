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
/**
 * 同時執行的 run 清單（PID）。
 *
 * 沒有它時，兩個同時跑的 playwright run 會互相拆台：後啟動的 run 看到 lc 已注入
 * 就跳過（也不會留下備份），先結束的 run 卻把 plugin.php 還原回去，後者剩下的
 * 測試全部撞上 License Check 而假紅——而且紅得像功能壞掉。改成引用計數後，
 * 只有最後一個離開的 run 才還原。
 */
const LOCK_FILE = PLUGIN_FILE + '.e2e-lock'

// 用來匹配 init() 呼叫中的 capability 行（最後一個 key），在其後插入 'lc' => false
// 注意：實際 plugin.php 的該行尾無逗號（因為是陣列最後一個 key），使用 regex 兼容兩種變體。
// 注入時若原本無尾逗號，callback 會自動補上，確保注入後語法正確。
const CAPABILITY_LINE_REGEX = /'capability'\s*=>\s*'manage_woocommerce'(,?)/
const LC_LINE = "\t\t\t\t\t'lc'          => false,"

/**
 * 讀取仍存活的持有者 PID
 *
 * 崩潰的 run 會把自己的 PID 留在鎖檔裡，用 kill(pid, 0) 剔除，
 * 否則一次 ctrl-c 就會讓 lc 永遠還原不掉。
 */
function readLiveHolders(): number[] {
	if (!fs.existsSync(LOCK_FILE)) {
		return []
	}

	try {
		const raw: unknown = JSON.parse(fs.readFileSync(LOCK_FILE, 'utf-8'))
		const pids = Array.isArray(raw) ? (raw as number[]) : []
		return pids.filter((pid) => {
			try {
				process.kill(pid, 0)
				return true
			} catch {
				return false
			}
		})
	} catch {
		return []
	}
}

/**
 * 寫回持有者清單；清單空了就刪掉鎖檔
 *
 * @param pids 仍存活的持有者 PID
 */
function writeHolders(pids: number[]): void {
	if (!pids.length) {
		if (fs.existsSync(LOCK_FILE)) {
			fs.unlinkSync(LOCK_FILE)
		}
		return
	}

	fs.writeFileSync(LOCK_FILE, JSON.stringify(pids), 'utf-8')
}

/**
 * 套用 LC bypass — 在 init() 的 capability 行後插入 'lc' => false
 */
export function applyLcBypass(): void {
	const holders = readLiveHolders()
	writeHolders([...new Set([...holders, process.pid])])

	const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')

	// 已經套用過了
	if (content.includes("'lc'") && content.includes('=> false')) {
		console.log(
			`[LC Bypass] Already applied, skipping. (holders: ${holders.length + 1})`,
		)
		return
	}

	// 備份原始檔案
	fs.writeFileSync(BACKUP_FILE, content, 'utf-8')

	// 插入 'lc' => false 在 capability 行之後；若 capability 行無尾逗號，自動補上
	const modified = content.replace(
		CAPABILITY_LINE_REGEX,
		(match, trailingComma) => {
			const capabilityWithComma = trailingComma ? match : match + ','
			return capabilityWithComma + '\n' + LC_LINE
		},
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
	const remaining = readLiveHolders().filter((pid) => pid !== process.pid)
	writeHolders(remaining)

	if (remaining.length) {
		console.log(
			`[LC Bypass] Kept applied: ${remaining.length} other run(s) still using it.`,
		)
		return
	}

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
