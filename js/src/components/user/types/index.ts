type TChapter = {
	id: string
	name: string
	chapter_video: string
	is_finished: boolean
}

export type TExpireDate = {
	is_subscription: boolean
	subscription_id: number | null
	is_expired: boolean
	timestamp: number | null
}

export type TAVLCourse = {
	id: string
	name: string
	progress: number
	total_chapters_count: number
	finished_chapters_count: number
	expire_date: TExpireDate
}

export type TUserRecord = {
	id: string
	user_login: string
	user_email: string
	display_name: string
	formatted_name: string
	user_registered: string
	user_registered_human: string
	user_avatar_url: string
	avl_courses: TAVLCourse[]
	is_teacher: boolean
	billing_phone?: string
}

/**
 * 使用者 meta 資料型別
 * 對應後端 GET users/{id} 回傳的 meta_data 物件（全部為字串）
 */
export type TUserMeta = {
	first_name: string
	last_name: string
	billing_first_name: string
	billing_last_name: string
	billing_email: string
	billing_phone: string
	billing_address_1: string
	billing_address_2: string
	billing_city: string
	billing_state: string
	billing_postcode: string
	billing_country: string
	shipping_first_name: string
	shipping_last_name: string
	shipping_address_1: string
	shipping_address_2: string
	shipping_city: string
	shipping_state: string
	shipping_postcode: string
	shipping_country: string
}

/**
 * 使用者詳細資料型別
 * 對應後端 GET users/{id} 回傳的扁平物件
 */
export type TUserDetail = {
	id: string
	user_login: string
	name: string
	display_name: string
	email: string
	meta_data: TUserMeta
}

/**
 * 訂單摘要單筆項目型別
 * 對應後端 GET users/{id}/orders-summary 的 recent 陣列元素
 */
export type TOrderSummaryItem = {
	id: number
	number: string
	date_created: string
	status: string
	total: string
	currency: string
	edit_url: string
}

/**
 * 訂單摘要型別
 * 對應後端 GET users/{id}/orders-summary 回傳結構
 */
export type TOrdersSummary = {
	total: number
	view_all_url: string
	recent: TOrderSummaryItem[]
}

// 內部型別：章節（目前僅 TUserRecord 的衍生型別可能用到，暫保留命名空間）
export type { TChapter }
