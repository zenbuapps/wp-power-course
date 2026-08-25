/**
 * 課程權限包（Access Pass）型別定義（Issue #252）
 *
 * 對應後端 REST API `power-course/access-passes` 與 api.yml 的 AccessPass schema。
 */

/** 範圍類型：all=全站（動態）；category=分類標籤聯集含子分類（動態）；specific=固定課程清單 */
export type TScopeType = 'all' | 'category' | 'specific'

/**
 * 期限類型：
 * - unlimited=永久 / 無限制
 * - fixed=購買後固定 N 單位（day/month/year）
 * - assigned=指定到期時間（limit_value 為絕對 Unix 秒級 timestamp，limit_unit='timestamp'）
 * - follow_subscription=跟隨訂閱狀態
 */
export type TLimitType =
	| 'unlimited'
	| 'fixed'
	| 'assigned'
	| 'follow_subscription'

/** 限時模式單位（fixed 用 day/month/year；assigned 用 timestamp） */
export type TLimitUnit = 'day' | 'month' | 'year' | 'timestamp'

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
	/** 期限類型 */
	limit_type: TLimitType
	/** 期限數值（fixed=正整數；assigned=絕對 Unix 秒級 timestamp） */
	limit_value: number | null
	/** 期限單位（fixed=day/month/year；assigned=timestamp） */
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
	limit_type: TLimitType
	limit_value?: number | ''
	limit_unit?: TLimitUnit | ''
	term_ids?: (number | string)[]
	course_ids?: (number | string)[]
}

/** 持有關係的來源：manual=後台手動發放；order=一次性訂單；subscription=訂閱首期開通 */
export type THoldingSource = 'manual' | 'order' | 'subscription'

/**
 * 通行證持有學員（GET /access-passes/{id}/users 的單筆）
 *
 * 對應後端 Service\Holdings::get_holders_of_pass()。
 * is_valid 由 Gate::is_expire_valid() 判定，與教室的觀看判定同一份邏輯。
 */
export type TAccessPassHolder = {
	/** pc_user_access_pass 的列 id */
	id: number
	/** 學員 user ID */
	user_id: number
	/** 通行證 post ID */
	pass_id: number
	/** 通行證名稱 */
	name: string
	scope_type: TScopeType
	limit_type: TLimitType
	status: TAccessPassStatus
	/** 原始到期表達式：null/'0'=永久、10 位 timestamp=限時、'subscription_{id}'=跟隨訂閱 */
	expire_date: string | null
	/** 解析後的到期 timestamp（永久 / 跟隨訂閱為 null） */
	expire_timestamp: number | null
	/** 到期日（Y-m-d，無到期日時為空字串） */
	expire_date_human: string
	/** 剩餘天數（永久 / 跟隨訂閱為 null） */
	days_remaining: number | null
	/** 目前是否仍有觀看權 */
	is_valid: boolean
	/** 是否落在「即將到期」門檻內 */
	is_expiring_soon: boolean
	subscription_id: number | null
	subscription_status: string | null
	next_payment_date: string | null
	source: THoldingSource
	source_order_id: number | null
	/** 發放者 user ID（僅手動發放有值） */
	granted_by: number | null
	/** 取得時間（Y-m-d H:i:s） */
	granted_at: string | null
	display_name: string
	user_email: string
	user_login: string
	avatar_url: string
	granted_by_name: string
}

/** GET /access-passes/{id}/users 的回應 */
export type TAccessPassHoldersResponse = {
	items: TAccessPassHolder[]
	total: number
	total_pages: number
}
