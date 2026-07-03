import { useLink } from '@refinedev/core'
import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Form, Radio, Space, InputNumber, Select, Input, Alert } from 'antd'
import React, { useEffect, memo } from 'react'

import { DatePicker } from '@/components/formItem'
import { TCoursesLimit } from '@/pages/admin/Courses/List/types'

const { Item } = Form

/** WatchLimit 元件 props */
export type TWatchLimitProps = {
	/**
	 * 是否監看商品類型（`['type']` 欄位）。
	 * 預設 `true`（課程行為）：simple 商品停用 follow_subscription，且切到 simple 時自動回退 unlimited。
	 * 傳 `false`（如通行證）時：不監看商品類型、不執行自動回退、follow_subscription 永遠可選。
	 */
	enableProductTypeWatch?: boolean
	/**
	 * 是否顯示課程專屬的 follow_subscription 說明 Alert（含 /products 連結）。
	 * 預設 `true`（課程行為）。傳 `false`（如通行證）時不渲染該 Alert。
	 */
	showFollowSubscriptionAlert?: boolean
	/**
	 * follow_subscription 的說明 Alert 內容，允許 caller 覆寫。
	 * 預設為課程專屬說明文案。
	 */
	followSubscriptionAlertContent?: React.ReactNode
}

const WatchLimitComponent = ({
	enableProductTypeWatch = true,
	showFollowSubscriptionAlert = true,
	followSubscriptionAlertContent,
}: TWatchLimitProps) => {
	const form = Form.useFormInstance()
	const watchLimitType: TCoursesLimit['limit_type'] = Form.useWatch(
		['limit_type'],
		form
	)

	const handleReset = (value: string) => {
		if ('unlimited' === value) {
			form.setFieldsValue({ limit_value: '', limit_unit: '' })
		}
		if ('fixed' === value) {
			form.setFieldsValue({ limit_value: 1, limit_unit: 'day' })
		}
		if ('assigned' === value) {
			form.setFieldsValue({
				limit_value: undefined,
				limit_unit: 'timestamp',
			})
		}
		if ('follow_subscription' === value) {
			form.setFieldsValue({ limit_value: '', limit_unit: '' })
		}
	}

	const watchProductType = Form.useWatch(
		enableProductTypeWatch ? ['type'] : [],
		form
	)

	useEffect(() => {
		if (!enableProductTypeWatch) {
			return
		}
		if (
			watchProductType === 'simple' &&
			watchLimitType === 'follow_subscription'
		) {
			form.setFieldValue(['limit_type'], 'unlimited')
		}
	}, [watchProductType])

	const Link = useLink()

	/** 課程預設的 follow_subscription 說明文案 */
	const defaultFollowSubscriptionAlert = (
		<ol className="pl-4">
			<li>
				{__(
					'If Follow Subscription is selected, the course must be a subscription product',
					'power-course'
				)}
			</li>
			<li>
				{createInterpolateElement(
					__(
						'You can also keep the course as a simple product, use Bundle to create a recurring subscription plan, then go to <a>Course Permission Binding</a> to change the course watch duration to Follow Subscription',
						'power-course'
					),
					{
						a: <Link to="/products" />,
					}
				)}
			</li>
		</ol>
	)

	return (
		<div>
			<Item
				label={__('Watch duration', 'power-course')}
				name={['limit_type']}
				initialValue={'unlimited'}
			>
				<Radio.Group
					className="w-full w-avg"
					options={[
						{ label: __('Unlimited', 'power-course'), value: 'unlimited' },
						{ label: __('Fixed days', 'power-course'), value: 'fixed' },
						{ label: __('Specified time', 'power-course'), value: 'assigned' },
						{
							label: __('Follow subscription', 'power-course'),
							value: 'follow_subscription',
							disabled: enableProductTypeWatch && watchProductType === 'simple',
						},
					]}
					optionType="button"
					buttonStyle="solid"
					onChange={(e) => {
						const value = e?.target?.value || ''
						handleReset(value)
					}}
				/>
			</Item>
			{'unlimited' === watchLimitType && (
				<>
					<Item name={['limit_value']} initialValue="" hidden />
					<Item name={['limit_unit']} initialValue="" hidden />
				</>
			)}
			{'fixed' === watchLimitType && (
				<Space.Compact block>
					<Item name={['limit_value']} initialValue={1} className="w-full">
						<InputNumber className="w-full" min={1} />
					</Item>
					<Item name={['limit_unit']} initialValue="day">
						<Select
							options={[
								{ label: __('Day', 'power-course'), value: 'day' },
								{ label: __('Month', 'power-course'), value: 'month' },
								{ label: __('Year', 'power-course'), value: 'year' },
							]}
							className="w-16"
						/>
					</Item>
				</Space.Compact>
			)}
			{'assigned' === watchLimitType && (
				<>
					<DatePicker
						formItemProps={{
							name: ['limit_value'],
							className: 'mb-0',
							rules: [
								{
									required: true,
									message: __('Please enter specified time', 'power-course'),
								},
							],
						}}
					/>
					<Item name={['limit_unit']} initialValue="timestamp" hidden>
						<Input />
					</Item>
				</>
			)}
			{'follow_subscription' === watchLimitType && (
				<>
					{showFollowSubscriptionAlert && (
						<Alert
							className="my-4"
							message={__('Notice', 'power-course')}
							description={
								followSubscriptionAlertContent ?? defaultFollowSubscriptionAlert
							}
							type="warning"
							showIcon
						/>
					)}
					<Item name={['limit_value']} initialValue="" hidden />
					<Item name={['limit_unit']} initialValue="" hidden />
				</>
			)}
		</div>
	)
}

export const WatchLimit = memo(WatchLimitComponent)
