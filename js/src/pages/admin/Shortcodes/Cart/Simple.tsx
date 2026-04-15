import { useCustom, useApiUrl } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Typography, Form, Select, Checkbox, Spin, Alert } from 'antd'
import { renderHTML } from 'antd-toolkit'
import { memo, useState } from 'react'

import { Heading } from '@/components/general'
import { useProductSelect } from '@/hooks'

const { Item } = Form
const { Text } = Typography

const Simple = () => {
	const [shortcode, setShortcode] = useState('[pc_simple_card]')
	const { selectProps } = useProductSelect({
		selectProps: {
			mode: undefined,
		},
		useSelectProps: {
			filters: [
				{
					field: 'type',
					operator: 'in',
					value: ['simple', 'subscription'],
				},
			],
		},
	})

	const [form] = Form.useForm()

	const handleValuesChange = () => {
		const { product_id } = form.getFieldsValue()
		setShortcode(
			`[pc_simple_card${product_id ? ` product_id="${product_id}"` : ''}]`
		)
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
			<Heading className="mt-8">{__('Simple product', 'power-course')}</Heading>
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
							name={['product_id']}
							label={__('Select product', 'power-course')}
							initialValue={false}
							tooltip={__(
								'Besides course products, this shortcode can also display simple products and simple subscription products.',
								'power-course'
							)}
						>
							<Select {...selectProps} />
						</Item>
					</Form>
					<Text key="copy" code className="m-0 text-base tw-block" copyable>
						{shortcode}
					</Text>
				</div>
				<Spin spinning={isFetching}>
					<Alert
						message={__(
							'Card width adapts to its container. You need to set the container width yourself, around 300~400px recommended.',
							'power-course'
						)}
						type="info"
						className="mb-4"
						showIcon
					/>
					{/* 預覽 */}
					<div className="w-[20rem]">{renderHTML(html)}</div>
				</Spin>
			</div>
		</>
	)
}

export default memo(Simple)
