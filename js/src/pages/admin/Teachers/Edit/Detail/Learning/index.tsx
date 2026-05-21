import { __ } from '@wordpress/i18n'
import { Heading } from 'antd-toolkit'
import React from 'react'

import { AvlCoursesList } from '@/components/user'
import HistoryDrawer from '@/components/user/UserTable/HistoryDrawer'

import { useRecord } from '../../hooks'

/**
 * 講師 Edit 頁 — 學習紀錄 Tab（Q11=A：講師本人作為學員）
 *
 * 重用：
 * - AvlCoursesList（階段 3.1 抽出的共用元件）：顯示 record.avl_courses
 * - HistoryDrawer（原 UserTable 內部元件）：點「學習歷程」按鈕打開章節 timeline
 *
 * Issue #226：AvlCoursesList 已改為純展示元件。
 * 此處不傳 currentCourseId，內部會 fallback 永遠展開全部授權課程；
 * 也不傳 showAllCourses（採用預設 false，但因 !currentCourseId 條件壓過去仍展全部）。
 */
const Learning = () => {
	const record = useRecord()

	return (
		<>
			<Heading size="sm" hideIcon>
				{__('Granted courses', 'power-course')}
			</Heading>

			<AvlCoursesList record={record} />

			{/* HistoryDrawer 靠 historyDrawerAtom 與 AvlCoursesList 通訊；
			    掛在此 Tab 內，以便點「學習歷程」按鈕時彈出 */}
			<HistoryDrawer />
		</>
	)
}

export default Learning
