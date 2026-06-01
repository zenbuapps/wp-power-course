import { createContext, useContext } from 'react'

import { TUserDetail } from '@/components/user/types'

/**
 * 學員詳細資料 Context
 *
 * 由 StudentEditModal 主體載入 GET users/{id} 後注入，供 Detail 各子區塊
 * （Basic / AutoFill / Meta / Cart / RecentOrders）透過 useRecord() 取用，
 * 避免逐層 props 傳遞。
 */
export const RecordContext = createContext<TUserDetail | undefined>(undefined)

/**
 * 取得當前 Modal 載入的學員詳細資料
 *
 * 尚未載入時回傳空物件（以 TUserDetail 轉型），讓子元件可安全解構。
 */
export const useRecord = () => {
	const record = useContext(RecordContext)
	return (record || {}) as TUserDetail
}
