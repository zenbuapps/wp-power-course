import { CopyOutlined, CheckOutlined } from '@ant-design/icons'
import { Alert, Button, Modal, Typography, message } from 'antd'
import { memo, useCallback, useState } from 'react'

const { Paragraph } = Typography

type TPlaintextTokenModalProps = {
	open: boolean
	/** 建立成功後的明文 token，關閉 modal 後即無法再取得 */
	plaintextToken: string | null
	/** Token 名稱（顯示用） */
	tokenName?: string
	/** 關閉 modal */
	onClose: () => void
}

/**
 * Token 建立成功後顯示明文 Token 的 Modal
 *
 * 強調此 token 只會顯示一次，必須立即複製保存。
 */
const PlaintextTokenModalComponent = ({
	open,
	plaintextToken,
	tokenName,
	onClose,
}: TPlaintextTokenModalProps) => {
	const [copied, setCopied] = useState(false)

	const handleCopy = useCallback(async () => {
		if (!plaintextToken) {
			return
		}
		try {
			await navigator.clipboard.writeText(plaintextToken)
			setCopied(true)
			message.success('已複製到剪貼簿')
			window.setTimeout(() => setCopied(false), 2000)
		} catch {
			message.error('複製失敗，請手動選取文字複製')
		}
	}, [plaintextToken])

	const handleClose = useCallback(() => {
		setCopied(false)
		onClose()
	}, [onClose])

	return (
		<Modal
			open={open}
			title="Token 建立成功"
			onCancel={handleClose}
			maskClosable={false}
			closable={false}
			keyboard={false}
			width={640}
			footer={[
				<Button key="confirm" type="primary" onClick={handleClose}>
					我已複製，確認關閉
				</Button>,
			]}
		>
			<Alert
				type="warning"
				showIcon
				className="mb-4"
				message="此 Token 只會顯示一次"
				description="關閉此視窗後將無法再次查看完整 Token 內容。請立即複製並妥善保存到安全位置。"
			/>

			{tokenName && (
				<Paragraph className="mb-2">
					Token 名稱：<strong>{tokenName}</strong>
				</Paragraph>
			)}

			<div className="bg-gray-50 border rounded p-3 font-mono text-sm break-all select-all">
				{plaintextToken ?? ''}
			</div>

			<div className="mt-3 text-right">
				<Button
					type="primary"
					icon={copied ? <CheckOutlined /> : <CopyOutlined />}
					onClick={handleCopy}
					disabled={!plaintextToken}
				>
					{copied ? '已複製' : '複製 Token'}
				</Button>
			</div>
		</Modal>
	)
}

export const PlaintextTokenModal = memo(PlaintextTokenModalComponent)
