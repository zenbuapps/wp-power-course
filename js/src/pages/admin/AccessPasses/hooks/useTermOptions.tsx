import useOptions from '@/components/product/ProductTable/hooks/useOptions'

type TTermOption = {
	label: string
	value: string
}

/**
 * 取得 product_cat / product_tag 選項的 Hook
 *
 * 重用商品篩選的 `products/options` 端點（已回傳 product_cats / product_tags），
 * 供 category 範圍的多選使用。term value 統一轉為字串（與 antd Select value 一致）。
 *
 * @return product_cat 與 product_tag 的選項陣列
 */
export const useTermOptions = (): {
	catOptions: TTermOption[]
	tagOptions: TTermOption[]
	isLoading: boolean
} => {
	const { options, isLoading } = useOptions({ endpoint: 'products/options' })

	const catOptions: TTermOption[] = (options?.product_cats ?? []).map(
		(term) => ({
			label: term.name,
			value: String(term.id),
		})
	)

	const tagOptions: TTermOption[] = (options?.product_tags ?? []).map(
		(term) => ({
			label: term.name,
			value: String(term.id),
		})
	)

	return { catOptions, tagOptions, isLoading }
}
