import { __ } from '@wordpress/i18n'

export const keyLabelMapper = (key: string | number | symbol): string => {
	switch (key) {
		case 'avl_course_ids':
			return __('Granted specific courses', 'power-course')
		default:
			return key as string
	}
}
