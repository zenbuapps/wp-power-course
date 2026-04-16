import { useNavigation } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Table, TableProps, Tag } from 'antd'
import { renderHTML } from 'antd-toolkit'
import React from 'react'

import { ProductName } from '@/components/product'
import { TAsRecord } from '@/pages/admin/Emails/types'
import { getASStatus } from '@/utils'

const useAsColumns = () => {
	const { edit } = useNavigation()
	const onClick = (record: TAsRecord) => () => {
		edit('emails', record.id)
	}
	const columns: TableProps<TAsRecord>['columns'] = [
		Table.SELECTION_COLUMN,
		{
			title: __('Hook name', 'power-course'),
			dataIndex: 'hook',
			width: 180,
			render: (hook: string, record) => (
				<ProductName<TAsRecord & any>
					record={record}
					hideImage
					onClick={onClick(record)}
					label={hook}
				/>
			),
		},

		{
			title: __('Status', 'power-course'),
			width: 64,
			align: 'center',
			dataIndex: 'status_name',
			render: (status_name: string) => (
				<Tag color={getASStatus(status_name).color}>
					{getASStatus(status_name).label}
				</Tag>
			),
		},
		{
			title: __('Arguments', 'power-course'),
			width: 240,
			align: 'center',
			dataIndex: 'args',
			render: (args) => JSON.stringify(args),
		},
		{
			title: <p className="text-center m-0">Log</p>,
			width: 240,
			dataIndex: 'log_entries',
			render: (log_entries) => renderHTML(log_entries),
		},
		{
			title: __('Recurrence', 'power-course'),
			width: 120,
			align: 'center',
			dataIndex: 'recurrence',
		},
		{
			title: 'Claim Id',
			width: 60,
			align: 'center',
			dataIndex: 'claim_id',
		},
		{
			title: __('Scheduled time', 'power-course'),
			align: 'right',
			dataIndex: 'schedule',
			width: 160,
		},
	]

	return columns
}

export default useAsColumns
