import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { FormInstance, message } from 'antd'
import { useCallback } from 'react'

const useSave = ({ form }: { form: FormInstance }) => {
	const apiUrl = useApiUrl('power-course')
	const mutation = useCustomMutation()
	const invalidate = useInvalidate()
	const { mutate } = mutation

	const handleSave = useCallback(() => {
		message.loading({
			content: __('Saving...', 'power-course'),
			duration: 0,
			key: 'save',
		})
		form.validateFields().then((values) => {
			mutate(
				{
					url: `${apiUrl}/settings`,
					method: 'post',
					values,
				},
				{
					onSuccess: () => {
						message.success({
							content: __('Saved successfully', 'power-course'),
							key: 'save',
						})

						// 刷新頁面
						window.location.reload()

						// 使所有的query cache失效
						// invalidate({
						// 	invalidates: ['all'],
						// })
					},
					onError: () => {
						message.error({
							content: __('Failed to save', 'power-course'),
							key: 'save',
						})
					},
				}
			)
		})
	}, [form])

	return {
		handleSave,
		mutation,
	}
}

export default useSave
