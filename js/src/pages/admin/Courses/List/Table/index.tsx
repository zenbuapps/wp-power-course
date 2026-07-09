import { PlusOutlined, DownOutlined } from '@ant-design/icons'
import { useTable } from '@refinedev/antd'
import { HttpError, useCreate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import {
	Table,
	FormInstance,
	Spin,
	Button,
	TableProps,
	Card,
	Dropdown,
	MenuProps,
} from 'antd'
import { useRowSelection } from 'antd-toolkit'
import { FilterTags } from 'antd-toolkit/refine'
import { memo } from 'react'

import Filter, {
	initialFilteredValues,
} from '@/components/product/ProductTable/Filter'
import { TFilterProps } from '@/components/product/ProductTable/types'
import {
	onSearch,
	keyLabelMapper,
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import useColumns from '@/pages/admin/Courses/List/hooks/useColumns'
import useValueLabelMapper from '@/pages/admin/Courses/List/hooks/useValueLabelMapper'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'
import { getInitialFilters, getIsVariation } from '@/utils'

import DeleteButton from './DeleteButton'

const Main = () => {
	const { tableProps, searchFormProps } = useTable<
		TCourseBaseRecord,
		HttpError,
		TFilterProps
	>({
		resource: 'courses',
		dataProviderName: 'power-course',
		onSearch,
		pagination: {
			pageSize: 20,
		},
		filters: {
			initial: getInitialFilters(initialFilteredValues),
		},
	})

	const { valueLabelMapper } = useValueLabelMapper()

	const { rowSelection, selectedRowKeys, setSelectedRowKeys } =
		useRowSelection<TCourseBaseRecord>({
			getCheckboxProps: (record) => {
				const isVariation = getIsVariation(record?.type)
				return {
					disabled: isVariation,
					className: isVariation ? 'tw-hidden' : '',
				}
			},
		})

	const columns = useColumns()

	const { mutate: create, isLoading: isCreating } = useCreate({
		resource: 'courses',
		dataProviderName: 'power-course',
		invalidates: ['list'],
		meta: {
			headers: { 'Content-Type': 'multipart/form-data;' },
		},
	})

	/** 建立站內課程 */
	const createInternalCourse = () => {
		create({
			values: {
				name: __('New course', 'power-course'),
				is_external: false,
				// 站內課程預設為虛擬商品（線上課程無需物流）
				virtual: 'yes',
			},
		})
	}

	/** 建立外部平台課程（product_url 先填預設值，待使用者進入編輯頁修改） */
	const createExternalCourse = () => {
		create({
			values: {
				name: __('New external platform course', 'power-course'),
				is_external: true,
				product_url: 'https://example.com',
			},
		})
	}

	/** 新增課程下拉選單（Issue #235：每個選項顯示永久可見的 description 文字） */
	const createMenuItems: MenuProps['items'] = [
		{
			key: 'internal',
			label: (
				<div className="py-1">
					<div>{__('In-site course', 'power-course')}</div>
					<div className="text-xs text-gray-400">
						{__(
							'Courses watched directly on this site, with chapter/video/student management.',
							'power-course'
						)}
					</div>
				</div>
			),
			onClick: createInternalCourse,
		},
		{
			key: 'external',
			label: (
				<div className="py-1">
					<div>{__('External platform course', 'power-course')}</div>
					<div className="text-xs text-gray-400">
						{__(
							'Redirects to courses on other platforms (e.g., Hahow, Udemy); only the sales page is shown on this site.',
							'power-course'
						)}
					</div>
				</div>
			),
			onClick: createExternalCourse,
		},
	]

	return (
		<Spin spinning={tableProps?.loading as boolean}>
			<Card title={__('Filter', 'power-course')} className="mb-4">
				<Filter
					searchFormProps={searchFormProps}
					optionParams={{
						endpoint: 'courses/options',
					}}
					isCourse={true}
				/>
				<div className="mt-2">
					<FilterTags
						form={searchFormProps?.form as FormInstance<TFilterProps>}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
						booleanKeys={[
							'featured',
							'downloadable',
							'virtual',
							'sold_individually',
						]}
					/>
				</div>
			</Card>
			<Card>
				<div className="mb-4 flex justify-between">
					<Dropdown menu={{ items: createMenuItems }} disabled={isCreating}>
						<Button loading={isCreating} type="primary" icon={<PlusOutlined />}>
							{__('Add course', 'power-course')} <DownOutlined />
						</Button>
					</Dropdown>
					<DeleteButton
						selectedRowKeys={selectedRowKeys}
						setSelectedRowKeys={setSelectedRowKeys}
					/>
				</div>
				<Table
					{...(defaultTableProps as unknown as TableProps<TCourseBaseRecord>)}
					{...tableProps}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({
							label: __('Course', 'power-course'),
						}),
					}}
					rowSelection={rowSelection}
					columns={columns}
					scroll={{ x: 1700 }}
					rowKey={(record) => record.id.toString()}
				/>
			</Card>
		</Spin>
	)
}

export default memo(Main)
