import { __ } from '@wordpress/i18n'

/**
 * Filter tag 顯示用的 key → label 映射
 *
 * FilterTags 會把 Form 的 fieldName 當 key，透過此 function 轉成
 * 人類可讀的標籤。
 */
export const keyLabelMapper = (key: string | number | symbol): string => {
	switch (key) {
		case 'search':
			return __('Keyword search', 'power-course')
		case 'is_teacher':
			return __('Instructor', 'power-course')
		case 'role__in':
			return __('Role', 'power-course')
		case 'teacher_course_id':
			return __('Taught courses', 'power-course')
		case 'include':
			return __('Include specific users', 'power-course')
		default:
			return key as string
	}
}
