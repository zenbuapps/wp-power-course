import { PlusOutlined, DownOutlined } from '@ant-design/icons'
import { useTable } from '@refinedev/antd'
import { HttpError, useCreate } from '@refinedev/core'
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
				name: '新課程',
				is_external: false,
			},
		})
	}

	/** 建立外部課程（product_url 先填預設值，待使用者進入編輯頁修改） */
	const createExternalCourse = () => {
		create({
			values: {
				name: '新外部課程',
				is_external: true,
				product_url: 'https://example.com',
			},
		})
	}

	/** 新增課程下拉選單 */
	const createMenuItems: MenuProps['items'] = [
		{
			key: 'internal',
			label: '站內課程',
			onClick: createInternalCourse,
		},
		{
			key: 'external',
			label: '外部課程',
			onClick: createExternalCourse,
		},
	]

	return (
		<Spin spinning={tableProps?.loading as boolean}>
			<Card title="篩選" className="mb-4">
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
							新增課程 <DownOutlined />
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
						...getDefaultPaginationProps({ label: '課程' }),
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
