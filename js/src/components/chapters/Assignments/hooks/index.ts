/**
 * 章節作業管理的資料存取 Hooks（Issue #131 P7）
 *
 * 作業 API 回傳的是「未分頁的純陣列」（GET /assignments）與自訂動作
 * （POST /assignments/sort），不符合 Refine 預設 resource 的分頁契約，
 * 故一律以 useCustom / useCustomMutation 操作，不自行 fetch。
 */
import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useList,
	type BaseKey,
	type HttpError,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'
import { useMemo } from 'react'

import { TChapterRecord } from '@/pages/admin/Courses/List/types'

import { TAssignment, TAssignmentPayload } from '../types'

/** 排序進度訊息共用的 message key，避免多次拖曳堆疊出多個提示 */
const SORT_MESSAGE_KEY = 'pc-assignment-sorting'

/**
 * 取得章節底下的作業列表
 *
 * 後端已依 menu_order → 建立時間 → ID 排好序（DEC-13），前端不再重排。
 *
 * @param chapterId 章節 ID，非正整數時不發送請求
 */
export const useChapterAssignments = (chapterId: number) => {
	const apiUrl = useApiUrl('power-course')

	const query = useCustom<TAssignment[], HttpError, { chapter_id: number }>({
		url: `${apiUrl}/assignments`,
		method: 'get',
		config: {
			query: { chapter_id: chapterId },
		},
		queryOptions: {
			enabled: chapterId > 0,
			// 章節作業會被同頁的新增 / 編輯 / 排序即時改動，不吃全域 10 分鐘 staleTime
			staleTime: 0,
		},
	})

	return {
		assignments: query.data?.data ?? [],
		isLoading: query.isLoading,
		isFetching: query.isFetching,
		refetch: query.refetch,
	}
}

/**
 * 作業的寫入操作
 *
 * 封裝建立 / 更新 / 刪除 / 排序四種 API 呼叫，元件只負責 UI 與收集參數。
 */
export const useAssignmentMutation = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation()

	/**
	 * 建立或更新作業
	 *
	 * @param assignmentId 作業 ID，null 代表建立
	 * @param payload      作業欄位
	 * @param onSuccess    成功後的回呼（關閉 Modal、重新取得列表）
	 */
	const saveAssignment = (
		assignmentId: number | null,
		payload: TAssignmentPayload,
		onSuccess: () => void
	) => {
		mutate(
			{
				url: assignmentId
					? `${apiUrl}/assignments/${assignmentId}`
					: `${apiUrl}/assignments`,
				method: assignmentId ? 'put' : 'post',
				values: payload,
			},
			{
				onSuccess: () => {
					message.success(__('Assignment saved', 'power-course'))
					onSuccess()
				},
			}
		)
	}

	/**
	 * 刪除作業（後端移至垃圾桶，學員繳交紀錄保留）
	 *
	 * @param assignmentId 作業 ID
	 * @param onSuccess    成功後的回呼
	 */
	const deleteAssignment = (assignmentId: number, onSuccess: () => void) => {
		mutate(
			{
				url: `${apiUrl}/assignments/${assignmentId}`,
				method: 'delete',
				values: {},
			},
			{
				onSuccess: () => {
					message.success(__('Assignment deleted', 'power-course'))
					onSuccess()
				},
			}
		)
	}

	/**
	 * 排序章節作業（依 ids 先後寫入 menu_order）
	 *
	 * @param chapterId 章節 ID
	 * @param ids       排序後的作業 ID 陣列
	 * @param onSettled 成功或失敗都要執行的回呼（重新取得列表）
	 */
	const sortAssignments = (
		chapterId: number,
		ids: number[],
		onSettled: () => void
	) => {
		message.loading({
			content: __('Saving sort order...', 'power-course'),
			key: SORT_MESSAGE_KEY,
		})

		mutate(
			{
				url: `${apiUrl}/assignments/sort`,
				method: 'post',
				values: { chapter_id: chapterId, ids },
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Sort order saved successfully', 'power-course'),
						key: SORT_MESSAGE_KEY,
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to save sort order', 'power-course'),
						key: SORT_MESSAGE_KEY,
					})
				},
				onSettled,
			}
		)
	}

	return {
		saveAssignment,
		deleteAssignment,
		sortAssignments,
		isMutating: isLoading,
	}
}

/**
 * 取得課程底下所有章節的下拉選項（含子章節，攤平為一層）
 *
 * 與 SortableChapters 使用完全相同的 useList 參數，可共用同一份 query cache，
 * 不會為了 Modal 多打一次章節 API。
 *
 * courseId 必須原封不動沿用 useParsed().id（BaseKey，實務上是字串），
 * 不可先轉成 number —— filters 的 value 會進 react-query 的 queryKey，
 * '3380' 與 3380 會 hash 成不同的 key，導致多打一次 chapters API。
 *
 * @param courseId 課程 ID，未解析出時不發送請求
 */
export const useChapterOptions = (courseId: BaseKey | undefined) => {
	const { data, isLoading } = useList<TChapterRecord, HttpError>({
		resource: 'chapters',
		dataProviderName: 'power-course',
		filters: [
			{
				field: 'meta_key',
				operator: 'eq',
				value: 'parent_course_id',
			},
			{
				field: 'meta_value',
				operator: 'eq',
				value: courseId,
			},
		],
		pagination: {
			current: 1,
			pageSize: -1,
		},
		queryOptions: {
			enabled: !!courseId,
		},
	})

	const options = useMemo(() => {
		const flatten = (
			chapters: TChapterRecord[]
		): { label: string; value: number }[] =>
			chapters.reduce<{ label: string; value: number }[]>(
				(acc, chapter) => [
					...acc,
					{ label: chapter.name, value: Number(chapter.id) },
					...flatten(chapter?.chapters ?? []),
				],
				[]
			)

		return flatten(data?.data ?? [])
	}, [data?.data])

	return { chapterOptions: options, isChapterOptionsLoading: isLoading }
}
