/**
 * 作業批改頁型別定義（Issue #131 P7）
 *
 * 欄位對齊後端 `J7\PowerCourse\Resources\Assignment\Service\Reporting`
 * （`list_submissions` / `stats` / `courses_with_submissions` / `student_overview`）
 * 與 specs/api/api.yml（tag Assignments，管理端 `/assignment-submissions*`）。
 * 檔尾另集中批改頁各元件的 props 型別。
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
	/** 討論未讀數（學員發送、老師尚未讀取的訊息數） */
	unread_count: number
	/** 討論訊息總數 */
	message_count: number
	/** 最後一則討論訊息時間（站台時區字串），沒有訊息為 null */
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

/** 討論串發送者（後端 Model\Message::SENDER_*） */
export const MESSAGE_SENDER = {
	STUDENT: 'student',
	TEACHER: 'teacher',
} as const

/** 討論串發送者型別 */
export type TMessageSender =
	(typeof MESSAGE_SENDER)[keyof typeof MESSAGE_SENDER]

/** 作業討論串訊息（api.yml AssignmentMessage；不回傳發送者 id 與 email） */
export type TAssignmentMessage = {
	/** 訊息 ID */
	id: number
	/** 發送者身分 */
	sender_type: TMessageSender
	/** 訊息本文（原樣儲存，顯示時只能當純文字） */
	body: string
	/** 對方讀取時間（站台時區字串），未讀為 null */
	read_at: string | null
	/** 發送時間（站台時區字串 Y-m-d H:i:s） */
	created_at: string
	/** 是否為查詢者這一方發送（管理端 = 老師發送） */
	mine: boolean
}

/** 發送討論訊息的 request body */
export type TThreadMessagePayload = {
	/** 訊息本文（長度 1～10000） */
	body: string
}

/** 匯入批改結果 CSV 的單列錯誤 */
export type TImportError = {
	/** CSV 列號（含表頭，第一筆資料為 2；整份檔案無法處理時為 0） */
	row: number
	/** 錯誤訊息（後端依站台語系翻譯） */
	message: string
}

/** 匯入批改結果 CSV 的摘要（api.yml AssignmentImportSummary） */
export type TImportSummary = {
	/** 資料列總數 */
	total: number
	/** 成功批改的列數 */
	updated: number
	/** 缺 submission_id / 狀態無法辨識而略過的列數 */
	skipped: number
	/** 批改失敗的列數（例如繳交紀錄不存在） */
	failed: number
	/** 錯誤明細（不截斷，UI 只顯示前 20 筆） */
	errors: TImportError[]
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

// ── 元件 props ──────────────────────────────────

/** 課程分頁 props */
export type TCourseTabsProps = {
	/** 有繳交紀錄的課程（後端已依繳交數 DESC、標題 ASC 排序） */
	courses: TCourseWithSubmissions[]
	/** 目前作用中的分頁 key（'all' 或課程 ID 字串） */
	activeKey: string
	/** 切換分頁的回呼 */
	onChange: (key: string) => void
}

/** 批改統計卡 props */
export type TStatsCardsProps = {
	/** 批改統計，尚未取得時為 undefined */
	stats?: TSubmissionStats
	/** 是否正在抓取。切換課程分頁時舊資料仍在快取中，續顯舊數字會被誤讀成新範圍的統計 */
	isFetching?: boolean
}

/** 批改狀態篩選 props */
export type TStatusFilterProps = {
	/** 目前的狀態篩選值 */
	value: TStatusFilter
	/** 切換狀態篩選的回呼 */
	onChange: (value: TStatusFilter) => void
}

/** 匯出單一作業 CSV 的作業選項 */
export type TAssignmentOption = {
	/** 作業 ID */
	value: number
	/** 作業標題 */
	label: string
}

/** CSV 匯出工具列 props */
export type TExportButtonsProps = {
	/** 目前課程分頁的課程 ID，0 代表全部課程 */
	courseId: number
	/** 目前的狀態篩選 */
	status: TStatusFilter
	/** 可匯出的作業選項（取自目前列表範圍內出現過的作業） */
	assignmentOptions: TAssignmentOption[]
}

/** 匯入批改結果 CSV 按鈕 props */
export type TImportButtonProps = {
	/** 開始上傳（外層據此收起上一次的結果） */
	onStart: () => void
	/** 匯入完成，帶入匯入摘要 */
	onImported: (summary: TImportSummary) => void
}

/** 匯入批改結果 CSV 的結果摘要 props */
export type TImportResultProps = {
	/** 匯入摘要 */
	summary: TImportSummary
	/** 關閉結果 */
	onClose: () => void
}

/** 作業繳交列表 props */
export type TSubmissionTableProps = {
	/** 本頁的繳交紀錄 */
	items: TSubmissionRecord[]
	/** 符合篩選條件的總筆數 */
	total: number
	/** 目前頁碼（1 起算） */
	page: number
	/** 每頁筆數 */
	pageSize: number
	/** 是否正在取得資料 */
	isFetching: boolean
	/** 換頁 / 換每頁筆數的回呼 */
	onPageChange: (page: number, pageSize: number) => void
	/** 點擊「查看詳情」的回呼 */
	onSelect: (record: TSubmissionRecord) => void
}

/** 繳交詳情 props */
export type TSubmissionDetailProps = {
	/** 目前檢視的繳交紀錄，未選取時為 null */
	record: TSubmissionRecord | null
	/** 抽屜是否開啟 */
	open: boolean
	/** 關閉抽屜的回呼 */
	onClose: () => void
	/** 批改成功後的回呼，帶入批改後的繳交紀錄（更新詳情、重新取得列表與統計） */
	onGraded: (graded: TGradeResponse) => void
}

/** 批改操作表單 props */
export type TGradeFormProps = {
	/** 目前批改中的繳交紀錄 */
	record: TSubmissionRecord
	/** 是否正在送出批改 */
	isGrading: boolean
	/** 送出批改的回呼 */
	onSubmit: (payload: TGradePayload) => void
}

/** 學生背景資料 props */
export type TStudentOverviewProps = {
	/** 學員背景資料，尚未取得時為 undefined */
	overview?: TStudentOverview
}

/** 作業討論串 props */
export type TSubmissionThreadProps = {
	/** 繳交紀錄 ID */
	submissionId: number
}

/** 單則討論訊息 props */
export type TThreadMessageProps = {
	/** 單則訊息 */
	message: TAssignmentMessage
}
