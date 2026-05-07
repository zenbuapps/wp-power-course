import { useTable } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Table, TableProps, FormInstance } from 'antd'
import { useRowSelection, Card } from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import { useAtom } from 'jotai'
import React, { memo, useEffect } from 'react'

import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'

import { TTeacherRecord } from '../types'

import { AddTeacherArea } from './AddTeacherArea'
import { selectedTeacherIdsAtom } from './atom'
import { BulkAction } from './BulkAction'
import Filter, { TFilterValues } from './Filter'
import useColumns from './hooks/useColumns'
import { keyLabelMapper } from './utils'

/**
 * 講師列表
 *
 * 核心結構（對齊 Power Shop components/user/UserTable）：
 * - useTable 固定 permanent filter is_teacher=yes + computed field meta_keys
 * - Filter（6 欄）+ FilterTags + AddTeacherArea（Create + Add from WP user）
 * - BulkAction（ResetPass + RemoveRole）在有選取時才顯示
 * - 多選透過 selectedTeacherIdsAtom 跨頁保留
 */
const TeacherTableComponent = () => {
	const [selectedTeacherIds, setSelectedTeacherIds] = useAtom(
		selectedTeacherIdsAtom
	)

	const { searchFormProps, tableProps, filters } = useTable<
		TTeacherRecord,
		HttpError,
		TFilterValues
	>({
		resource: 'users',
		pagination: {
			pageSize: 20,
		},
		filters: {
			permanent: [
				{
					field: 'meta_keys',
					operator: 'eq',
					value: [
						'is_teacher',
						'formatted_name',
						'billing_phone',
						'teacher_courses_count',
						'teacher_students_count',
					],
				},
			],
			initial: [
				{
					field: 'is_teacher',
					operator: 'eq',
					value: 'yes',
				},
				{
					field: 'search',
					operator: 'contains',
					value: '',
				},
			],
			defaultBehavior: 'replace',
		},
		onSearch: (values) => {
			const isTeacher = values.is_teacher || undefined
			return [
				{
					field: 'search',
					operator: 'contains' as const,
					value: values.search,
				},
				{ field: 'is_teacher', operator: 'eq' as const, value: isTeacher },
				{ field: 'role__in', operator: 'eq' as const, value: values.role__in },
				{
					field: 'teacher_course_id',
					operator: 'eq' as const,
					value: values.teacher_course_id,
				},
				{ field: 'include', operator: 'eq' as const, value: values.include },
			]
		},
	})

	const currentAllKeys =
		tableProps?.dataSource?.map((record) => record?.id?.toString()) || []

	// 多選（跨換頁保留，對齊 Power Shop UserTable 的 pattern）
	const { rowSelection, setSelectedRowKeys } = useRowSelection<TTeacherRecord>({
		onChange: (currentSelectedRowKeys: React.Key[]) => {
			setSelectedRowKeys(currentSelectedRowKeys)

			// 不在這頁的已選用戶
			const notInCurrentPage = selectedTeacherIds.filter(
				(id) => !currentAllKeys.includes(id)
			)

			const currentStringified = currentSelectedRowKeys.map((k) => k.toString())

			setSelectedTeacherIds(() => {
				const newKeys = new Set([...notInCurrentPage, ...currentStringified])
				return [...newKeys]
			})
		},
	})

	// 換頁 / filter 變更時，同步 selectedRowKeys 與全域 atom 的交集
	const filtersKey = JSON.stringify(filters)
	const paginationKey = JSON.stringify(tableProps?.pagination)
	useEffect(() => {
		if (!tableProps?.loading) {
			const filteredKey =
				currentAllKeys?.filter((id) => selectedTeacherIds?.includes(id)) || []
			setSelectedRowKeys(filteredKey)
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [filtersKey, paginationKey, tableProps?.loading])

	// 全清空已選用戶時，同步清空當頁 rowSelection
	useEffect(() => {
		if (selectedTeacherIds.length === 0) {
			setSelectedRowKeys([])
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [selectedTeacherIds.length])

	// 初次載入清空殘留
	useEffect(() => {
		setSelectedTeacherIds([])
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [])

	const columns = useColumns()

	return (
		<>
			<Card
				title={__('Filter', 'power-course')}
				variant="borderless"
				className="mb-4"
			>
				<Filter formProps={searchFormProps} />
				<FilterTags
					form={searchFormProps.form as FormInstance}
					keyLabelMapper={keyLabelMapper}
				/>
			</Card>

			<Card variant="borderless">
				<AddTeacherArea />

				{!!selectedTeacherIds.length && (
					<div className="mb-4 flex items-center justify-between gap-4 flex-wrap">
						<div className="text-sm text-gray-500">
							{__('Batch operation', 'power-course')}
						</div>
						<BulkAction />
					</div>
				)}

				<Table
					{...(defaultTableProps as unknown as TableProps<TTeacherRecord>)}
					{...tableProps}
					className="mt-4"
					columns={columns}
					rowSelection={rowSelection}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({
							label: __('Instructors', 'power-course'),
						}),
					}}
				/>
			</Card>
		</>
	)
}

export const TeacherTable = memo(TeacherTableComponent)
export * from './atom'
