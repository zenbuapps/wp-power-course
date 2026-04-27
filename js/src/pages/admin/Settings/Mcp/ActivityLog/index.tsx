import { CheckCircleFilled, CloseCircleFilled } from '@ant-design/icons'
import { Input, Table, Tag, Tooltip, Typography } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import { memo, useCallback, useMemo, useState } from 'react'

import { TMcpActivity } from '@/types/mcp'

import { useMcpActivity } from '../hooks/useMcpActivity'

const { Text } = Typography
const { Search } = Input

const PARAMS_MAX_LENGTH = 50

const formatDateTime = (iso: string): string => {
	const date = dayjs(iso)
	return date.isValid() ? date.format('YYYY-MM-DD HH:mm:ss') : iso
}

const summarizeParams = (params: Record<string, unknown>): string => {
	try {
		const json = JSON.stringify(params)
		if (json.length <= PARAMS_MAX_LENGTH) {
			return json
		}
		return `${json.slice(0, PARAMS_MAX_LENGTH)}…`
	} catch {
		return '[無法序列化]'
	}
}

/**
 * MCP Activity Log 檢視
 *
 * 顯示最近 AI 操作紀錄，支援 tool_name 篩選與分頁。
 */
const ActivityLogComponent = () => {
	const [page, setPage] = useState(1)
	const [pageSize, setPageSize] = useState(20)
	const [toolName, setToolName] = useState<string>('')

	const { items, total, isLoading } = useMcpActivity({
		page,
		pageSize,
		toolName: toolName.trim() || undefined,
	})

	const handleSearch = useCallback((value: string) => {
		setToolName(value)
		setPage(1)
	}, [])

	const handleTableChange = useCallback(
		(nextPage: number, nextPageSize: number) => {
			setPage(nextPage)
			setPageSize(nextPageSize)
		},
		[]
	)

	const columns = useMemo<ColumnsType<TMcpActivity>>(
		() => [
			{
				title: '時間',
				dataIndex: 'created_at',
				key: 'created_at',
				width: 170,
				render: (value: string) => (
					<Text className="text-xs">{formatDateTime(value)}</Text>
				),
			},
			{
				title: '使用者',
				dataIndex: 'user_display_name',
				key: 'user_display_name',
				width: 140,
				render: (value: string) => value || <Text type="secondary">—</Text>,
			},
			{
				title: 'Tool',
				dataIndex: 'tool_name',
				key: 'tool_name',
				width: 180,
				render: (value: string) => <Tag color="geekblue">{value}</Tag>,
			},
			{
				title: 'Params',
				dataIndex: 'params',
				key: 'params',
				render: (value: Record<string, unknown>) => {
					const summary = summarizeParams(value ?? {})
					return (
						<Tooltip
							title={
								<pre className="text-xs whitespace-pre-wrap break-all max-w-md">
									{JSON.stringify(value ?? {}, null, 2)}
								</pre>
							}
						>
							<code className="text-xs text-gray-600">{summary}</code>
						</Tooltip>
					)
				},
			},
			{
				title: '結果',
				key: 'result',
				width: 120,
				render: (_value, record) => {
					const icon = record.success ? (
						<CheckCircleFilled style={{ color: '#52c41a' }} />
					) : (
						<CloseCircleFilled style={{ color: '#ff4d4f' }} />
					)
					return (
						<Tooltip title={record.result_summary}>
							<span className="inline-flex items-center gap-1">
								{icon}
								<Text className="text-xs">
									{record.success ? '成功' : '失敗'}
								</Text>
							</span>
						</Tooltip>
					)
				},
			},
		],
		[]
	)

	return (
		<div>
			<div className="flex justify-end mb-3">
				<Search
					placeholder="依 tool_name 篩選"
					allowClear
					enterButton="搜尋"
					style={{ maxWidth: 320 }}
					onSearch={handleSearch}
				/>
			</div>
			<Table<TMcpActivity>
				rowKey="id"
				columns={columns}
				dataSource={items}
				loading={isLoading}
				locale={{ emptyText: '尚無活動記錄' }}
				pagination={{
					current: page,
					pageSize,
					total,
					showSizeChanger: true,
					pageSizeOptions: ['20', '50', '100'],
					showTotal: (totalCount) => `共 ${totalCount} 筆`,
					onChange: handleTableChange,
				}}
			/>
		</div>
	)
}

export const ActivityLog = memo(ActivityLogComponent)
