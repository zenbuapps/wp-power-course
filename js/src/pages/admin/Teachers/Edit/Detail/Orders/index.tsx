import { __ } from '@wordpress/i18n'
import { Empty, Tag } from 'antd'
import { Heading } from 'antd-toolkit'
import { ORDER_STATUS, useWoocommerce } from 'antd-toolkit/wp'
import React from 'react'

import { useRecord } from '../../hooks'

/**
 * 講師 Edit 頁 — 訂單紀錄 Tab（Q10=A：講師本人下的訂單）
 *
 * 合併 Power Shop 的 Cart + RecentOrders 到同一 Tab：
 * - 上半部：當前購物車（持久化 WC session cart）
 * - 下半部：最近 5 筆訂單（來自 wc_get_orders customer_id=<teacher_id>）
 *
 * Subtitle 明示「您本人的購買紀錄」，避免使用者誤以為是「講師教的課
 * 的訂單」（plan 風險緩解）。
 */
const Orders = () => {
	const record = useRecord()
	const cart = record?.cart ?? []
	const recent_orders = record?.recent_orders ?? []
	const { currency } = useWoocommerce()
	const symbol = currency?.symbol ?? ''

	const cartTotal = cart.reduce((acc, item) => acc + (item?.line_total ?? 0), 0)

	const formatPrice = (amount: number) =>
		`${symbol}${Number(amount).toLocaleString(undefined, { maximumFractionDigits: 2 })}`

	return (
		<>
			<p className="text-xs text-gray-400 mb-4">
				{__(
					'These records reflect the instructor&apos;s own purchase history.',
					'power-course'
				)}
			</p>

			<Heading size="sm" hideIcon>
				{__('Current cart', 'power-course')}
			</Heading>

			<div className="rounded-lg border border-gray-200 border-solid p-3 mb-8">
				{!cart.length && (
					<Empty
						image={Empty.PRESENTED_IMAGE_SIMPLE}
						description={__('Cart is empty', 'power-course')}
					/>
				)}

				{cart.length > 0 && (
					<>
						{cart.map(
							({ product_id, product_name, quantity, product_image }) => (
								<div
									key={product_id}
									className="grid grid-cols-[2rem_1fr_0.5rem_2rem] items-center mb-2 text-xs"
								>
									<img
										alt={product_name}
										loading="lazy"
										decoding="async"
										className="rounded-md text-transparent size-8 object-cover"
										src={product_image}
									/>
									<span className="mx-2 truncate">{product_name}</span>
									<span>x</span>
									<span className="text-right">{quantity}</span>
								</div>
							)
						)}
						<div className="bg-gray-200 h-[1px] w-full my-2" />
						<div className="flex justify-between items-center text-xs">
							<span>{__('Cart total', 'power-course')}</span>
							<span className="font-medium">{formatPrice(cartTotal)}</span>
						</div>
					</>
				)}
			</div>

			<Heading size="sm" hideIcon>
				{__('Recent orders', 'power-course')}
			</Heading>

			{!recent_orders.length && (
				<Empty
					image={Empty.PRESENTED_IMAGE_SIMPLE}
					description={__('No recent orders', 'power-course')}
				/>
			)}

			{recent_orders.map(
				({ order_id, order_date, order_total, order_status, order_items }) => {
					const findStatus = ORDER_STATUS.find(
						(item) => item.value === order_status
					)
					return (
						<div
							key={order_id}
							className="rounded-lg border border-gray-200 border-solid p-3 mb-4"
						>
							<div className="flex items-center justify-between mb-2">
								<div>
									<h3 className="text-sm font-medium m-0">#{order_id}</h3>
									<p className="text-xs text-gray-400 mb-0">{order_date}</p>
								</div>
								<Tag
									className="m-0"
									color={findStatus?.color || 'default'}
									bordered={false}
								>
									{findStatus?.label || __('Unknown status', 'power-course')}
								</Tag>
							</div>
							{order_items?.map(
								({ product_id, product_name, quantity, product_image }) => (
									<div
										key={product_id}
										className="grid grid-cols-[2rem_1fr_0.5rem_2rem] items-center mb-2 text-xs"
									>
										<img
											alt={product_name}
											loading="lazy"
											decoding="async"
											className="rounded-md text-transparent size-8 object-cover"
											src={product_image}
										/>
										<span className="mx-2 truncate">{product_name}</span>
										<span>x</span>
										<span className="text-right">{quantity}</span>
									</div>
								)
							)}
							<div className="bg-gray-200 h-[1px] w-full my-2" />
							<div className="flex justify-between items-center text-xs">
								<span>{__('Order total', 'power-course')}</span>
								<span className="font-medium">{formatPrice(order_total)}</span>
							</div>
						</div>
					)
				}
			)}
		</>
	)
}

export default Orders
