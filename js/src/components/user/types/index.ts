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
}

// 內部型別：章節（目前僅 TUserRecord 的衍生型別可能用到，暫保留命名空間）
export type { TChapter }
