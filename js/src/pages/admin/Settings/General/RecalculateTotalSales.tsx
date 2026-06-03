import { __ } from '@wordpress/i18n'
import { Button, Popconfirm } from 'antd'
import { memo } from 'react'

import { Heading } from '@/components/general'
import useRecalculateTotalSales from '@/pages/admin/Settings/hooks/useRecalculateTotalSales'

/**
 * 課程銷售資料區塊
 * 提供「重新計算已售出數量」按鈕，點擊後（經二次確認）以背景排程方式
 * 依有效訂單重新計算所有課程的 total_sales。
 */
const RecalculateTotalSales = () => {
	const { handleRecalculate, isLoading } = useRecalculateTotalSales()

	return (
		<>
			<Heading className="mt-8">
				{__('Course sales data', 'power-course')}
			</Heading>
			<p className="mb-4 text-gray-500">
				{__(
					'Recalculate the sold count of all courses based on valid orders. This is useful after order refunds or cancellations.',
					'power-course'
				)}
			</p>
			<Popconfirm
				title={__('Recalculate total sales', 'power-course')}
				description={__(
					"Recalculate all courses' sold count? This rebuilds from valid orders.",
					'power-course'
				)}
				okText={__('Confirm', 'power-course')}
				cancelText={__('Cancel', 'power-course')}
				onConfirm={handleRecalculate}
			>
				<Button type="primary" loading={isLoading}>
					{__('Recalculate total sales', 'power-course')}
				</Button>
			</Popconfirm>
		</>
	)
}

export default memo(RecalculateTotalSales)
