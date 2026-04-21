import { PlusOutlined } from '@ant-design/icons'
import { useSelect } from '@refinedev/antd'
import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Select, Space, Button, Form, message, FormInstance } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { useState } from 'react'

import { UserDrawer } from '@/components/user'
import { TUserRecord } from '@/components/user/types'
import { useUserFormDrawer } from '@/hooks'

/**
 * 新增講師區塊
 *
 * 包含兩條入口：
 * 1. 「新增講師」按鈕（Create）→ 打開 UserDrawer 建立新用戶並設 is_teacher=yes
 *    透過 useUserFormDrawer；送出後由 useUserFormDrawer 內部處理 POST /users
 * 2. 「從 WP 用戶加為講師」Select → 搜尋 is_teacher!=yes 的既有用戶，
 *    選擇後 POST /users/add-teachers
 *
 * 對應 plan 步驟 10：取代舊 UserSelector（含 @ts-nocheck 技術債）。
 */
export const AddTeacherArea = () => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const [selectedUserIds, setSelectedUserIds] = useState<string[]>([])

	// 搜尋「非講師」的 WP 用戶
	const { selectProps } = useSelect<TUserRecord>({
		resource: 'users',
		optionLabel: 'formatted_name',
		optionValue: 'id',
		filters: [
			{
				field: 'search',
				operator: 'eq',
				value: '',
			},
			{
				field: 'is_teacher',
				operator: 'ne',
				value: 'yes',
			},
			// 觸發 powerhouse/user/get_meta_keys_array filter 讓 formatted_name 有值
			{
				field: 'meta_keys',
				operator: 'eq',
				value: ['formatted_name'],
			},
		],
		onSearch: (value) => [
			{
				field: 'search',
				operator: 'eq',
				value,
			},
		],
	})

	// 批次加為講師
	const { mutate, isLoading } = useCustomMutation()
	const handleAdd = () => {
		mutate(
			{
				url: `${apiUrl}/users/add-teachers`,
				method: 'post',
				values: {
					user_ids: selectedUserIds,
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
						content: __('Instructor added successfully', 'power-course'),
						key: 'add-teachers',
					})
					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
					setSelectedUserIds([])
				},
				onError: () => {
					message.error({
						content: __('Failed to add instructor', 'power-course'),
						key: 'add-teachers',
					})
				},
			}
		)
	}

	// 「新增講師」Create 表單 Drawer（含新建用戶表單）
	const [form] = Form.useForm<FormInstance>()
	const { show, drawerProps } = useUserFormDrawer({
		form,
		resource: 'users',
	})

	return (
		<div className="flex flex-col lg:flex-row gap-4 items-start lg:items-center mb-4">
			<Button type="primary" icon={<PlusOutlined />} onClick={show()}>
				{__('Create instructor', 'power-course')}
			</Button>

			<Space.Compact className="w-full lg:w-auto lg:flex-1">
				<Button
					type="primary"
					onClick={handleAdd}
					loading={isLoading}
					disabled={!selectedUserIds.length}
				>
					{__('Add from WordPress user', 'power-course')}
				</Button>
				<Select<string[]>
					{...defaultSelectProps}
					{...selectProps}
					className="flex-1"
					placeholder={__('Try searching email, name, or ID', 'power-course')}
					value={selectedUserIds}
					onChange={(value) => setSelectedUserIds(value)}
				/>
			</Space.Compact>

			<Form layout="vertical" form={form}>
				<UserDrawer {...drawerProps} />
			</Form>
		</div>
	)
}
