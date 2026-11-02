import { __, _n, sprintf } from '@wordpress/i18n'
import { Alert, Button, Modal } from 'antd'
import { memo, useState } from 'react'

import { useEmailJsonHealth } from './hooks'
import Item from './Item'

/**
 * 郵件模板內容健康檢查（Issue #290）
 * 有內容無法解析的模板時顯示警告，並開啟 Modal 逐封檢視與修復；沒有損壞時不渲染任何 UI。
 */
const JsonHealth = () => {
	const { items, isFetching } = useEmailJsonHealth()
	const [isModalOpen, setIsModalOpen] = useState(false)

	if (!items.length) {
		return null
	}

	return (
		<>
			<Alert
				className="mb-4"
				type="warning"
				showIcon
				message={sprintf(
					// translators: %d: 內容無法解析的郵件模板數量
					_n(
						'%d email template has content that cannot be parsed',
						'%d email templates have content that cannot be parsed',
						items.length,
						'power-course'
					),
					items.length
				)}
				description={__(
					'The HTML used for sending is not affected. Only the content in the email editor cannot be loaded.',
					'power-course'
				)}
				action={
					<Button size="small" onClick={() => setIsModalOpen(true)}>
						{__('View details', 'power-course')}
					</Button>
				}
			/>
			<Modal
				title={__('Email templates with unparsable content', 'power-course')}
				open={isModalOpen}
				onCancel={() => setIsModalOpen(false)}
				footer={null}
				width={800}
			>
				<div className="flex flex-col gap-4">
					{items.map((item) => (
						<Item key={item.id} item={item} isRefreshing={isFetching} />
					))}
				</div>
			</Modal>
		</>
	)
}

export default memo(JsonHealth)
