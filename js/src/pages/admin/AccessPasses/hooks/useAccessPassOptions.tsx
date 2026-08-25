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
 * 同時回傳原始 records：手動發放的 Select 需要依 `limit_type` 判斷是否可選
 * （跟隨訂閱的通行證無法手動發放，見 `Grant::assert_grantable`），
 * 只有 label/value 的話呼叫端做不到這件事。
 *
 * @return options（label=名稱、value=id）、records（原始資料）與 isLoading
 */
export const useAccessPassOptions = (): {
	options: TAccessPassOption[]
	records: TAccessPassRecord[]
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

	const records: TAccessPassRecord[] = data?.data ?? []

	const options: TAccessPassOption[] = records.map((pass) => ({
		label: pass.name,
		value: pass.id,
	}))

	return { options, records, isLoading }
}
