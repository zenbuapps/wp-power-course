import { EyeTwoTone, EyeInvisibleOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Form, Input, Space, Select, Button } from 'antd'
import { Heading } from 'antd-toolkit'
import React, { useState, useEffect } from 'react'

import { useOptions } from '@/components/teacher/TeacherTable/hooks/useOptions'
import { UserAvatarUpload } from '@/components/user'

import { useIsEditing, useRecord } from '../../hooks'

const { Item } = Form
const { TextArea } = Input

/**
 * 講師 Edit 頁 — 基本資料 Tab
 *
 * 對齊 Power Shop Basic Tab，但：
 * - 最上方加 UserAvatarUpload（講師頭像可編輯）
 * - 移除生日欄位（講師情境不需要）
 * - 密碼區塊保留「直接修改」flow，但移除 Bulk ResetPass 按鈕
 *   （因為單一用戶的重設已由 Edit 自身承擔）
 */
const Basic = () => {
	const isEditing = useIsEditing()
	const [confirmEditingPassword, setConfirmEditingPassword] = useState(false)
	const record = useRecord()
	const { roles } = useOptions()
	const { first_name, last_name, display_name, description, user_email, role } =
		record

	const canEditPassword = isEditing && confirmEditingPassword

	useEffect(() => {
		setConfirmEditingPassword(false)
	}, [isEditing])

	return (
		<div className="grid grid-cols-1 gap-y-2">
			{isEditing && <UserAvatarUpload />}

			<table className="table table-vertical table-sm text-xs [&_th]:!w-20 [&_td]:break-all">
				<tbody>
					<tr>
						<th>{__('Name', 'power-course')}</th>
						<td className="gap-x-1">
							{!isEditing && `${last_name || ''} ${first_name || ''}`}
							{isEditing &&
								['last_name', 'first_name'].map((field) => (
									<Space.Compact key={field} block>
										<div className="text-xs bg-gray-50 border-l border-y border-r-0 border-solid border-gray-300 w-20 rounded-l-[0.25rem] px-2 text-left">
											{field === 'last_name'
												? __('Last name', 'power-course')
												: __('First name', 'power-course')}
										</div>
										<Item name={[field]} noStyle label={field}>
											<Input
												size="small"
												className="text-right text-xs flex-1"
											/>
										</Item>
									</Space.Compact>
								))}
						</td>
					</tr>
					<tr>
						<th>{__('Display name', 'power-course')}</th>
						<td>
							{!isEditing && display_name}
							{isEditing && (
								<Item name={['display_name']} noStyle>
									<Input size="small" className="text-right text-xs" />
								</Item>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Email', 'power-course')}</th>
						<td>
							{!isEditing && user_email}
							{isEditing && (
								<Item name={['user_email']} noStyle>
									<Input size="small" className="text-right text-xs" />
								</Item>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Role', 'power-course')}</th>
						<td>
							{!isEditing &&
								(roles?.find(({ value }) => value === role)?.label || role)}
							{isEditing && (
								<Item name={['role']} noStyle>
									<Select
										size="small"
										className="text-right [&_.ant-select-selection-item]:!text-xs w-full h-[1.125rem]"
										options={roles}
										allowClear
									/>
								</Item>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Description', 'power-course')}</th>
						<td>
							{!isEditing && description}
							{isEditing && (
								<Item name={['description']} noStyle>
									<TextArea rows={6} className="text-xs" />
								</Item>
							)}
						</td>
					</tr>
				</tbody>
			</table>

			<Heading className="mb-4" size="sm" hideIcon>
				{__('Password', 'power-course')}
			</Heading>

			<table className="table table-vertical table-sm text-xs [&_th]:!w-20 [&_td]:break-all">
				<tbody>
					<tr>
						<th>{__('Change password', 'power-course')}</th>
						<td>
							{!canEditPassword && isEditing && (
								<Button
									size="small"
									color="primary"
									variant="solid"
									className="w-fit px-4"
									onClick={() => setConfirmEditingPassword(true)}
								>
									{__('Change password directly', 'power-course')}
								</Button>
							)}
							{canEditPassword && (
								<Item name={['user_pass']} noStyle>
									<Input.Password
										size="small"
										className="text-right text-xs"
										placeholder={__('Enter new password', 'power-course')}
										iconRender={(visible) =>
											visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
										}
									/>
								</Item>
							)}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	)
}

export default Basic
