import { useCustomMutation, useApiUrl } from '@refinedev/core'

/**
 * Issue #235：呼叫 POST /power-course/v2/courses/{id} 切換課程類型
 * （帶 confirm_type_change=true 旗標，後端才會處理 WC_Product class 切換）
 */
const useTypeChange = (courseId: string | number) => {
	const apiUrl = useApiUrl('power-course')
	const mutation = useCustomMutation()

	const switchType = (
		targetType: 'simple' | 'external',
		options?: {
			onSuccess?: () => void
			onError?: () => void
		}
	) => {
		mutation.mutate(
			{
				url: `${apiUrl}/courses/${courseId}`,
				method: 'post',
				values: {
					type: targetType,
					confirm_type_change: true,
				},
			},
			{
				onSuccess: () => {
					options?.onSuccess?.()
				},
				onError: () => {
					options?.onError?.()
				},
			}
		)
	}

	return {
		switchType,
		isMutating: mutation.isLoading,
	}
}

export default useTypeChange
