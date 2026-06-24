import { useUpdate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Select, SelectProps } from 'antd'
import { toFormData } from 'antd-toolkit'
import { memo, FC } from 'react'

import { TProductRecord } from '@/components/product/ProductTable/types'

type TAccessPassSelectorProps = {
	/** 商品記錄 */
	record: TProductRecord
	/** 啟用中的權限包選項（由表格層級的 useColumns 注入，避免每列重複查詢） */
	options: SelectProps['options']
	/** 選項是否載入中 */
	loading?: boolean
}

/**
 * 商品列的「課程權限包」掛載 Select（Issue #252）
 *
 * 與 bind_courses_data（逐課綁定）並存，效果並集（OR）。掛載走既有商品更新路徑
 * （Refine useUpdate → POST products/{id}），把 access_pass_id 寫入商品 meta；
 * 清除時送空字串（對齊 Issue #203 清空語義）。
 *
 * ⚠️ ASM-F2：後端商品列表 format_product_details() 尚未回傳 access_pass_id，
 * 故 record.access_pass_id 暫為 undefined，Select 目前無法回填當前掛載值
 * （待後端於列表 format 補上後即自動生效）。
 *
 * 變體商品（variation）不支援掛載權限包，故停用。
 */
const AccessPassSelectorComponent: FC<TAccessPassSelectorProps> = ({
	record,
	options,
	loading = false,
}) => {
	const { mutate, isLoading } = useUpdate()

	// 變體 / 可變商品母體不支援掛載
	const isVariationOrVariable =
		record?.type?.includes('variation') || record?.type?.startsWith('variable')

	const currentValue =
		record.access_pass_id !== undefined && record.access_pass_id !== ''
			? Number(record.access_pass_id)
			: undefined

	const handleChange = (value?: number) => {
		const formData = toFormData({
			// 清除時送空字串：經 WP::separator → meta_data → update_meta_data 寫空（未掛載）
			access_pass_id: value ?? '',
		})
		mutate({
			resource: 'products',
			dataProviderName: 'power-course',
			id: record.id,
			values: formData,
			meta: {
				headers: { 'Content-Type': 'multipart/form-data;' },
			},
		})
	}

	return (
		<Select
			className="w-full"
			size="small"
			allowClear
			showSearch
			optionFilterProp="label"
			placeholder={__('No access pass', 'power-course')}
			loading={loading || isLoading}
			disabled={isVariationOrVariable || isLoading}
			value={currentValue}
			options={options}
			onChange={handleChange}
		/>
	)
}

export const AccessPassSelector = memo(AccessPassSelectorComponent)
