/**
 * MCP（Model Context Protocol）相關型別定義
 *
 * 對應後端 REST 端點：
 *   - /power-course/mcp/settings
 *   - /power-course/mcp/tokens（Issue #230，於 AI tab 內管理 Bearer Token）
 */

/**
 * MCP 可用的 Tool Category
 *
 * 對應後端 MCP tools 分類（10 大類）。
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
	| 'subtitle'

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
 * MCP Bearer Token（列表項目）— Issue #230
 *
 * 對應 GET /power-course/mcp/tokens 的回傳項目。
 * 後端只回非敏感欄位，不含 token_hash 與明文。
 */
export type TMcpToken = {
	/** Token 資料庫 ID */
	id: number
	/** 使用者標記的名稱 */
	name: string
	/** 最後使用時間（ISO 字串）；從未使用為 null */
	last_used_at: string | null
	/** 建立時間（ISO 字串） */
	created_at: string
	/** 到期時間（ISO 字串）；null = 永不過期 */
	expires_at: string | null
}

/**
 * 建立 MCP Token 的回應 payload（明文僅此一次）— Issue #230
 *
 * 對應 POST /power-course/mcp/tokens 的 data 欄位。
 */
export type TMcpTokenCreateResponse = {
	/** 新建立的 Token 資料庫 ID */
	id: number
	/** Token 名稱 */
	name: string
	/** 明文 Token，僅建立時回傳一次（對齊後端 `token` 欄位） */
	token: string
	/** 僅顯示一次的警示文字 */
	warning: string
}

/**
 * 查看 MCP Token 明文的回應 payload — Issue #230
 *
 * 對應 GET /power-course/mcp/tokens/{id}/reveal 的 data 欄位（owner-only）。
 * 與建立回應不同，不含 warning（Token 可重複查看）。
 */
export type TMcpTokenRevealResponse = {
	/** Token 資料庫 ID */
	id: number
	/** Token 名稱 */
	name: string
	/** 明文 Token */
	token: string
}

/**
 * 建立 Token 的有效期限選項值（Issue #230 Q1）
 *
 * 數字字串對應 expires_days；`never` 代表永不過期（預設）。
 */
export type TMcpTokenExpiresOption = '30' | '90' | '365' | 'never'
