import { __, sprintf } from '@wordpress/i18n'
import { Tag } from 'antd'
import dayjs from 'dayjs'
import React, { memo } from 'react'

import { TExpireDate } from '@/pages/admin/Courses/List/types/user'

const getColor = (expireDate: TExpireDate) => {
	const { is_expired, timestamp } = expireDate
	if (timestamp === 0) {
		return 'blue'
	}

	if (is_expired) {
		return 'magenta'
	}

	return 'green'
}

const getLabel = (expireDate: TExpireDate) => {
	const { is_expired, timestamp } = expireDate

	if (timestamp === 0) {
		return __('Unlimited', 'power-course')
	}

	return is_expired
		? __('Expired', 'power-course')
		: __('Not expired', 'power-course')
}

export const getWatchStatusTagTooltip = (expireDate: TExpireDate) => {
	const { is_subscription, subscription_id, is_expired, timestamp } = expireDate
	if (is_subscription) {
		return sprintf(
			// translators: %s: 訂閱 ID
			__('Follow subscription #%s', 'power-course'),
			String(subscription_id)
		)
	}

	if (!timestamp) return ''

	const formattedDate = dayjs.unix(timestamp).format('YYYY/MM/DD HH:mm')
	return is_expired
		? sprintf(
				// translators: %s: 日期時間（YYYY/MM/DD HH:mm）
				__('Expired on %s', 'power-course'),
				formattedDate
			)
		: sprintf(
				// translators: %s: 日期時間（YYYY/MM/DD HH:mm）
				__('Available until %s', 'power-course'),
				formattedDate
			)
}

const WatchStatusTagComponent = ({
	expireDate,
}: {
	expireDate: TExpireDate
}) => {
	const color = getColor(expireDate)
	const label = getLabel(expireDate)

	return (
		<Tag color={color} bordered={false}>
			{label}
		</Tag>
	)
}

export const WatchStatusTag = memo(WatchStatusTagComponent)
