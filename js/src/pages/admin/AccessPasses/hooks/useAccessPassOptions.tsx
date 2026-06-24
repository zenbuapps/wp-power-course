import { useList } from '@refinedev/core'

import { TAccessPassRecord } from '@/pages/admin/AccessPasses/types'

type TAccessPassOption = {
	label: string
	value: number
}

/**
 * 取得「啟用中」課程權限包選項的 Hook
 *
 * 供商品掛載 Select 使用（停用的權限包不可掛新商品，故只取 status=active）。
 * 後端 `GET /access-passes?status=active` 回傳已過濾的清單。
 *
 * @return options（label=名稱、value=id）與 isLoading
 */
export const useAccessPassOptions = (): {
	options: TAccessPassOption[]
	isLoading: boolean
} => {
	const { data, isLoading } = useList<TAccessPassRecord>({
		resource: 'access-passes',
		dataProviderName: 'power-course',
		pagination: { mode: 'off' },
		filters: [
			{
				field: 'status',
				operator: 'eq',
				value: 'active',
			},
		],
	})

	const options: TAccessPassOption[] = (data?.data ?? []).map((pass) => ({
		label: pass.name,
		value: pass.id,
	}))

	return { options, isLoading }
}
