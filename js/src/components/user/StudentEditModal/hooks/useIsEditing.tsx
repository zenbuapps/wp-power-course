import { createContext, useContext } from 'react'

/**
 * 編輯模式 Context
 *
 * 由 StudentEditModal 主體控制 view↔edit 狀態並注入，供 Detail 各子區塊判斷
 * 該以唯讀檢視或編輯表單呈現。
 */
export const IsEditingContext = createContext<boolean>(false)

/**
 * 取得當前是否處於編輯模式
 */
export const useIsEditing = () => {
	const isEditing = useContext(IsEditingContext)
	return isEditing
}
