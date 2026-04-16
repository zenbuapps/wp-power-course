// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck

import { useSelect } from '@refinedev/antd'
import {
	useCustomMutation,
	useApiUrl,
	useInvalidate,
	useParsed,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Select, Space, Button, Form, message } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import { useState, memo } from 'react'

import { TUserRecord } from '@/pages/admin/Courses/List/types'

const UserSelector = () => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const [userIds, setUserIds] = useState<string[]>([])
	const [keyword, setKeyword] = useState<string>('')
	const [searchField, setSearchField] = useState<string>('all')

	const form = Form.useFormInstance()
	const { id: courseId } = useParsed()

	const { selectProps, queryResult } = useSelect<TUserRecord>({
		resource: 'students',
		dataProviderName: 'power-course',
		optionLabel: 'formatted_name',
		optionValue: 'id',
		filters: [
			{
				field: 'search',
				operator: 'eq',
				value: '',
			},
			{
				field: 'search_field',
				operator: 'eq',
				value: searchField,
			},
			{
				field: 'posts_per_page',
				operator: 'eq',
				value: '30',
			},
			{
				field: 'meta_key',
				operator: 'eq',
				value: 'avl_course_ids',
			},
			{
				field: 'meta_value',
				operator: 'ne',
				value: courseId,
			},
		],
		onSearch: (value) => {
			setKeyword(value)
			return [
				{
					field: 'search',
					operator: 'eq',
					value,
				},
			]
		},
		queryOptions: {
			enabled: !!courseId,
		},
	})

	// add student mutation
	const { mutate: addStudent, isLoading } = useCustomMutation()

	const handleAdd = () => {
		addStudent(
			{
				url: `${apiUrl}/courses/add-students`,
				method: 'post',
				values: {
					user_ids: userIds,
					course_ids: [courseId],
				},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Students added successfully', 'power-course'),
						key: 'add-students',
					})
					invalidate({
						resource: 'students',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					setUserIds([])
				},
				onError: () => {
					message.error({
						content: __('Failed to add students', 'power-course'),
						key: 'add-students',
					})
				},
			}
		)
	}

	return (
		<Space.Compact className="w-full">
			<Button
				type="primary"
				onClick={handleAdd}
				loading={isLoading}
				disabled={!userIds.length || queryResult.isFetching}
			>
				{__('Add student', 'power-course')}
			</Button>
			<Select
				{...defaultSelectProps}
				{...selectProps}
				placeholder={__('Search by email, name, or ID', 'power-course')}
				onChange={(value: string[]) => {
					setUserIds(value)
				}}
				value={userIds}
				loading={queryResult.isFetching}
			/>
			<Select
				value={searchField}
				style={{ width: 120 }}
				onChange={(value: string) => {
					setSearchField(value)
				}}
				options={[
					{ value: 'all', label: __('All fields', 'power-course') },
					{ value: 'email', label: 'Email' },
					{ value: 'name', label: __('Name', 'power-course') },
					{ value: 'id', label: 'ID' },
				]}
				disabled={isLoading || queryResult.isFetching}
			/>
		</Space.Compact>
	)
}

export default memo(UserSelector)
