import { __ } from '@wordpress/i18n'
import { Tabs } from 'antd'
import { stringToBool } from 'antd-toolkit/wp'
import React, { useState } from 'react'

import Condition from './Condition'
import useSendCondition from './hooks'
import Specific from './Specific'
import Variables from './Variables'

export const SendCondition = ({ email_ids }: { email_ids: string[] }) => {
	const [activeKey, setActiveKey] = useState('condition')
	const { data } = useSendCondition()
	const enable_manual_send_email = stringToBool(
		data?.data?.enable_manual_send_email || 'no'
	)

	const items = [
		{
			label: __('Configure send timing', 'power-course'),
			key: 'condition',
			children: <Condition email_ids={email_ids} />,
		},
		{
			label: __('Send manually to specific users', 'power-course'),
			key: 'specific',
			children: <Specific email_ids={email_ids} />,
		},
	].filter((item) => {
		if (enable_manual_send_email) {
			return true
		}
		return item.key !== 'specific'
	})

	return (
		<div className="grid grid-cols-1 lg:grid-cols-[1fr_32rem] gap-x-4">
			<Tabs
				activeKey={activeKey}
				onChange={(key) => {
					setActiveKey(key as string)
				}}
				items={items}
			/>
			<Tabs
				defaultActiveKey="avl_variables"
				items={[
					{
						label: __('Available variables', 'power-course'),
						key: 'Variables',
						children: <Variables activeKey={activeKey} />,
					},
				]}
			/>
		</div>
	)
}
