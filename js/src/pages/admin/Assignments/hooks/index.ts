/**
 * 作業批改頁的資料存取 Hooks（Issue #131 P7）
 *
 * 後端批改頁端點回傳 `{items, total}` + `limit` / `offset`，不符合 Refine
 * 預設 resource 的分頁契約（`useList` 會送 `_page` / `_limit` 並期待 `data` / `total`），
 * 因此列表、統計、課程分頁、學員背景資料與批改一律走 `useCustom` / `useCustomMutation`，
 * 不自行 fetch（.claude/rules/react.rule.md「禁止直接 API 呼叫」）。
 */
import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	type HttpError,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'

import { useEnv } from '@/hooks'

import {
	STATUS_ALL,
	type TCourseWithSubmissions,
	type TGradePayload,
	type TGradeResponse,
	type TStatusFilter,
	type TStudentOverview,
	type TSubmissionListResponse,
	type TSubmissionQuery,
	type TSubmissionStats,
} from '../types'

/** 批改結果訊息共用的 message key，連續批改時不堆疊多個提示 */
const GRADE_MESSAGE_KEY = 'pc-assignment-grading'

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

	const query = useCustom<TSubmissionListResponse, HttpError, TSubmissionQuery>(
		{
			url: `${apiUrl}/assignment-submissions`,
			method: 'get',
			config: {
				query: {
					...(courseId > 0 ? { course_id: courseId } : {}),
					status,
					limit: pageSize,
					offset: (page - 1) * pageSize,
				},
			},
			queryOptions: {
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
