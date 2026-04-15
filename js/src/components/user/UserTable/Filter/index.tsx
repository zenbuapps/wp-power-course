import { UndoOutlined, SearchOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { FormProps, Form, Input, Button, FormInstance, Select } from 'antd'
import React, { memo } from 'react'

import { useCourseSelect } from '@/hooks'

export type TFilterValues = {
	search?: string
	avl_course_ids?: string[]
	include?: string[]
}

const { Item } = Form

/**
 * TODO
 * 1. 已買過指定商品的用戶
 * 2. 沒開通 OO 課程權限的用戶
 * 3. 沒買過 OO 商品的用戶
 */

const Filter = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps?.form as FormInstance<TFilterValues>
	const { selectProps: courseSelectProps } = useCourseSelect()

	return (
		<div className="mb-2">
			<Form {...formProps} layout="vertical">
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-5 gap-x-4">
					<Item name="search" label={__('Keyword search', 'power-course')}>
						<Input
							placeholder={__(
								'Enter user ID, username, email or display name',
								'power-course'
							)}
							allowClear
						/>
					</Item>
					<Item
						name="avl_course_ids"
						label={__('Granted specific courses', 'power-course')}
						className="col-span-2"
					>
						<Select {...courseSelectProps} />
					</Item>

					<Item
						name="include"
						label={__('Include specific users', 'power-course')}
						className="col-span-2"
					>
						<Select
							mode="tags"
							placeholder={__('Enter user ID', 'power-course')}
							allowClear
						/>
					</Item>
					{/* <Item name="bought_product_ids" label="已買過指定商品的用戶">
						<Input
							placeholder="可以輸入用戶ID, 帳號, Email, 顯示名稱"
							allowClear
						/>
					</Item> */}
				</div>
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-4 gap-x-4">
					<Button
						htmlType="submit"
						type="primary"
						className="w-full"
						icon={<SearchOutlined />}
					>
						{__('Filter', 'power-course')}
					</Button>
					<Button
						type="default"
						className="w-full"
						onClick={() => {
							form.resetFields()
							form.submit()
						}}
						icon={<UndoOutlined />}
					>
						{__('Reset', 'power-course')}
					</Button>
				</div>
			</Form>
		</div>
	)
}

export default memo(Filter)
