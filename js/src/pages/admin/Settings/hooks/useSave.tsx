import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { FormInstance, message } from 'antd'
import { useCallback } from 'react'

const useSave = ({ form }: { form: FormInstance }) => {
	const apiUrl = useApiUrl('power-course')
	const mutation = useCustomMutation()
	const invalidate = useInvalidate()
	const { mutate } = mutation

	const handleSave = useCallback(() => {
		message.loading({
			content: '儲存中...',
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
							content: '儲存成功',
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
							content: '儲存失敗',
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
