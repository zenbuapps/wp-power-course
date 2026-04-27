import { DeleteOutlined } from '@ant-design/icons'
import { Button, Popconfirm } from 'antd'
import { memo, useCallback } from 'react'

import { useRevokeMcpToken } from '../hooks/useMcpTokens'

type TRevokeTokenButtonProps = {
	tokenId: number
	tokenName: string
}

/**
 * 撤銷 Token 按鈕（含 Popconfirm 二次確認）
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
			title="確定要撤銷此 Token？"
			description={
				<div className="text-xs max-w-xs">
					即將撤銷「{tokenName}」，已使用此 Token 的 AI
					應用將立即失效，且無法復原。
				</div>
			}
			okText="確定撤銷"
			cancelText="取消"
			okButtonProps={{ danger: true, loading: isLoading }}
			onConfirm={handleConfirm}
		>
			<Button danger type="text" size="small" icon={<DeleteOutlined />}>
				撤銷
			</Button>
		</Popconfirm>
	)
}

export const RevokeTokenButton = memo(RevokeTokenButtonComponent)
