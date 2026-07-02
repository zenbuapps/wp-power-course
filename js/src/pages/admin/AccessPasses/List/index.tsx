import { PlusOutlined } from '@ant-design/icons'
import { List, useTable } from '@refinedev/antd'
import { HttpError, useNavigation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Button, Card, Form, Table, TableProps } from 'antd'
import { FilterTags } from 'antd-toolkit/refine'
import { useCallback, useMemo, useState } from 'react'

import {
	getDefaultPaginationProps,
	defaultTableProps,
} from '@/components/product/ProductTable/utils'
import { DeletePassModal } from '@/pages/admin/AccessPasses/components'
import { useLabels } from '@/pages/admin/AccessPasses/hooks'
import {
	Filter,
	TPassFilterValues,
} from '@/pages/admin/AccessPasses/List/components'
import useColumns from '@/pages/admin/AccessPasses/List/hooks/useColumns'
import {
	TAccessPassRecord,
	TScopeType,
	TLimitType,
} from '@/pages/admin/AccessPasses/types'

/**
 * 課程通行證列表頁（Issue #252）
 *
 * 以 Refine `<List>` + `useTable` 呈現所有通行證（含 active / disabled），
 * 採「篩選卡 + 列表卡」兩卡版面（對齊課程列表頁）。
 * 後端 list 一次回傳全部且不支援名稱搜尋，故名稱 / 狀態 / 範圍類型 / 存取期間
 * 篩選一律 client-side 套用：Filter 與 FilterTags 共用同一 form instance，按鈕 /
 * 重設 / 移除 tag 皆透過 form.submit() 觸發 onFinish，統一更新 appliedFilters
 * （過濾真相來源）。
 */
const AccessPassesList = () => {
	const { create } = useNavigation()

	// 待刪除的通行證（null 時 Modal 關閉）
	const [deletingRecord, setDeletingRecord] =
		useState<TAccessPassRecord | null>(null)

	// 篩選 form（Filter 與 FilterTags 共用的唯一真相來源）
	const [form] = Form.useForm<TPassFilterValues>()

	// 已套用的篩選條件（client-side 過濾真相；各欄位未設＝全部）
	const [appliedFilters, setAppliedFilters] = useState<TPassFilterValues>({
		name: '',
		status: undefined,
		scope_type: undefined,
		limit_type: undefined,
	})

	const { tableProps } = useTable<TAccessPassRecord, HttpError>({
		resource: 'access-passes',
		dataProviderName: 'power-course',
		// 後端 list 一次回傳全部（含 disabled），不分頁
		pagination: { mode: 'off' },
	})

	const columns = useColumns({ onDelete: setDeletingRecord })

	const { getScopeLabel, getLimitTypeLabel, getStatusLabel } = useLabels()

	/** 套用篩選（綁定 Form onFinish；按鈕 / 重設 / 移除 tag 都會觸發） */
	const handleFilter = useCallback((values: TPassFilterValues) => {
		setAppliedFilters({
			name: values.name ?? '',
			status: values.status,
			scope_type: values.scope_type,
			limit_type: values.limit_type,
		})
	}, [])

	/** 對已載入的全部資料做名稱（substring）+ 狀態 / 範圍 / 期間衍生過濾 */
	const filteredDataSource = useMemo<TAccessPassRecord[]>(() => {
		const records = (tableProps.dataSource ?? []) as TAccessPassRecord[]
		const keyword = (appliedFilters.name ?? '').trim().toLowerCase()
		return records.filter((record) => {
			const matchName = keyword
				? record.name.toLowerCase().includes(keyword)
				: true
			const matchStatus =
				!appliedFilters.status || record.status === appliedFilters.status
			const matchScope =
				!appliedFilters.scope_type ||
				record.scope_type === appliedFilters.scope_type
			const matchLimit =
				!appliedFilters.limit_type ||
				record.limit_type === appliedFilters.limit_type
			return matchName && matchStatus && matchScope && matchLimit
		})
	}, [tableProps.dataSource, appliedFilters])

	/** FilterTags 欄位名 → 顯示標籤（重用既有 msgid） */
	const keyLabelMapper = (key: keyof TPassFilterValues): string => {
		switch (key) {
			case 'name':
				return __('Name', 'power-course')
			case 'status':
				return __('Status', 'power-course')
			case 'scope_type':
				return __('Scope type', 'power-course')
			case 'limit_type':
				return __('Access period', 'power-course')
			default:
				return String(key)
		}
	}

	/** FilterTags 欄位值 → 顯示文字（沿用 useLabels 大類文案） */
	const valueLabelMapper = (
		value: string,
		key?: keyof TPassFilterValues
	): string => {
		switch (key) {
			case 'status':
				return getStatusLabel('disabled' === value ? 'disabled' : 'active')
					.label
			case 'scope_type':
				return getScopeLabel(value as TScopeType)
			case 'limit_type':
				return getLimitTypeLabel(value as TLimitType)
			default:
				return value
		}
	}

	return (
		<List
			title={__('Access passes', 'power-course')}
			headerButtons={() => null}
		>
			<Card title={__('Filter', 'power-course')} className="mb-4">
				<Filter form={form} onFilter={handleFilter} />
				<div className="mt-2">
					<FilterTags
						form={form}
						keyLabelMapper={keyLabelMapper}
						valueLabelMapper={valueLabelMapper}
					/>
				</div>
			</Card>
			<Card>
				<div className="mb-4">
					<Button
						type="primary"
						icon={<PlusOutlined />}
						onClick={() => create('access-passes')}
					>
						{__('Add access pass', 'power-course')}
					</Button>
				</div>
				<Table
					{...(defaultTableProps as unknown as TableProps<TAccessPassRecord>)}
					{...tableProps}
					dataSource={filteredDataSource}
					pagination={{
						...tableProps.pagination,
						...getDefaultPaginationProps({}),
						// 覆寫共用 util 的 showTotal：共用文案 %3$s 是資源名稱，
						// 但其 zh_TW 佔位對應把 %3$s 當成筆數，會渲染成
						//「共 課程通行證 筆」。此頁改用單一總筆數文案。
						showTotal: (total: number) =>
							sprintf(
								// translators: %d: 課程通行證總筆數
								__('Total %d access passes', 'power-course'),
								total
							),
					}}
					columns={columns}
					rowKey={(record) => record.id.toString()}
				/>
			</Card>
			<DeletePassModal
				record={deletingRecord}
				onClose={() => setDeletingRecord(null)}
			/>
		</List>
	)
}

export default AccessPassesList
