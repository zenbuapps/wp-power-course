import { List, useTable } from '@refinedev/antd'
import { HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Table, TableProps } from 'antd'
import { useState } from 'react'

import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { DeletePassModal } from '@/pages/admin/AccessPasses/components'
import useColumns from '@/pages/admin/AccessPasses/List/hooks/useColumns'
import { TAccessPassRecord } from '@/pages/admin/AccessPasses/types'

/**
 * 課程權限包列表頁（Issue #252）
 *
 * 以 Refine `<List>` + `useTable` 呈現所有權限包（含 active / disabled），
 * 提供新增、編輯、停用、刪除（含「將影響 N 位已購用戶」確認 Modal）。
 */
const AccessPassesList = () => {
	// 待刪除的權限包（null 時 Modal 關閉）
	const [deletingRecord, setDeletingRecord] =
		useState<TAccessPassRecord | null>(null)

	const { tableProps } = useTable<TAccessPassRecord, HttpError>({
		resource: 'access-passes',
		dataProviderName: 'power-course',
		// 後端 list 一次回傳全部（含 disabled），不分頁
		pagination: { mode: 'off' },
	})

	const columns = useColumns({ onDelete: setDeletingRecord })

	return (
		<List title={__('Access passes', 'power-course')}>
			<Table
				{...(defaultTableProps as unknown as TableProps<TAccessPassRecord>)}
				{...tableProps}
				pagination={{
					...tableProps.pagination,
					...getDefaultPaginationProps({
						label: __('Access passes', 'power-course'),
					}),
				}}
				columns={columns}
				rowKey={(record) => record.id.toString()}
			/>
			<DeletePassModal
				record={deletingRecord}
				onClose={() => setDeletingRecord(null)}
			/>
		</List>
	)
}

export default AccessPassesList
