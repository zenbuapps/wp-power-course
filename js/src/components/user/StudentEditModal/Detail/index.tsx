import { __, sprintf } from '@wordpress/i18n'
import { Statistic, Tabs, TabsProps } from 'antd'
import { Heading } from 'antd-toolkit'
import { useWoocommerce } from 'antd-toolkit/wp'
import { memo } from 'react'

import { ContactRemarks } from '@/components/user/ContactRemarks'
import { useRecord } from '@/components/user/StudentEditModal/hooks'

import AutoFill from './AutoFill'
import Basic from './Basic'
import Cart from './Cart'
import Meta from './Meta'
import RecentOrders from './RecentOrders'

const items: TabsProps['items'] = [
	{
		key: 'Basic',
		label: __('Basic info', 'power-course'),
		children: <Basic />,
	},
	{
		key: 'AutoFill',
		label: __('Auto fill', 'power-course'),
		children: <AutoFill />,
	},
	{
		key: 'Meta',
		label: __('Other fields', 'power-course'),
		children: <Meta />,
	},
]

/**
 * 學員詳細資料雙欄卡片
 *
 * 左欄：消費數據（6 個 Statistic，吃 record 統計欄位 + WooCommerce 貨幣符號）
 * + 用戶資料 Tabs（基本資料 / 自動填入 / 其他欄位）。
 * 右欄：聯絡註記 + 購物車 + 最近訂單。
 * 子區塊全透過 Context（useRecord / useIsEditing）取資料與編輯狀態。
 */
const DetailComponent = () => {
	const { currency } = useWoocommerce()
	const { symbol } = currency
	const record = useRecord()
	const {
		total_spend,
		orders_count,
		avg_order_value,
		date_last_active,
		date_last_order,
		user_registered,
		user_registered_human,
	} = record

	return (
		<div className="grid grid-cols-1 lg:grid-cols-2 gap-20">
			<div>
				<Heading className="mb-8">
					{__('Consumption data', 'power-course')}
				</Heading>
				{record?.id && (
					<div className="grid grid-cols-2 gap-8">
						<Statistic
							className="mt-4"
							title={__('Total spend', 'power-course')}
							value={total_spend || 0}
							prefix={symbol}
						/>
						<Statistic
							className="mt-4"
							title={__('Total orders', 'power-course')}
							value={orders_count || 0}
						/>
						<Statistic
							className="mt-4"
							title={__('Average order value', 'power-course')}
							value={avg_order_value || 0}
							precision={2}
							prefix={symbol}
						/>
						<Statistic
							className="mt-4"
							title={__('Last active', 'power-course')}
							value={date_last_active || ''}
						/>
						<Statistic
							className="mt-4"
							title={__('Last order', 'power-course')}
							value={date_last_order || ''}
						/>
						<Statistic
							className="mt-4"
							title={
								user_registered_human
									? sprintf(
											// translators: %s: 註冊距今的人類可讀時間（如「3 個月前」）
											__('Registration time ( %s )', 'power-course'),
											user_registered_human
										)
									: __('Registration time', 'power-course')
							}
							value={user_registered || ''}
						/>
					</div>
				)}

				<Heading className="mb-8 mt-20">
					{__('User data', 'power-course')}
				</Heading>
				<Tabs items={items} />
			</div>
			<div className="grid grid-cols-2 gap-12">
				<div>
					<Heading className="mb-8">
						{__('Contact notes', 'power-course')}
					</Heading>

					<ContactRemarks record={record} />
				</div>
				<div>
					<Heading>{__('Cart', 'power-course')}</Heading>

					<Cart />

					<Heading>{__('Recent orders', 'power-course')}</Heading>

					<RecentOrders />
				</div>
			</div>
		</div>
	)
}

export const Detail = memo(DetailComponent)
