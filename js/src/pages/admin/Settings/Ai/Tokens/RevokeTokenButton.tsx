import { DeleteOutlined } from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Button, Popconfirm } from 'antd'
import { memo, useCallback } from 'react'

import { useRevokeMcpToken } from '../hooks/useMcpTokens'

type TRevokeTokenButtonProps = {
	tokenId: number
	tokenName: string
}

/**
 * 撤銷 Token 按鈕（含 Popconfirm 二次確認，Issue #230）
 */
const RevokeTokenButtonComponent = ({
	tokenId,
	tokenName,
}: TRevokeTokenButtonProps) => {
	const { revoke, isLoading } = useRevokeMcpToken()

	const handleConfirm = useCallback(() => {
		revoke(tokenId)
	}, [revoke, tokenId])

	return (
		<Popconfirm
			title={__('Revoke this token?', 'power-course')}
			description={
				<div className="text-xs max-w-xs">
					{sprintf(
						// translators: %s: Token 名稱
						__(
							'Revoking “%s” will immediately invalidate any AI client using it. This cannot be undone.',
							'power-course'
						),
						tokenName
					)}
				</div>
			}
			okText={__('Confirm revoke', 'power-course')}
			cancelText={__('Cancel', 'power-course')}
			okButtonProps={{ danger: true, loading: isLoading }}
			onConfirm={handleConfirm}
		>
			<Button danger type="text" size="small" icon={<DeleteOutlined />}>
				{__('Revoke', 'power-course')}
			</Button>
		</Popconfirm>
	)
}

export const RevokeTokenButton = memo(RevokeTokenButtonComponent)
