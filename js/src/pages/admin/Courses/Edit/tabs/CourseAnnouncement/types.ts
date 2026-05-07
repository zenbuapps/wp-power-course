/**
 * 課程公告資料型別
 */

export type TAnnouncementVisibility = 'public' | 'enrolled'

export type TAnnouncementStatusLabel =
	| 'active'
	| 'scheduled'
	| 'expired'
	| 'draft'

export type TAnnouncement = {
	id: string
	post_title: string
	post_content: string
	/**
	 * 後端 normalize 後的 post_status：
	 * - publish：已發佈（未到 end_at）
	 * - future：排程中（post_date 在未來）
	 * - draft：草稿（不公開、未排程）
	 * - trash：垃圾桶
	 */
	post_status: 'publish' | 'future' | 'draft' | 'trash' | string
	post_date: string
	post_date_gmt: string
	post_modified: string
	post_parent: number
	parent_course_id: number
	end_at: number | ''
	visibility: TAnnouncementVisibility
	editor: string
	status_label: TAnnouncementStatusLabel | string
}

export type TAnnouncementFormValues = {
	post_title: string
	post_content?: string
	/**
	 * 表單送出時的意圖：publish（發佈意圖，後端會依 post_date 自動 normalize 為 publish/future）
	 * 或 draft（儲存為草稿）。
	 */
	post_status: 'publish' | 'draft'
	post_date?: string
	end_at?: number | ''
	visibility: TAnnouncementVisibility
}
