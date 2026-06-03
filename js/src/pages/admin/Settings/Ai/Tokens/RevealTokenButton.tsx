import { EyeOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Button } from 'antd'
import { memo, useCallback } from 'react'

import { useRevealMcpToken } from '../hooks/useMcpTokens'

type TRevealTokenButtonProps = {
	tokenId: number
	/** 成功取得明文後回傳給父層開啟 PlaintextTokenModal */
	onRevealed: (token: string, name: string) => void
}

/**
 * 查看 Token 按鈕（點擊後 on-demand 取得明文，Issue #230）
 *
 * 對應 GET /power-course/mcp/tokens/{id}/reveal；成功後交由父層以 reveal 模式開啟明文 Modal。
 */
const RevealTokenButtonComponent = ({
	tokenId,
	onRevealed,
}: TRevealTokenButtonProps) => {
	const { reveal, isLoading } = useRevealMcpToken(tokenId)

	const handleClick = useCallback(() => {
		reveal((response) => {
			onRevealed(response.token, response.name)
		})
	}, [reveal, onRevealed])

	return (
		<Button
			type="text"
			size="small"
			loading={isLoading}
			icon={<EyeOutlined />}
			onClick={handleClick}
		>
			{__('View', 'power-course')}
		</Button>
	)
}

export const RevealTokenButton = memo(RevealTokenButtonComponent)
