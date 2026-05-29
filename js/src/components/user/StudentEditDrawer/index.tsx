import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
	useOne,
} from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import {
	Button,
	Collapse,
	Drawer,
	Empty,
	Form,
	Input,
	Select,
	Spin,
	Tag,
	Typography,
	message,
} from 'antd'
import { useAtom } from 'jotai'
import React, { memo, useEffect, useRef } from 'react'

import { TOrdersSummary, TUserDetail } from '@/components/user/types'

import { studentEditDrawerAtom } from '../UserTable/atom'

import { useCountryOptions } from './hooks/useCountryOptions'

const { Item } = Form
const { Text, Link } = Typography

/** 表單欄位型別 */
type TFormValues = {
	user_email?: string
	display_name?: string
	first_name?: string
	last_name?: string
	user_pass?: string
	user_pass_confirm?: string
	billing_first_name?: string
	billing_last_name?: string
	billing_email?: string
	billing_phone?: string
	billing_address_1?: string
	billing_address_2?: string
	billing_city?: string
	billing_state?: string
	billing_postcode?: string
	billing_country?: string
	shipping_first_name?: string
	shipping_last_name?: string
	shipping_address_1?: string
	shipping_address_2?: string
	shipping_city?: string
	shipping_state?: string
	shipping_postcode?: string
	shipping_country?: string
}

/**
 * 國家 / 州省連動選擇欄位
 *
 * 依所選國家動態切換 state 欄位：有州省清單 → Select；無 → 純 Input。
 */
const CountryStateFields: React.FC<{
	prefix: 'billing' | 'shipping'
}> = ({ prefix }) => {
	const { countryOptions, getStateOptions } = useCountryOptions()

	return (
		<>
			<Item name={`${prefix}_country`} label={__('Country', 'power-course')}>
				<Select
					showSearch
					allowClear
					optionFilterProp="label"
					options={countryOptions}
					placeholder={__('Country', 'power-course')}
				/>
			</Item>
			<Item
				noStyle
				shouldUpdate={(prev, next) =>
					prev[`${prefix}_country`] !== next[`${prefix}_country`]
				}
			>
				{({ getFieldValue }) => {
					const countryCode = getFieldValue(`${prefix}_country`) as
						| string
						| undefined
					const stateOptions = getStateOptions(countryCode)
					return (
						<Item
							name={`${prefix}_state`}
							label={__('State / Province', 'power-course')}
						>
							{stateOptions.length > 0 ? (
								<Select
									showSearch
									allowClear
									optionFilterProp="label"
									options={stateOptions}
									placeholder={__('State / Province', 'power-course')}
								/>
							) : (
								<Input />
							)}
						</Item>
					)
				}}
			</Item>
		</>
	)
}

/**
 * 學員快速編輯 Drawer
 *
 * 透過 jotai atom 控制開關與當前編輯的 user_id。
 * 載入使用者詳細資料與訂單摘要，提供基本資料、密碼、帳單、收件、訂單摘要等區塊。
 * 所有 HTTP 請求皆透過 Refine hooks，所有可見字串皆走 i18n。
 */
const StudentEditDrawerComponent: React.FC = () => {
	const [{ user_id, open }, setEditDrawer] = useAtom(studentEditDrawerAtom)
	const [form] = Form.useForm<TFormValues>()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	/** 載入時的 Email 基準值，用於判斷登入 Email 是否變更 */
	const loadedEmailRef = useRef<string>('')

	const enabled = open && !!user_id

	// 載入使用者詳細資料
	const { data: userData, isFetching: isUserFetching } = useOne<TUserDetail>({
		resource: 'users',
		id: user_id,
		dataProviderName: 'power-course',
		queryOptions: {
			enabled,
		},
	})

	// 載入訂單摘要
	const { data: ordersData, isFetching: isOrdersFetching } =
		useCustom<TOrdersSummary>({
			url: `${apiUrl}/users/${user_id}/orders-summary`,
			method: 'get',
			config: {
				query: { limit: 5 },
			},
			queryOptions: {
				enabled,
			},
		})

	const ordersSummary = ordersData?.data

	// 儲存 mutation
	const { mutate: save, isLoading: isSaving } = useCustomMutation()

	// 發送密碼重設信 mutation
	const { mutate: resetPassword, isLoading: isResetting } = useCustomMutation()

	// 載入資料後填入表單
	useEffect(() => {
		const detail = userData?.data
		if (open && detail) {
			const { meta_data } = detail
			loadedEmailRef.current = detail.email ?? ''
			form.setFieldsValue({
				user_email: detail.email,
				display_name: detail.display_name,
				first_name: meta_data?.first_name,
				last_name: meta_data?.last_name,
				billing_first_name: meta_data?.billing_first_name,
				billing_last_name: meta_data?.billing_last_name,
				billing_email: meta_data?.billing_email,
				billing_phone: meta_data?.billing_phone,
				billing_address_1: meta_data?.billing_address_1,
				billing_address_2: meta_data?.billing_address_2,
				billing_city: meta_data?.billing_city,
				billing_state: meta_data?.billing_state,
				billing_postcode: meta_data?.billing_postcode,
				billing_country: meta_data?.billing_country,
				shipping_first_name: meta_data?.shipping_first_name,
				shipping_last_name: meta_data?.shipping_last_name,
				shipping_address_1: meta_data?.shipping_address_1,
				shipping_address_2: meta_data?.shipping_address_2,
				shipping_city: meta_data?.shipping_city,
				shipping_state: meta_data?.shipping_state,
				shipping_postcode: meta_data?.shipping_postcode,
				shipping_country: meta_data?.shipping_country,
			})
		}
	}, [userData?.data, open])

	const handleClose = () => {
		setEditDrawer({ open: false })
		form.resetFields()
	}

	const handleSave = () => {
		if (!user_id) return
		form.validateFields().then(() => {
			const values = form.getFieldsValue(true) as TFormValues
			// 移除密碼確認欄位，且空密碼不送出（代表不變更）
			const { user_pass_confirm: _confirm, ...rest } = values
			const payload: Record<string, string> = {}
			Object.entries(rest).forEach(([key, value]) => {
				if (key === 'user_pass' && !value) return
				payload[key] = (value as string | undefined) ?? ''
			})

			const emailChanged =
				typeof values.user_email === 'string' &&
				values.user_email !== loadedEmailRef.current

			save(
				{
					url: `${apiUrl}/users/${user_id}`,
					method: 'post',
					values: payload,
					config: {
						headers: {
							'Content-Type': 'multipart/form-data;',
						},
					},
				},
				{
					onSuccess: () => {
						message.success({
							content: __('Modified successfully', 'power-course'),
							key: 'student-edit',
						})
						if (emailChanged) {
							message.info({
								content: __('Login email updated', 'power-course'),
								key: 'student-edit-email',
							})
							loadedEmailRef.current = values.user_email ?? ''
						}
						// 清空密碼欄位（已套用或不變更）
						form.setFieldsValue({
							user_pass: undefined,
							user_pass_confirm: undefined,
						})
						invalidate({ resource: 'users', invalidates: ['list', 'detail'] })
						invalidate({
							resource: 'students',
							dataProviderName: 'power-course',
							invalidates: ['list'],
						})
					},
					onError: (error) => {
						message.error({
							content:
								(error as { message?: string })?.message ||
								__('Modification failed', 'power-course'),
							key: 'student-edit',
						})
					},
				}
			)
		})
	}

	const handleResetPassword = () => {
		if (!user_id) return
		resetPassword(
			{
				url: `${apiUrl}/users/${user_id}/reset-password`,
				method: 'post',
				values: {},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Password reset email sent', 'power-course'),
						key: 'student-reset-pass',
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to send password reset email', 'power-course'),
						key: 'student-reset-pass',
					})
				},
			}
		)
	}

	return (
		<Drawer
			width={640}
			open={open}
			onClose={handleClose}
			title={
				<>
					<p className="my-0">{__('Edit student', 'power-course')}</p>
					{!!user_id && (
						<span className="text-gray-400 text-xs">#{user_id}</span>
					)}
				</>
			}
			extra={
				<Button type="primary" loading={isSaving} onClick={handleSave}>
					{__('Save', 'power-course')}
				</Button>
			}
		>
			<Spin spinning={isUserFetching}>
				<Form<TFormValues> form={form} layout="vertical">
					{/* 基本資料 */}
					<p className="mt-0 mb-3 font-bold">
						{__('Basic info', 'power-course')}
					</p>
					<Item label={__('User ID', 'power-course')}>
						<Text>{user_id ?? '-'}</Text>
					</Item>
					<Item label={__('Username', 'power-course')}>
						<Input value={userData?.data?.user_login ?? ''} disabled />
					</Item>
					<Item
						name="user_email"
						label="Email"
						rules={[
							{
								type: 'email',
								message: __('Invalid email format', 'power-course'),
							},
						]}
					>
						<Input />
					</Item>
					<Item name="display_name" label={__('Display name', 'power-course')}>
						<Input />
					</Item>
					<Item name="last_name" label={__('Last name', 'power-course')}>
						<Input />
					</Item>
					<Item name="first_name" label={__('First name', 'power-course')}>
						<Input />
					</Item>

					{/* 新密碼 */}
					<p className="mt-6 mb-3 font-bold">
						{__('New password', 'power-course')}
					</p>
					<Item
						name="user_pass"
						label={__('New password', 'power-course')}
						rules={[
							({ getFieldValue }) => ({
								validator(_rule, value) {
									const confirm = getFieldValue('user_pass_confirm')
									if (!value && !confirm) return Promise.resolve()
									if (value !== confirm) {
										return Promise.reject(
											new Error(__('Passwords do not match', 'power-course'))
										)
									}
									return Promise.resolve()
								},
							}),
						]}
					>
						<Input.Password autoComplete="new-password" />
					</Item>
					<Item
						name="user_pass_confirm"
						label={__('Confirm new password', 'power-course')}
						dependencies={['user_pass']}
						rules={[
							({ getFieldValue }) => ({
								validator(_rule, value) {
									const pass = getFieldValue('user_pass')
									if (!pass && !value) return Promise.resolve()
									if (pass !== value) {
										return Promise.reject(
											new Error(__('Passwords do not match', 'power-course'))
										)
									}
									return Promise.resolve()
								},
							}),
						]}
					>
						<Input.Password autoComplete="new-password" />
					</Item>
					<Button
						className="mb-4"
						loading={isResetting}
						onClick={handleResetPassword}
					>
						{__('Send password reset email', 'power-course')}
					</Button>

					{/* 帳單資料 */}
					<p className="mt-6 mb-3 font-bold">
						{__('Billing info', 'power-course')}
					</p>
					<Item
						name="billing_last_name"
						label={__('Last name', 'power-course')}
					>
						<Input />
					</Item>
					<Item
						name="billing_first_name"
						label={__('First name', 'power-course')}
					>
						<Input />
					</Item>
					<Item
						name="billing_phone"
						label={__('Billing phone', 'power-course')}
					>
						<Input />
					</Item>
					<Item
						name="billing_email"
						label={__('Billing email', 'power-course')}
						rules={[
							{
								type: 'email',
								message: __('Invalid email format', 'power-course'),
							},
						]}
					>
						<Input />
					</Item>
					<Item
						name="billing_address_1"
						label={__('Address line 1', 'power-course')}
					>
						<Input />
					</Item>
					<Item
						name="billing_address_2"
						label={__('Address line 2', 'power-course')}
					>
						<Input />
					</Item>
					<Item name="billing_city" label={__('City', 'power-course')}>
						<Input />
					</Item>
					<Item name="billing_postcode" label={__('Postcode', 'power-course')}>
						<Input />
					</Item>
					<CountryStateFields prefix="billing" />

					{/* 收件資料（預設收合） */}
					<Collapse
						className="mt-6 mb-4"
						items={[
							{
								key: 'shipping',
								label: __('Shipping info', 'power-course'),
								children: (
									<>
										<Item
											name="shipping_last_name"
											label={__('Last name', 'power-course')}
										>
											<Input />
										</Item>
										<Item
											name="shipping_first_name"
											label={__('First name', 'power-course')}
										>
											<Input />
										</Item>
										<Item
											name="shipping_address_1"
											label={__('Address line 1', 'power-course')}
										>
											<Input />
										</Item>
										<Item
											name="shipping_address_2"
											label={__('Address line 2', 'power-course')}
										>
											<Input />
										</Item>
										<Item
											name="shipping_city"
											label={__('City', 'power-course')}
										>
											<Input />
										</Item>
										<Item
											name="shipping_postcode"
											label={__('Postcode', 'power-course')}
										>
											<Input />
										</Item>
										<CountryStateFields prefix="shipping" />
									</>
								),
							},
						]}
					/>
				</Form>

				{/* 訂單摘要 */}
				<p className="mt-6 mb-3 font-bold">
					{__('Order summary', 'power-course')}
				</p>
				<Spin spinning={isOrdersFetching}>
					{!ordersSummary || ordersSummary.total === 0 ? (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description={__('No orders yet', 'power-course')}
						/>
					) : (
						<div>
							<p className="text-gray-500 text-sm mb-2">
								{sprintf(
									// translators: %s: 訂單總數
									__('Total %s orders', 'power-course'),
									String(ordersSummary.total)
								)}
							</p>
							{ordersSummary.recent.map((order) => (
								<div
									key={order.id}
									className="flex items-center justify-between border-0 border-b border-solid border-gray-100 py-2"
								>
									<div>
										<Link href={order.edit_url} target="_blank">
											#{order.number}
										</Link>
										<span className="ml-2 text-gray-400 text-xs">
											{order.date_created}
										</span>
									</div>
									<div className="flex items-center gap-2">
										<Tag>{order.status}</Tag>
										<span>
											{order.total} {order.currency}
										</span>
									</div>
								</div>
							))}
							<div className="mt-3">
								<Link href={ordersSummary.view_all_url} target="_blank">
									{__('View all orders', 'power-course')}
								</Link>
							</div>
						</div>
					)}
				</Spin>
			</Spin>
		</Drawer>
	)
}

export const StudentEditDrawer = memo(StudentEditDrawerComponent)
