import { UndoOutlined, SearchOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { FormProps, Form, Input, Button, FormInstance, Select } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { memo } from 'react'

import { useCourseSelect } from '@/hooks'

import { useOptions } from '../hooks/useOptions'

export type TFilterValues = {
	search?: string
	role__in?: string[]
	billing_phone?: string
	user_birthday?: string
	teacher_course_id?: string
	include?: string[]
}

const { Item } = Form

/**
 * 月份下拉選項：01 ~ 12
 *
 * 對齊 Powerhouse ExtendQuery 的 user_birthday filter（它會把 value
 * 轉成 -{value}- LIKE 比對，例：「-03-」匹配 3 月生日）。
 */
const MONTH_OPTIONS = Array.from({ length: 12 }, (_, i) => {
	const m = String(i + 1).padStart(2, '0')
	return { value: m, label: m }
})

const Filter = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps?.form as FormInstance<TFilterValues>
	const { roles } = useOptions()
	const { selectProps: courseSelectProps } = useCourseSelect()

	return (
		<div className="mb-2">
			<Form {...formProps} layout="vertical">
				<div className="grid grid-cols-2 md:grid-cols-3 2xl:grid-cols-6 gap-x-4">
					<Item name="search" label={__('Keyword search', 'power-course')}>
						<Input
							placeholder={__(
								'Enter user ID, username, email or display name',
								'power-course'
							)}
							allowClear
						/>
					</Item>

					<Item name="role__in" label={__('Role', 'power-course')}>
						<Select
							{...defaultSelectProps}
							mode="multiple"
							options={roles}
							placeholder={__('Select role', 'power-course')}
						/>
					</Item>

					<Item name="billing_phone" label={__('Phone', 'power-course')}>
						<Input
							placeholder={__('Enter phone number', 'power-course')}
							allowClear
						/>
					</Item>

					<Item
						name="user_birthday"
						label={__('Birthday month', 'power-course')}
					>
						<Select
							{...defaultSelectProps}
							options={MONTH_OPTIONS}
							placeholder={__('Select month', 'power-course')}
							allowClear
							mode={undefined}
						/>
					</Item>

					<Item
						name="teacher_course_id"
						label={__('Taught courses', 'power-course')}
					>
						<Select
							{...courseSelectProps}
							mode={undefined}
							allowClear
							placeholder={__('Select course', 'power-course')}
						/>
					</Item>

					<Item
						name="include"
						label={__('Include specific users', 'power-course')}
						hidden
					>
						<Select
							mode="tags"
							placeholder={__('Enter user ID', 'power-course')}
							allowClear
						/>
					</Item>
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
