import { __ } from '@wordpress/i18n'
import { Form, Select, Radio, Alert } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { memo } from 'react'

import {
	useCourseSelectOptions,
	useTermOptions,
} from '@/pages/admin/AccessPasses/hooks'
import { TScopeType } from '@/pages/admin/AccessPasses/types'

const { Item } = Form

/**
 * 範圍設定欄位（依 scope_type 動態切換）
 *
 * - all：無額外欄位，顯示「含日後新增課程」動態警告
 * - category：product_cat / product_tag 多選（聯集，含子分類），顯示動態警告
 * - specific：課程多選（固定清單，不隨新增課程擴張）
 *
 * 須置於 antd `<Form>` 內使用（透過 Form.useWatch 讀取 scope_type）。
 */
const ScopeFieldsComponent = () => {
	const form = Form.useFormInstance()
	const scopeType: TScopeType = Form.useWatch(['scope_type'], form)

	const { catOptions, tagOptions } = useTermOptions()
	const { selectProps: courseSelectProps } = useCourseSelectOptions()

	return (
		<>
			<Item
				label={__('Scope type', 'power-course')}
				name={['scope_type']}
				initialValue="all"
				rules={[
					{
						required: true,
						message: __('Please select scope type', 'power-course'),
					},
				]}
			>
				<Radio.Group
					block
					optionType="button"
					buttonStyle="solid"
					options={[
						{ label: __('All courses', 'power-course'), value: 'all' },
						{
							label: __('Category / Tag', 'power-course'),
							value: 'category',
						},
						{
							label: __('Specific courses', 'power-course'),
							value: 'specific',
						},
					]}
				/>
			</Item>

			{('all' === scopeType || 'category' === scopeType) && (
				<Alert
					className="mb-4"
					type="info"
					showIcon
					message={__(
						'This scope is dynamic and includes courses added in the future.',
						'power-course'
					)}
				/>
			)}

			{'category' === scopeType && (
				<Item
					label={__('Product category / Product tag', 'power-course')}
					name={['term_ids']}
					rules={[
						{
							required: true,
							message: __(
								'Please select at least one category or tag',
								'power-course'
							),
						},
					]}
				>
					<Select
						{...defaultSelectProps}
						mode="multiple"
						placeholder={__('Select category or tag', 'power-course')}
						options={[
							{
								label: __('Product category', 'power-course'),
								options: catOptions,
							},
							{
								label: __('Product tag', 'power-course'),
								options: tagOptions,
							},
						]}
					/>
				</Item>
			)}

			{'specific' === scopeType && (
				<Item
					label={__('Specific courses', 'power-course')}
					name={['course_ids']}
					tooltip={__(
						'A fixed course list. Courses added later are not included.',
						'power-course'
					)}
					rules={[
						{
							required: true,
							message: __('Please select at least one course', 'power-course'),
						},
					]}
				>
					<Select {...courseSelectProps} mode="multiple" />
				</Item>
			)}
		</>
	)
}

export const ScopeFields = memo(ScopeFieldsComponent)
