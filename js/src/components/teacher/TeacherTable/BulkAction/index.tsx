import { Space } from 'antd'
import { useAtomValue } from 'jotai'
import React from 'react'

import { selectedTeacherIdsAtom } from '../atom'

import { RemoveRoleButton } from './RemoveRoleButton'
import { ResetPassButton } from './ResetPassButton'

/**
 * 講師列表批次操作區
 *
 * 包含兩顆按鈕：
 * 1. 傳送密碼重設連結（ResetPassButton）
 * 2. 移除講師身分（RemoveRoleButton，含二次確認 Modal）
 */
export const BulkAction = () => {
	const selectedTeacherIds = useAtomValue(selectedTeacherIdsAtom)

	return (
		<Space size="middle">
			<ResetPassButton user_ids={selectedTeacherIds} mode="multiple" />
			<RemoveRoleButton user_ids={selectedTeacherIds} />
		</Space>
	)
}
