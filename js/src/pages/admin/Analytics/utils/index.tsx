import { DatePickerProps, TimeRangePickerProps } from 'antd'
import dayjs from 'dayjs'

import { __ } from '@wordpress/i18n'

export const FORMAT = 'YYYY-MM-DDTHH:mm:ss'

export const RANGE_PRESETS: TimeRangePickerProps['presets'] = [
	{
		label: __('Today', 'power-course'),
		value: [dayjs().startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 7 days', 'power-course'),
		value: [dayjs().add(-7, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 14 days', 'power-course'),
		value: [dayjs().add(-14, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 30 days', 'power-course'),
		value: [dayjs().add(-30, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 90 days', 'power-course'),
		value: [dayjs().add(-90, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 180 days', 'power-course'),
		value: [dayjs().add(-180, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Last 365 days', 'power-course'),
		value: [dayjs().add(-365, 'd').startOf('day'), dayjs().endOf('day')],
	},
	{
		label: __('Month to date', 'power-course'),
		value: [dayjs().startOf('month'), dayjs().endOf('day')],
	},
	{
		label: __('Year to date', 'power-course'),
		value: [dayjs().startOf('year'), dayjs().endOf('day')],
	},
]

// Disabled 732 days from the selected date
export const maxDateRange: DatePickerProps['disabledDate'] = (
	current,
	{ from, type }
) => {
	if (current && current > dayjs().endOf('day')) {
		return true
	}
	if (from) {
		return Math.abs(current.diff(from, 'days')) >= 366
	}

	return false
}

// 避免刻度太密集
export const tickFilter = (
	datum: string,
	index: number,
	datums: string[]
): boolean => {
	const length = datums?.length || 0
	if (length > 12 && (length % 3 === 0 || length % 4 === 0)) {
		if (length % 3 === 0) {
			return (index + 1) % 3 === 0
		}
		if (length % 2 === 0) {
			return (index + 1) % 2 === 0
		}
	}

	return true
}

export const cards = [
	{
		title: __('Total revenue', 'power-course'),
		slug: 'total_sales',
		unit: __('NT$', 'power-course'),
		tooltip: __(
			'Total revenue (received amount) = Original sales (product price × quantity) - Coupon discount - Refunds + Shipping',
			'power-course'
		),
	},
	{
		title: __('Net revenue', 'power-course'),
		slug: 'net_revenue',
		unit: __('NT$', 'power-course'),
		tooltip: __('Net revenue = Total revenue - Shipping', 'power-course'),
	},
	{
		title: __('Shipping', 'power-course'),
		slug: 'shipping',
		unit: __('NT$', 'power-course'),
	},
	{
		title: __('Total orders', 'power-course'),
		slug: 'orders_count',
		unit: __('pcs', 'power-course'),
	},
	{
		title: __('Completed orders', 'power-course'),
		slug: 'non_refunded_orders_count',
		unit: __('pcs', 'power-course'),
		tooltip: __(
			'Completed orders = Total orders - Refunded orders',
			'power-course'
		),
	},
	{
		title: __('Refunded orders', 'power-course'),
		slug: 'refunded_orders_count',
		unit: __('pcs', 'power-course'),
	},
	{
		title: __('Refund amount', 'power-course'),
		slug: 'refunds',
		unit: __('NT$', 'power-course'),
	},
	{
		title: __('Student count', 'power-course'),
		slug: 'student_count',
		unit: __('people', 'power-course'),
	},
	{
		title: __('Finished lessons count', 'power-course'),
		slug: 'finished_chapters_count',
		unit: __('pcs', 'power-course'),
	},

	// 以下為 WC 原本就有的數據
	{
		title: __('Items sold', 'power-course'),
		slug: 'num_items_sold',
		unit: __('pcs', 'power-course'),
	},
	{
		title: __('Items sold', 'power-course'),
		slug: 'items_sold', // product query 才會出現
		unit: __('pcs', 'power-course'),
	},
	{
		title: __('Coupon amount', 'power-course'),
		slug: 'coupons',
		unit: __('NT$', 'power-course'),
	},
	{
		title: __('Coupon count', 'power-course'),
		slug: 'coupons_count',
		unit: __('pcs', 'power-course'),
	},

	// {
	// 	title: '稅金',
	// 	slug: 'taxes',
	// },

	{
		title: __('Avg items per order', 'power-course'),
		slug: 'avg_items_per_order',
		unit: __('pcs', 'power-course'),
	},
	{
		title: __('Avg order value', 'power-course'),
		slug: 'avg_order_value',
		unit: __('NT$', 'power-course'),
	},
	{
		title: __('Total customers', 'power-course'),
		slug: 'total_customers',
		unit: __('people', 'power-course'),
	},
]
