import { EyeInvisibleOutlined, EyeTwoTone } from '@ant-design/icons'
import { useApiUrl, useCustomMutation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Button, DatePicker, Form, Input, Select, Space } from 'antd'
import { Heading } from 'antd-toolkit'
import dayjs, { Dayjs } from 'dayjs'
import { useEffect, useState } from 'react'

import {
	useIsEditing,
	useOptions,
	useRecord,
} from '@/components/user/StudentEditModal/hooks'
import { INFO_LABEL_MAPPER } from '@/utils'
import { IS_ADMIN } from '@/utils/env'

const { Item } = Form
const { TextArea } = Input

/**
 * 基本資料區塊
 *
 * view 模式以唯讀表格呈現姓名、顯示名稱、Email、角色、生日、簡介；
 * edit 模式提供對應輸入欄位（角色用 useOptions 下拉、生日用 DatePicker 並以 YYYY-MM-DD
 * 序列化）。密碼區提供「寄送重設信」與「直接修改密碼」（需二次確認）兩種方式，
 * 重設信沿用既有 users/{id}/reset-password 端點。
 */
const Basic = () => {
	const isEditing = useIsEditing()
	const [confirmEditingPassword, setConfirmEditingPassword] = useState(false)
	const record = useRecord()
	const { roles: roleOptions } = useOptions()
	const apiUrl = useApiUrl('power-course')
	const {
		id,
		first_name,
		last_name,
		display_name,
		description,
		user_email,
		user_birthday,
		role,
	} = record

	// 發送密碼重設信 mutation
	const { mutate: resetPassword, isLoading: isResetting } = useCustomMutation()

	const canEditPassword = isEditing && confirmEditingPassword

	useEffect(() => {
		setConfirmEditingPassword(false)
	}, [isEditing])

	const handleResetPassword = () => {
		if (!id) return
		resetPassword({
			url: `${apiUrl}/users/${id}/reset-password`,
			method: 'post',
			values: {},
			config: {
				headers: {
					'Content-Type': 'multipart/form-data;',
				},
			},
			successNotification: () => ({
				message: __('Password reset email sent', 'power-course'),
				type: 'success',
			}),
			errorNotification: () => ({
				message: __('Failed to send password reset email', 'power-course'),
				type: 'error',
			}),
		})
	}

	return (
		<div className="grid grid-cols-1 gap-y-2">
			<table className="table table-vertical table-sm text-xs [&_th]:!w-20 [&_td]:break-all">
				<tbody>
					<tr>
						<th>{__('Name', 'power-course')}</th>
						<td className="gap-x-1">
							{!isEditing && `${last_name} ${first_name}`}
							{isEditing &&
								['last_name', 'first_name'].map((field) => (
									<Space.Compact
										key={field}
										block
										className={isEditing ? '' : 'tw-hidden'}
									>
										<div className="text-xs bg-gray-50 border-l border-y border-r-0 border-solid border-gray-300 w-20 rounded-l-[0.25rem] px-2 text-left">
											{INFO_LABEL_MAPPER?.[field]}
										</div>
										<Item
											name={[field]}
											noStyle
											label={field}
											hidden={!isEditing}
										>
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
								<Item name={['display_name']} noStyle hidden={!isEditing}>
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
								<Item
									name={['user_email']}
									noStyle
									hidden={!isEditing}
									rules={[
										{
											type: 'email',
											message: __('Invalid email format', 'power-course'),
										},
									]}
								>
									<Input size="small" className="text-right text-xs" />
								</Item>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Role', 'power-course')}</th>
						<td>
							{!isEditing &&
								(roleOptions?.find(({ value }) => value === role)?.label ||
									role)}
							{/* F2/Q1=B：僅 Administrator（IS_ADMIN）可在編輯模式修改角色 */}
							{isEditing && IS_ADMIN && (
								<Item name={['role']} noStyle hidden={!isEditing}>
									<Select
										size="small"
										className="text-right [&_.ant-select-selection-item]:!text-xs w-full h-[1.125rem]"
										options={roleOptions}
										allowClear
										// B1：浮層掛在觸發元素的父層（Modal stacking context 內），避免被遮擋
										getPopupContainer={(trigger) =>
											trigger.parentElement as HTMLElement
										}
									/>
								</Item>
							)}
							{/* F2/Q1=B：非 Administrator 在編輯模式僅顯示純文字唯讀角色 */}
							{isEditing && !IS_ADMIN && (
								<span className="text-gray-500">
									{sprintf(
										// translators: %s: 目前角色名稱
										__('Current role: %s', 'power-course'),
										roleOptions?.find(({ value }) => value === role)?.label ||
											role ||
											''
									)}
								</span>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Birthday', 'power-course')}</th>
						<td>
							{!isEditing && user_birthday}
							{isEditing && (
								<Item
									name={['user_birthday']}
									noStyle
									hidden={!isEditing}
									getValueProps={(value: string | null | undefined) => {
										// 用 regex 判斷是否為 YYYY-MM-DD
										if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) {
											return {
												value: undefined,
											}
										}
										return {
											value: dayjs(value, 'YYYY-MM-DD'),
										}
									}}
									normalize={(value: Dayjs) => value?.format('YYYY-MM-DD')}
								>
									<DatePicker
										className="w-full"
										placeholder={__('Select date', 'power-course')}
										size="small"
										allowClear
									/>
								</Item>
							)}
						</td>
					</tr>
					<tr>
						<th>{__('Bio', 'power-course')}</th>
						<td>
							{!isEditing && description}
							{isEditing && (
								<Item name={['description']} noStyle hidden={!isEditing}>
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
					{canEditPassword && (
						<>
							<tr>
								<th>{__('New password', 'power-course')}</th>
								<td>
									<Item
										name={['user_pass']}
										noStyle
										rules={[
											({ getFieldValue }) => ({
												validator(_rule, value) {
													const confirm = getFieldValue(['user_pass_confirm'])
													if (!value && !confirm) return Promise.resolve()
													if (value !== confirm) {
														return Promise.reject(
															new Error(
																__('Passwords do not match', 'power-course')
															)
														)
													}
													return Promise.resolve()
												},
											}),
										]}
									>
										<Input.Password
											size="small"
											className="text-right text-xs"
											autoComplete="new-password"
											placeholder={__('Enter new password', 'power-course')}
											iconRender={(visible) =>
												visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
											}
										/>
									</Item>
								</td>
							</tr>
							<tr>
								<th>{__('Confirm new password', 'power-course')}</th>
								<td>
									<Item
										name={['user_pass_confirm']}
										noStyle
										dependencies={['user_pass']}
										rules={[
											({ getFieldValue }) => ({
												validator(_rule, value) {
													const pass = getFieldValue(['user_pass'])
													if (!pass && !value) return Promise.resolve()
													if (pass !== value) {
														return Promise.reject(
															new Error(
																__('Passwords do not match', 'power-course')
															)
														)
													}
													return Promise.resolve()
												},
											}),
										]}
									>
										<Input.Password
											size="small"
											className="text-right text-xs"
											autoComplete="new-password"
											iconRender={(visible) =>
												visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
											}
										/>
									</Item>
								</td>
							</tr>
						</>
					)}
					<tr>
						<th>{__('Change password', 'power-course')}</th>
						<td>
							<Button
								size="small"
								variant="filled"
								color="default"
								className="w-fit px-4"
								loading={isResetting}
								onClick={handleResetPassword}
							>
								{__('Send password reset email', 'power-course')}
							</Button>
							{isEditing && !confirmEditingPassword && (
								<Button
									size="small"
									color="primary"
									variant="solid"
									className="w-fit px-4 ml-2"
									onClick={() => setConfirmEditingPassword(true)}
								>
									{__('Change password directly', 'power-course')}
								</Button>
							)}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	)
}

export default Basic
