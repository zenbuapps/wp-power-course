import { atom } from 'jotai'

import {
	defaultHistoryDrawerProps,
	THistoryDrawerProps,
} from './HistoryDrawer/types'

export const selectedUserIdsAtom = atom<string[]>([])

export const historyDrawerAtom = atom<THistoryDrawerProps>(
	defaultHistoryDrawerProps
)

/**
 * 學員快速編輯 Modal 的狀態 atom
 * - user_id：當前編輯的使用者 ID
 * - open：Modal 是否開啟
 */
export const studentEditModalAtom = atom<{ user_id?: string; open: boolean }>({
	open: false,
})
