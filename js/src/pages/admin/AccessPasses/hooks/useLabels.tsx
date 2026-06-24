import { __, sprintf } from '@wordpress/i18n'

import {
	TScopeType,
	TLimitMode,
	TLimitUnit,
	TAccessPassStatus,
} from '@/pages/admin/AccessPasses/types'

/**
 * 課程權限包各列舉值的顯示標籤工具 Hook
 *
 * 集中管理 scope_type / limit_mode / status 與限時單位的 i18n 文案與顏色，
 * 供 List 欄位渲染與表單共用，避免文案分散。
 */
export const useLabels = () => {
	/** 範圍類型標籤 */
	const getScopeLabel = (scopeType: TScopeType): string => {
		switch (scopeType) {
			case 'all':
				return __('All courses', 'power-course')
			case 'category':
				return __('Category / Tag', 'power-course')
			case 'specific':
				return __('Specific courses', 'power-course')
			default:
				return scopeType
		}
	}

	/** 限時單位標籤 */
	const getLimitUnitLabel = (unit: TLimitUnit | null): string => {
		switch (unit) {
			case 'day':
				return __('day', 'power-course')
			case 'month':
				return __('month', 'power-course')
			case 'year':
				return __('year', 'power-course')
			default:
				return ''
		}
	}

	/** 期限模式標籤（limited 時帶入數值與單位） */
	const getLimitLabel = (
		limitMode: TLimitMode,
		limitValue: number | null,
		limitUnit: TLimitUnit | null
	): string => {
		switch (limitMode) {
			case 'permanent':
				return __('Permanent', 'power-course')
			case 'follow_subscription':
				return __('Follow subscription', 'power-course')
			case 'limited':
				return sprintf(
					// translators: 1: 期限數值, 2: 期限單位（日/月/年）
					__('%1$s %2$s after purchase', 'power-course'),
					String(limitValue ?? ''),
					getLimitUnitLabel(limitUnit)
				)
			default:
				return limitMode
		}
	}

	/** 狀態標籤與顏色 */
	const getStatusLabel = (
		status: TAccessPassStatus
	): { label: string; color: string } => {
		if ('disabled' === status) {
			return { label: __('Disabled', 'power-course'), color: 'default' }
		}
		return { label: __('Active', 'power-course'), color: 'green' }
	}

	return { getScopeLabel, getLimitUnitLabel, getLimitLabel, getStatusLabel }
}
