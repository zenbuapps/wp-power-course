import { __ } from '@wordpress/i18n'
import { Tag } from 'antd'
import { FC } from 'react'

import { TUserRecord } from '@/components/user/types'

/**
 * 使用者名稱 + 頭像
 *
 * Issue #264：`nameField` 決定名字要讀哪個欄位，由**呼叫端的情境**決定，不是由使用者屬性決定。
 * - 學員情境（預設 formatted_name）：走 Issue #54 的 billing → WP meta → display_name chain，
 *   讓管理者能把學員對上金流後台的帳單姓名。
 * - 講師情境（傳 'display_name'）：講師是公開身分，名字由站方在 WP 顯示名稱欄位決定，
 *   跟這個人身為客戶的帳單姓名無關。
 *
 * 刻意不用 `record.is_teacher` 自動判斷 —— 那是使用者屬性。同一個人可以既是講師又是學員，
 * 他在學員列表要顯示帳單姓名、在講師列表要顯示公開名稱，只有呼叫端知道現在是哪個情境。
 */
export const UserName: FC<{
	record: TUserRecord
	onClick?: (_record: TUserRecord | undefined) => () => void
	nameField?: 'formatted_name' | 'display_name'
}> = ({
	record,
	onClick = (_record: TUserRecord | undefined) => () => {},
	nameField = 'formatted_name',
}) => {
	const {
		formatted_name,
		display_name,
		user_email,
		id,
		user_avatar_url,
		is_teacher,
	} = record
	// 講師情境不 fallback 回 formatted_name —— 掉回去就等於掉回帳單姓名，正是 Issue #264 要修的行為。
	// WP 的 display_name 註冊時必填（預設為 user_login），實務上不會是空字串。
	const showName =
		nameField === 'display_name' ? display_name : formatted_name || display_name
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
