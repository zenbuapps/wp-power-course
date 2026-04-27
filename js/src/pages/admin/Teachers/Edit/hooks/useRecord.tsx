import { useContext, createContext } from 'react'

import { TTeacherDetails } from '@/components/teacher/types'

/**
 * 講師 Edit 頁的 Record Context
 *
 * 由上層 <Edit> 把 useForm query 拿到的 record 注入，
 * 底下的 Tab 透過 useRecord() 直接取得（避免 props drilling）。
 */
export const RecordContext = createContext<TTeacherDetails | undefined>(
	undefined
)

export const useRecord = () => {
	const record = useContext(RecordContext)
	return (record || {}) as TTeacherDetails
}
