import {
	useApiUrl,
	useCustom,
	useCustomMutation,
	useInvalidate,
	type BaseRecord,
	type HttpError,
} from '@refinedev/core'
import { useQueryClient } from '@tanstack/react-query'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'

import type { TEmailJsonHealthItem } from '@/pages/admin/Emails/types'

const DATA_PROVIDER_NAME = 'power-email'

/** 覆寫 Refine `useCustom` 的預設 key，修復後才能不經 props 傳遞 refetch 就重新檢查 */
const JSON_HEALTH_QUERY_KEY = ['pc-email-json-health'] as const

/**
 * 取得內容無法解析的郵件模板與修復預覽
 *
 * 清單帶有預覽當下的 fingerprint，不吃全域 10 分鐘 staleTime，避免一進頁面就拿舊指紋修復而收到 409。
 */
export const useEmailJsonHealth = () => {
	const apiUrl = useApiUrl(DATA_PROVIDER_NAME)

	const query = useCustom<TEmailJsonHealthItem[], HttpError>({
		url: `${apiUrl}/emails/json-health`,
		method: 'get',
		dataProviderName: DATA_PROVIDER_NAME,
		// 檢查失敗時畫面上不會有 Alert，要明確提示，否則看起來就像所有郵件都正常
		errorNotification: () => ({
			message: __(
				'Failed to check email templates for unparsable content',
				'power-course'
			),
			type: 'error',
		}),
		queryOptions: {
			queryKey: JSON_HEALTH_QUERY_KEY,
			staleTime: 0,
			// 本機站 REST 偶發逾時；Refine 全域 retry 為 0
			retry: 1,
			refetchOnWindowFocus: false,
		},
	})

	return {
		items: query.data?.data ?? [],
		isFetching: query.isFetching,
	}
}

/**
 * 修復單一郵件模板的內容
 *
 * @param id 郵件模板 ID
 */
export const useRepairEmailJson = (id: string) => {
	const apiUrl = useApiUrl(DATA_PROVIDER_NAME)
	const queryClient = useQueryClient()
	const invalidate = useInvalidate()

	const { mutate, isLoading } = useCustomMutation<
		BaseRecord,
		HttpError,
		{ fingerprint: string }
	>({
		mutationOptions: {
			// 寫在 mutation 層而非 mutate() 的 callback：請求途中點名稱前往編輯頁時元件已卸載，
			// mutate() 的 callback 不會執行，編輯頁就會讀到快取裡修復前的內容。
			// 失敗也要重新檢查：409 代表預覽已過期，要換上新的預覽與指紋
			onSettled: () => {
				void invalidate({
					resource: 'emails',
					dataProviderName: DATA_PROVIDER_NAME,
					invalidates: ['detail'],
					id,
				})
				void queryClient.invalidateQueries({
					queryKey: JSON_HEALTH_QUERY_KEY,
				})
			},
		},
	})

	/**
	 * 送出修復請求
	 *
	 * @param fingerprint 預覽當下取得的內容指紋
	 */
	const repair = (fingerprint: string) => {
		mutate(
			{
				url: `${apiUrl}/emails/${id}/repair-json`,
				method: 'post',
				values: { fingerprint },
				dataProviderName: DATA_PROVIDER_NAME,
				successNotification: false,
				errorNotification: false,
			},
			{
				onSuccess: () => {
					message.success(__('Email content repaired', 'power-course'))
				},
				onError: (error) => {
					message.error(
						error?.message ||
							__('Failed to repair the email content.', 'power-course')
					)
				},
			}
		)
	}

	return { repair, isRepairing: isLoading }
}
