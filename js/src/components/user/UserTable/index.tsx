import { useTable, useModal } from '@refinedev/antd'
import {
	HttpError,
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
	useParsed,
} from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import {
	Table,
	TableProps,
	FormInstance,
	Button,
	Modal,
	CardProps,
	message,
	DatePicker,
} from 'antd'
import { useRowSelection, Card } from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import dayjs, { type Dayjs } from 'dayjs'
import { useAtom } from 'jotai'
import React, { memo, useEffect, useState } from 'react'

import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import {
	GrantCourseAccess,
	RemoveCourseAccess,
	ModifyCourseExpireDate,
} from '@/components/user'
import { TUserRecord, TAVLCourse } from '@/components/user/types'
import { useGCDItems, useEnv } from '@/hooks'

import { selectedUserIdsAtom } from './atom'
import CsvUpload from './CsvUpload'
import Filter, { TFilterValues } from './Filter'
import HistoryDrawer from './HistoryDrawer'
import useColumns from './hooks/useColumns'
import SelectedUser from './SelectedUser'
import { keyLabelMapper } from './utils'

export type UserTableMode = 'global' | 'course-exclude'

const UserTableComponent = ({
	mode = 'global',
	canGrantCourseAccess = false,
	tableProps: overrideTableProps,
	cardProps,
}: {
	mode?: UserTableMode
	canGrantCourseAccess?: boolean
	tableProps?: TableProps<TUserRecord>
	cardProps?: CardProps & { showCard?: boolean }
}) => {
	const [selectedUserIds, setSelectedUserIds] = useAtom(selectedUserIdsAtom)
	const { id: courseId } = useParsed()

	const onSearchHandler = (values: TFilterValues) =>
		Object.keys(values).map((key) => ({
			field: key,
			operator: 'contains' as const,
			value: values[key as keyof TFilterValues],
		}))

	const useTableConfig =
		mode === 'course-exclude'
			? {
					resource: 'students',
					dataProviderName: 'power-course',
					pagination: { pageSize: 20 },
					filters: {
						permanent: [
							{
								field: 'meta_key',
								operator: 'eq' as const,
								value: 'avl_course_ids',
							},
							{
								field: 'meta_value',
								operator: 'ne' as const,
								value: courseId,
							},
							{
								field: 'meta_keys',
								operator: 'eq' as const,
								value: ['formatted_name'],
							},
						],
					},
					queryOptions: { enabled: !!courseId },
					onSearch: onSearchHandler,
				}
			: {
					resource: 'users',
					pagination: { pageSize: 20 },
					filters: {
						permanent: [
							{
								field: 'meta_keys',
								operator: 'eq' as const,
								value: ['is_teacher', 'avl_courses'],
							},
						],
					},
					onSearch: onSearchHandler,
				}

	const { searchFormProps, tableProps, filters } = useTable<
		TUserRecord,
		HttpError,
		TFilterValues
	>(useTableConfig)

	const currentAllKeys =
		tableProps?.dataSource?.map((record) => record?.id.toString()) || []

	// 多選
	const { rowSelection, setSelectedRowKeys, selectedRowKeys } =
		useRowSelection<TUserRecord>({
			onChange: (currentSelectedRowKeys: React.Key[]) => {
				setSelectedRowKeys(currentSelectedRowKeys)

				/**
				 * 不在這頁的已選擇用戶
				 * @type string[]
				 */
				const setSelectedUserIdsNotInCurrentPage = selectedUserIds.filter(
					(selectedUserId) => !currentAllKeys.includes(selectedUserId)
				)

				/**
				 * 在這頁的已選擇用戶
				 * @type string[]
				 */
				const currentSelectedRowKeysStringify = currentSelectedRowKeys.map(
					(key) => key.toString()
				)

				setSelectedUserIds(() => {
					// 把這頁的已選用戶加上 不在這頁的已選用戶
					const newKeys = new Set([
						...setSelectedUserIdsNotInCurrentPage,
						...currentSelectedRowKeysStringify,
					])
					return [...newKeys]
				})
			},
		})

	/*
	 * 換頁時，將已加入的商品全局狀態同步到當前頁面的 selectedRowKeys 狀態
	 */
	useEffect(() => {
		if (!tableProps?.loading) {
			const filteredKey =
				currentAllKeys?.filter((id) => selectedUserIds?.includes(id)) || []
			setSelectedRowKeys(filteredKey)
		}
	}, [
		JSON.stringify(filters),
		JSON.stringify(tableProps?.pagination),
		tableProps?.loading,
	])

	useEffect(() => {
		// 如果清空已選擇的用戶，連帶清空 selectedRowKeys (畫面上的打勾)
		if (selectedUserIds.length === 0) {
			setSelectedRowKeys([])
		}
	}, [selectedUserIds.length])

	useEffect(() => {
		// 剛載入組件時，清空已選擇的用戶
		setSelectedUserIds([])
	}, [])

	const columns = useColumns()

	const selectedAllAVLCourses = selectedRowKeys
		.map((key) => {
			return tableProps?.dataSource?.find((user) => user.id === key)
				?.avl_courses
		})
		.filter((courses) => courses !== undefined)

	// 取得最大公約數的課程
	const { GcdItemsTags, selectedGCDs, setSelectedGCDs, gcdItems } =
		useGCDItems<TAVLCourse>({
			allItems: selectedAllAVLCourses,
		})

	// CSV 上傳 Modal
	const { show, modalProps } = useModal()

	// 學員匯出 CSV
	const apiUrl = useApiUrl('power-course')
	const { NONCE } = useEnv()

	// 加入學員到本課程（course-exclude 模式）
	const invalidate = useInvalidate()
	const { mutate: addStudents, isLoading: isAdding } = useCustomMutation()
	const [expireDate, setExpireDate] = useState<Dayjs | undefined>(undefined)

	const handleAddStudents = () => {
		if (!courseId || selectedRowKeys.length === 0) return
		addStudents(
			{
				url: `${apiUrl}/courses/add-students`,
				method: 'post',
				values: {
					user_ids: selectedRowKeys as string[],
					course_ids: [courseId],
					expire_date: expireDate ? expireDate.unix() : 0,
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
					setSelectedUserIds([])
					setExpireDate(undefined)
				},
				onError: () => {
					message.error({
						content: __('Failed to add students to course', 'power-course'),
						key: 'add-students',
					})
				},
			}
		)
	}

	/** 匯出篩選參數狀態，非 null 時觸發 useCustom 查詢 */
	const [exportQueryParams, setExportQueryParams] = useState<Record<
		string,
		string | string[]
	> | null>(null)

	/**
	 * 從篩選表單取得當前篩選值，建構 URL query string（用於 CSV 下載）
	 */
	const getExportUrlParams = (): string => {
		const form = searchFormProps?.form as FormInstance<TFilterValues>
		const values = form?.getFieldsValue()
		const params = new URLSearchParams()
		params.append('_wpnonce', NONCE)
		if (values?.search) params.append('search', values.search)
		if (values?.avl_course_ids?.length) {
			values.avl_course_ids.forEach((id) =>
				params.append('avl_course_ids[]', id)
			)
		}
		if (values?.include?.length) {
			values.include.forEach((id) => params.append('include[]', id))
		}
		return params.toString()
	}

	/** 透過 useCustom 取得匯出預估筆數 */
	const { isFetching: exportLoading } = useCustom<{ count: number }>({
		url: `${apiUrl}/students/export-count`,
		method: 'get',
		config: {
			query: exportQueryParams ?? {},
		},
		queryOptions: {
			enabled: exportQueryParams !== null,
			staleTime: 0,
			cacheTime: 0,
			onSuccess: (responseData) => {
				const count = responseData?.data?.count ?? 0
				const urlParams = getExportUrlParams()
				Modal.confirm({
					title: __('Export students as CSV', 'power-course'),
					content:
						count > 0
							? sprintf(
									// translators: %s: 預估筆數
									__(
										'Estimated %s records to export. Confirm to export?',
										'power-course'
									),
									count
								)
							: __(
									'No student data under current filter conditions',
									'power-course'
								),
					okText: __('Confirm export', 'power-course'),
					cancelText: __('Cancel', 'power-course'),
					onOk:
						count > 0
							? () => {
									window.open(
										`${apiUrl}/students/export-all?${urlParams}`,
										'_blank'
									)
								}
							: undefined,
					okButtonProps: { disabled: count === 0 },
				})
				setExportQueryParams(null)
			},
			onError: () => {
				message.error(__('Failed to get export count', 'power-course'))
				setExportQueryParams(null)
			},
		},
	})

	/**
	 * 點擊匯出按鈕：快照當前篩選值並觸發 useCustom 查詢
	 */
	const handleExportClick = () => {
		const form = searchFormProps?.form as FormInstance<TFilterValues>
		const values = form?.getFieldsValue()
		const query: Record<string, string | string[]> = {}
		if (values?.search) query.search = values.search
		if (values?.avl_course_ids?.length)
			query.avl_course_ids = values.avl_course_ids
		if (values?.include?.length) query.include = values.include
		setExportQueryParams(query)
	}

	const showAdminFeatures = mode === 'global' && canGrantCourseAccess

	return (
		<>
			<Card
				title={__('Filter', 'power-course')}
				variant="borderless"
				className="mb-4"
				{...cardProps}
			>
				<Filter formProps={searchFormProps} />
				<FilterTags
					form={searchFormProps.form as FormInstance}
					keyLabelMapper={keyLabelMapper}
				/>
			</Card>
			<Card variant="borderless" {...cardProps}>
				{showAdminFeatures && (
					<>
						<div className="mt-4">
							<GrantCourseAccess
								user_ids={selectedRowKeys as string[]}
								label={__('Add other courses', 'power-course')}
							/>
						</div>

						<div className="mt-4 flex gap-x-6 justify-between">
							<div>
								{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
								<label className="tw-block mb-2">
									{__('Batch operation', 'power-course')}
								</label>
								<div className="flex gap-x-4">
									<ModifyCourseExpireDate
										user_ids={selectedRowKeys as string[]}
										course_ids={selectedGCDs}
										onSettled={() => {
											setSelectedGCDs([])
										}}
									/>
									<RemoveCourseAccess
										user_ids={selectedRowKeys}
										course_ids={selectedGCDs}
										onSettled={() => {
											setSelectedGCDs([])
										}}
									/>
								</div>
							</div>
							{!!gcdItems.length && (
								<div className="flex-1">
									{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
									<label className="tw-block mb-2">
										{__('Select course', 'power-course')}
									</label>
									<GcdItemsTags />
								</div>
							)}
							<div className="flex gap-x-4 self-end">
								<Button
									onClick={handleExportClick}
									color="primary"
									variant="outlined"
									loading={exportLoading}
								>
									{__('Export students as CSV', 'power-course')}
								</Button>
								<Button onClick={show} color="primary" variant="outlined">
									{__('Batch upload student access via CSV', 'power-course')}
								</Button>
							</div>
						</div>
					</>
				)}

				<SelectedUser
					user_ids={selectedUserIds}
					onClear={() => {
						setSelectedUserIds([])
					}}
					onSelected={() => {
						const searchForm = searchFormProps?.form
						if (!searchForm) return
						searchForm.setFieldValue(['include'], selectedUserIds)
						searchForm.submit()
					}}
				/>

				{mode === 'course-exclude' && (
					<div className="mt-4 flex gap-x-4 items-end">
						<Button
							type="primary"
							onClick={handleAddStudents}
							loading={isAdding}
							disabled={selectedRowKeys.length === 0 || isAdding || !courseId}
						>
							{selectedRowKeys.length > 0
								? sprintf(
										// translators: %s: 勾選人數
										__('Add %s students to this course', 'power-course'),
										selectedRowKeys.length
									)
								: __('Add students to this course', 'power-course')}
						</Button>
						<div className="flex flex-col">
							{/* eslint-disable-next-line jsx-a11y/label-has-associated-control */}
							<label className="text-xs text-gray-500 mb-1">
								{__('Expire date (applied to all selected)', 'power-course')}
							</label>
							<DatePicker
								value={expireDate}
								onChange={(value) => setExpireDate(value ?? undefined)}
								placeholder={__(
									'Leave empty for permanent access',
									'power-course'
								)}
								showTime
								format="YYYY-MM-DD HH:mm"
								disabledDate={(current) =>
									!!current && current < dayjs().startOf('day')
								}
								disabled={isAdding}
							/>
						</div>
					</div>
				)}

				<Table
					{...(defaultTableProps as unknown as TableProps<TUserRecord>)}
					{...tableProps}
					className="mt-4"
					columns={columns}
					rowSelection={rowSelection}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({
							label: __('user', 'power-course'),
						}),
					}}
					{...overrideTableProps}
				/>
			</Card>
			{showAdminFeatures && (
				<Modal
					{...modalProps}
					centered
					title={__('Batch upload student access via CSV', 'power-course')}
					footer={null}
					width={800}
				>
					<CsvUpload />
				</Modal>
			)}

			{mode === 'global' && <HistoryDrawer />}
		</>
	)
}

export const UserTable = memo(UserTableComponent)
export * from './atom'
export { default as SelectedUser } from './SelectedUser'
