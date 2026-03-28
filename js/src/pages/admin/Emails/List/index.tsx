import { List } from '@refinedev/antd'
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
					查看 Woocommerce 排程紀錄
				</Button>
			}
			items={[
				{
					key: 'emails',
					label: 'Email 模板管理',
					children: (
						<List title="">
							<Table />
						</List>
					),
				},
				{
					key: 'email-scheduled-actions',
					label: '排程紀錄',
					children: <AsTable />,
				},
			]}
		/>
	)
}

export default EmailsList
