import { Form, Button, Tabs, TabsProps } from 'antd'
import { memo } from 'react'

import Appearance from './Appearance'
import AutoGrant from './AutoGrant'
import General from './General'
import useSave from './hooks/useSave'
import useSettings from './hooks/useSettings'

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
