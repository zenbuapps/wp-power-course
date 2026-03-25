import { Form, Input, InputNumber, Select, Space } from 'antd'
import React, { memo, useEffect } from 'react'

const { Item } = Form

// eslint-disable-next-line no-shadow
export enum SUBSCRIPTION {
	PRICE = '_subscription_price', // 訂閱價格每 M 個月 [N 元] - number
	PERIOD_INTERVAL = '_subscription_period_interval', // 訂閱價格每 [M 個] 月 N 元 - number
	PERIOD = '_subscription_period', // 訂閱價格每 M 個 [月] N 元 - day | week | month | year
	LENGTH = '_subscription_length', // 續訂截止日，0 = 無期限 - number
	SIGN_UP_FEE = '_subscription_sign_up_fee', // 註冊費 - number
	TRIAL_LENGTH = '_subscription_trial_length', // 免費試用 [N] 天 - number
	TRIAL_PERIOD = '_subscription_trial_period', // 免費試用 N [天] - day | week | month | year
	// LIMIT = '_subscription_limit', // 續訂限制 - number
	// ONE_TIME_SHIPPING = '_subscription_one_time_shipping', // 一次性運費 - number
}

const PERIOD_OPTIONS = [
	{
		value: 'day',
		label: '天',
	},
	{
		value: 'week',
		label: '週',
	},
	{
		value: 'month',
		label: '月',
	},
	{
		value: 'year',
		label: '年',
	},
]

const lengthOptions = new Array(31).fill(0).map((_, index) => ({
	value: index,
	label: index ? `${index}` : '直到取消為止',
}))

const Subscription = () => {
	const form = Form.useFormInstance()
	const watchPeriod = Form.useWatch(SUBSCRIPTION.PERIOD, form)
	const watchPeriodLabel = watchPeriod
		? PERIOD_OPTIONS.find((option) => option.value === watchPeriod)?.label
		: ''

	const watchPrice = Form.useWatch(SUBSCRIPTION.PRICE, form)

	useEffect(() => {
		form.setFieldValue('regular_price', watchPrice)
	}, [watchPrice])

	return (
		<>
			<Item name={['regular_price']} label="原價" hidden />

			<div className="mb-6">
				{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
				<label className="tw-block mb-2">訂閱價格</label>
				<Space.Compact block>
					<Item name={SUBSCRIPTION.PRICE} noStyle rules={[{ required: true }]}>
						<InputNumber className="w-[37%]" addonAfter="元" />
					</Item>
					<Item name={SUBSCRIPTION.PERIOD_INTERVAL} noStyle initialValue={1}>
						<InputNumber
							className="w-[37%]"
							addonBefore="每"
							addonAfter="個"
							min={1}
						/>
					</Item>
					<Item name={SUBSCRIPTION.PERIOD} noStyle initialValue="month">
						<Select options={PERIOD_OPTIONS} className="w-[26%]" />
					</Item>
				</Space.Compact>
			</div>

			<div className="mb-6">
				{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
				<label className="tw-block mb-2">續訂截止日(扣款期數)</label>
				<Space.Compact block>
					<Item
						name={SUBSCRIPTION.LENGTH}
						label="續訂截止日(扣款期數)"
						noStyle
						initialValue={0}
					>
						<Select options={lengthOptions} className="flex-1" />
					</Item>
					<Input
						className="w-[32%] pointer-events-none"
						addonBefore={<span className="px-[5px]">個</span>}
						value={watchPeriodLabel}
					/>
				</Space.Compact>
			</div>

			<Item
				name={SUBSCRIPTION.SIGN_UP_FEE}
				label="註冊費 (NT$)"
				initialValue={0}
			>
				<InputNumber className="w-full" />
			</Item>

			<div className="mb-6">
				{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
				<label className="tw-block mb-2">免費試用</label>
				<Space.Compact block>
					<Item name={SUBSCRIPTION.TRIAL_LENGTH} noStyle initialValue={0}>
						<InputNumber className="w-[74%]" addonAfter="個" />
					</Item>
					<Item name={SUBSCRIPTION.TRIAL_PERIOD} noStyle initialValue="month">
						<Select options={PERIOD_OPTIONS} className="w-[26%]" />
					</Item>
				</Space.Compact>
			</div>
		</>
	)
}

export default memo(Subscription)
