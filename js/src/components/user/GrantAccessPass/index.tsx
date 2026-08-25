import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Select, Button, Space, message } from 'antd'
import { memo, useState } from 'react'

import { useAccessPassOptions } from '@/pages/admin/AccessPasses/hooks'

/** POST /access-passes/{id}/grant 的回應 */
type TGrantResponse = {
	granted_ids?: number[]
	granted_count?: number
	failed?: { user_id: number; message: string }[]
}

/**
 * 批次把課程通行證手動發放給選定學員
 *
 * 到期日一律由後端依通行證的 limit_type 自動計算，所以這裡**沒有** DatePicker——
 * 期限是通行證的屬性，不是發放動作的屬性（與 GrantCourseAccess 的差異）。
 *
 * 跟隨訂閱（follow_subscription）的通行證在選單中停用：它的到期表達式必須綁定一張
 * 真實訂閱，手動發放沒有訂單可推導，發了會立刻失效（後端 `Grant::assert_grantable`
 * 也會擋，這裡先擋是為了讓站主當下就看懂原因，而不是送出後才吃一個錯誤）。
 */
const GrantAccessPassComponent = ({
	user_ids,
	label,
	onSuccess,
}: {
	user_ids: string[]
	label?: string
	onSuccess?: () => void
}) => {
	const { records, isLoading: isLoadingPasses } = useAccessPassOptions()
	const [passId, setPassId] = useState<number | undefined>(undefined)

	const { mutate, isLoading } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	const options = records.map((pass) => {
		const isFollowSubscription = 'follow_subscription' === pass.limit_type
		return {
			label: isFollowSubscription
				? sprintf(
						/* translators: %s: 通行證名稱 */
						__(
							'%s (follows subscription, cannot be granted manually)',
							'power-course'
						),
						pass.name
					)
				: pass.name,
			value: pass.id,
			disabled: isFollowSubscription,
		}
	})

	const handleClick = () => {
		if (!passId || !user_ids.length) {
			return
		}

		mutate(
			{
				url: `${apiUrl}/access-passes/${passId}/grant`,
				method: 'post',
				values: { user_ids },
			},
			{
				onSuccess: (response) => {
					const result = (response?.data ?? {}) as TGrantResponse
					const failedCount = result.failed?.length ?? 0

					if (failedCount > 0) {
						message.warning({
							content: sprintf(
								/* translators: 1: 成功筆數, 2: 失敗筆數 */
								__('%1$d granted, %2$d failed', 'power-course'),
								result.granted_count ?? 0,
								failedCount
							),
							key: 'grant-access-pass',
						})
					} else {
						message.success({
							content: __('Access pass granted', 'power-course'),
							key: 'grant-access-pass',
						})
					}

					invalidate({
						resource: 'access-passes',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					onSuccess?.()
				},
				onError: () => {
					message.error({
						content: __('Failed to grant access pass', 'power-course'),
						key: 'grant-access-pass',
					})
				},
			}
		)
	}

	return (
		<>
			{label && <label className="tw-block mb-2">{label}</label>}
			<Space.Compact className="w-full">
				<Select
					className="w-full"
					placeholder={__('Select an access pass', 'power-course')}
					loading={isLoadingPasses}
					options={options}
					value={passId}
					onChange={setPassId}
					allowClear
					showSearch
					optionFilterProp="label"
				/>
				<Button
					type="primary"
					loading={isLoading}
					disabled={!user_ids.length || !passId}
					onClick={handleClick}
				>
					{__('Grant access pass', 'power-course')}
				</Button>
			</Space.Compact>
		</>
	)
}

export const GrantAccessPass = memo(GrantAccessPassComponent)
