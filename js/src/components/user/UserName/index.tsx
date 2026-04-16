import { __ } from '@wordpress/i18n'
import { Tag } from 'antd'
import React, { FC } from 'react'

import { TUserRecord } from '@/pages/admin/Courses/List/types'

export const UserName: FC<{
	record: TUserRecord
	onClick?: (_record: TUserRecord | undefined) => () => void
}> = ({ record, onClick = (_record: TUserRecord | undefined) => () => {} }) => {
	const {
		formatted_name,
		display_name,
		user_email,
		id,
		user_avatar_url,
		is_teacher,
	} = record
	const showName = formatted_name || display_name
	return (
		<div className="grid grid-cols-[2rem_1fr] gap-4 items-center">
			<img alt="" src={user_avatar_url} className="size-8 rounded-full" />
			<div>
				<p className="mb-1 cursor-pointer" onClick={onClick(record)}>
					{is_teacher ? (
						<Tag color="magenta">{__('Instructor', 'power-course')}</Tag>
					) : (
						''
					)}
					{showName} <span className="ml-1 text-gray-400 text-xs">#{id}</span>
				</p>
				<p className="text-xs text-gray-400">{user_email}</p>
			</div>
		</div>
	)
}
