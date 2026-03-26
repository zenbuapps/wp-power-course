import { atom } from 'jotai'

import { TProductRecord } from '@/components/product/ProductTable/types'
import Table from '@/pages/admin/Products/ProductTable/Table'

export const productsAtom = atom<TProductRecord[]>([])

const ProductTable = () => {
	return <Table />
}

export default ProductTable
