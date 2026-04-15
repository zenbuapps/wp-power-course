import { Form, Button, Tabs, TabsProps, Spin } from 'antd'
import { lazy, memo, Suspense } from 'react'

import Appearance from './Appearance'
import AutoGrant from './AutoGrant'
import General from './General'
import useSave from './hooks/useSave'
import useSettings from './hooks/useSettings'

const McpTab = lazy(() => import('./Mcp'))

const McpTabLoader = () => (
	<Suspense
		fallback={
			<div className="flex justify-center py-16">
				<Spin />
			</div>
		}
	>
		<McpTab />
	</Suspense>
)

const items: TabsProps['items'] = [
	{
		key: 'general',
		label: '一般設定',
		children: <General />,
	},
	{
		key: 'appearance',
		label: '外觀設定',
		children: <Appearance />,
	},
	{
		key: 'auto-grant',
		label: '自動開通',
		children: <AutoGrant />,
	},
	{
		key: 'mcp',
		label: 'MCP',
		children: <McpTabLoader />,
	},
]

const Settings = () => {
	const [form] = Form.useForm()
	const { handleSave, mutation } = useSave({ form })
	const { isLoading: isSaveLoading } = mutation
	const { isLoading: isGetLoading } = useSettings({ form })

	return (
		<Form layout="vertical" form={form} onFinish={handleSave}>
			<Tabs
				tabBarExtraContent={{
					left: (
						<Button
							className="mr-8"
							type="primary"
							htmlType="submit"
							loading={isSaveLoading}
							disabled={isGetLoading}
						>
							儲存
						</Button>
					),
				}}
				defaultActiveKey="general"
				items={items}
			/>
		</Form>
	)
}

export default memo(Settings)
