import { __ } from '@wordpress/i18n'

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
