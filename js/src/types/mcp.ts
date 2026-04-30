/**
 * MCP（Model Context Protocol）相關型別定義
 *
 * 對應後端 REST 端點：
 *   - /power-course/v2/mcp/settings
 *   - /power-course/v2/mcp/tokens
 *   - /power-course/v2/mcp/activity
 */

/**
 * MCP 可用的 Tool Category
 *
 * 對應後端 41 個 MCP tools 分類（9 大類）。
 */
export type TMcpCategory =
	| 'course'
	| 'chapter'
	| 'student'
	| 'teacher'
	| 'bundle'
	| 'order'
	| 'progress'
	| 'comment'
	| 'report'

/**
 * 9 個 category 的顯示資訊（按固定順序排列）
 */
export const MCP_CATEGORIES: {
	key: TMcpCategory
	label: string
	toolCount: number
}[] = [
	{ key: 'course', label: '課程', toolCount: 6 },
	{ key: 'chapter', label: '章節', toolCount: 7 },
	{ key: 'student', label: '學員', toolCount: 9 },
	{ key: 'teacher', label: '講師', toolCount: 4 },
	{ key: 'bundle', label: '銷售方案', toolCount: 4 },
	{ key: 'order', label: '訂單', toolCount: 3 },
	{ key: 'progress', label: '學習進度', toolCount: 3 },
	{ key: 'comment', label: '留言', toolCount: 3 },
	{ key: 'report', label: '報表', toolCount: 2 },
]

/**
 * MCP 整體設定
 */
export type TMcpSettings = {
	/** 是否啟用 MCP Server */
	enabled: boolean
	/** 允許 AI 呼叫的 tool categories */
	enabled_categories: TMcpCategory[]
	/** 每分鐘 rate limit（可選） */
	rate_limit?: number
	/** 允許 AI 修改資料（OP_UPDATE 類 tool）— Issue #217 */
	allow_update?: boolean
	/** 允許 AI 刪除資料（OP_DELETE 類 tool）— Issue #217 */
	allow_delete?: boolean
}

/**
 * MCP API Token（不含 plaintext）
 */
export type TMcpToken = {
	id: number
	name: string
	capabilities: TMcpCategory[]
	last_used_at: string | null
	created_at: string
}

/**
 * 建立 Token 的回傳型別（包含明文，只在建立時出現一次）
 */
export type TMcpTokenCreateResponse = TMcpToken & {
	/** 明文 Token，只在建立時出現一次 */
	plaintext_token: string
}

/**
 * MCP Activity Log 單筆紀錄
 */
export type TMcpActivity = {
	id: number
	user_id: number
	user_display_name: string
	tool_name: string
	params: Record<string, unknown>
	result_summary: string
	success: boolean
	created_at: string
}
