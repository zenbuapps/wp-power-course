/**
 * 課程權限包（Access Pass）型別定義（Issue #252）
 *
 * 對應後端 REST API `power-course/access-passes` 與 api.yml 的 AccessPass schema。
 */

/** 範圍類型：all=全站（動態）；category=分類標籤聯集含子分類（動態）；specific=固定課程清單 */
export type TScopeType = 'all' | 'category' | 'specific'

/** 期限模式：permanent=永久；follow_subscription=跟隨訂閱；limited=限時 N 單位 */
export type TLimitMode = 'permanent' | 'follow_subscription' | 'limited'

/** 限時模式單位 */
export type TLimitUnit = 'day' | 'month' | 'year'

/** 狀態：active=啟用中；disabled=已停用（不可掛新商品，已購用戶權限保留） */
export type TAccessPassStatus = 'active' | 'disabled'

/**
 * 課程權限包列表 / 詳情記錄（後端 AccessPass::to_array() + Query 注入 attached_product_count）
 */
export type TAccessPassRecord = {
	/** 權限包 post ID */
	id: number
	/** 權限包名稱（wp_posts.post_title） */
	name: string
	/** 範圍類型 */
	scope_type: TScopeType
	/** 期限模式 */
	limit_mode: TLimitMode
	/** 限時模式數值（僅 limit_mode=limited） */
	limit_value: number | null
	/** 限時模式單位（僅 limit_mode=limited） */
	limit_unit: TLimitUnit | null
	/** 狀態 */
	status: TAccessPassStatus
	/** category 範圍的 term id 清單（product_cat / product_tag 聯集，含子分類） */
	term_ids: number[]
	/** specific 範圍的固定課程 id 清單 */
	course_ids: number[]
	/** 已掛載此權限包的商品數 */
	attached_product_count: number
}

/**
 * Create / Edit 表單值
 *
 * 注意：term_ids / course_ids 在表單中以字串陣列承載（antd Select value），
 * 送出時後端會 absint 清洗，故型別放寬為 (number | string)[]。
 */
export type TAccessPassFormValues = {
	name: string
	scope_type: TScopeType
	limit_mode: TLimitMode
	limit_value?: number | ''
	limit_unit?: TLimitUnit | ''
	term_ids?: (number | string)[]
	course_ids?: (number | string)[]
}
