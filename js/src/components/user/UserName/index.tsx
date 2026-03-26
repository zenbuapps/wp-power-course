import { Tag } from 'antd'
import React, { FC } from 'react'

import { TUserRecord } from '@/pages/admin/Courses/List/types'

export const UserName: FC<{
	record: TUserRecord
	onClick?: (_record: TUserRecord | undefined) => () => void
}> = ({ record, onClick = (_record: TUserRecord | undefined) => () => {} }) => {
	const { display_name, user_email, id, user_avatar_url, is_teacher } = record
	return (
		<div className="grid grid-cols-[2rem_1fr] gap-4 items-center">
			<img alt="" src={user_avatar_url} className="size-8 rounded-full" />
			<div>
				<p className="mb-1 cursor-pointer" onClick={onClick(record)}>
					{is_teacher ? <Tag color="magenta">講師</Tag> : ''}
					{display_name}{' '}
					<span className="ml-1 text-gray-400 text-xs">#{id}</span>
				</p>
				<p className="text-xs text-gray-400">{user_email}</p>
			</div>
		</div>
	)
}
