import { useCustom, useApiUrl, useCustomMutation } from '@refinedev/core'
import { message } from 'antd'
import { useCallback } from 'react'

import { TMcpSettings } from '@/types/mcp'

type TSettingsResponse = {
	code: string
	data: TMcpSettings
	message: string
}

/**
 * 取得 MCP 整體設定
 *
 * 對應 GET /power-course/v2/mcp/settings
 */
export const useMcpSettings = () => {
	const apiUrl = useApiUrl('power-course')
	const queryResult = useCustom<TSettingsResponse>({
		url: `${apiUrl}/mcp/settings`,
		method: 'get',
	})

	const settings: TMcpSettings = queryResult.data?.data?.data ?? {
		enabled: false,
		enabled_categories: [],
		allow_update: false,
		allow_delete: false,
	}

	return {
		settings,
		isLoading: queryResult.isLoading,
		isFetching: queryResult.isFetching,
		refetch: queryResult.refetch,
	}
}

/**
 * 儲存 MCP 整體設定
 *
 * 對應 POST /power-course/v2/mcp/settings
 */
export const useSaveMcpSettings = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation()

	const save = useCallback(
		(values: TMcpSettings, onSuccess?: () => void) => {
			message.loading({
				content: '儲存中...',
				duration: 0,
				key: 'save-mcp-settings',
			})
			mutate(
				{
					url: `${apiUrl}/mcp/settings`,
					method: 'post',
					values,
				},
				{
					onSuccess: () => {
						message.success({
							content: 'MCP 設定已儲存',
							key: 'save-mcp-settings',
						})
						onSuccess?.()
					},
					onError: () => {
						message.error({
							content: '儲存失敗，請稍後再試',
							key: 'save-mcp-settings',
						})
					},
				}
			)
		},
		[apiUrl, mutate]
	)

	return { save, isLoading }
}
