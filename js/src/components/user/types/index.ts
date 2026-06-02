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
 *
 * 註：為向下相容既有 StudentEditModal 與 E2E 而保留；雙欄卡片新元件改吃巢狀的
 * `billing` / `shipping` 物件（見 TUserInfo）。
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
 * 帳單 / 運送資訊物件型別
 * 對應後端 GET users/{id} 回傳的 billing / shipping 巢狀物件，
 * 比照 power-shop `TOrderInfo`。
 */
export type TUserInfo = {
	first_name: string
	last_name: string
	email?: string
	phone?: string
	company?: string
	postcode: string
	country: string
	state: string
	city: string
	address_1: string
	address_2: string
}

/**
 * 使用者購物車 / 訂單內的單一商品項目
 * 對應後端 cart[] 與 recent_orders[].order_items[] 元素。
 */
export type TUserCartItem = {
	product_id: string
	product_name: string
	quantity: number
	price: number
	line_total: number
	product_image: string
}

/**
 * 最近訂單單筆型別
 * 對應後端 GET users/{id} 回傳的 recent_orders[] 元素。
 */
export type TUserRecentOrder = {
	order_id: string
	order_date: string
	order_total: number
	order_status: string
	order_items: TUserCartItem[]
}

/**
 * 聯絡註記單筆型別
 * 對應後端 GET users/{id} 的 contact_remarks[] 與 GET comments 回傳元素。
 */
export type TUserContactRemark = {
	id: number
	content: string
	date_created: string
	customer_note: boolean
	added_by: string
	user_id: string
	/** 被留言的用戶 id */
	commented_user_id: string
}

/**
 * 其他 user_meta 欄位（危險操作區）型別
 * 對應後端 GET users/{id} 的 other_meta_data[] 元素。
 */
export type TUserOtherMeta = {
	umeta_id: string
	meta_key: string
	meta_value: string
}

/**
 * 使用者詳細資料型別
 * 對應後端 GET users/{id} 回傳的扁平物件。
 *
 * 數字欄位（total_spend / orders_count / avg_order_value）與日期欄位
 * （date_last_active / date_last_order）比照 antd-toolkit/wp 的 TUserBaseRecord，
 * 後端在無資料時回傳 null。
 *
 * 註：保留既有 `email` / `meta_data`（向下相容既有元件與 E2E）；新雙欄卡片元件
 * 改吃 `user_email` 與巢狀 `billing` / `shipping` / `cart` / `recent_orders` 等欄位。
 */
export type TUserDetail = {
	id: string
	user_login: string
	name: string
	display_name: string
	/** 既有欄位（向下相容） */
	email: string
	/** 既有欄位（向下相容） */
	meta_data: TUserMeta
	// ── 新雙欄卡片欄位（鏡像 antd-toolkit/wp TUserBaseRecord） ──
	user_email: string
	first_name: string
	last_name: string
	description: string
	role: string
	user_birthday: string
	user_avatar_url: string
	user_registered: string
	user_registered_human: string
	total_spend: number | null
	orders_count: number | null
	avg_order_value: number | null
	date_last_active: string | null
	date_last_order: string | null
	edit_url: string
	billing: TUserInfo
	shipping: TUserInfo
	cart: TUserCartItem[]
	recent_orders: TUserRecentOrder[]
	contact_remarks: TUserContactRemark[]
	other_meta_data: TUserOtherMeta[]
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
