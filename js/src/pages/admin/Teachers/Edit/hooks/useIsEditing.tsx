import { useContext, createContext } from 'react'

/**
 * 講師 Edit 頁的 Editing 狀態 Context
 *
 * 由上層 <Edit> 管理 view / edit 兩種模式，底下的 Tab 透過
 * useIsEditing() 取得 boolean，決定欄位顯示為 read-only 還是 <Input>。
 */
export const IsEditingContext = createContext<boolean>(false)

export const useIsEditing = () => {
	const isEditing = useContext(IsEditingContext)
	return isEditing
}
