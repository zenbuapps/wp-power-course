import { __ } from '@wordpress/i18n'

export const getASStatus = (status: string) => {
	switch (status) {
		case 'pending':
			return {
				label: __('Scheduled', 'power-course'),
				color: 'volcano',
			}
		case 'complete':
			return {
				label: __('Completed', 'power-course'),
				color: '#87d068',
			}
		default:
			return {
				label: status,
				color: 'default',
			}
	}
}
