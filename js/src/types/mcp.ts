/**
 * MCP（Model Context Protocol）相關型別定義
 *
 * 對應後端 REST 端點：
 *   - /power-course/mcp/settings
 *
 * MCP 後台管理 tab（tokens / activity）已移除，
 * 僅保留 AI tab 用到的 settings flag 型別。
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
