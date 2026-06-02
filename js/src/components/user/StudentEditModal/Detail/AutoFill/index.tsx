import { __ } from '@wordpress/i18n'
import { Heading } from 'antd-toolkit'

import { InfoTable } from '@/components/user/InfoTable'
import {
	useIsEditing,
	useRecord,
} from '@/components/user/StudentEditModal/hooks'

/**
 * 自動填入區塊
 *
 * 讀取學員的 billing / shipping 巢狀物件，分別渲染帳單與運送資訊的 InfoTable。
 */
const AutoFill = () => {
	const isEditing = useIsEditing()
	const record = useRecord()
	const { billing, shipping } = record

	return (
		<div className="grid grid-cols-1 gap-y-8">
			<div>
				<Heading className="mb-4" size="sm" hideIcon>
					{__('Billing info', 'power-course')}
				</Heading>
				<InfoTable isEditing={isEditing} type="billing" {...billing} />
			</div>
			<div>
				<Heading className="mb-4" size="sm" hideIcon>
					{__('Shipping info', 'power-course')}
				</Heading>
				<InfoTable isEditing={isEditing} type="shipping" {...shipping} />
			</div>
		</div>
	)
}

export default AutoFill
