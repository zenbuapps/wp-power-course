/**
 * 學生背景資料（Issue #131 P7）
 *
 * 基本資料 + 本課程作業統計 + 學習進度，
 * 對應 `features/assignment/查詢作業學員背景資料.feature`。
 */
import { _x, __, sprintf } from '@wordpress/i18n'
import { Skeleton } from 'antd'
import { memo } from 'react'

import { type TStudentOverviewProps } from '../types'

/**
 * 學生背景資料元件
 */
const StudentOverviewComponent = ({ overview }: TStudentOverviewProps) => {
	if (!overview) {
		return <Skeleton active paragraph={{ rows: 3 }} />
	}

	const { user, stats, progress } = overview

	return (
		<div className="pc-assignment-student-overview">
			<div className="mb-2 font-semibold">
				{__('Student overview', 'power-course')}
			</div>

			<div>{user.name}</div>
			<div className="text-gray-500">{user.email}</div>
			{!!user.registered_at && (
				<div className="text-gray-500">
					{`${__('Registered at', 'power-course')} ${user.registered_at}`}
				</div>
			)}

			<div className="mt-3 flex flex-wrap gap-x-4 gap-y-1">
				<div>
					{sprintf(
						// translators: %s: 已繳交筆數
						_x('Submitted: %s', 'assignment student overview', 'power-course'),
						stats.submitted
					)}
				</div>
				<div>
					{sprintf(
						// translators: %s: 待批改筆數
						_x('Pending: %s', 'assignment student overview', 'power-course'),
						stats.pending
					)}
				</div>
				<div>
					{sprintf(
						// translators: %s: 已通過筆數
						_x('Passed: %s', 'assignment student overview', 'power-course'),
						stats.passed
					)}
				</div>
				<div>
					{sprintf(
						// translators: %s: 未通過筆數
						_x('Failed: %s', 'assignment student overview', 'power-course'),
						stats.failed
					)}
				</div>
			</div>

			<div className="mt-3">
				{sprintf(
					// translators: 1: 已完成章節數, 2: 章節總數, 3: 完成百分比（含 % 符號）
					_x(
						'Completed %1$s / %2$s chapters (%3$s)',
						'assignment student overview',
						'power-course'
					),
					progress.completed_chapters,
					progress.total_chapters,
					`${progress.percent}%`
				)}
			</div>
		</div>
	)
}

export const StudentOverview = memo(StudentOverviewComponent)
