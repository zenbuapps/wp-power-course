/**
 * 課程公告資料型別
 */

export type TAnnouncementVisibility = 'public' | 'enrolled'

export type TAnnouncementStatusLabel = 'active' | 'scheduled' | 'expired'

export type TAnnouncement = {
	id: string
	post_title: string
	post_content: string
	post_status: 'publish' | 'future' | 'trash' | string
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
	post_status: 'publish' | 'future'
	post_date?: string
	end_at?: number | ''
	visibility: TAnnouncementVisibility
}
