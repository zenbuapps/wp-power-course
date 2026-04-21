import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Button, Modal, Input, message } from 'antd'
import { useSetAtom } from 'jotai'
import React, { FC, useState } from 'react'

import { selectedTeacherIdsAtom } from '../../atom'

/**
 * 二次確認用的 CONFIRM_WORD（必須與 Modal 內的提示文字一致）
 *
 * 使用者必須輸入此字串才會啟用「確定」按鈕，防止誤操作。
 */
const getConfirmWord = () =>
	__('I am sure I want to remove these instructor roles', 'power-course')

type TRemoveTeachersResponse = {
	code: string
	message: string
	data: {
		user_ids: string[]
		failed_user_ids: string[]
	}
}

/**
 * 批次移除講師身分（含二次確認 Modal）
 *
 * 對接自建 `/power-course/v2/users/remove-teachers`，支援 partial failure。
 * 若 response 有 failed_user_ids，改為 warning 提示部分成功。
 */
export const RemoveRoleButton: FC<{
	user_ids: string[]
}> = ({ user_ids }) => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const setSelectedTeacherIds = useSetAtom(selectedTeacherIdsAtom)
	const { mutate, isLoading } = useCustomMutation<TRemoveTeachersResponse>()
	const [open, setOpen] = useState(false)
	const [confirmText, setConfirmText] = useState('')

	const confirmWord = getConfirmWord()
	const confirmMatched = confirmText === confirmWord

	const handleCancel = () => {
		setOpen(false)
		setConfirmText('')
	}

	const handleOk = () => {
		mutate(
			{
				url: `${apiUrl}/users/remove-teachers`,
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
				onSuccess: (response) => {
					const data = response?.data?.data
					const successIds = data?.user_ids ?? []
					const failedIds = data?.failed_user_ids ?? []

					if (failedIds.length > 0) {
						message.warning({
							content: sprintf(
								// translators: 1: 成功數, 2: 失敗數
								__('%1$d succeeded, %2$d failed', 'power-course'),
								successIds.length,
								failedIds.length
							),
							key: 'remove-teachers',
						})
					} else {
						message.success({
							content: sprintf(
								// translators: %d: 已移除講師身分的用戶數
								__(
									'Successfully removed instructor role for %d users',
									'power-course'
								),
								successIds.length
							),
							key: 'remove-teachers',
						})
					}

					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
					setSelectedTeacherIds([])
					handleCancel()
				},
				onError: () => {
					message.error({
						content: __('Failed to remove instructor role', 'power-course'),
						key: 'remove-teachers',
					})
				},
			}
		)
	}

	return (
		<>
			<Button danger disabled={!user_ids.length} onClick={() => setOpen(true)}>
				{__('Remove instructor role', 'power-course')}
			</Button>
			<Modal
				title={sprintf(
					// translators: %d: 欲移除講師身分的用戶數
					__(
						'Confirm to remove instructor role for %d teachers',
						'power-course'
					),
					user_ids.length
				)}
				open={open}
				onCancel={handleCancel}
				onOk={handleOk}
				okText={__('Remove instructor role', 'power-course')}
				cancelText={__('Cancel', 'power-course')}
				okButtonProps={{
					danger: true,
					disabled: !confirmMatched,
					loading: isLoading,
				}}
				destroyOnClose
			>
				<p>
					{__(
						'This action will remove the is_teacher meta from selected users.',
						'power-course'
					)}
				</p>
				<p className="text-red-500 font-mono">{confirmWord}</p>
				<Input
					value={confirmText}
					onChange={(e) => setConfirmText(e.target.value)}
					placeholder={__(
						'Please type the confirmation text above',
						'power-course'
					)}
				/>
			</Modal>
		</>
	)
}
