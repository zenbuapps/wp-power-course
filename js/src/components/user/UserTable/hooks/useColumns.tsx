import { useParsed } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Switch, TableProps, Tooltip } from 'antd'
import { useSetAtom } from 'jotai'
import React, { useState } from 'react'

import { AvlCoursesList } from '@/components/user/AvlCoursesList'
import { TUserRecord } from '@/components/user/types'
import { UserName } from '@/components/user/UserName'

import { studentEditDrawerAtom } from '../atom'

type TUseColumnsParams = {
	onClick?: (_record: TUserRecord | undefined) => () => void
}

const useColumns = (params?: TUseColumnsParams) => {
	const setEditDrawer = useSetAtom(studentEditDrawerAtom)
	// 預設點擊行為：開啟學員快速編輯 Drawer；若外部傳入 onClick 則優先使用
	const handleClick =
		params?.onClick ??
		((record: TUserRecord | undefined) => () =>
			setEditDrawer({ user_id: record?.id, open: true }))
	const { id: currentCourseId } = useParsed()

	/**
	 * Issue #226：將「顯示全部」switch 上提到表頭，一次套用全部 row。
	 * 狀態僅本次頁面停留有效（不寫 localStorage），切 tab / reload / 跳堂後自動重置 OFF。
	 *
	 * 渲染規則：
	 * - currentCourseId 存在（Course Edit 頁的學員管理 TAB）→ 表頭渲染 switch；初始 OFF
	 * - currentCourseId 不存在（/admin/students 全局頁）→ 表頭不渲染 switch；
	 *   AvlCoursesList 內部會因 !currentCourseId fallback 永遠展全部，等同既有行為
	 */
	const [showAllCourses, setShowAllCourses] = useState(false)

	const grantedCoursesTitle = currentCourseId ? (
		<div className="flex items-center justify-between gap-2">
			<span>{__('Granted courses', 'power-course')}</span>
			<div className="flex items-center gap-2 text-xs font-normal">
				<Tooltip
					title={__('Show all courses granted to the user', 'power-course')}
				>
					<span>{__('Show all', 'power-course')}</span>
				</Tooltip>
				<Switch
					checked={showAllCourses}
					onChange={setShowAllCourses}
					size="small"
				/>
			</div>
		</div>
	) : (
		__('Granted courses', 'power-course')
	)

	const columns: TableProps<TUserRecord>['columns'] = [
		{
			title: __('Student', 'power-course'),
			dataIndex: 'id',
			width: 180,
			render: (_, record) => <UserName record={record} onClick={handleClick} />,
		},
		{
			title: grantedCoursesTitle,
			dataIndex: 'avl_courses',
			width: 240,
			render: (_avl_courses, record) => (
				<AvlCoursesList
					record={record}
					currentCourseId={currentCourseId as string | undefined}
					showAllCourses={showAllCourses}
				/>
			),
		},
		{
			title: __('Phone', 'power-course'),
			dataIndex: 'billing_phone',
			width: 140,
			render: (phone) => phone || '-',
		},
		{
			title: __('Registered at', 'power-course'),
			dataIndex: 'user_registered',
			width: 180,
			render: (user_registered, record) => (
				<>
					<p className="m-0">
						{sprintf(
							// translators: %s: 相對時間，如「3天前」
							__('Registered %s', 'power-course'),
							record?.user_registered_human
						)}
					</p>
					<p className="m-0 text-gray-400 text-xs">{user_registered}</p>
				</>
			),
		},
	]

	return columns
}

export default useColumns
