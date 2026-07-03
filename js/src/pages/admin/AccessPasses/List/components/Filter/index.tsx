import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Form, Input, Select, Button, FormInstance } from 'antd'
import { memo } from 'react'

import { useLabels } from '@/pages/admin/AccessPasses/hooks'
import {
	TScopeType,
	TLimitType,
	TAccessPassStatus,
} from '@/pages/admin/AccessPasses/types'

/**
 * 課程通行證列表篩選條件
 *
 * 名稱為關鍵字（substring 比對）；狀態 / 範圍類型 / 存取期間未選（undefined）
 * 代表全部、不過濾。皆為前端衍生過濾用，不送往後端。
 */
export type TPassFilterValues = {
	/** 名稱關鍵字（不分大小寫 substring 比對） */
	name?: string
	/** 狀態：未選（undefined）= 全部 */
	status?: TAccessPassStatus
	/** 範圍類型：未選（undefined）= 全部 */
	scope_type?: TScopeType
	/** 存取期間（期限大類）：未選（undefined）= 全部 */
	limit_type?: TLimitType
}

type TFilterProps = {
	/** 父層建立的 form instance（與 FilterTags 共用，作為篩選唯一真相來源） */
	form: FormInstance<TPassFilterValues>
	/** Form onFinish 回呼（按鈕 submit / 重設 / 移除 tag 都會觸發） */
	onFilter: (values: TPassFilterValues) => void
}

const { Item } = Form

/**
 * 課程通行證列表篩選表單
 *
 * 採 grid 版型（對齊課程列表頁篩選），含名稱關鍵字、狀態 / 範圍類型 / 存取期間
 * 下拉與篩選 / 重設按鈕。form 由父層持有並與 FilterTags 共用；按鈕、重設、移除
 * tag 一律透過 form.submit() 觸發 onFinish，由父層做 client-side 過濾。
 *
 * @param props.form     父層 form instance
 * @param props.onFilter Form onFinish 回呼
 */
const Filter = ({ form, onFilter }: TFilterProps) => {
	const { getScopeLabel, getLimitTypeLabel, getStatusLabel } = useLabels()

	/** 狀態下拉選項（沿用 useLabels 既有文案：已啟用 / 已停用） */
	const statusOptions = [
		{ value: 'active', label: getStatusLabel('active').label },
		{ value: 'disabled', label: getStatusLabel('disabled').label },
	]

	/** 範圍類型下拉選項（沿用 useLabels.getScopeLabel 大類文案） */
	const scopeOptions = [
		{ value: 'all', label: getScopeLabel('all') },
		{ value: 'category', label: getScopeLabel('category') },
		{ value: 'specific', label: getScopeLabel('specific') },
	]

	/** 存取期間下拉選項（沿用 useLabels.getLimitTypeLabel 大類文案） */
	const limitTypeOptions = [
		{ value: 'unlimited', label: getLimitTypeLabel('unlimited') },
		{ value: 'fixed', label: getLimitTypeLabel('fixed') },
		{ value: 'assigned', label: getLimitTypeLabel('assigned') },
		{
			value: 'follow_subscription',
			label: getLimitTypeLabel('follow_subscription'),
		},
	]

	/** 重設表單並重新套用（清空條件） */
	const handleReset = () => {
		form.resetFields()
		form.submit()
	}

	return (
		<Form<TPassFilterValues>
			form={form}
			layout="vertical"
			onFinish={onFilter}
			className="antd-form-sm"
		>
			<div className="grid grid-cols-2 xl:grid-cols-4 gap-x-4">
				<Item name="name" label={__('Name', 'power-course')}>
					<Input
						placeholder={__('Search by name', 'power-course')}
						allowClear
					/>
				</Item>

				<Item name="status" label={__('Status', 'power-course')}>
					<Select
						options={statusOptions}
						className="w-full"
						allowClear
						placeholder={__('All', 'power-course')}
					/>
				</Item>

				<Item name="scope_type" label={__('Scope type', 'power-course')}>
					<Select
						options={scopeOptions}
						className="w-full"
						allowClear
						placeholder={__('All', 'power-course')}
					/>
				</Item>

				<Item name="limit_type" label={__('Access period', 'power-course')}>
					<Select
						options={limitTypeOptions}
						className="w-full"
						allowClear
						placeholder={__('All', 'power-course')}
					/>
				</Item>
			</div>
			<div className="grid grid-cols-2 xl:grid-cols-4 gap-x-4 mt-4">
				<Button
					htmlType="submit"
					type="primary"
					className="w-full"
					icon={<SearchOutlined />}
				>
					{__('Filter', 'power-course')}
				</Button>
				<Button
					type="default"
					className="w-full"
					onClick={handleReset}
					icon={<UndoOutlined />}
				>
					{__('Reset', 'power-course')}
				</Button>
			</div>
		</Form>
	)
}

export default memo(Filter)
