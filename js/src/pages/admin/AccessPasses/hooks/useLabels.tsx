import { __, _x, sprintf } from '@wordpress/i18n'
import dayjs from 'dayjs'

import {
	TScopeType,
	TLimitType,
	TLimitUnit,
	TAccessPassStatus,
} from '@/pages/admin/AccessPasses/types'

/**
 * 課程權限包各列舉值的顯示標籤工具 Hook
 *
 * 集中管理 scope_type / limit_type / status 與限時單位的 i18n 文案與顏色，
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

	/** 限時單位標籤（timestamp 不顯示單位，回空字串） */
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

	/**
	 * 期限類型標籤
	 * - unlimited：無限制
	 * - fixed：購買後 N 日/月/年
	 * - assigned：到期日期（limit_value 為 Unix 秒級 timestamp，格式化為 YYYY-MM-DD HH:mm）
	 * - follow_subscription：跟隨訂閱
	 */
	const getLimitLabel = (
		limitType: TLimitType,
		limitValue: number | null,
		limitUnit: TLimitUnit | null
	): string => {
		switch (limitType) {
			case 'unlimited':
				return __('Unlimited', 'power-course')
			case 'follow_subscription':
				return __('Follow subscription', 'power-course')
			case 'fixed':
				return sprintf(
					// translators: 1: 期限數值, 2: 期限單位（日/月/年）
					__('%1$s %2$s after purchase', 'power-course'),
					String(limitValue ?? ''),
					getLimitUnitLabel(limitUnit)
				)
			case 'assigned':
				return sprintf(
					// translators: %s: 到期日期時間（YYYY-MM-DD HH:mm）
					__('Expires on %s', 'power-course'),
					// limit_value 為 Unix 秒級 timestamp，乘 1000 轉毫秒給 dayjs
					limitValue ? dayjs(limitValue * 1000).format('YYYY-MM-DD HH:mm') : ''
				)
			default:
				return limitType
		}
	}

	/** 狀態標籤與顏色 */
	const getStatusLabel = (
		status: TAccessPassStatus
	): { label: string; color: string } => {
		if ('disabled' === status) {
			return { label: __('Disabled', 'power-course'), color: 'default' }
		}
		return {
			label: _x('Active', 'access pass status', 'power-course'),
			color: 'green',
		}
	}

	return { getScopeLabel, getLimitUnitLabel, getLimitLabel, getStatusLabel }
}
