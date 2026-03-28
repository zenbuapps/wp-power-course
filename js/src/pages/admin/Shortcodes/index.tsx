import { Tabs, TabsProps } from 'antd'
import { memo } from 'react'

import Cart from './Cart'
import General from './General'

const items: TabsProps['items'] = [
	{
		key: 'general',
		label: '一般',
		children: <General />,
	},
	{
		key: 'cart',
		label: '銷售卡片',
		children: <Cart />,
	},
]

const Shortcodes = () => {
	return <Tabs defaultActiveKey="general" items={items} />
}

export default memo(Shortcodes)
