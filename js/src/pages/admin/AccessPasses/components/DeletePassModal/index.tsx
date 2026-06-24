import { useApiUrl, useCustomMutation, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Modal, Alert, Typography } from 'antd'
import { memo } from 'react'

import { TAccessPassRecord } from '@/pages/admin/AccessPasses/types'

const { Paragraph, Text } = Typography

type TDeletePassModalProps = {
	/** 要刪除的權限包記錄；null 時不開啟 */
	record: TAccessPassRecord | null
	/** 關閉 Modal 回呼 */
	onClose: () => void
}

/**
 * 刪除課程權限包確認 Modal（Issue #252）
 *
 * 二次確認 + 影響提示：刪除會收回已購用戶的觀看權，故須明確警示。
 * 影響範圍以 attached_product_count（已掛載商品數）呈現，提醒站長刪除前評估。
 *
 * 後端契約：DELETE /access-passes，body { ids:[id], confirm:true }。
 * 刪除只收回 pc_user_access_pass 持有關係，不影響單獨購買 / 逐課綁定（OR 疊加）。
 */
const DeletePassModalComponent = ({
	record,
	onClose,
}: TDeletePassModalProps) => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const { mutate, isLoading } = useCustomMutation()

	const handleConfirm = () => {
		if (!record) {
			return
		}
		mutate(
			{
				url: `${apiUrl}/access-passes`,
				method: 'delete',
				values: {
					ids: [record.id],
					confirm: true,
				},
				successNotification: () => ({
					message: sprintf(
						// translators: %s: 權限包名稱
						__('Access pass "%s" deleted', 'power-course'),
						record.name
					),
					type: 'success',
				}),
				errorNotification: () => ({
					message: __('Failed to delete access pass', 'power-course'),
					type: 'error',
				}),
			},
			{
				onSuccess: () => {
					invalidate({
						resource: 'access-passes',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					onClose()
				},
			}
		)
	}

	const attachedCount = record?.attached_product_count ?? 0

	return (
		<Modal
			open={!!record}
			title={__('Confirm delete access pass', 'power-course')}
			okText={__('Confirm delete', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			okButtonProps={{ danger: true, loading: isLoading }}
			cancelButtonProps={{ disabled: isLoading }}
			onOk={handleConfirm}
			onCancel={isLoading ? undefined : onClose}
			maskClosable={!isLoading}
		>
			<Paragraph>
				{sprintf(
					// translators: %s: 權限包名稱
					__('You are about to delete the access pass "%s".', 'power-course'),
					record?.name ?? ''
				)}
			</Paragraph>
			<Alert
				type="warning"
				showIcon
				message={
					attachedCount > 0
						? sprintf(
								// translators: %d: 已掛載的商品數
								__(
									'This pass is attached to %d product(s). Deleting it will revoke access for users who purchased through this pass.',
									'power-course'
								),
								attachedCount
							)
						: __(
								'Deleting it will revoke access for users who purchased through this pass.',
								'power-course'
							)
				}
				description={
					<Text type="secondary">
						{__(
							'Courses obtained via individual purchase or per-course binding are not affected.',
							'power-course'
						)}
					</Text>
				}
			/>
		</Modal>
	)
}

export const DeletePassModal = memo(DeletePassModalComponent)
