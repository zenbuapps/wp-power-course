import { TUserRecord } from '@/components/user/types'

/**
 * 講師列表 record 型別
 *
 * 繼承 TUserRecord 並補上講師專屬 computed field（後端 ExtendQuery 掛 powerhouse
 * filter 附加至 response）與 WP 用戶基本資訊。
 */
export type TTeacherRecord = TUserRecord & {
	/** 負責課程數（computed field） */
	teacher_courses_count?: number
	/** 班級學員人數（computed field，跨課程去重） */
	teacher_students_count?: number
	/** WP user role slug，例：author / contributor / editor */
	role?: string
	/** 手機號碼（WC billing_phone） */
	billing_phone?: string
}

/**
 * 講師 Edit 頁完整 record 型別（GET /users/{id} context=edit）
 *
 * 來源：Powerhouse\Domains\User\Model\User::to_array('edit')
 */
export type TTeacherCartItem = {
	product_id: string
	product_name: string
	quantity: number
	price: number
	line_total: number
	product_image?: string
}

export type TTeacherOrderItem = {
	product_id: string
	product_name: string
	quantity: number
	price: number
	line_total: number
	product_image?: string
}

export type TTeacherOrder = {
	order_id: string
	order_date: string
	order_date_human?: string | null
	order_total: number
	order_status: string
	order_items?: TTeacherOrderItem[]
}

export type TTeacherDetails = TTeacherRecord & {
	first_name?: string
	last_name?: string
	description?: string
	user_url?: string
	recent_orders?: TTeacherOrder[]
	cart?: TTeacherCartItem[]
	other_meta_data?: Array<{
		umeta_id: string
		meta_key: string
		meta_value: string
	}>
	edit_url?: string
	date_last_active?: string | null
	date_last_order?: string | null
}
