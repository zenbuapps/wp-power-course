import {
	useCustom,
	useApiUrl,
	useCustomMutation,
	useInvalidate,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { message } from 'antd'
import { useCallback } from 'react'

import { TMcpToken, TMcpTokenCreateResponse } from '@/types/mcp'

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

/** 建立 Token 時送出的欄位（Issue #230） */
export type TCreateTokenValues = {
	name: string
	/** 有效天數；不帶＝永不過期 */
	expires_days?: number
}

const TOKEN_QUERY_KEY = 'mcp-tokens'

/**
 * 取得 MCP Token 列表（只含當前登入管理員自己的，Issue #230）
 *
 * 對應 GET /power-course/mcp/tokens
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
 * 建立 MCP Token（Issue #230）
 *
 * 對應 POST /power-course/mcp/tokens
 * 回傳包含明文 token（僅此一次）。
 */
export const useCreateMcpToken = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation<TTokenCreateApiResponse>()
	const invalidate = useInvalidate()

	const create = useCallback(
		(
			values: TCreateTokenValues,
			onSuccess?: (response: TMcpTokenCreateResponse) => void
		) => {
			message.loading({
				content: __('Creating token…', 'power-course'),
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
							content: __('Token created', 'power-course'),
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
							content: __(
								'Failed to create token, please try again',
								'power-course'
							),
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
 * 撤銷（刪除）MCP Token（Issue #230）
 *
 * 對應 DELETE /power-course/mcp/tokens/{id}
 */
export const useRevokeMcpToken = () => {
	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading } = useCustomMutation()
	const invalidate = useInvalidate()

	const revoke = useCallback(
		(id: number, onSuccess?: () => void) => {
			message.loading({
				content: __('Revoking…', 'power-course'),
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
							content: __('Token revoked', 'power-course'),
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
							content: __(
								'Failed to revoke token, please try again',
								'power-course'
							),
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
