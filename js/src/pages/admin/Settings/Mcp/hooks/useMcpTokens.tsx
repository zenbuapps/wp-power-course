import {
	useCustom,
	useApiUrl,
	useCustomMutation,
	useInvalidate,
} from '@refinedev/core'
import { message } from 'antd'
import { useCallback } from 'react'

import { TMcpToken, TMcpTokenCreateResponse, TMcpCategory } from '@/types/mcp'

type TTokensResponse = {
	code: string
	data: TMcpToken[]
	message: string
}

type TTokenCreateApiResponse = {
	code: string
	data: TMcpTokenCreateResponse
	message: string
}

const TOKEN_QUERY_KEY = 'mcp-tokens'

/**
 * 取得 MCP Token 列表
 *
 * 對應 GET /power-course/v2/mcp/tokens
 */
export const useMcpTokens = () => {
	const apiUrl = useApiUrl('power-course')
	const queryResult = useCustom<TTokensResponse>({
		url: `${apiUrl}/mcp/tokens`,
		method: 'get',
		queryOptions: {
			queryKey: [TOKEN_QUERY_KEY],
		},
	})

	const tokens: TMcpToken[] = queryResult.data?.data?.data ?? []

	return {
		tokens,
		isLoading: queryResult.isLoading,
		isFetching: queryResult.isFetching,
		refetch: queryResult.refetch,
	}
}

/**
 * 建立 MCP Token
 *
 * 對應 POST /power-course/v2/mcp/tokens
 * 回傳包含 plaintext_token（只顯示一次）
 */
export const useCreateMcpToken = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation<TTokenCreateApiResponse>()
	const invalidate = useInvalidate()

	const create = useCallback(
		(
			values: { name: string; capabilities: TMcpCategory[] },
			onSuccess?: (response: TMcpTokenCreateResponse) => void
		) => {
			message.loading({
				content: '建立 Token 中...',
				duration: 0,
				key: 'create-mcp-token',
			})
			mutate(
				{
					url: `${apiUrl}/mcp/tokens`,
					method: 'post',
					values,
				},
				{
					onSuccess: (response) => {
						message.success({
							content: 'Token 建立成功',
							key: 'create-mcp-token',
						})
						invalidate({
							dataProviderName: 'power-course',
							invalidates: ['all'],
						})
						const payload = response?.data?.data
						if (payload) {
							onSuccess?.(payload)
						}
					},
					onError: () => {
						message.error({
							content: '建立失敗，請稍後再試',
							key: 'create-mcp-token',
						})
					},
				}
			)
		},
		[apiUrl, mutate, invalidate]
	)

	return { create, isLoading }
}

/**
 * 撤銷（刪除）MCP Token
 *
 * 對應 DELETE /power-course/v2/mcp/tokens/{id}
 */
export const useRevokeMcpToken = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation()
	const invalidate = useInvalidate()

	const revoke = useCallback(
		(id: number, onSuccess?: () => void) => {
			message.loading({
				content: '撤銷中...',
				duration: 0,
				key: `revoke-mcp-token-${id}`,
			})
			mutate(
				{
					url: `${apiUrl}/mcp/tokens/${id}`,
					method: 'delete',
					values: {},
				},
				{
					onSuccess: () => {
						message.success({
							content: 'Token 已撤銷',
							key: `revoke-mcp-token-${id}`,
						})
						invalidate({
							dataProviderName: 'power-course',
							invalidates: ['all'],
						})
						onSuccess?.()
					},
					onError: () => {
						message.error({
							content: '撤銷失敗，請稍後再試',
							key: `revoke-mcp-token-${id}`,
						})
					},
				}
			)
		},
		[apiUrl, mutate, invalidate]
	)

	return { revoke, isLoading }
}
