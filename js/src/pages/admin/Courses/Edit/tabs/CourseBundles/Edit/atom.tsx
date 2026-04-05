import { atom } from 'jotai'

import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

export const selectedProductsAtom = atom<TBundleProductRecord[]>([])

export const courseAtom = atom<TCourseRecord | undefined>(undefined)

export const bundleProductAtom = atom<TBundleProductRecord | undefined>(
	undefined
)

// 各商品數量 {"product_id": qty}
export const productQuantitiesAtom = atom<Record<string, number>>({})
