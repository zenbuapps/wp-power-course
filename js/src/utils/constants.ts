import { __, _x } from '@wordpress/i18n'

export const backordersOptions = [
	{ label: __('Not allowed', 'power-course'), value: 'no' },
	{ label: __('Allowed', 'power-course'), value: 'yes' },
	{
		label: __('Allowed only when out of stock', 'power-course'),
		value: 'notify',
	},
]
export const stockStatusOptions = [
	{ label: __('In stock', 'power-course'), value: 'instock' },
	{ label: __('Out of stock', 'power-course'), value: 'outofstock' },
	{ label: __('On backorder', 'power-course'), value: 'onbackorder' },
]

export const statusOptions = [
	{ label: __('Published', 'power-course'), value: 'publish' },
	{ label: __('Pending review', 'power-course'), value: 'pending' },
	{ label: __('Draft', 'power-course'), value: 'draft' },
	{ label: __('Private', 'power-course'), value: 'private' },
	// Issue #256：排程中（future）——沿用課程公告的藍色 scheduled 用語，帶 'post status' context
	{ label: _x('Scheduled', 'post status', 'power-course'), value: 'future' },
]

/**
 * used in WooCommerce wc_get_products() PHP function
 */

export const dateRelatedFields = [
	{
		label: __('Product created date', 'power-course'),
		value: 'date_created',
	},
	{
		label: __('Product modified date', 'power-course'),
		value: 'date_modified',
	},
	{
		label: __('Sale start date', 'power-course'),
		value: 'date_on_sale_from',
	},
	{
		label: __('Sale end date', 'power-course'),
		value: 'date_on_sale_to',
	},
]

/**
 * 帳單 / 運送資訊欄位的 label 對照表
 *
 * 用於 InfoTable / Basic 編輯模式下，於欄位左側顯示在地化欄位名稱。
 */
export const INFO_LABEL_MAPPER: Record<string, string> = {
	first_name: __('First name', 'power-course'),
	last_name: __('Last name', 'power-course'),
	postcode: __('Postcode', 'power-course'),
	country: __('Country', 'power-course'),
	state: __('State', 'power-course'),
	city: __('City', 'power-course'),
	address_1: __('Address line 1', 'power-course'),
	address_2: __('Address line 2', 'power-course'),
}

export const productTypes = [
	{
		value: 'simple',
		label: __('Simple product', 'power-course'),
		color: 'processing', // 藍色
	},
	{
		value: 'grouped',
		label: __('Grouped product', 'power-course'),
		color: 'orange', // 綠色
	},
	{
		value: 'external',
		label: __('External product', 'power-course'),
		color: 'lime', // 橘色
	},
	{
		value: 'variable',
		label: __('Variable product', 'power-course'),
		color: 'magenta', // 紅色
	},
	{
		value: 'variation',
		label: __('Product variation', 'power-course'),
		color: 'magenta', // 紅色
	},
	{
		value: 'subscription',
		label: __('Simple subscription', 'power-course'),
		color: 'cyan', // 紫色
	},
	{
		value: 'variable-subscription',
		label: __('Variable subscription', 'power-course'),
		color: 'purple', // 青色
	},
	{
		value: 'subscription_variation',
		label: __('Subscription variation', 'power-course'),
		color: 'purple',
	},
]
