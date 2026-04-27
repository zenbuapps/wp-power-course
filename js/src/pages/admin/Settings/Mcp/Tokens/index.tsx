import { PlusOutlined } from '@ant-design/icons'
import { Button, Table, Tag, Typography } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import { memo, useCallback, useMemo, useState } from 'react'

import { TMcpToken } from '@/types/mcp'

import { useMcpTokens } from '../hooks/useMcpTokens'

import { CreateTokenModal } from './CreateTokenModal'
import { PlaintextTokenModal } from './PlaintextTokenModal'
import { RevokeTokenButton } from './RevokeTokenButton'

const { Text } = Typography

type TTokensListProps = {
	/** 是否 disabled（MCP 總開關關閉時） */
	disabled?: boolean
}

const formatDateTime = (iso: string | null): string => {
	if (!iso) {
		return '—'
	}
	const date = dayjs(iso)
	return date.isValid() ? date.format('YYYY-MM-DD HH:mm') : iso
}

/**
 * MCP API Token 管理列表
 *
 * 支援：建立（顯示明文 token 一次）、列表檢視、撤銷。
 */
const TokensListComponent = ({ disabled = false }: TTokensListProps) => {
	const { tokens, isLoading } = useMcpTokens()

	const [createOpen, setCreateOpen] = useState(false)
	const [plaintextToken, setPlaintextToken] = useState<string | null>(null)
	const [plaintextTokenName, setPlaintextTokenName] = useState<string>('')

	const handleOpenCreate = useCallback(() => {
		setCreateOpen(true)
	}, [])

	const handleCloseCreate = useCallback(() => {
		setCreateOpen(false)
	}, [])

	const handleCreated = useCallback((token: string, name: string) => {
		setCreateOpen(false)
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
				title: '名稱',
				dataIndex: 'name',
				key: 'name',
				render: (value: string) => <Text strong>{value}</Text>,
			},
			{
				title: 'Capabilities',
				dataIndex: 'capabilities',
				key: 'capabilities',
				render: (values: TMcpToken['capabilities']) => (
					<div className="flex flex-wrap gap-1">
						{values.length === 0 ? (
							<Text type="secondary">—</Text>
						) : (
							values.map((cap) => (
								<Tag key={cap} color="blue">
									{cap}
								</Tag>
							))
						)}
					</div>
				),
			},
			{
				title: '最後使用',
				dataIndex: 'last_used_at',
				key: 'last_used_at',
				width: 160,
				render: (value: string | null) => formatDateTime(value),
			},
			{
				title: '建立時間',
				dataIndex: 'created_at',
				key: 'created_at',
				width: 160,
				render: (value: string) => formatDateTime(value),
			},
			{
				title: '操作',
				key: 'actions',
				width: 100,
				align: 'right',
				render: (_value, record) => (
					<RevokeTokenButton tokenId={record.id} tokenName={record.name} />
				),
			},
		],
		[]
	)

	return (
		<>
			<div className="flex justify-end mb-3">
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={handleOpenCreate}
					disabled={disabled}
				>
					新增 Token
				</Button>
			</div>
			<Table<TMcpToken>
				rowKey="id"
				columns={columns}
				dataSource={tokens}
				loading={isLoading}
				pagination={false}
				locale={{ emptyText: '尚未建立任何 Token' }}
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
				onClose={handleClosePlaintext}
			/>
		</>
	)
}

export const TokensList = memo(TokensListComponent)
