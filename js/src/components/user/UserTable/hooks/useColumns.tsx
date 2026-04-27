import { useParsed } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { TableProps } from 'antd'
import React from 'react'

import { AvlCoursesList } from '@/components/user/AvlCoursesList'
import { TUserRecord } from '@/components/user/types'
import { UserName } from '@/components/user/UserName'

type TUseColumnsParams = {
	onClick?: (_record: TUserRecord | undefined) => () => void
}

const useColumns = (params?: TUseColumnsParams) => {
	const handleClick = params?.onClick
	const { id: currentCourseId } = useParsed()

	const columns: TableProps<TUserRecord>['columns'] = [
		{
			title: __('Student', 'power-course'),
			dataIndex: 'id',
			width: 180,
			render: (_, record) => <UserName record={record} onClick={handleClick} />,
		},
		{
			title: __('Granted courses', 'power-course'),
			dataIndex: 'avl_courses',
			width: 240,
			render: (_avl_courses, record) => (
				<AvlCoursesList
					record={record}
					currentCourseId={currentCourseId as string | undefined}
					showToggle
				/>
			),
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
