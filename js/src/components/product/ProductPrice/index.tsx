import { renderHTML } from 'antd-toolkit'
import { FC } from 'react'

import { TProductRecord } from '@/components/product/ProductTable/types'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'

export const ProductPrice: FC<{
	record: TProductRecord | TCourseBaseRecord
}> = ({ record }) => {
	const { price_html } = record
	if (!price_html) return null
	return <div className="at-product-price">{renderHTML(price_html)}</div>
}
