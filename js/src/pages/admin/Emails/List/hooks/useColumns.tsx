import { useNavigation } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Table, TableProps, Tag } from 'antd'
import React from 'react'

import { DuplicateButton } from '@/components/general'
import { ProductName } from '@/components/product'
import { TEmailListRecord } from '@/pages/admin/Emails/types'
import { getPostStatus } from '@/utils'

const useColumns = () => {
	const { edit } = useNavigation()
	const onClick = (record: TEmailListRecord) => () => {
		edit('emails', record.id)
	}
	const columns: TableProps<TEmailListRecord>['columns'] = [
		Table.SELECTION_COLUMN,
		{
			title: __('Email name', 'power-course'),
			dataIndex: 'name',
			width: 300,
			render: (name: string, record) => (
				<ProductName<TEmailListRecord>
					record={record}
					hideImage
					onClick={onClick(record)}
				/>
			),
		},

		{
			title: __('Status', 'power-course'),
			width: 64,
			align: 'center',
			dataIndex: 'status',
			render: (status: string) => (
				<Tag color={getPostStatus(status)?.color}>
					{status === 'publish'
						? __('Enabled', 'power-course')
						: __('Disabled', 'power-course')}
				</Tag>
			),
		},
		{
			title: __('Email subject', 'power-course'),
			dataIndex: 'subject',
			width: 300,
		},

		// {
		// 	title: '發送時機',
		// 	width: 240,
		// 	align: 'center',
		// 	dataIndex: 'condition',
		// 	render: (condition) => (
		// 		<Space.Compact>
		// 			<Input
		// 				className="pointer-events-none"
		// 				value={condition?.trigger_at as string}
		// 			/>
		// 		</Space.Compact>
		// 	),
		// },
		{
			title: __('Last modified', 'power-course'),
			align: 'right',
			dataIndex: 'date_modified',
			width: 160,
		},
		{
			title: __('Actions', 'power-course'),
			dataIndex: '_actions',
			key: '_actions',
			width: 48,
			render: (_, record) => (
				<DuplicateButton
					id={record.id}
					invalidateProps={{
						resource: 'emails',
						dataProviderName: 'power-email',
					}}
					tooltipProps={{ title: __('Duplicate email', 'power-course') }}
				/>
			),
		},
	]

	return columns
}

export default useColumns
