import { atom } from 'jotai'

import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

export const selectedProductsAtom = atom<TBundleProductRecord[]>([])

export const courseAtom = atom<TCourseRecord | undefined>(undefined)

export const bundleProductAtom = atom<TBundleProductRecord | undefined>(
	undefined
)

// 每個商品的數量 { [product_id]: quantity }，預設為空物件（未設定時使用 ?? 1 取得預設值）
export const productQuantitiesAtom = atom<Record<string, number>>({})
