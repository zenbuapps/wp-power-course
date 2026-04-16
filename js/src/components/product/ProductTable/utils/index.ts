import { __, sprintf } from '@wordpress/i18n'
import { PaginationProps, TableProps, RadioGroupProps } from 'antd'

import { TFilterProps, TTerm } from '@/components/product/ProductTable/types'

export * from './onSearch'

export const getFilterLabels = (
	label?: string
): {
	[key in keyof TFilterProps]: string
} => {
	const resourceLabel = label ?? __('Product', 'power-course')
	return {
		s: __('Keyword', 'power-course'),
		sku: __('SKU', 'power-course'),
		product_category_id: sprintf(
			// translators: %s: 資源名稱（如「商品」或「課程」）
			__('%s category', 'power-course'),
			resourceLabel
		),
		product_tag_id: sprintf(
			// translators: %s: 資源名稱
			__('%s tag', 'power-course'),
			resourceLabel
		),
		product_brand_id: __('Brand', 'power-course'),
		status: sprintf(
			// translators: %s: 資源名稱
			__('%s status', 'power-course'),
			resourceLabel
		),
		featured: __('Featured product', 'power-course'),
		downloadable: __('Downloadable', 'power-course'),
		virtual: __('Virtual product', 'power-course'),
		sold_individually: __('Sold individually', 'power-course'),
		backorders: __('Allow backorders', 'power-course'),
		stock_status: __('Stock status', 'power-course'),
		date_created: sprintf(
			// translators: %s: 資源名稱
			__('%s created date', 'power-course'),
			resourceLabel
		),
		is_course: __('Is course product', 'power-course'),
		price_range: __('Price range', 'power-course'),
	}
}

export const keyLabelMapper = (key: keyof TFilterProps, label?: string) => {
	return getFilterLabels(label)?.[key] || key
}

export const defaultBooleanRadioButtonProps: {
	radioGroupProps: RadioGroupProps
} = {
	radioGroupProps: {
		size: 'small',
	},
}

export const defaultTableProps: TableProps = {
	size: 'small',
	rowKey: 'id',
	bordered: true,
	sticky: true,
}

export const getDefaultPaginationProps = ({
	label,
}: {
	label?: string
}): PaginationProps & {
	position: [
		| 'topLeft'
		| 'topCenter'
		| 'topRight'
		| 'bottomLeft'
		| 'bottomCenter'
		| 'bottomRight',
	]
} => {
	const resourceLabel = label ?? __('product', 'power-course')
	return {
		position: ['bottomCenter'],
		size: 'default',
		showSizeChanger: true,
		showQuickJumper: true,
		showTitle: true,
		showTotal: (total: number, range: [number, number]) =>
			sprintf(
				// translators: 1: 起始序號, 2: 結束序號, 3: 資源名稱, 4: 總筆數, 5: 資源名稱
				__('Showing %1$s ~ %2$s of %3$s, total %4$s %5$s', 'power-course'),
				range?.[0],
				range?.[1],
				resourceLabel,
				total,
				resourceLabel
			),
	}
}

export const termToOptions = (terms: TTerm[]) => {
	return terms?.map((term) => ({
		value: term.id,
		label: term.name,
	}))
}
