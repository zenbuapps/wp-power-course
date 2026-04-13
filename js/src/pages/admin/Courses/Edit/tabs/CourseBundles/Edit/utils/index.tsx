import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

export const INCLUDED_PRODUCT_IDS_FIELD_NAME = 'pbp_product_ids' // 包含商品的 ids
export const PRODUCT_QUANTITIES_FIELD_NAME = 'pbp_product_quantities' // 各商品數量

export const BUNDLE_TYPE_OPTIONS = [
	{ label: '合購優惠', value: 'bundle', color: 'cyan' },
	{
		label: '🚧 團購優惠 (開發中...)',
		value: 'groupbuy',
		disabled: true,
		color: 'purple',
	},
]

export const PRODUCT_TYPE_OPTIONS = [
	{ label: '簡單商品', value: 'simple' },
	{ label: '定期定額', value: 'subscription' },
]

// 取得總金額（含各商品數量）
export const getPrice = ({
	isFetching = false,
	type,
	products,
	course,
	returnType = 'number',
	quantities = {},
	courseId,
}: {
	isFetching?: boolean
	type: 'regular_price' | 'sale_price'
	products: TBundleProductRecord[] | undefined
	course: TCourseRecord | undefined
	returnType?: 'string' | 'number'
	quantities?: Record<string, number>
	courseId?: string
}): React.ReactNode => {
	if (isFetching) {
		return <div className="w-20 bg-slate-300 animate-pulse h-3 inline-block" />
	}

	// 課程價格 × 數量（如果課程在方案中）
	const courseQty = courseId ? (quantities[String(courseId)] ?? 1) : 0
	const coursePrice =
		Number(course?.[type] || course?.regular_price || 0) * courseQty

	// 其他商品價格 × 各自數量
	const productsTotal = Number(
		products?.reduce((acc, product) => {
			const qty = quantities[String(product.id)] ?? 1
			return acc + Number(product?.[type] || product.regular_price) * qty
		}, 0)
	)

	const total = productsTotal + coursePrice

	if ('number' === returnType) return total
	return `NT$ ${total?.toLocaleString()}`
}
