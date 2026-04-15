import { PlusOutlined } from '@ant-design/icons'
import { useTable } from '@refinedev/antd'
import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { Table, message, Button, Form, TableProps } from 'antd'
import { useRowSelection } from 'antd-toolkit'
import React from 'react'

import { PopconfirmDelete } from '@/components/general'
import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { UserDrawer } from '@/components/user'
import { useUserFormDrawer } from '@/hooks'
import { TUserRecord } from '@/pages/admin/Courses/List/types'

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
						content: '移除講師成功！',
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
						content: '移除講師失敗！',
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
					創建講師
				</Button>
				<div className="flex-1">
					<UserSelector />
				</div>
				<PopconfirmDelete
					type="button"
					popconfirmProps={{
						title: '確認移除這些用戶的講師身分嗎?',
						onConfirm: handleRemove,
					}}
					buttonProps={{
						children: '移除講師身分',
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
					...getDefaultPaginationProps({ label: '講師' }),
				}}
			/>
			<Form layout="vertical" form={form}>
				<UserDrawer {...drawerProps} />
			</Form>
		</>
	)
}

export default TeacherTable
