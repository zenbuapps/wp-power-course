import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Button, message } from 'antd'
import React, { FC } from 'react'

/**
 * 批次傳送密碼重設連結
 *
 * 對接 Powerhouse 預設 dataProvider 的 `/users/resetpassword` endpoint（V2Api:61）。
 * 後端會逐一對 user_ids 呼叫 retrieve_password() 發信。
 */
export const ResetPassButton: FC<{
	user_ids: string[]
	mode?: 'single' | 'multiple'
}> = ({ user_ids, mode = 'multiple' }) => {
	const apiUrl = useApiUrl()
	const invalidate = useInvalidate()
	const { mutate, isLoading } = useCustomMutation()

	const handleClick = () => {
		mutate(
			{
				url: `${apiUrl}/users/resetpassword`,
				method: 'post',
				values: {
					user_ids,
				},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __(
							'Password reset links sent successfully',
							'power-course'
						),
						key: 'reset-pass',
					})
					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to send password reset links', 'power-course'),
						key: 'reset-pass',
					})
				},
			}
		)
	}

	const label =
		mode === 'multiple'
			? sprintf(
					// translators: %s: 已選取用戶數
					__('Send password reset links (%s)', 'power-course'),
					user_ids.length
				)
			: __('Send password reset link', 'power-course')

	return (
		<Button
			onClick={handleClick}
			disabled={!user_ids.length}
			loading={isLoading}
		>
			{label}
		</Button>
	)
}
