import { PlusOutlined } from '@ant-design/icons'
import { useTable } from '@refinedev/antd'
import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Table, message, Button, Form, TableProps } from 'antd'
import { useRowSelection } from 'antd-toolkit'
import React from 'react'

import { PopconfirmDelete } from '@/components/general'
import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { UserDrawer } from '@/components/user'
import { TUserRecord } from '@/components/user/types'
import { useUserFormDrawer } from '@/hooks'

import UserSelector from '../UserSelector'

import useColumns from './hooks/useColumns'

const TeacherTable = () => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	const { tableProps } = useTable<TUserRecord>({
		resource: 'users',
		filters: {
			permanent: [
				{
					field: 'is_teacher',
					operator: 'eq',
					value: 'yes',
				},
			],
		},
		pagination: {
			pageSize: 20,
		},
	})

	const users = tableProps?.dataSource || []

	// 多選
	const { rowSelection, setSelectedRowKeys, selectedRowKeys } =
		useRowSelection<TUserRecord>({
			onChange: (currentSelectedRowKeys: React.Key[]) => {
				setSelectedRowKeys(currentSelectedRowKeys)
			},
		})

	// remove teacher mutation
	const { mutate, isLoading } = useCustomMutation()

	const handleRemove = () => {
		mutate(
			{
				url: `${apiUrl}/users/remove-teachers`,
				method: 'post',
				values: {
					user_ids: selectedRowKeys,
				},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Instructor removed successfully', 'power-course'),
						key: 'remove-teachers',
					})
					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
					setSelectedRowKeys([])
				},
				onError: () => {
					message.error({
						content: __('Failed to remove instructor', 'power-course'),
						key: 'remove-teachers',
					})
				},
			}
		)
	}

	const [form] = Form.useForm()
	const { show, drawerProps } = useUserFormDrawer({
		form,
		resource: 'users',
		users,
	})
	const columns = useColumns({
		onClick: show,
	})

	return (
		<>
			<div className="flex gap-4 mb-4">
				<Button
					type="primary"
					className="mb-4"
					icon={<PlusOutlined />}
					onClick={show()}
				>
					{__('Create instructor', 'power-course')}
				</Button>
				<div className="flex-1">
					<UserSelector />
				</div>
				<PopconfirmDelete
					type="button"
					popconfirmProps={{
						title: __(
							'Confirm to remove instructor role from these users?',
							'power-course'
						),
						onConfirm: handleRemove,
					}}
					buttonProps={{
						children: __('Remove instructor role', 'power-course'),
						disabled: !selectedRowKeys.length,
						loading: isLoading,
					}}
				/>
			</div>
			<Table
				{...(defaultTableProps as unknown as TableProps<TUserRecord>)}
				{...tableProps}
				columns={columns}
				rowSelection={rowSelection}
				pagination={{
					...tableProps.pagination,
					...getDefaultPaginationProps({
						label: __('Instructors', 'power-course'),
					}),
				}}
			/>
			<Form layout="vertical" form={form}>
				<UserDrawer {...drawerProps} />
			</Form>
		</>
	)
}

export default TeacherTable
