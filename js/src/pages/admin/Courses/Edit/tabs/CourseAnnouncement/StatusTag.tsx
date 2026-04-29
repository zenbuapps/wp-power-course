import { __ } from '@wordpress/i18n'
import { Tag } from 'antd'
import React from 'react'

import { TAnnouncementStatusLabel } from './types'

type TProps = {
	status: TAnnouncementStatusLabel | string
}

const labelMap: Record<TAnnouncementStatusLabel, { color: string; text: string }> =
	{
		active: { color: 'green', text: __('Active', 'power-course') },
		scheduled: { color: 'blue', text: __('Scheduled', 'power-course') },
		expired: { color: 'default', text: __('Expired', 'power-course') },
	}

export const StatusTag = ({ status }: TProps) => {
	const meta = labelMap[status as TAnnouncementStatusLabel]
	if (!meta) {
		return <Tag>{status}</Tag>
	}
	return <Tag color={meta.color}>{meta.text}</Tag>
}
