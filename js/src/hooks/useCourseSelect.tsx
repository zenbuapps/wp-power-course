import { useSelect } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import { SelectProps } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { useState } from 'react'

import { TCourseRecord } from '@/pages/admin/Courses/List/types'

type TUseCourseSelectParams = {
	selectProps?: SelectProps
}

export const useCourseSelect = (params?: TUseCourseSelectParams) => {
	const selectProps = params?.selectProps
	const [courseIds, setCourseIds] = useState<string[]>([])

	const { selectProps: refineSelectProps, query } = useSelect<TCourseRecord>({
		resource: 'courses',
		dataProviderName: 'power-course',
		debounce: 500,
		pagination: {
			pageSize: 20,
			mode: 'server',
		},
		onSearch: (value) => [
			{
				field: 's',
				operator: 'contains',
				value,
			},
		],
	})

	const courses = query?.data?.data ?? []

	const options = courses?.map((course) => ({
		label: course.name,
		value: course.id,
	}))

	const mergedSelectProps: SelectProps = {
		...defaultSelectProps,
		value: courseIds,
		onChange: (value: string[]) => {
			setCourseIds(value)
		},
		placeholder: __('Search course keyword', 'power-course'),
		...selectProps,
		...refineSelectProps,
		options,
	}

	return {
		selectProps: mergedSelectProps,
		courseIds,
		setCourseIds,
	}
}
