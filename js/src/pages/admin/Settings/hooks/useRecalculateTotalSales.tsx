import { useCustomMutation, useApiUrl } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'
import { useCallback } from 'react'

/** message 提示共用的 key，確保 loading / success / error 互相覆蓋 */
const MESSAGE_KEY = 'recalculate-total-sales'

/**
 * 重新計算課程已售出數量的 Hook
 * 封裝對 `power-course` REST 端點 `courses/recalculate-total-sales` 的 POST 請求，
 * 並處理 loading / success / error 的訊息提示。
 */
const useRecalculateTotalSales = () => {
	const apiUrl = useApiUrl('power-course')
	const mutation = useCustomMutation()
	const { mutate, isLoading } = mutation

	const handleRecalculate = useCallback(() => {
		message.loading({
			content: __('Recalculating...', 'power-course'),
			duration: 0,
			key: MESSAGE_KEY,
		})

		mutate(
			{
				url: `${apiUrl}/courses/recalculate-total-sales`,
				method: 'post',
				values: {},
			},
			{
				onSuccess: () => {
					message.success({
						content: __(
							'Recalculation scheduled, processing in the background',
							'power-course'
						),
						key: MESSAGE_KEY,
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to recalculate total sales', 'power-course'),
						key: MESSAGE_KEY,
					})
				},
			}
		)
	}, [apiUrl, mutate])

	return {
		handleRecalculate,
		isLoading,
	}
}

export default useRecalculateTotalSales
