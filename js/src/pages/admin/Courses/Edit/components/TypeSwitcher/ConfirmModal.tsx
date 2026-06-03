import { __, sprintf } from '@wordpress/i18n'
import { Modal, Descriptions, Alert } from 'antd'
import { memo } from 'react'

type TConfirmModalProps = {
	open: boolean
	targetType: 'simple' | 'external'
	studentCount: number
	chapterCount: number
	bundleCount: number
	loading: boolean
	onConfirm: () => void
	onCancel: () => void
}

/**
 * Issue #235：課程類型切換確認對話框
 * - 站內 → 外部：列出已授權學員 / 章節 / Bundle 三項影響清單，Bundle > 0 時額外提示前台展示風險
 * - 外部 → 站內：說明 product_url / button_text 將被隱藏但不刪除，並提示需自行設定章節銷售方案
 */
const ConfirmModal = ({
	open,
	targetType,
	studentCount,
	chapterCount,
	bundleCount,
	loading,
	onConfirm,
	onCancel,
}: TConfirmModalProps) => {
	const switchToExternal = targetType === 'external'

	const title = switchToExternal
		? __('Switch to external platform course', 'power-course')
		: __('Switch to in-site course', 'power-course')

	return (
		<Modal
			open={open}
			title={title}
			okText={__('Confirm switch', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			onOk={onConfirm}
			onCancel={onCancel}
			okButtonProps={{ loading }}
			cancelButtonProps={{ disabled: loading }}
			maskClosable={!loading}
			closable={!loading}
			width={520}
		>
			{switchToExternal ? (
				<>
					<Descriptions
						column={1}
						size="small"
						bordered
						className="mb-4"
						items={[
							{
								key: 'students',
								label: __('Authorized students', 'power-course'),
								children: studentCount,
							},
							{
								key: 'chapters',
								label: __('Chapters', 'power-course'),
								children: chapterCount,
							},
							{
								key: 'bundles',
								label: __('Linked bundles', 'power-course'),
								children: bundleCount,
							},
						]}
					/>
					<p className="text-sm text-gray-500 mb-2">
						{__(
							'After switching, the above data will be hidden (not deleted) and restored when you switch back.',
							'power-course'
						)}
					</p>
					{bundleCount > 0 && (
						<Alert
							type="warning"
							showIcon
							message={sprintf(
								// translators: %d: 銷售方案數量
								__(
									'This course is linked by %d bundle(s); the front-end display of bundles may be inconsistent after switching to external platform course. Please review.',
									'power-course'
								),
								bundleCount
							)}
						/>
					)}
				</>
			) : (
				<>
					<p className="text-sm mb-2">
						{__(
							'External Link URL and CTA button text will be hidden but not deleted (auto-restored when switching back).',
							'power-course'
						)}
					</p>
					<p className="text-sm text-gray-500">
						{__(
							'After switching to in-site course, you will need to configure chapters, bundles, etc. yourself.',
							'power-course'
						)}
					</p>
				</>
			)}
		</Modal>
	)
}

export default memo(ConfirmModal)
