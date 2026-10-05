/**
 * 作業批改頁的資料存取 Hooks（Issue #131 P7）
 *
 * 後端批改頁端點回傳 `{items, total}` + `limit` / `offset`，不符合 Refine
 * 預設 resource 的分頁契約（`useList` 會送 `_page` / `_limit` 並期待 `data` / `total`），
 * 因此列表、統計、課程分頁、學員背景資料、批改與討論串一律走 `useCustom` / `useCustomMutation`，
 * 不自行 fetch（.claude/rules/react.rule.md「禁止直接 API 呼叫」）。
 * 唯一例外是 CSV 匯入：要延長單次請求的逾時，data provider 做不到，見 `useImportGradingCsv`。
 */
import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	type HttpError,
} from '@refinedev/core'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'
import { isAxiosError } from 'axios'

import { useEnv } from '@/hooks'

import {
	STATUS_ALL,
	type TAssignmentMessage,
	type TCourseWithSubmissions,
	type TGradePayload,
	type TGradeResponse,
	type TImportSummary,
	type TStatusFilter,
	type TStudentOverview,
	type TSubmissionListResponse,
	type TSubmissionQuery,
	type TSubmissionStats,
	type TThreadMessagePayload,
} from '../types'

/** 批改結果訊息共用的 message key，連續批改時不堆疊多個提示 */
const GRADE_MESSAGE_KEY = 'pc-assignment-grading'

/**
 * CSV 匯入請求的逾時：10 分鐘
 *
 * 上限 5000 列，本機實測 500 列 6.4 秒 → 5000 列約 64 秒；留約 10 倍餘裕給較慢的主機。
 * 後端已 `wc_set_time_limit(0)` 會把全部列套用完，前端提早放棄只會讓老師誤以為失敗而重匯（學員收到第二封通知）。
 */
const IMPORT_TIMEOUT_MS = 10 * 60 * 1000

/** 批改頁 query key 的共同開頭 */
const QUERY_KEY_ROOT = 'pc-assignment-submissions'

/**
 * 批改頁各查詢的 query key（覆寫 Refine `useCustom` 依 url / config 產生的預設 key）
 *
 * 讀完討論串後重新取得列表（「討論」徽章歸零）、匯入後重新取得列表 / 統計 / 課程分頁，
 * 都以這組 key invalidate，不必把各查詢的 refetch 一路以 props 傳進子元件。
 * 不同分類的 key 互不為前綴：討論串的 GET 會把學員訊息標已讀，不能被其他查詢的 invalidate 順帶觸發。
 */
const SUBMISSION_QUERY_KEYS = {
	/** 有繳交紀錄的課程（課程分頁） */
	courses: () => [QUERY_KEY_ROOT, 'courses'] as const,
	/** 所有繳交列表（不分課程 / 狀態 / 分頁） */
	lists: () => [QUERY_KEY_ROOT, 'list'] as const,
	/** 單一查詢條件的繳交列表 */
	list: (query: TSubmissionQuery) =>
		[...SUBMISSION_QUERY_KEYS.lists(), query] as const,
	/** 所有批改統計（不分課程） */
	stats: () => [QUERY_KEY_ROOT, 'stats'] as const,
	/** 單一課程範圍的批改統計，0 代表全部課程 */
	courseStats: (courseId: number) =>
		[...SUBMISSION_QUERY_KEYS.stats(), courseId] as const,
	/** 學生背景資料 */
	studentOverview: (userId: number, courseId: number) =>
		[QUERY_KEY_ROOT, 'student-overview', userId, courseId] as const,
	/** 單一繳交的討論串 */
	thread: (submissionId: number) =>
		[QUERY_KEY_ROOT, 'thread', submissionId] as const,
}

/**
 * 取得有繳交紀錄的課程（課程分頁來源）
 *
 * 後端已依繳交數 DESC、標題 ASC 排序，前端不再重排。
 */
export const useCoursesWithSubmissions = () => {
	const apiUrl = useApiUrl('power-course')

	const query = useCustom<TCourseWithSubmissions[], HttpError>({
		url: `${apiUrl}/assignment-submissions/courses`,
		method: 'get',
		queryOptions: {
			queryKey: SUBMISSION_QUERY_KEYS.courses(),
			// 批改後繳交數不變，但新繳交會即時進來，不吃全域 10 分鐘 staleTime
			staleTime: 0,
			// 本機站 REST 偶發逾時；Refine 全域 retry 為 0，單次失敗會讓畫面永遠停在載入中
			retry: 1,
			// staleTime 為 0，若再吃預設的 focus 重抓，切回分頁就會無謂地整塊轉圈
			refetchOnWindowFocus: false,
		},
	})

	return {
		courses: query.data?.data ?? [],
		isFetching: query.isFetching,
		refetch: query.refetch,
	}
}

/**
 * 取得作業繳交列表
 *
 * @param params 課程 / 狀態 / 分頁參數；course_id 為 0 代表不限課程
 */
export const useSubmissionList = (params: {
	courseId: number
	status: TStatusFilter
	page: number
	pageSize: number
}) => {
	const apiUrl = useApiUrl('power-course')
	const { courseId, status, page, pageSize } = params
	const filters: TSubmissionQuery = {
		...(courseId > 0 ? { course_id: courseId } : {}),
		status,
		limit: pageSize,
		offset: (page - 1) * pageSize,
	}

	const query = useCustom<TSubmissionListResponse, HttpError, TSubmissionQuery>(
		{
			url: `${apiUrl}/assignment-submissions`,
			method: 'get',
			config: {
				query: filters,
			},
			queryOptions: {
				// 查詢條件整組放進 key：換課程 / 狀態 / 分頁才會是另一筆快取
				queryKey: SUBMISSION_QUERY_KEYS.list(filters),
				staleTime: 0,
				// 本機站 REST 偶發逾時；Refine 全域 retry 為 0，單次失敗會讓畫面永遠停在載入中
				retry: 1,
				// staleTime 為 0，若再吃預設的 focus 重抓，切回分頁就會無謂地整塊轉圈
				refetchOnWindowFocus: false,
			},
		}
	)

	return {
		items: query.data?.data?.items ?? [],
		total: query.data?.data?.total ?? 0,
		isFetching: query.isFetching,
		refetch: query.refetch,
	}
}

/**
 * 取得批改統計（範圍跟著課程分頁走，不吃狀態篩選）
 *
 * @param courseId 課程 ID，0 代表全部課程
 */
export const useSubmissionStats = (courseId: number) => {
	const apiUrl = useApiUrl('power-course')

	const query = useCustom<TSubmissionStats, HttpError, TSubmissionQuery>({
		url: `${apiUrl}/assignment-submissions/stats`,
		method: 'get',
		config: {
			query: courseId > 0 ? { course_id: courseId } : {},
		},
		queryOptions: {
			queryKey: SUBMISSION_QUERY_KEYS.courseStats(courseId),
			staleTime: 0,
			// 本機站 REST 偶發逾時；Refine 全域 retry 為 0，單次失敗會讓畫面永遠停在載入中
			retry: 1,
			// staleTime 為 0，若再吃預設的 focus 重抓，切回分頁就會無謂地整塊轉圈
			refetchOnWindowFocus: false,
		},
	})

	return {
		stats: query.data?.data,
		isFetching: query.isFetching,
		refetch: query.refetch,
	}
}

/**
 * 取得學生背景資料（基本資料 + 本課程作業統計 + 學習進度）
 *
 * @param userId   學員 user ID，0 時不發送請求
 * @param courseId 繳交所屬課程 ID，0 時由後端以 assignment_id 反推（此處一律帶課程）
 */
export const useStudentOverview = (userId: number, courseId: number) => {
	const apiUrl = useApiUrl('power-course')

	const query = useCustom<
		TStudentOverview,
		HttpError,
		{ user_id: number; course_id?: number }
	>({
		url: `${apiUrl}/assignment-submissions/student-overview`,
		method: 'get',
		config: {
			query: {
				user_id: userId,
				...(courseId > 0 ? { course_id: courseId } : {}),
			},
		},
		queryOptions: {
			queryKey: SUBMISSION_QUERY_KEYS.studentOverview(userId, courseId),
			enabled: userId > 0,
			staleTime: 0,
			// 本機站 REST 偶發逾時；Refine 全域 retry 為 0，單次失敗會讓畫面永遠停在載入中
			retry: 1,
			// staleTime 為 0，若再吃預設的 focus 重抓，切回分頁就會無謂地整塊轉圈
			refetchOnWindowFocus: false,
		},
	})

	return {
		overview: query.data?.data,
		isFetching: query.isFetching,
		refetch: query.refetch,
	}
}

/**
 * 批改作業繳交（通過 / 未通過 / 改回待批改 + 評語）
 */
export const useGradeSubmission = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation<
		TGradeResponse,
		HttpError,
		TGradePayload
	>()

	/**
	 * 送出批改結果
	 *
	 * @param submissionId 繳交紀錄 ID
	 * @param payload      批改狀態與評語
	 * @param onSuccess    成功後的回呼，帶入批改後的繳交紀錄（更新詳情、重新取得列表 / 統計 / 背景資料）
	 */
	const grade = (
		submissionId: number,
		payload: TGradePayload,
		onSuccess: (graded: TGradeResponse) => void
	) => {
		mutate(
			{
				url: `${apiUrl}/assignment-submissions/${submissionId}/grade`,
				method: 'patch',
				values: payload,
			},
			{
				onSuccess: ({ data }) => {
					message.success({
						content: __('Grading saved', 'power-course'),
						key: GRADE_MESSAGE_KEY,
					})
					onSuccess(data)
				},
			}
		)
	}

	return { grade, isGrading: isLoading }
}

/**
 * 取得作業討論串（管理端）
 *
 * GET 有副作用：後端會先把學員的未讀訊息標為已讀再回傳（DEC-W2-04）。
 * 所以只在開啟詳情時載入，不吃視窗 focus 重抓、不輪詢（R-W2-02）。
 * 每次讀取成功後重新取得列表，讓「討論」徽章歸零（統計不含未讀數，不必重抓）。
 *
 * @param submissionId 繳交紀錄 ID，0 時不發送請求
 */
export const useSubmissionThread = (submissionId: number) => {
	const apiUrl = useApiUrl('power-course')
	const queryClient = useQueryClient()

	const query = useCustom<TAssignmentMessage[], HttpError>({
		url: `${apiUrl}/assignment-submissions/${submissionId}/messages`,
		method: 'get',
		// 載入失敗在討論串區塊內就地顯示，不另跳 Refine 通知
		errorNotification: false,
		queryOptions: {
			queryKey: SUBMISSION_QUERY_KEYS.thread(submissionId),
			enabled: submissionId > 0,
			staleTime: 0,
			// 本機站 REST 偶發逾時；Refine 全域 retry 為 0，單次失敗會讓畫面永遠停在載入中
			retry: 1,
			// GET 會標已讀，不能在使用者沒看的時候自己重抓
			refetchOnWindowFocus: false,
			onSuccess: () => {
				void queryClient.invalidateQueries({
					queryKey: SUBMISSION_QUERY_KEYS.lists(),
				})
			},
		},
	})

	return {
		messages: query.data?.data ?? [],
		isLoading: query.isFetching && !query.data,
		isError: query.isError,
	}
}

/**
 * 以老師身分在討論串發送訊息（管理端）
 */
export const usePostThreadMessage = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation<
		TAssignmentMessage,
		HttpError,
		TThreadMessagePayload
	>()

	/**
	 * 送出訊息
	 *
	 * @param submissionId 繳交紀錄 ID
	 * @param body         訊息本文（呼叫端已 trim 且不為空）
	 * @param onSuccess    成功後的回呼，帶入新訊息（就地附加到討論串）
	 */
	const post = (
		submissionId: number,
		body: string,
		onSuccess: (sent: TAssignmentMessage) => void
	) => {
		mutate(
			{
				url: `${apiUrl}/assignment-submissions/${submissionId}/messages`,
				method: 'post',
				values: { body },
				successNotification: false,
				errorNotification: false,
			},
			{
				onSuccess: ({ data }) => {
					onSuccess(data)
				},
				onError: (error) => {
					message.error(
						error?.message || __('Failed to send message', 'power-course')
					)
				},
			}
		)
	}

	return { post, isPosting: isLoading }
}

/**
 * 取出匯入失敗時的伺服器訊息（WP_Error 的 message），沒有就回空字串
 *
 * @param error useMutation 拋出的錯誤（axios 錯誤或其他）
 */
const getImportErrorMessage = (error: unknown): string => {
	if (!isAxiosError<{ message?: unknown }>(error)) {
		return ''
	}
	const serverMessage = error.response?.data?.message

	return typeof serverMessage === 'string' ? serverMessage : ''
}

/**
 * 匯入批改結果 CSV（管理端，DEC-W2-18）
 *
 * multipart 上傳，field 名 `file`；後端逐列等同一次批改，回傳匯入摘要。
 * 不帶 `_locale=user`：匯出（`window.open`）也走站台語系，兩邊的表頭譯文才會一致（DEC-W2-09）。
 *
 * 為什麼不走 `useCustomMutation`：antd-toolkit 的 axios instance 逾時固定 30 秒，data provider 的 `custom()`
 * 只合併 headers、不吃單次請求的 config，延長不了逾時。大檔匯入超過 30 秒時前端先報錯，後端卻照樣套用完
 * 並排了批改通知信。所以改用 TanStack Query 的 `useMutation`，直接以 `useEnv()` 的 instance
 * （帶 X-WP-Nonce 與 403 處理）送出，逾時只延長這一次請求。
 */
export const useImportGradingCsv = () => {
	const apiUrl = useApiUrl('power-course')
	const { AXIOS_INSTANCE } = useEnv()
	const queryClient = useQueryClient()

	const { mutate, isLoading } = useMutation<TImportSummary, unknown, File>({
		mutationFn: async (file) => {
			const formData = new FormData()
			formData.append('file', file)

			const { data } = await AXIOS_INSTANCE.post<TImportSummary>(
				`${apiUrl}/assignment-submissions/import`,
				formData,
				{
					timeout: IMPORT_TIMEOUT_MS,
					// 單次請求的 config，不寫進 instance 的 defaults。
					// instance 預設 application/json 會讓 axios 把 FormData 轉成 JSON（檔案遺失）；
					// 設為 undefined 交給瀏覽器補上帶 boundary 的 multipart/form-data
					headers: { 'Content-Type': undefined },
				}
			)

			return data
		},
		// 匯入逐列改了批改狀態：只重新取得列表、統計與課程分頁，狀態篩選維持不動
		// （預設「待批改」下，被改成通過 / 未通過的列會離開目前列表，這是預期行為）
		onSuccess: () => {
			void queryClient.invalidateQueries({
				queryKey: SUBMISSION_QUERY_KEYS.lists(),
			})
			void queryClient.invalidateQueries({
				queryKey: SUBMISSION_QUERY_KEYS.stats(),
			})
			void queryClient.invalidateQueries({
				queryKey: SUBMISSION_QUERY_KEYS.courses(),
			})
		},
	})

	/**
	 * 上傳 CSV
	 *
	 * 結果在頁內 Alert 顯示，失敗用 message 帶出伺服器訊息；成功後列表、統計與課程分頁由 hook 自行重新取得。
	 *
	 * @param file      使用者選擇的 CSV 檔案
	 * @param onSuccess 成功後的回呼，帶入匯入摘要（顯示結果）
	 */
	const importCsv = (
		file: File,
		onSuccess: (summary: TImportSummary) => void
	) => {
		mutate(file, {
			onSuccess,
			onError: (error) => {
				message.error(
					getImportErrorMessage(error) ||
						__('Failed to import CSV', 'power-course')
				)
			},
		})
	}

	return { importCsv, isImporting: isLoading }
}

/**
 * 下載類端點的網址組裝（附件下載與兩種 CSV 匯出）
 *
 * 下載端點回的是 `Content-Disposition: attachment` 的二進位內容，不是 Refine
 * data provider 能處理的 JSON，沿用學員列表 `components/user/UserTable` 的做法：
 * 網址帶 `_wpnonce`，交給瀏覽器直接下載（檔名由後端標頭決定，大檔也不必先讀進記憶體）。
 */
export const useAssignmentDownloadUrl = () => {
	const apiUrl = useApiUrl('power-course')
	const { NONCE } = useEnv()

	/**
	 * 作業附件下載網址
	 *
	 * @param attachmentId 附件 ID
	 */
	const getAttachmentUrl = (attachmentId: number): string =>
		`${apiUrl}/assignment-attachments/${attachmentId}/download?${new URLSearchParams(
			{ _wpnonce: NONCE }
		).toString()}`

	/**
	 * 批改用（可往返）CSV 匯出網址
	 *
	 * @param courseId 課程 ID，0 代表不限
	 * @param status   批改狀態篩選
	 */
	const getGradingCsvUrl = (
		courseId: number,
		status: TStatusFilter
	): string => {
		const params = new URLSearchParams({ _wpnonce: NONCE })
		if (courseId > 0) {
			params.append('course_id', String(courseId))
		}
		if (status !== STATUS_ALL) {
			params.append('status', status)
		}

		return `${apiUrl}/assignment-submissions/export?${params.toString()}`
	}

	/**
	 * 單一作業的繳交 CSV 匯出網址
	 *
	 * @param assignmentId 作業 ID
	 */
	const getAssignmentCsvUrl = (assignmentId: number): string =>
		`${apiUrl}/assignments/${assignmentId}/submissions/export?${new URLSearchParams(
			{ _wpnonce: NONCE }
		).toString()}`

	return { getAttachmentUrl, getGradingCsvUrl, getAssignmentCsvUrl }
}

export { default as useColumns } from './useColumns'
