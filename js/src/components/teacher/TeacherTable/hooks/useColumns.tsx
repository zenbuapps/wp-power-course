import { EditOutlined } from '@ant-design/icons'
import { useNavigation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { TableProps, Typography, Button } from 'antd'
import { UserRole } from 'antd-toolkit/wp'
import React from 'react'

import { UserName } from '@/components/user'

import { TTeacherRecord } from '../../types'

const { Text } = Typography

type TUseColumnsParams = {
	onClick?: (_record: TTeacherRecord | undefined) => () => void
}

/**
 * TeacherTable 欄位定義
 *
 * 7 欄：
 * 1. 講師（UserName + 點擊 → edit 頁）
 * 2. Email
 * 3. 註冊時間（雙行：相對時間 + 完整日期）
 * 4. 負責課程數（computed field teacher_courses_count，0 顯示 dash）
 * 5. 學員人數（computed field teacher_students_count，0 顯示 dash）
 * 6. WP 角色（UserRole Tag，from antd-toolkit/wp）
 * 7. 操作（Edit 按鈕，連結 /teachers/edit/:id）
 */
const useColumns = (params?: TUseColumnsParams) => {
	const { edit } = useNavigation()
	const handleClick = params?.onClick

	const columns: TableProps<TTeacherRecord>['columns'] = [
		{
			title: __('Instructor', 'power-course'),
			dataIndex: 'id',
			width: 200,
			render: (_, record) => <UserName record={record} onClick={handleClick} />,
		},
		{
			title: __('Email', 'power-course'),
			dataIndex: 'user_email',
			width: 220,
			render: (email: string) => (
				<Text ellipsis={{ tooltip: email }} className="text-xs">
					{email || '-'}
				</Text>
			),
		},
		{
			title: __('Registered at', 'power-course'),
			dataIndex: 'user_registered',
			width: 180,
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
		{
			title: __('Courses count', 'power-course'),
			dataIndex: 'teacher_courses_count',
			width: 100,
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
			width: 100,
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
			title: __('Actions', 'power-course'),
			dataIndex: 'id',
			width: 100,
			render: (id: string) => (
				<Button
					type="link"
					size="small"
					icon={<EditOutlined />}
					onClick={() => edit('teachers', id)}
				>
					{__('Edit', 'power-course')}
				</Button>
			),
		},
	]

	return columns
}

export default useColumns
