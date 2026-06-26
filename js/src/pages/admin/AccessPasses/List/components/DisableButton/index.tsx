import { StopOutlined } from '@ant-design/icons'
import { useApiUrl, useCustomMutation, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Popconfirm, Button, Tooltip } from 'antd'
import { memo } from 'react'

import { TAccessPassRecord } from '@/pages/admin/AccessPasses/types'

type TDisableButtonProps = {
	/** 權限包記錄 */
	record: TAccessPassRecord
}

/**
 * 停用課程權限包按鈕（Issue #252）
 *
 * 停用後權限包不可再掛載到新商品，但已購用戶的觀看權保留（OR 疊加）。
 * 後端契約：POST /access-passes/{id}/disable。已是 disabled 時按鈕禁用。
 */
const DisableButtonComponent = ({ record }: TDisableButtonProps) => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const { mutate, isLoading } = useCustomMutation()

	const isDisabled = 'disabled' === record.status

	const handleDisable = () => {
		mutate(
			{
				url: `${apiUrl}/access-passes/${record.id}/disable`,
				method: 'post',
				values: {},
				successNotification: () => ({
					message: sprintf(
						// translators: %s: 通行證名稱
						__('Access pass "%s" disabled', 'power-course'),
						record.name
					),
					type: 'success',
				}),
				errorNotification: () => ({
					message: __('Failed to disable access pass', 'power-course'),
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
				},
			}
		)
	}

	if (isDisabled) {
		return (
			<Tooltip title={__('Already disabled', 'power-course')}>
				<Button type="text" icon={<StopOutlined />} disabled />
			</Tooltip>
		)
	}

	return (
		<Popconfirm
			title={__('Disable this access pass?', 'power-course')}
			description={__(
				'It cannot be attached to new products after disabling. Purchased users keep their access.',
				'power-course'
			)}
			okText={__('Confirm', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			okButtonProps={{ loading: isLoading }}
			onConfirm={handleDisable}
		>
			<Tooltip title={__('Disable', 'power-course')}>
				<Button
					type="text"
					icon={<StopOutlined className="text-yellow-700" />}
					loading={isLoading}
				/>
			</Tooltip>
		</Popconfirm>
	)
}

export const DisableButton = memo(DisableButtonComponent)
