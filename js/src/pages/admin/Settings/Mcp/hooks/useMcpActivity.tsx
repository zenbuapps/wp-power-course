import { useCustom, useApiUrl } from '@refinedev/core'

import { TMcpActivity } from '@/types/mcp'

type TActivityResponse = {
	code: string
	data: TMcpActivity[]
	total: number
	message: string
}

type TUseMcpActivityParams = {
	page?: number
	pageSize?: number
	toolName?: string
}

/**
 * 取得 MCP Activity Log（AI 操作紀錄）
 *
 * 對應 GET /power-course/v2/mcp/activity
 */
export const useMcpActivity = ({
	page = 1,
	pageSize = 20,
	toolName,
}: TUseMcpActivityParams = {}) => {
	const apiUrl = useApiUrl('power-course')

	const query: Record<string, string | number> = {
		page,
		per_page: pageSize,
	}
	if (toolName) {
		query.tool_name = toolName
	}

	const queryResult = useCustom<TActivityResponse>({
		url: `${apiUrl}/mcp/activity`,
		method: 'get',
		config: {
			query,
		},
		queryOptions: {
			queryKey: ['mcp-activity', page, pageSize, toolName ?? ''],
		},
	})

	const items: TMcpActivity[] = queryResult.data?.data?.data ?? []
	const total: number = queryResult.data?.data?.total ?? 0

	return {
		items,
		total,
		isLoading: queryResult.isLoading,
		isFetching: queryResult.isFetching,
		refetch: queryResult.refetch,
	}
}
