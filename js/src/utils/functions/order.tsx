import { __ } from '@wordpress/i18n'

export const getOrderStatus = (status: string) => {
	const rmPrefixStatus = status.replace('wc-', '')
	switch (rmPrefixStatus) {
		case 'processing':
			return {
				label: __('Processing', 'power-course'),
				color: '#108ee9',
			}
		case 'pending':
			return {
				label: __('Pending payment', 'power-course'),
				color: 'volcano',
			}
		case 'wmp-in-transit':
			return {
				label: __('In transit', 'power-course'),
				color: '#2db7f5',
			}
		case 'wmp-shipped':
			return {
				label: __('Shipped', 'power-course'),
				color: 'green',
			}
		case 'on-hold':
			return {
				label: __('On hold', 'power-course'),
				color: 'gold',
			}
		case 'completed':
			return {
				label: __('Completed', 'power-course'),
				color: '#87d068',
			}
		case 'cancelled':
			return {
				label: __('Cancelled', 'power-course'),
				color: 'orange',
			}
		case 'refunded':
			return {
				label: __('Refunded', 'power-course'),
				color: 'volcano',
			}
		case 'failed':
			return {
				label: __('Failed', 'power-course'),
				color: 'magenta',
			}
		case 'checkout-draft':
			return {
				label: __('Checkout draft', 'power-course'),
				color: 'gold',
			}
		case 'ry-at-cvs':
			return {
				label: __('RY waiting for pickup', 'power-course'),
				color: 'cyan',
			}
		case 'ry-out-cvs':
			return {
				label: __('RY order expired', 'power-course'),
				color: 'purple',
			}

		default:
			return {
				label: status,
				color: 'default',
			}
	}
}
