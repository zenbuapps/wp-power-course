import { __ } from '@wordpress/i18n'
import { Tabs, TabsProps } from 'antd'
import { memo } from 'react'

import Cart from './Cart'
import General from './General'

const Shortcodes = () => {
	const items: TabsProps['items'] = [
		{
			key: 'general',
			label: __('General', 'power-course'),
			children: <General />,
		},
		{
			key: 'cart',
			label: __('Sales cards', 'power-course'),
			children: <Cart />,
		},
	]

	return <Tabs defaultActiveKey="general" items={items} />
}

export default memo(Shortcodes)
