import { __, _x } from '@wordpress/i18n'
import { Tag } from 'antd'
import React from 'react'

import { TAnnouncementStatusLabel } from './types'

type TProps = {
	status: TAnnouncementStatusLabel | string
}

const labelMap: Record<
	TAnnouncementStatusLabel,
	{ color: string; text: string }
> = {
	active: { color: 'green', text: __('Active', 'power-course') },
	// 用 _x 帶 context 與 actionScheduler 的 'Scheduled'（已排程）區分；
	// 此處語意為「公告排程發布中、尚未生效」，故譯「排程中」。
	scheduled: {
		color: 'blue',
		text: _x('Scheduled', 'announcement status', 'power-course'),
	},
	expired: { color: 'default', text: __('Expired', 'power-course') },
	// 草稿：不公開、不排程的暫存狀態
	draft: {
		color: 'default',
		text: _x('Draft', 'announcement status', 'power-course'),
	},
}

export const StatusTag = ({ status }: TProps) => {
	const meta = labelMap[status as TAnnouncementStatusLabel]
	if (!meta) {
		return <Tag>{status}</Tag>
	}
	return <Tag color={meta.color}>{meta.text}</Tag>
}
