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
			label: '設定發信時機',
			key: 'condition',
			children: <Condition email_ids={email_ids} />,
		},
		{
			label: '手動發給指定用戶',
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
						label: '可用變數',
						key: 'Variables',
						children: <Variables activeKey={activeKey} />,
					},
				]}
			/>
		</div>
	)
}
