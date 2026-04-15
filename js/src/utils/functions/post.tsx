import { __ } from '@wordpress/i18n'

export const getPostStatus = (status: string) => {
	switch (status) {
		case 'pending':
			return {
				label: __('Pending review', 'power-course'),
				color: 'volcano',
			}
		case 'draft':
			return {
				label: __('Draft', 'power-course'),
				color: 'orange',
			}
		case 'publish':
			return {
				label: __('Published', 'power-course'),
				color: 'blue',
			}

		default:
			return {
				label: status,
				color: 'default',
			}
	}
}
