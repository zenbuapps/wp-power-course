import { PlusOutlined } from '@ant-design/icons'
import { useTable } from '@refinedev/antd'
import { HttpError, useCreate } from '@refinedev/core'
import { Table, Button, TableProps, Card } from 'antd'
import { useRowSelection } from 'antd-toolkit'
import { memo } from 'react'

import { TFilterProps } from '@/components/product/ProductTable/types'
import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import useColumns from '@/pages/admin/Emails/List/hooks/useColumns'
import { TEmailListRecord } from '@/pages/admin/Emails/types'

import DeleteButton from './DeleteButton'
const Main = () => {
	const { tableProps } = useTable<TEmailListRecord, HttpError, TFilterProps>({
		resource: 'emails',
		dataProviderName: 'power-email',
	})

	const { rowSelection, selectedRowKeys, setSelectedRowKeys } =
		useRowSelection<TEmailListRecord>()

	const columns = useColumns()

	const { mutate: create, isLoading: isCreating } = useCreate({
		resource: 'emails',
		dataProviderName: 'power-email',
		invalidates: ['list'],
		meta: {
			headers: { 'Content-Type': 'multipart/form-data;' },
		},
	})

	const createEmail = () => {
		create({
			values: {},
		})
	}

	return (
		<Card>
			<div className="mb-4 flex justify-between">
				<Button
					loading={isCreating}
					type="primary"
					icon={<PlusOutlined />}
					onClick={createEmail}
				>
					新增 Email
				</Button>

				<DeleteButton
					selectedRowKeys={selectedRowKeys}
					setSelectedRowKeys={setSelectedRowKeys}
				/>
			</div>
			<Table
				{...(defaultTableProps as unknown as TableProps<TEmailListRecord>)}
				{...tableProps}
				pagination={{
					...tableProps.pagination,
					...getDefaultPaginationProps({ label: 'Email' }),
				}}
				rowSelection={rowSelection}
				columns={columns}
				rowKey={(record) => record.id.toString()}
			/>
		</Card>
	)
}

export default memo(Main)
