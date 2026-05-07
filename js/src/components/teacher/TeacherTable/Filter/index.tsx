import { UndoOutlined, SearchOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import {
	FormProps,
	Form,
	Input,
	Button,
	FormInstance,
	Select,
	Radio,
} from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { memo } from 'react'

import { useCourseSelect } from '@/hooks'

import { useOptions } from '../hooks/useOptions'

export type TFilterValues = {
	search?: string
	is_teacher?: string
	role__in?: string[]
	teacher_course_id?: string
	include?: string[]
}

const { Item } = Form

const IS_TEACHER_OPTIONS = [
	{ label: 'ALL', value: '' },
	{ label: __('Yes', 'power-course'), value: 'yes' },
	{ label: __('No', 'power-course'), value: '!yes' },
]

const Filter = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps?.form as FormInstance<TFilterValues>
	const { roles } = useOptions()
	const { selectProps: courseSelectProps } = useCourseSelect()

	return (
		<div className="mb-2">
			<Form
				{...formProps}
				layout="vertical"
				initialValues={{ is_teacher: 'yes' }}
			>
				<div className="grid grid-cols-2 xl:grid-cols-4 gap-x-4">
					<Item name="search" label={__('Keyword search', 'power-course')}>
						<Input
							placeholder={__(
								'Enter user ID, username, email or display name',
								'power-course'
							)}
							allowClear
						/>
					</Item>

					<Item name="is_teacher" label={__('Instructor', 'power-course')}>
						<Radio.Group
							optionType="button"
							buttonStyle="solid"
							className="w-avg"
							options={IS_TEACHER_OPTIONS}
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

				<div className="grid grid-cols-2 xl:grid-cols-4 gap-x-4">
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
