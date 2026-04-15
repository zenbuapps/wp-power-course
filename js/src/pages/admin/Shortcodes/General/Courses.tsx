import { useCustom, useApiUrl } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import {
	Typography,
	Form,
	InputNumber,
	Slider,
	SliderSingleProps,
	Select,
	Checkbox,
	Spin,
} from 'antd'
import { renderHTML, defaultSelectProps } from 'antd-toolkit'
import { memo, useState } from 'react'

import { Heading } from '@/components/general'
import useOptions from '@/components/product/ProductTable/hooks/useOptions'
import {
	keyLabelMapper,
	termToOptions,
} from '@/components/product/ProductTable/utils'
import { useCourseSelect } from '@/hooks'

const { Item } = Form
const { Text } = Typography

const marks: SliderSingleProps['marks'] = {
	1: '1',
	2: '2',
	3: '3',
	4: '4',
}

const Courses = () => {
	const [shortcode, setShortcode] = useState('[pc_courses]')
	const { selectProps } = useCourseSelect()
	const { options } = useOptions({
		endpoint: 'courses/options',
	})
	const { product_cats = [], product_tags = [] } = options
	const [form] = Form.useForm()

	const handleValuesChange = () => {
		const { preview, ...values } = form.getFieldsValue()
		const valuesToShortCodeString = Object.keys(values).reduce((acc, key) => {
			const value = values[key]

			if (!value) {
				// 如果 undefined 就不加上
				return acc
			}

			if (Array.isArray(value) && value?.length === 0) {
				// 如果是 [] 也不加上
				return acc
			}

			if ('columns' === key && value === 3) {
				// 如果 columns 是 3 就不加上
				return acc
			}

			if ('limit' === key && value === 12) {
				// 如果 limit 是 12 就不加上
				return acc
			}

			acc += ` ${key}="${value}"`
			return acc
		}, '')
		setShortcode(`[pc_courses${valuesToShortCodeString}]`)
	}

	const watchPreview = Form.useWatch(['preview'], form) || false
	const apiUrl = useApiUrl('power-course')
	const { data, isFetching } = useCustom({
		url: `${apiUrl}/shortcode`,
		method: 'get',
		config: {
			query: {
				shortcode,
			},
		},
		queryOptions: {
			enabled: watchPreview,
		},
	})

	const html = data?.data?.data || ''

	return (
		<>
			<Heading className="mt-8">{__('Course list', 'power-course')}</Heading>
			<div className="grid grid-cols-1 md:grid-cols-[25rem_1fr] gap-8">
				<div>
					<Form
						form={form}
						labelCol={{ span: 8 }}
						wrapperCol={{ span: 14 }}
						layout="horizontal"
						onValuesChange={handleValuesChange}
					>
						<Item
							name={['preview']}
							label={__('Live preview', 'power-course')}
							initialValue={false}
							valuePropName="checked"
						>
							<Checkbox />
						</Item>
						<Item
							name={['limit']}
							label={__('Display count', 'power-course')}
							tooltip={__('Default 12', 'power-course')}
							initialValue={12}
						>
							<InputNumber className="w-full" min={0} max={100} />
						</Item>
						<Item
							name={['columns']}
							label={__('Columns', 'power-course')}
							tooltip={__('Default 3', 'power-course')}
							initialValue={3}
						>
							<Slider marks={marks} min={1} max={4} />
						</Item>
						<Item
							name={['include']}
							label={__('Only include specific courses', 'power-course')}
						>
							<Select {...selectProps} />
						</Item>
						<Item
							name={['exclude']}
							label={__('Exclude specific courses', 'power-course')}
						>
							<Select {...selectProps} />
						</Item>
						<Item name={['orderby']} label={__('Sort by', 'power-course')}>
							<Select
								className="w-full"
								allowClear
								options={[
									{
										value: 'ID',
										label: 'ID',
									},
									{
										value: 'name',
										label: __('Name', 'power-course'),
									},
									{
										value: 'rand',
										label: __('Random', 'power-course'),
									},
									{
										value: 'date',
										label: __('Published date', 'power-course'),
									},
									{
										value: 'modified',
										label: __('Modified date', 'power-course'),
									},
								]}
							/>
						</Item>
						<Item name={['order']} label={__('Sort order', 'power-course')}>
							<Select
								className="w-full"
								allowClear
								options={[
									{
										value: 'ASC',
										label: __('Ascending (ASC)', 'power-course'),
									},
									{
										value: 'DESC',
										label: __('Descending (DESC)', 'power-course'),
									},
								]}
							/>
						</Item>
						<Item
							name={['product_category_id']}
							label={keyLabelMapper('product_category_id')}
						>
							<Select
								{...defaultSelectProps}
								options={termToOptions(product_cats)}
								placeholder={__('Multiple selection', 'power-course')}
							/>
						</Item>

						<Item
							name={['product_tag_id']}
							label={keyLabelMapper('product_tag_id')}
						>
							<Select
								{...defaultSelectProps}
								options={termToOptions(product_tags)}
								placeholder={__('Multiple selection', 'power-course')}
							/>
						</Item>

						<Item
							name={['exclude_avl_courses']}
							label={__('Exclude granted courses', 'power-course')}
							tooltip={__(
								'Only show courses that users have not been granted access to',
								'power-course',
							)}
							valuePropName="checked"
						>
							<Checkbox />
						</Item>
					</Form>
					<Text key="copy" code className="m-0 text-base tw-block" copyable>
						{shortcode}
					</Text>
				</div>
				<Spin spinning={isFetching}>
					{/* 預覽 */}
					{renderHTML(html)}
				</Spin>
			</div>
		</>
	)
}

export default memo(Courses)
