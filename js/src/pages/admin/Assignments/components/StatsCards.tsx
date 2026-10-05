/**
 * 批改統計卡（Issue #131 P7）
 *
 * 全部 / 待批改 / 通過 / 未通過 / 通過率 / 平均批改時間，範圍跟著課程分頁切換
 * （`features/assignment/查詢作業批改統計.feature`）。
 *
 * 統計未取得前一律不渲染統計卡：切換課程分頁時若先用舊範圍的數字佔位，
 * 畫面會出現一瞬間屬於別的課程的數據。
 */
import { _x, __ } from '@wordpress/i18n'
import { Card, Col, Row, Skeleton, Statistic } from 'antd'
import { memo } from 'react'

import { type TStatsCardsProps } from '../types'

/** 無資料時的佔位符（平均批改時間尚無任何已批改紀錄） */
const EMPTY_PLACEHOLDER = '-'

/**
 * 統計卡元件
 */
const StatsCardsComponent = ({ stats, isFetching }: TStatsCardsProps) => {
	if (!stats || isFetching) {
		return <Skeleton active paragraph={{ rows: 2 }} />
	}

	const cards = [
		{ key: 'total', title: __('All', 'power-course'), value: stats.total },
		{
			key: 'pending',
			title: _x('Pending', 'assignment submission status', 'power-course'),
			value: stats.pending,
		},
		{
			key: 'pass',
			title: _x('Passed', 'assignment submission status', 'power-course'),
			value: stats.pass,
		},
		{
			key: 'fail',
			title: _x('Failed', 'assignment submission status', 'power-course'),
			value: stats.fail,
		},
		{
			key: 'pass_rate',
			title: __('Pass rate', 'power-course'),
			value: stats.pass_rate,
			suffix: '%',
		},
		{
			key: 'avg',
			title: __('Average grading time', 'power-course'),
			value:
				null === stats.avg_grading_seconds
					? EMPTY_PLACEHOLDER
					: stats.avg_grading_seconds,
			suffix:
				null === stats.avg_grading_seconds
					? undefined
					: _x('sec', 'assignment grading time unit', 'power-course'),
		},
	]

	return (
		<Row gutter={[16, 16]}>
			{cards.map((card) => (
				<Col key={card.key} xs={12} md={8} xl={4}>
					<Card size="small" className={`pc-assignment-stat-${card.key}`}>
						<Statistic
							title={card.title}
							value={card.value}
							suffix={card.suffix}
						/>
					</Card>
				</Col>
			))}
		</Row>
	)
}

export const StatsCards = memo(StatsCardsComponent)
