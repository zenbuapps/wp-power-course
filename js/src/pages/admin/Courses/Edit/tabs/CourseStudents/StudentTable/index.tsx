import { useTable } from '@refinedev/antd'
import {
	useCustomMutation,
	useApiUrl,
	useInvalidate,
	useParsed,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import {
	Table,
	message,
	DatePicker,
	Space,
	Button,
	TableProps,
	FormInstance,
} from 'antd'
import { useRowSelection } from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import { Dayjs } from 'dayjs'
import React, { useState, memo } from 'react'

import { PopconfirmDelete } from '@/components/general'
import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { TUserRecord } from '@/components/user/types'
import HistoryDrawer from '@/components/user/UserTable/HistoryDrawer'
import useColumns from '@/components/user/UserTable/hooks/useColumns'
import { useEnv } from '@/hooks'

import AddOtherCourse from '../AddOtherCourse'

import Filter, { TStudentFilterValues } from './Filter'
import { onSearch, keyLabelMapper } from './utils'

const StudentTable = () => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const { id: courseId } = useParsed()
	const columns = useColumns()
	const { tableProps, searchFormProps } = useTable<TUserRecord>({
		resource: 'students',
		dataProviderName: 'power-course',
		onSearch,
		filters: {
			permanent: [
				{
					field: 'meta_key',
					operator: 'eq',
					value: 'avl_course_ids',
				},
				{
					field: 'meta_value',
					operator: 'eq',
					value: courseId,
				},
				{
					field: 'meta_keys',
					operator: 'eq',
					value: ['is_teacher', 'avl_courses'],
				},
			],
		},
		pagination: {
			pageSize: 20,
		},
		queryOptions: {
			enabled: !!courseId,
		},
	})

	// 多選
	const { rowSelection, setSelectedRowKeys, selectedRowKeys } =
		useRowSelection<TUserRecord>({
			onChange: (currentSelectedRowKeys: React.Key[]) => {
				setSelectedRowKeys(currentSelectedRowKeys)
			},
		})

	// remove student mutation
	const { mutate, isLoading } = useCustomMutation()

	const handleRemove = () => {
		mutate(
			{
				url: `${apiUrl}/courses/remove-students`,
				method: 'post',
				values: {
					user_ids: selectedRowKeys,
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
						content: __('Students removed successfully', 'power-course'),
						key: 'remove-students',
					})
					invalidate({
						resource: 'students',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					setSelectedRowKeys([])
				},
				onError: () => {
					message.error({
						content: __('Failed to remove students', 'power-course'),
						key: 'remove-students',
					})
				},
			}
		)
	}

	// update student mutation
	const [time, setTime] = useState<Dayjs | undefined>(undefined)

	const handleUpdate = () => {
		mutate(
			{
				url: `${apiUrl}/courses/update-students`,
				method: 'post',
				values: {
					user_ids: selectedRowKeys,
					course_ids: [courseId],
					timestamp: time ? time?.unix() : 0,
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
						content: __(
							'Watch time limit updated successfully',
							'power-course'
						),
						key: 'update-students',
					})
					invalidate({
						resource: 'students',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					setSelectedRowKeys([])
					setTime(undefined)
				},
				onError: () => {
					message.error({
						content: __('Failed to update watch time limit', 'power-course'),
						key: 'update-students',
					})
				},
			}
		)
	}

	const { NONCE } = useEnv()

	/**
	 * 匯出 CSV — 帶入當前 Filter 條件（search / progress_operator / progress_value）
	 * Issue #227 Q5=A：匯出套用 Filter
	 */
	const handleExport = () => {
		const form = searchFormProps?.form as FormInstance<TStudentFilterValues>
		const values = (form?.getFieldsValue() || {}) as TStudentFilterValues

		const params = new URLSearchParams({ _wpnonce: NONCE })
		if (values?.search) {
			params.append('search', values.search)
		}
		const hasOp = !!values?.progress_operator
		const hasValue =
			values?.progress_value !== undefined &&
			values?.progress_value !== null &&
			(values?.progress_value as unknown as string) !== ''
		if (hasOp && hasValue) {
			params.append('progress_operator', values.progress_operator as string)
			params.append('progress_value', String(values.progress_value))
		}

		window.open(
			`${apiUrl}/students/export/${courseId}?${params.toString()}`,
			'_blank'
		)
	}

	return (
		<>
			<div className="mb-4">
				<Filter formProps={searchFormProps} />
				<FilterTags
					form={searchFormProps?.form as FormInstance}
					keyLabelMapper={keyLabelMapper}
				/>
			</div>

			<div className="mb-4 flex justify-between gap-4">
				<Space.Compact>
					<DatePicker
						value={time}
						placeholder={__('Leave empty for unlimited', 'power-course')}
						showTime
						format="YYYY-MM-DD HH:mm"
						onChange={(value: Dayjs) => {
							setTime(value)
						}}
					/>
					<Button
						type="primary"
						disabled={!selectedRowKeys.length}
						onClick={handleUpdate}
					>
						{__('Update watch time limit', 'power-course')}
					</Button>
				</Space.Compact>
				<div>
					<Button
						color="primary"
						variant="filled"
						className="mr-2"
						onClick={handleExport}
					>
						{__('Export students CSV', 'power-course')}
					</Button>
					<PopconfirmDelete
						type="button"
						popconfirmProps={{
							title: __(
								'Are you sure you want to remove these students?',
								'power-course'
							),
							onConfirm: handleRemove,
						}}
						buttonProps={{
							children: __('Remove student access', 'power-course'),
							disabled: !selectedRowKeys.length,
							loading: isLoading,
						}}
					/>
				</div>
			</div>

			<AddOtherCourse user_ids={selectedRowKeys as string[]} />

			<Table
				{...(defaultTableProps as unknown as TableProps<TUserRecord>)}
				{...tableProps}
				columns={columns}
				rowSelection={rowSelection}
				expandable={undefined}
				pagination={{
					...tableProps.pagination,
					...getDefaultPaginationProps({
						label: __('Student', 'power-course'),
					}),
				}}
			/>
			<HistoryDrawer />
		</>
	)
}

export default memo(StudentTable)
