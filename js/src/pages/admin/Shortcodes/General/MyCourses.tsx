import { useCustom, useApiUrl } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Typography, Form, Checkbox, Spin } from 'antd'
import { renderHTML } from 'antd-toolkit'
import { memo, useState } from 'react'

import { Heading } from '@/components/general'

const { Item } = Form
const { Text } = Typography

const MyCourses = () => {
	const [shortcode, setShortcode] = useState('[pc_my_courses]')

	const [form] = Form.useForm()

	const handleValuesChange = () => {
		const { preview, ...values } = form.getFieldsValue()
		const valuesToShortCodeString = Object.keys(values).reduce((acc, key) => {
			const value = values[key]

			if (!value) {
				// 如果 undefined 就不加上
				return acc
			}

			acc += ` ${key}="${value}"`
			return acc
		}, '')
		setShortcode(`[pc_my_courses${valuesToShortCodeString}]`)
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
			<Heading className="mt-8">{__('My courses', 'power-course')}</Heading>
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

export default memo(MyCourses)
