import { EditOutlined, DeleteOutlined } from '@ant-design/icons'
import { useNavigation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { TableProps, Tag, Button, Tooltip, Space } from 'antd'
import { NameId } from 'antd-toolkit'

import { useLabels } from '@/pages/admin/AccessPasses/hooks'
import { DisableButton } from '@/pages/admin/AccessPasses/List/components'
import { TAccessPassRecord } from '@/pages/admin/AccessPasses/types'

type TUseColumnsParams = {
	/** 點擊刪除時開啟確認 Modal 的回呼 */
	onDelete: (record: TAccessPassRecord) => void
}

/**
 * 課程權限包列表欄位定義
 *
 * 欄位：名稱 / 範圍類型（含範圍細節）/ 期限模式 / 狀態 / 已掛載商品數 / 操作。
 * 操作含：編輯、停用、刪除（觸發影響提示 Modal）。
 *
 * @param params.onDelete 點擊刪除按鈕的回呼
 */
const useColumns = ({
	onDelete,
}: TUseColumnsParams): TableProps<TAccessPassRecord>['columns'] => {
	const { edit } = useNavigation()
	const { getScopeLabel, getLimitLabel, getStatusLabel } = useLabels()

	return [
		{
			title: __('Access pass name', 'power-course'),
			dataIndex: 'name',
			render: (name: string, record) => (
				<span
					className="cursor-pointer"
					onClick={() => edit('access-passes', record.id)}
				>
					<NameId name={name} id={String(record.id)} />
				</span>
			),
		},
		{
			title: __('Scope type', 'power-course'),
			dataIndex: 'scope_type',
			width: 200,
			render: (_, record) => {
				const label = getScopeLabel(record.scope_type)
				if ('category' === record.scope_type) {
					return (
						<>
							<Tag color="blue">{label}</Tag>
							<span className="text-gray-400 text-xs">
								{sprintf(
									// translators: %d: 分類 / 標籤數量
									__('%d term(s)', 'power-course'),
									record.term_ids.length
								)}
							</span>
						</>
					)
				}
				if ('specific' === record.scope_type) {
					return (
						<>
							<Tag>{label}</Tag>
							<span className="text-gray-400 text-xs">
								{sprintf(
									// translators: %d: 課程數量
									__('%d course(s)', 'power-course'),
									record.course_ids.length
								)}
							</span>
						</>
					)
				}
				return <Tag color="gold">{label}</Tag>
			},
		},
		{
			title: __('Access period', 'power-course'),
			dataIndex: 'limit_type',
			width: 180,
			render: (_, record) => (
				<Tag>
					{getLimitLabel(
						record.limit_type,
						record.limit_value,
						record.limit_unit
					)}
				</Tag>
			),
		},
		{
			title: __('Status', 'power-course'),
			dataIndex: 'status',
			width: 90,
			align: 'center',
			render: (_, record) => {
				const { label, color } = getStatusLabel(record.status)
				return <Tag color={color}>{label}</Tag>
			},
		},
		{
			title: __('Attached products', 'power-course'),
			dataIndex: 'attached_product_count',
			width: 110,
			align: 'center',
			render: (count: number) => count,
		},
		{
			title: __('Actions', 'power-course'),
			dataIndex: '_actions',
			key: '_actions',
			width: 140,
			align: 'center',
			render: (_, record) => (
				<Space size={0}>
					<Tooltip title={__('Edit', 'power-course')}>
						<Button
							type="text"
							icon={<EditOutlined />}
							onClick={() => edit('access-passes', record.id)}
						/>
					</Tooltip>
					<DisableButton record={record} />
					<Tooltip title={__('Delete', 'power-course')}>
						<Button
							type="text"
							danger
							icon={<DeleteOutlined />}
							onClick={() => onDelete(record)}
						/>
					</Tooltip>
				</Space>
			),
		},
	]
}

export default useColumns
