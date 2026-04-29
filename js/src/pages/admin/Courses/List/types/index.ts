import { TVideo } from '@/components/formItem/VideoInput/types'
import {
	TTerm,
	TStockStatus,
	TProductType,
	TProductAttribute,
	TImage,
} from '@/components/product/ProductTable/types'

// TUserRecord / TAVLCourse / TExpireDate 於 #196 重構時搬到 @/components/user/types
// 若需要這些型別請直接從 @/components/user/types import，本檔不再 re-export

// List 只會拿基本的欄位
export type TCourseBaseRecord = {
	id: string
	type: TProductType
	name: string
	slug: string
	date_created: string
	date_modified: string
	status: string
	featured: boolean
	catalog_visibility: string
	sku: string
	menu_order: number
	virtual: boolean
	downloadable: boolean
	permalink: string
	edit_url: string
	custom_rating: number
	extra_review_count: number
	price_html: string
	regular_price: string
	sale_price: string
	on_sale: boolean
	date_on_sale_from: string | null
	date_on_sale_to: string | null
	total_sales: number
	stock: number | null
	stock_status: TStockStatus
	manage_stock: boolean
	stock_quantity: number | null
	backorders: 'yes'
	backorders_allowed: boolean
	backordered: boolean
	low_stock_amount: number | null
	categories: TTerm[]
	tags: TTerm[]
	images: TImage[]
	is_course: 'yes' | 'no' | ''
	is_free: 'yes' | 'no' | ''
	hours: number
	course_schedule: number
	course_hour: number
	course_minute: number
	course_length: number
	classroom_link: string
	product_url: string
	button_text: string
}

export type TCoursesLimit = {
	limit_type: 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	limit_value: number | ''
	limit_unit: 'second' | 'day' | 'month' | 'year' | ''
}

// Edit, Show, Create 會拿全部的欄位
export type TCourseRecord = TCourseBaseRecord &
	TCoursesLimit & {
		purchase_note: string
		description: string
		short_description: string
		upsell_ids: number[]
		cross_sell_ids: number[]
		attributes: TProductAttribute[]
		chapters?: TChapterRecord[]
		qa_list: {
			question: string
			answer: string
		}[]
		is_popular: 'yes' | 'no' | ''
		is_featured: 'yes' | 'no' | ''
		show_join: 'yes' | 'no' | ''
		show_review: 'yes' | 'no' | ''
		reviews_allowed: boolean
		show_review_tab: 'yes' | 'no' | ''
		show_review_list: 'yes' | 'no' | ''
		show_total_sales: 'yes' | 'no' | ''
		show_rest_stock: 'yes' | 'no' | ''
		show_total_student: 'yes' | 'no' | ''
		teacher_ids: string[]
		enable_comment: 'yes' | 'no' | ''
		hide_single_course: 'yes' | 'no' | ''
		extra_student_count: number
		feature_video: TVideo
		/** @deprecated Issue #10：改用 trial_videos 陣列；保留供向下相容讀取 */
		trial_video: TVideo
		/** Issue #10：多影片試看（最多 6 部） */
		trial_videos: TVideo[]
		editor: 'power-editor' | 'elementor' | ''
	}

export type TChapterRecord = {
	id: string
	type: 'chapter'
	status: string
	depth: number
	name: string
	slug?: string
	date_created?: string
	date_modified?: string
	catalog_visibility?: string
	description?: string
	short_description?: string
	sku?: undefined
	menu_order?: number
	total_sales?: undefined
	permalink?: string
	chapter_length: number
	category_ids?: string[]
	tag_ids?: string[]
	images?: TImage[]
	chapters?: TChapterRecord[]
	parent_id?: string
	chapter_video?: TVideo
	enable_comment: 'yes' | 'no'
	editor: 'power-editor' | 'elementor' | ''
}
