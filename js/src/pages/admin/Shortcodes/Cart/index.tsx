import { Card } from 'antd'
import React from 'react'

import Bundle from './Bundle'
import Simple from './Simple'

const Cart = () => {
	return (
		<Card>
			<Simple />
			<Bundle />
		</Card>
	)
}

export default Cart
