import { PlusOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Button, Empty, Table, Typography } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import { memo, useCallback, useMemo, useState } from 'react'

import { TMcpToken, TMcpTokenCreateResponse } from '@/types/mcp'

import { useMcpTokens } from '../hooks/useMcpTokens'

import { CreateTokenModal } from './CreateTokenModal'
import { PlaintextTokenModal } from './PlaintextTokenModal'
import { RevealTokenButton } from './RevealTokenButton'
import { RevokeTokenButton } from './RevokeTokenButton'

const { Text } = Typography

const formatDateTime = (iso: string | null): string => {
	if (!iso) {
		return '—'
	}
	const date = dayjs(iso)
	return date.isValid() ? date.format('YYYY-MM-DD HH:mm') : iso
}

const formatDate = (iso: string | null): string => {
	if (!iso) {
		return ''
	}
	const date = dayjs(iso)
	return date.isValid() ? date.format('YYYY-MM-DD') : iso
}

/**
 * MCP API Token 管理列表（Issue #230）
 *
 * 支援：建立（顯示明文 token 一次 + 快速設定範本）、列表檢視、撤銷。
 * 只列出當前登入管理員自己的 Token（後端依 current_user_id 過濾）。
 */
const TokensListComponent = () => {
	const { tokens, isLoading } = useMcpTokens()

	const [createOpen, setCreateOpen] = useState(false)
	const [plaintextToken, setPlaintextToken] = useState<string | null>(null)
	const [plaintextTokenName, setPlaintextTokenName] = useState<string>('')
	const [plaintextMode, setPlaintextMode] = useState<'created' | 'reveal'>(
		'created'
	)

	const handleOpenCreate = useCallback(() => {
		setCreateOpen(true)
	}, [])

	const handleCloseCreate = useCallback(() => {
		setCreateOpen(false)
	}, [])

	const handleCreated = useCallback((response: TMcpTokenCreateResponse) => {
		setCreateOpen(false)
		setPlaintextMode('created')
		setPlaintextToken(response.token)
		setPlaintextTokenName(response.name)
	}, [])

	const handleRevealed = useCallback((token: string, name: string) => {
		setPlaintextMode('reveal')
		setPlaintextToken(token)
		setPlaintextTokenName(name)
	}, [])

	const handleClosePlaintext = useCallback(() => {
		setPlaintextToken(null)
		setPlaintextTokenName('')
	}, [])

	const columns = useMemo<ColumnsType<TMcpToken>>(
		() => [
			{
				title: __('Name', 'power-course'),
				dataIndex: 'name',
				key: 'name',
				render: (value: string) => <Text strong>{value}</Text>,
			},
			{
				title: __('Created at', 'power-course'),
				dataIndex: 'created_at',
				key: 'created_at',
				width: 160,
				render: (value: string) => formatDateTime(value),
			},
			{
				title: __('Last used at', 'power-course'),
				dataIndex: 'last_used_at',
				key: 'last_used_at',
				width: 160,
				render: (value: string | null) =>
					value ? (
						formatDateTime(value)
					) : (
						<Text type="secondary">{__('Never used', 'power-course')}</Text>
					),
			},
			{
				title: __('Expires at', 'power-course'),
				dataIndex: 'expires_at',
				key: 'expires_at',
				width: 140,
				render: (value: string | null) =>
					value ? (
						formatDate(value)
					) : (
						<Text type="secondary">{__('Never expires', 'power-course')}</Text>
					),
			},
			{
				title: __('Actions', 'power-course'),
				key: 'actions',
				width: 160,
				align: 'right',
				render: (_value, record) => (
					<div className="flex items-center justify-end gap-1">
						<RevealTokenButton
							tokenId={record.id}
							onRevealed={handleRevealed}
						/>
						<RevokeTokenButton tokenId={record.id} tokenName={record.name} />
					</div>
				),
			},
		],
		[handleRevealed]
	)

	return (
		<>
			<div className="flex justify-end mb-3">
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={handleOpenCreate}
				>
					{__('Add token', 'power-course')}
				</Button>
			</div>
			<Table<TMcpToken>
				rowKey="id"
				columns={columns}
				dataSource={tokens}
				loading={isLoading}
				pagination={false}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description={
								<div className="text-sm">
									<p className="mb-1">
										{__(
											'No tokens yet. Click “Add token” to connect an AI client.',
											'power-course'
										)}
									</p>
									<p className="text-xs text-gray-400 m-0">
										{__(
											'This token is designed for Power Course MCP. You do not need a WordPress application password.',
											'power-course'
										)}
									</p>
								</div>
							}
						/>
					),
				}}
			/>

			<CreateTokenModal
				open={createOpen}
				onClose={handleCloseCreate}
				onCreated={handleCreated}
			/>

			<PlaintextTokenModal
				open={plaintextToken !== null}
				plaintextToken={plaintextToken}
				tokenName={plaintextTokenName}
				mode={plaintextMode}
				onClose={handleClosePlaintext}
			/>
		</>
	)
}

export const TokensList = memo(TokensListComponent)
