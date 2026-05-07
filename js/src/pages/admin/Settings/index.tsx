import { __ } from '@wordpress/i18n'
import { Form, Button, Tabs, TabsProps, Spin } from 'antd'
import { lazy, memo, Suspense } from 'react'

import Appearance from './Appearance'
import AutoGrant from './AutoGrant'
import General from './General'
import useSave from './hooks/useSave'
import useSettings from './hooks/useSettings'

const AiTab = lazy(() => import('./Ai'))

const AiTabLoader = () => (
	<Suspense
		fallback={
			<div className="flex justify-center py-16">
				<Spin />
			</div>
		}
	>
		<AiTab />
	</Suspense>
)

const getItems = (): TabsProps['items'] => [
	{
		key: 'general',
		label: __('General settings', 'power-course'),
		children: <General />,
	},
	{
		key: 'appearance',
		label: __('Appearance settings', 'power-course'),
		children: <Appearance />,
	},
	{
		key: 'auto-grant',
		label: __('Auto-grant', 'power-course'),
		children: <AutoGrant />,
	},
	{
		key: 'ai',
		label: __('AI', 'power-course'),
		children: <AiTabLoader />,
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
							{__('Save', 'power-course')}
						</Button>
					),
				}}
				defaultActiveKey="general"
				items={getItems()}
			/>
		</Form>
	)
}

export default memo(Settings)
