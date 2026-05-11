import { __ } from '@wordpress/i18n'
import { Form, Button, Tabs, TabsProps, Spin } from 'antd'
import { lazy, memo, Suspense } from 'react'

import { IS_ADMIN } from '@/utils/env'

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

/**
 * 取得 Settings 頁的 tab 清單。
 * AI Tab 僅對 admin 顯示（Issue #221）：當 IS_ADMIN 為 false 時，
 * AI Tab key 完全不存在於 items 陣列，React 不會 mount AiTabLoader，
 * 也不會 trigger lazy import('./Ai') —— DOM 上完全消失。
 *
 * UI 隱藏僅作 UX 改善，真正的權限執行點仍是後端 REST permission_callback。
 *
 * 未來新增其他敏感 tab 時，建議直接使用 `<RoleGate>` 元件包裹 children，
 * 而不是再寫一份 `IS_ADMIN` 條件 push，避免兩條判定路徑分歧。
 * 範例：`<RoleGate capability="admin"><SecretTab /></RoleGate>`
 */
const getItems = (): TabsProps['items'] => {
	const items: NonNullable<TabsProps['items']> = [
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
	]

	if (IS_ADMIN) {
		items.push({
			key: 'ai',
			label: __('AI', 'power-course'),
			children: <AiTabLoader />,
		})
	}

	return items
}

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
