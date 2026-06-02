import { CheckOutlined, CopyOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Alert, Button, Modal, Tabs, Typography, message } from 'antd'
import { memo, useCallback, useState } from 'react'

import { SITE_URL } from '@/utils/env'

const { Paragraph, Text } = Typography

/** 對外 MCP server endpoint 路徑（mcp-adapter 註冊於 power-course/v2/mcp） */
const MCP_ENDPOINT_PATH = '/wp-json/power-course/v2/mcp'

type TPlaintextTokenModalProps = {
	open: boolean
	/** 建立成功後的明文 token，關閉 modal 後即無法再取得 */
	plaintextToken: string | null
	/** Token 名稱（顯示用） */
	tokenName?: string
	/** 關閉 modal */
	onClose: () => void
}

type TCodeBlockProps = {
	/** 要顯示與複製的內容 */
	code: string
}

/**
 * 內嵌「程式碼 + 一鍵複製」區塊
 *
 * 內容為技術設定（CLI 命令 / JSON），屬語言中立的設定字串，不做翻譯；
 * 僅按鈕等 UI 文字使用 i18n。
 */
const CodeBlock = ({ code }: TCodeBlockProps) => {
	const [copied, setCopied] = useState(false)

	const handleCopy = useCallback(async () => {
		try {
			await navigator.clipboard.writeText(code)
			setCopied(true)
			message.success(__('Copied to clipboard', 'power-course'))
			window.setTimeout(() => setCopied(false), 2000)
		} catch {
			message.error(
				__('Copy failed, please select the text manually', 'power-course')
			)
		}
	}, [code])

	return (
		<div className="relative">
			<pre className="bg-gray-50 border rounded p-3 pr-20 text-xs overflow-x-auto whitespace-pre-wrap break-all m-0">
				{code}
			</pre>
			<Button
				size="small"
				className="!absolute top-2 right-2"
				icon={copied ? <CheckOutlined /> : <CopyOutlined />}
				onClick={handleCopy}
			>
				{copied ? __('Copied', 'power-course') : __('Copy', 'power-course')}
			</Button>
		</div>
	)
}

/**
 * Token 建立成功後顯示明文 Token 的 Modal（Issue #230）
 *
 * 強調此 token 只會顯示一次，必須立即複製保存；
 * 同時提供 Claude Code / Cursor 的快速設定範本（Bearer 認證，免 Base64）。
 */
const PlaintextTokenModalComponent = ({
	open,
	plaintextToken,
	tokenName,
	onClose,
}: TPlaintextTokenModalProps) => {
	const [tokenCopied, setTokenCopied] = useState(false)

	const token = plaintextToken ?? ''
	const endpoint = `${SITE_URL}${MCP_ENDPOINT_PATH}`

	const handleCopyToken = useCallback(async () => {
		if (!token) {
			return
		}
		try {
			await navigator.clipboard.writeText(token)
			setTokenCopied(true)
			message.success(__('Copied to clipboard', 'power-course'))
			window.setTimeout(() => setTokenCopied(false), 2000)
		} catch {
			message.error(
				__('Copy failed, please select the text manually', 'power-course')
			)
		}
	}, [token])

	const handleClose = useCallback(() => {
		setTokenCopied(false)
		onClose()
	}, [onClose])

	// 以下為語言中立的技術設定範本，不做翻譯
	const cliCommand = `claude mcp add --transport http power-course \\\n  ${endpoint} \\\n  --header "Authorization: Bearer ${token}"`

	const mcpServerConfig = {
		mcpServers: {
			'power-course': {
				type: 'http',
				url: endpoint,
				headers: {
					Authorization: `Bearer ${token}`,
				},
			},
		},
	}
	const jsonConfig = JSON.stringify(mcpServerConfig, null, 2)

	return (
		<Modal
			open={open}
			title={__('Token created', 'power-course')}
			onCancel={handleClose}
			maskClosable={false}
			closable={false}
			keyboard={false}
			width={680}
			footer={[
				<Button key="confirm" type="primary" onClick={handleClose}>
					{__('I have copied it, close', 'power-course')}
				</Button>,
			]}
		>
			<Alert
				type="warning"
				showIcon
				className="mb-4"
				message={
					<strong>{__('This token is shown only once', 'power-course')}</strong>
				}
				description={__(
					'You will not be able to view the full token again after closing this dialog. Copy it now and store it securely.',
					'power-course'
				)}
			/>

			{tokenName && (
				<Paragraph className="mb-2">
					{__('Token name', 'power-course')}：<strong>{tokenName}</strong>
				</Paragraph>
			)}

			<div className="flex items-center gap-2 mb-2">
				<div className="flex-1 bg-gray-50 border rounded p-3 font-mono text-sm break-all select-all">
					{token}
				</div>
				<Button
					type="primary"
					icon={tokenCopied ? <CheckOutlined /> : <CopyOutlined />}
					onClick={handleCopyToken}
					disabled={!token}
				>
					{tokenCopied
						? __('Copied', 'power-course')
						: __('Copy token', 'power-course')}
				</Button>
			</div>

			<Alert
				type="info"
				showIcon
				className="mb-4"
				message={__(
					'This token is designed for Power Course MCP. You do not need a WordPress application password.',
					'power-course'
				)}
			/>

			<Paragraph className="mb-1">
				<Text strong>{__('Quick setup', 'power-course')}</Text>
			</Paragraph>
			<Paragraph type="secondary" className="text-xs mb-2">
				{__(
					'Copy the snippet for your AI client. Uses Bearer authentication — no Base64 encoding required.',
					'power-course'
				)}
			</Paragraph>

			<Tabs
				items={[
					{
						key: 'claude-cli',
						label: __('Claude Code (CLI)', 'power-course'),
						children: <CodeBlock code={cliCommand} />,
					},
					{
						key: 'claude-json',
						label: __('Claude Code (JSON)', 'power-course'),
						children: <CodeBlock code={jsonConfig} />,
					},
					{
						key: 'cursor',
						label: __('Cursor', 'power-course'),
						children: <CodeBlock code={jsonConfig} />,
					},
				]}
			/>
		</Modal>
	)
}

export const PlaintextTokenModal = memo(PlaintextTokenModalComponent)
