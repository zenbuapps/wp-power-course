import { useNavigation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { TableProps } from 'antd'
import { UserRole } from 'antd-toolkit/wp'
import React from 'react'

import { UserName } from '@/components/user'

import { TTeacherRecord } from '../../types'

type TUseColumnsParams = {
	onClick?: (_record: TTeacherRecord | undefined) => () => void
}

const useColumns = (params?: TUseColumnsParams) => {
	const { edit } = useNavigation()

	const handleClick =
		params?.onClick ??
		((record: TTeacherRecord | undefined) => () => {
			if (record?.id) edit('teachers', record.id.toString())
		})

	const columns: TableProps<TTeacherRecord>['columns'] = [
		{
			title: __('Instructor', 'power-course'),
			dataIndex: 'id',
			width: 300,
			render: (_, record) => <UserName record={record} onClick={handleClick} />,
		},
		{
			title: __('Phone', 'power-course'),
			dataIndex: 'billing_phone',
			width: 180,
			render: (phone: string | undefined) => phone || '-',
		},
		{
			title: __('Courses count', 'power-course'),
			dataIndex: 'teacher_courses_count',
			width: 120,
			align: 'right',
			render: (value: number | undefined) =>
				value ? (
					<strong>{value}</strong>
				) : (
					<span className="text-gray-300">-</span>
				),
		},
		{
			title: __('Students count', 'power-course'),
			dataIndex: 'teacher_students_count',
			width: 120,
			align: 'right',
			render: (value: number | undefined) =>
				value ? (
					<strong>{value}</strong>
				) : (
					<span className="text-gray-300">-</span>
				),
		},
		{
			title: __('Role tag', 'power-course'),
			dataIndex: 'role',
			width: 120,
			render: (role: string | undefined) => <UserRole role={role || ''} />,
		},
		{
			title: __('Registered at', 'power-course'),
			dataIndex: 'user_registered',
			width: 180,
			align: 'right',
			render: (user_registered: string, record) => (
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
