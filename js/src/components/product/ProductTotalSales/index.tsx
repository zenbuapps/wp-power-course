import { Badge, Tooltip } from 'antd'
import React, { FC } from 'react'
import { __ } from '@wordpress/i18n'

import useOptions, {
	TUseOptionsParams,
} from '@/components/product/ProductTable/hooks/useOptions'
import { TProductRecord } from '@/components/product/ProductTable/types'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'

const COLOR_GRADE = {
	'tier-5': '#ffccc7',
	'tier-4': '#ffa39e',
	'tier-3': '#ff7875',
	'tier-2': '#ff4d4f',
	'tier-1': '#f5222d',
}

export const ProductTotalSales: FC<{
	record: TProductRecord | TCourseBaseRecord
	optionParams?: TUseOptionsParams
}> = ({
	record,
	optionParams = {
		endpoint: 'products/options',
	},
}) => {
	const { total_sales } = record
	const { options } = useOptions(optionParams)
	const { top_sales_products = [] } = options
	const max_sales = top_sales_products?.[0]?.total_sales || 0

	if (total_sales === undefined) return null

	const { color, label } = get_tier(total_sales, max_sales)

	return (
		<Tooltip zIndex={1000000 + 20} title={label}>
			<Badge count={total_sales} color={color} showZero />
		</Tooltip>
	)
}

function get_tier(total_sales: number, max_sales: number) {
	if (total_sales > max_sales * 0.8 || max_sales === 0) {
		return {
			color: COLOR_GRADE['tier-1'],
			tier: 'tier-1',
			label: __('Top selling product (top 20%)', 'power-course'),
		}
	} else if (total_sales > max_sales * 0.6) {
		return {
			color: COLOR_GRADE['tier-2'],
			tier: 'tier-2',
			label: __('Best selling product (top 40%)', 'power-course'),
		}
	} else if (total_sales > max_sales * 0.4) {
		return {
			color: COLOR_GRADE['tier-3'],
			tier: 'tier-3',
			label: __('Sales volume (top 60%)', 'power-course'),
		}
	} else if (total_sales > max_sales * 0.2) {
		return {
			color: COLOR_GRADE['tier-4'],
			tier: 'tier-4',
			label: __('Sales volume (top 80%)', 'power-course'),
		}
	}
	return {
		color: COLOR_GRADE['tier-5'],
		tier: 'tier-5',
		label: __('Sales volume (top 100%)', 'power-course'),
	}
}
