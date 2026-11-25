import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Alert, Tabs } from 'antd'
import { stringToBool } from 'antd-toolkit/wp'
import { useState } from 'react'

import { useEnv } from '@/hooks'

import Condition from './Condition'
import useSendCondition from './hooks'
import Specific from './Specific'
import Variables from './Variables'

export const SendCondition = ({ email_ids }: { email_ids: string[] }) => {
	const [activeKey, setActiveKey] = useState('condition')
	const { SITE_URL } = useEnv()
	const { data, isLoading } = useSendCondition()
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
			<div>
				{/* 手動發信開關在 Powerhouse，未開啟時分頁被隱藏，提示站長去哪裡開（Issue #301） */}
				{!isLoading && !enable_manual_send_email && (
					<Alert
						className="mb-2"
						type="info"
						showIcon
						message={__(
							'Want to send this email manually to specific students?',
							'power-course'
						)}
						description={createInterpolateElement(
							__(
								'Go to <a>WordPress admin → Powerhouse → Settings</a> → General settings, turn on "Allow users to send emails manually" and save. Then come back and reload this page, and the "Send manually to specific users" tab will appear.',
								'power-course'
							),
							{
								// 連結文字由 createInterpolateElement 從翻譯字串填入
								a: (
									// eslint-disable-next-line jsx-a11y/anchor-has-content
									<a
										href={`${SITE_URL}/wp-admin/admin.php?page=powerhouse`}
										target="_blank"
										rel="noopener noreferrer"
									/>
								),
							}
						)}
					/>
				)}
				<Tabs
					activeKey={activeKey}
					onChange={(key) => {
						setActiveKey(key as string)
					}}
					items={items}
				/>
			</div>
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
