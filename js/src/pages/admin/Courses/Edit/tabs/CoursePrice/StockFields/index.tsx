import { ExportOutlined } from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Form, InputNumber, Select, Button, Alert } from 'antd'
import { useEnv } from 'antd-toolkit'
import { PRODUCT_STOCK_STATUS, useWoocommerce } from 'antd-toolkit/wp'

import { FiSwitch as Switch } from '@/components/formItem'

const { Item } = Form

const StockFields = () => {
	const { SITE_URL } = useEnv()
	const { notify_low_stock_amount, manage_stock: canManageStock } =
		useWoocommerce()
	const form = Form.useFormInstance()
	const manageStockName = ['manage_stock']
	const watchManageStock = Form.useWatch(manageStockName, form)
	const enableStockManagement = watchManageStock === 'yes'

	if (!canManageStock) {
		return (
			<Alert
				message={
					<>
						{__(
							'Stock management is not enabled in your store',
							'power-course'
						)}
						<Button
							color="primary"
							variant="link"
							href={`${SITE_URL}/wp-admin/admin.php?page=wc-settings&tab=products&section=inventory`}
							target="_blank"
							icon={<ExportOutlined />}
							iconPosition="end"
						>
							{__('Go to enable', 'power-course')}
						</Button>
					</>
				}
				type="info"
				showIcon
				className="mb-4"
			/>
		)
	}

	return (
		<>
			<Item
				name={['backorders']}
				label={__('Allow backorders', 'power-course')}
			>
				<Select
					className="w-full"
					options={[
						{
							label: __('Yes, and notify customer', 'power-course'),
							value: 'notify',
						},
						{ label: __('Yes', 'power-course'), value: 'yes' },
						{ label: __('No', 'power-course'), value: 'no' },
					]}
					allowClear
				/>
			</Item>

			<Switch
				formItemProps={{
					name: ['manage_stock'],
					label: __('Manage stock', 'power-course'),
				}}
			/>
			{!enableStockManagement && (
				<Item
					name={['stock_status']}
					label={__('Stock status', 'power-course')}
				>
					<Select
						className="w-full"
						options={PRODUCT_STOCK_STATUS}
						allowClear
					/>
				</Item>
			)}

			{enableStockManagement && (
				<>
					<Item
						name={['stock_quantity']}
						label={__('Stock quantity', 'power-course')}
					>
						<InputNumber className="w-full" />
					</Item>
					<Item
						name={['low_stock_amount']}
						label={__('Low stock threshold', 'power-course')}
					>
						<InputNumber
							placeholder={sprintf(
								// translators: %s: 全店低庫存門檻數量
								__('Store-wide threshold (%s)', 'power-course'),
								notify_low_stock_amount
							)}
							className="w-full"
						/>
					</Item>
				</>
			)}

			<Switch
				formItemProps={{
					name: ['sold_individually'],
					label: __('Purchase limit', 'power-course'),
					help: __('Limit one item per order', 'power-course'),
					tooltip: __(
						'When checked, customers can only purchase one of this item per order. Useful for limited items such as art pieces or handmade goods.',
						'power-course'
					),
				}}
			/>
		</>
	)
}

export default StockFields
