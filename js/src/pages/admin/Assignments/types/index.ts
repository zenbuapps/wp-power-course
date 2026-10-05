/**
 * 作業批改頁型別定義（Issue #131 P7）
 *
 * 欄位對齊後端 `J7\PowerCourse\Resources\Assignment\Service\Reporting`
 * （`list_submissions` / `stats` / `courses_with_submissions` / `student_overview`）
 * 與 specs/api/api.yml（tag Assignments，管理端 `/assignment-submissions*`）。
 */

/** 批改狀態（後端 pc_assignment_submissions.status） */
export const SUBMISSION_STATUS = {
	PENDING: 'pending',
	PASS: 'pass',
	FAIL: 'fail',
} as const

/** 批改狀態型別 */
export type TSubmissionStatus =
	(typeof SUBMISSION_STATUS)[keyof typeof SUBMISSION_STATUS]

/** 狀態篩選的「不限」值（後端 SubmissionFilter::STATUS_ALL） */
export const STATUS_ALL = 'all'

/** 狀態篩選值 */
export type TStatusFilter = TSubmissionStatus | typeof STATUS_ALL

/** 課程分頁的「全部」分頁 key */
export const COURSE_TAB_ALL = 'all'

/** 附件大類（後端 Utils::resolve_attachment_kind() 的回傳值） */
export const ATTACHMENT_KIND = {
	IMAGE: 'image',
	VIDEO: 'video',
	AUDIO: 'audio',
	PDF: 'pdf',
	DOC: 'doc',
	SPREADSHEET: 'spreadsheet',
	ARCHIVE: 'archive',
	FILE: 'file',
} as const

/** 附件大類型別 */
export type TAttachmentKind =
	(typeof ATTACHMENT_KIND)[keyof typeof ATTACHMENT_KIND]

/** 繳交附件（API 不輸出 stored_path 與 URL，下載一律走權限端點） */
export type TSubmissionAttachment = {
	/** 附件 ID（下載端點的路徑參數） */
	attachment_id: number
	/** 原始檔名 */
	file_name: string | null
	/** 附件大類 */
	kind: TAttachmentKind
}

/** 作業繳交（批改列表的單筆資料） */
export type TSubmissionRecord = {
	/** 繳交紀錄 ID */
	id: number
	/** 作業 post ID */
	assignment_id: number
	/** 學員 user ID */
	user_id: number
	/** 學員顯示名稱 */
	customer_name: string | null
	/** 學員 Email */
	customer_email: string | null
	/** 作業標題 */
	assignment_title: string | null
	/** 所屬課程 ID */
	course_id: number | null
	/** 所屬課程標題 */
	course_title: string | null
	/** 學員繳交的文字內容 */
	text_body: string | null
	/** 繳交時間（站台時區字串） */
	submitted_at: string | null
	/** 批改狀態 */
	status: TSubmissionStatus
	/** 講師評語 */
	feedback: string | null
	/** 批改者 user ID */
	graded_by: number | null
	/** 最後批改時間（站台時區字串），未批改為 null */
	graded_at: string | null
	/** 附件清單 */
	attachments: TSubmissionAttachment[]
	/** 討論未讀數（第一波恆為 0） */
	unread_count: number
	/** 討論訊息總數（第一波恆為 0） */
	message_count: number
	/** 最後一則討論訊息時間（第一波恆為 null） */
	last_message_at: string | null
}

/** 繳交列表回應（後端回 {items, total}，不符 Refine 預設分頁契約） */
export type TSubmissionListResponse = {
	/** 本頁資料 */
	items: TSubmissionRecord[]
	/** 符合篩選條件的總筆數 */
	total: number
}

/** 批改統計 */
export type TSubmissionStats = {
	/** 全部繳交數 */
	total: number
	/** 待批改數 */
	pending: number
	/** 通過數 */
	pass: number
	/** 未通過數 */
	fail: number
	/** 通過率（通過 / 已判定，%；分母 0 時為 0） */
	pass_rate: number
	/** 平均批改時間（秒），無已批改紀錄時為 null */
	avg_grading_seconds: number | null
}

/** 有繳交紀錄的課程（課程分頁） */
export type TCourseWithSubmissions = {
	/** 課程（商品）ID */
	id: number
	/** 課程標題 */
	title: string
	/** 該課程的繳交數 */
	count: number
}

/** 學生背景資料（詳情右欄） */
export type TStudentOverview = {
	/** 基本資料 */
	user: {
		/** 學員 user ID */
		id: number
		/** 顯示名稱 */
		name: string | null
		/** Email */
		email: string | null
		/** 註冊時間（站台時區字串） */
		registered_at: string | null
	}
	/** 所屬課程 */
	course: {
		/** 課程 ID */
		id: number | null
		/** 課程標題 */
		title: string | null
	}
	/** 本課程作業統計 */
	stats: {
		/** 已繳交 */
		submitted: number
		/** 已通過 */
		passed: number
		/** 待批改 */
		pending: number
		/** 未通過 */
		failed: number
	}
	/** 本課程學習進度 */
	progress: {
		/** 已完成章節數 */
		completed_chapters: number
		/** 章節總數 */
		total_chapters: number
		/** 完成百分比 */
		percent: number
	}
}

/** 批改送出的 request body */
export type TGradePayload = {
	/** 批改結果 */
	status: TSubmissionStatus
	/** 講師評語（空字串等同清空） */
	feedback: string
}

/**
 * 批改回應（api.yml AssignmentSubmission，後端 `Grading::grade()->to_array()`）
 *
 * 附件只有 attachment_id / file_name、沒有 kind，也沒有學員 / 課程等補充欄位，
 * 所以不能直接取代列表那筆，只拿來合併批改相關欄位。
 */
export type TGradeResponse = Pick<
	TSubmissionRecord,
	| 'id'
	| 'assignment_id'
	| 'user_id'
	| 'text_body'
	| 'submitted_at'
	| 'status'
	| 'feedback'
	| 'graded_by'
	| 'graded_at'
> & {
	/** 附件參照 */
	attachments: Pick<TSubmissionAttachment, 'attachment_id' | 'file_name'>[]
}

/** 繳交列表 / 統計的查詢參數 */
export type TSubmissionQuery = {
	/** 課程 ID，未指定時不限 */
	course_id?: number
	/** 批改狀態 */
	status?: TStatusFilter
	/** 每頁筆數（後端夾限 1～200） */
	limit?: number
	/** 位移 */
	offset?: number
}
