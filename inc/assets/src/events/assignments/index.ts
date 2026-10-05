/**
 * 教室作業區塊的前台互動入口（Issue #131 P8）
 *
 * 區塊本體（#pc-classroom-assignments）由 PHP 伺服器端渲染
 * （inc/templates/components/assignment/{section,overview,card,thread}.php），
 * 這裡只掛上「上傳附件」、「繳交」與「討論串」（第二波 DEC-W2-19）三個行為；
 * 管理員預覽模式（data-readonly="yes"）完全不綁事件，表單已在 PHP 端 disabled，討論串也不輸出。
 */
/* eslint-disable @typescript-eslint/no-explicit-any */
import { bindAssignmentSubmit } from './submit'
import { bindAssignmentThread } from './thread'
import { bindAssignmentUpload } from './upload'

export function assignments(): void {
	const section = document.getElementById('pc-classroom-assignments')
	if (!section) return

	// 管理員預覽：表單與按鈕全部停用，不提供任何互動
	if (section.dataset.readonly === 'yes') return

	const restUrl = section.dataset.restUrl || ''
	if (!restUrl) return

	const nonce = String((window as any).pc_data?.nonce || '')

	bindAssignmentUpload(section, restUrl, nonce)
	bindAssignmentSubmit(section, restUrl, nonce)
	bindAssignmentThread(section, restUrl, nonce)
}
