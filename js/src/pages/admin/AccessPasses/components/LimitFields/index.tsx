import { __ } from '@wordpress/i18n'
import { Form, Select, Radio, InputNumber, Space } from 'antd'
import { memo } from 'react'

import { TLimitMode } from '@/pages/admin/AccessPasses/types'

const { Item } = Form

/**
 * 期限設定欄位（依 limit_mode 動態切換）
 *
 * - permanent：無額外欄位
 * - follow_subscription：無額外欄位（觀看權跟隨訂閱狀態，compute-on-read）
 * - limited：limit_value（正整數）+ limit_unit（日 / 月 / 年）
 *
 * 切換模式時重置 limit_value / limit_unit，避免殘留非當前模式的值。
 * 須置於 antd `<Form>` 內使用。
 */
const LimitFieldsComponent = () => {
	const form = Form.useFormInstance()
	const limitMode: TLimitMode = Form.useWatch(['limit_mode'], form)

	/** 切換期限模式時重置限時欄位 */
	const handleModeChange = (mode: TLimitMode) => {
		if ('limited' === mode) {
			form.setFieldsValue({ limit_value: 1, limit_unit: 'day' })
		} else {
			form.setFieldsValue({ limit_value: '', limit_unit: '' })
		}
	}

	return (
		<>
			<Item
				label={__('Access period', 'power-course')}
				name={['limit_mode']}
				initialValue="permanent"
				rules={[
					{
						required: true,
						message: __('Please select access period', 'power-course'),
					},
				]}
			>
				<Radio.Group
					optionType="button"
					buttonStyle="solid"
					onChange={(e) => handleModeChange(e.target.value as TLimitMode)}
					options={[
						{ label: __('Permanent', 'power-course'), value: 'permanent' },
						{
							label: __('Follow subscription', 'power-course'),
							value: 'follow_subscription',
						},
						{ label: __('Limited time', 'power-course'), value: 'limited' },
					]}
				/>
			</Item>

			{'limited' === limitMode && (
				<Space.Compact block>
					<Item
						name={['limit_value']}
						initialValue={1}
						className="w-full"
						rules={[
							{
								required: true,
								message: __('Please enter a value', 'power-course'),
							},
						]}
					>
						<InputNumber className="w-full" min={1} precision={0} />
					</Item>
					<Item name={['limit_unit']} initialValue="day">
						<Select
							className="w-24"
							options={[
								{ label: __('day', 'power-course'), value: 'day' },
								{ label: __('month', 'power-course'), value: 'month' },
								{ label: __('year', 'power-course'), value: 'year' },
							]}
						/>
					</Item>
				</Space.Compact>
			)}

			{'limited' !== limitMode && (
				<>
					<Item name={['limit_value']} initialValue="" hidden />
					<Item name={['limit_unit']} initialValue="" hidden />
				</>
			)}
		</>
	)
}

export const LimitFields = memo(LimitFieldsComponent)
