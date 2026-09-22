/**
 * 作業批改頁（Issue #131 P7）
 *
 * 管理員跨課程管理所有學員作業繳交：課程分頁 → 統計卡 → 狀態篩選 → 繳交列表 → 詳情批改。
 * 對應 specs/ui/作業批改頁.md 與 specs/features/assignment/ 的批改頁 8 個 feature。
 */
import { Card, Spin } from 'antd'
import { useCallback, useMemo, useState } from 'react'

import {
	CourseTabs,
	ExportButtons,
	StatsCards,
	StatusFilter,
	SubmissionDetail,
	SubmissionTable,
} from './components'
import {
	useCoursesWithSubmissions,
	useSubmissionList,
	useSubmissionStats,
} from './hooks'
import {
	COURSE_TAB_ALL,
	STATUS_ALL,
	type TStatusFilter,
	type TSubmissionRecord,
} from './types'

/** 每頁預設筆數 */
const DEFAULT_PAGE_SIZE = 20

/**
 * 作業批改頁
 */
const Assignments = () => {
	// 課程分頁（'all' 或課程 ID 字串）
	const [courseKey, setCourseKey] = useState<string>(COURSE_TAB_ALL)
	// 狀態篩選
	const [status, setStatus] = useState<TStatusFilter>(STATUS_ALL)
	// 分頁
	const [page, setPage] = useState(1)
	const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
	// 目前開啟詳情的繳交紀錄（只保存 id 對應的快照，實際顯示以最新列表為準）
	const [selected, setSelected] = useState<TSubmissionRecord | null>(null)

	const courseId = COURSE_TAB_ALL === courseKey ? 0 : Number(courseKey)

	const { courses, isFetching: isCoursesFetching } = useCoursesWithSubmissions()
	const {
		items,
		total,
		isFetching,
		refetch: refetchList,
	} = useSubmissionList({
		courseId,
		status,
		page,
		pageSize,
	})
	const {
		stats,
		isFetching: isStatsFetching,
		refetch: refetchStats,
	} = useSubmissionStats(courseId)

	// 詳情一律顯示最新列表中的那一筆：批改後 refetch 回來的狀態與批改時間才會即時反映。
	// 若該筆已被目前篩選排除（例如篩「待批改」時把它批改成通過），退回開啟當下的快照。
	const detailRecord = useMemo(() => {
		if (!selected) {
			return null
		}

		return items.find((item) => item.id === selected.id) ?? selected
	}, [items, selected])

	// 匯出單一作業 CSV 的作業選項：取自目前範圍內出現過的作業（沒有繳交的作業匯出也沒有意義）
	const assignmentOptions = useMemo(() => {
		const optionMap = new Map<number, string>()

		items.forEach((item) => {
			if (item.assignment_id && !optionMap.has(item.assignment_id)) {
				optionMap.set(
					item.assignment_id,
					item.assignment_title ?? String(item.assignment_id)
				)
			}
		})

		return Array.from(optionMap, ([value, label]) => ({ value, label }))
	}, [items])

	const handleCourseChange = useCallback((key: string) => {
		setCourseKey(key)
		setPage(1)
		setSelected(null)
	}, [])

	const handleStatusChange = useCallback((value: TStatusFilter) => {
		setStatus(value)
		setPage(1)
	}, [])

	const handlePageChange = useCallback(
		(nextPage: number, nextPageSize: number) => {
			setPage(nextPage)
			setPageSize(nextPageSize)
		},
		[]
	)

	const handleGraded = useCallback(() => {
		refetchList()
		refetchStats()
	}, [refetchList, refetchStats])

	return (
		<>
			{/*
			  課程分頁與統計卡各自打一支 API，兩者都沒拿到資料前整塊掛 Spin：
			  課程分頁少一個課程、統計卡先顯示舊範圍的數字，都會讓人看到錯的資訊。
			*/}
			<Card>
				<Spin spinning={isCoursesFetching || isStatsFetching}>
					<CourseTabs
						courses={courses}
						activeKey={courseKey}
						onChange={handleCourseChange}
					/>
					<StatsCards stats={stats} isFetching={isStatsFetching} />
				</Spin>
			</Card>

			<Card className="mt-4">
				<div className="mb-4 flex flex-wrap items-center justify-between gap-4">
					<StatusFilter value={status} onChange={handleStatusChange} />
					<ExportButtons
						courseId={courseId}
						status={status}
						assignmentOptions={assignmentOptions}
					/>
				</div>

				<SubmissionTable
					items={items}
					total={total}
					page={page}
					pageSize={pageSize}
					isFetching={isFetching}
					onPageChange={handlePageChange}
					onSelect={setSelected}
				/>
			</Card>

			<SubmissionDetail
				record={detailRecord}
				open={!!selected}
				onClose={() => setSelected(null)}
				onGraded={handleGraded}
			/>
		</>
	)
}

export default Assignments
