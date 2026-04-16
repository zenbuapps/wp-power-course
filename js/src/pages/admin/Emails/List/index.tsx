import { List } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import { Tabs, Button } from 'antd'

import { useEnv } from '@/hooks'
import AsTable from '@/pages/admin/Emails/List/AsTable'
import Table from '@/pages/admin/Emails/List/Table'

const EmailsList = () => {
	const { SITE_URL } = useEnv()
	return (
		<Tabs
			tabBarExtraContent={
				<Button
					href={`${SITE_URL}/wp-admin/admin.php?page=wc-status&tab=action-scheduler&s=power_email_send_`}
					target="_blank"
				>
					{__('View WooCommerce scheduled actions', 'power-course')}
				</Button>
			}
			items={[
				{
					key: 'emails',
					label: __('Email templates', 'power-course'),
					children: (
						<List title="">
							<Table />
						</List>
					),
				},
				{
					key: 'email-scheduled-actions',
					label: __('Scheduled actions', 'power-course'),
					children: <AsTable />,
				},
			]}
		/>
	)
}

export default EmailsList
