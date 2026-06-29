import { SearchOutlined, UndoOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Form, Input, Select, Button, Space, FormInstance } from 'antd'
import { memo } from 'react'

import { useLabels } from '@/pages/admin/AccessPasses/hooks'
import { TAccessPassStatus } from '@/pages/admin/AccessPasses/types'

/**
 * 課程通行證列表篩選條件
 *
 * 名稱為關鍵字（substring 比對）；狀態未選（undefined）代表全部、不過濾。
 * 皆為前端衍生過濾用，不送往後端。
 */
export type TPassFilterValues = {
	/** 名稱關鍵字（不分大小寫 substring 比對） */
	name?: string
	/** 狀態：未選（undefined）= 全部 */
	status?: TAccessPassStatus
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
 * 採水平排版（仿課程學員 Tab Filter），含名稱關鍵字、狀態下拉與篩選 / 重設按鈕。
 * form 由父層持有並與 FilterTags 共用；按鈕、重設、移除 tag 一律透過 form.submit()
 * 觸發 onFinish，由父層做 client-side 過濾。
 *
 * @param props.form     父層 form instance
 * @param props.onFilter Form onFinish 回呼
 */
const Filter = ({ form, onFilter }: TFilterProps) => {
	const { getStatusLabel } = useLabels()

	/** 狀態下拉選項（沿用 useLabels 既有文案：已啟用 / 已停用） */
	const statusOptions = [
		{ value: 'active', label: getStatusLabel('active').label },
		{ value: 'disabled', label: getStatusLabel('disabled').label },
	]

	/** 重設表單並重新套用（清空條件） */
	const handleReset = () => {
		form.resetFields()
		form.submit()
	}

	return (
		<Form<TPassFilterValues> form={form} layout="vertical" onFinish={onFilter}>
			<div className="flex flex-wrap items-start gap-x-4">
				<Item
					name="name"
					label={__('Name', 'power-course')}
					className="flex-1 min-w-[16rem]"
				>
					<Input
						placeholder={__('Search by name', 'power-course')}
						allowClear
					/>
				</Item>

				<Item name="status" label={__('Status', 'power-course')}>
					<Select
						options={statusOptions}
						style={{ width: 160 }}
						allowClear
						placeholder={__('All', 'power-course')}
					/>
				</Item>

				<Item label={<span className="opacity-0">.</span>}>
					<Space>
						<Button htmlType="submit" type="primary" icon={<SearchOutlined />}>
							{__('Filter', 'power-course')}
						</Button>
						<Button
							type="default"
							onClick={handleReset}
							icon={<UndoOutlined />}
						>
							{__('Reset', 'power-course')}
						</Button>
					</Space>
				</Item>
			</div>
		</Form>
	)
}

export default memo(Filter)
