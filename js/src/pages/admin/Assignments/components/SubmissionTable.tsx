/**
 * 作業繳交列表（Issue #131 P7）
 *
 * 分頁由後端的 limit / offset 控制（每頁上限 200 筆），排序固定為繳交時間由新到舊，
 * 不提供 client-side 排序，避免與 CSV 匯出的順序不一致。
 *
 * 刻意不設定 `scroll` 與欄位 `ellipsis`：rc-table 只要偵測到水平捲動或 ellipsis
 * 就會在 tbody 多插一列 `.ant-table-measure-row`，讓「列數」的語意變得不可靠。
 */
import { __ } from '@wordpress/i18n'
import { Empty, Table } from 'antd'
import { memo } from 'react'

import { useColumns } from '../hooks'
import { type TSubmissionRecord } from '../types'

/** 每頁筆數選項（後端 SubmissionFilter 上限 200） */
const PAGE_SIZE_OPTIONS = ['20', '50', '100', '200']

type TSubmissionTableProps = {
	/** 本頁的繳交紀錄 */
	items: TSubmissionRecord[]
	/** 符合篩選條件的總筆數 */
	total: number
	/** 目前頁碼（1 起算） */
	page: number
	/** 每頁筆數 */
	pageSize: number
	/** 是否正在取得資料 */
	isFetching: boolean
	/** 換頁 / 換每頁筆數的回呼 */
	onPageChange: (page: number, pageSize: number) => void
	/** 點擊「查看詳情」的回呼 */
	onSelect: (record: TSubmissionRecord) => void
}

/**
 * 繳交列表元件
 */
const SubmissionTableComponent = ({
	items,
	total,
	page,
	pageSize,
	isFetching,
	onPageChange,
	onSelect,
}: TSubmissionTableProps) => {
	const columns = useColumns(onSelect)

	return (
		<div className="pc-assignment-submission-table">
			<Table<TSubmissionRecord>
				rowKey="id"
				columns={columns}
				dataSource={items}
				loading={isFetching}
				locale={{
					emptyText: (
						<Empty
							image={Empty.PRESENTED_IMAGE_SIMPLE}
							description={__('No submissions', 'power-course')}
						/>
					),
				}}
				pagination={{
					current: page,
					pageSize,
					total,
					showSizeChanger: true,
					pageSizeOptions: PAGE_SIZE_OPTIONS,
					onChange: onPageChange,
				}}
			/>
		</div>
	)
}

export const SubmissionTable = memo(SubmissionTableComponent)
