import { TableProps } from 'antd'
import React from 'react'

import { UserName } from '@/components/user'
import { TUserRecord } from '@/pages/admin/Courses/List/types'

const useColumns = ({
	onClick: show,
}: {
	onClick?: (_record: TUserRecord | undefined) => () => void
}) => {
	const columns: TableProps<TUserRecord>['columns'] = [
		{
			title: '講師',
			dataIndex: 'id',
			width: 180,
			render: (_, record) => <UserName record={record} onClick={show} />,
		},
	]

	return columns
}

export default useColumns
