import { atom } from 'jotai'

/**
 * 已選取講師 ID 的全域狀態（跨換頁保留）
 *
 * 對齊 components/user/UserTable/atom.tsx 的 selectedUserIdsAtom pattern，
 * 避免 Ant Table rowSelection 只存當頁 keys 的限制。
 */
export const selectedTeacherIdsAtom = atom<string[]>([])
