/**
 * Playwright Global Teardown
 *
 * 測試結束後執行：
 * 1. 還原 LC bypass（恢復 plugin.php 原始內容）
 * 2. 清理暫存檔案
 */
import { revertLcBypass } from './helpers/lc-bypass'

async function globalTeardown(): Promise<void> {
	console.log('[Global Teardown] Reverting LC bypass...')
	try {
		revertLcBypass()
	} catch (error) {
		console.error('[Global Teardown] Failed to revert LC bypass:', error)
		// 不要因為還原失敗就讓整個測試報告失敗
	}

	console.log('[Global Teardown] Complete.')
}

export default globalTeardown
