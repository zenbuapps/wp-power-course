import { __ } from '@wordpress/i18n'
import { Empty } from 'antd'

import { Price } from '@/components/general'
import { useRecord } from '@/components/user/StudentEditModal/hooks'

/**
 * 購物車區塊
 *
 * 顯示學員當前 persistent cart 內的商品（縮圖 + 名稱 + 數量）與購物車金額。
 * 空購物車時顯示空狀態。
 */
const Cart = () => {
	const record = useRecord()
	const { cart } = record
	const cartTotal = cart?.reduce((acc, item) => acc + item?.line_total, 0) || 0
	return (
		<div className="rounded-lg border border-gray-200 border-solid p-3 mb-12">
			<h3 className="text-xs font-medium mb-2">
				{__('Current cart contains:', 'power-course')}
			</h3>
			{!cart?.length && (
				<Empty
					className="text-xs"
					description={__('Cart is empty', 'power-course')}
					image={Empty.PRESENTED_IMAGE_SIMPLE}
				/>
			)}

			{!!cart?.length &&
				cart?.map(({ product_id, product_name, quantity, product_image }) => (
					<div
						key={product_id}
						className="grid grid-cols-[2rem_1fr_0.5rem_2rem] items-center mb-2 text-xs"
					>
						<img
							alt={product_name}
							loading="lazy"
							decoding="async"
							className="rounded-md text-transparent size-8 object-cover"
							src={product_image}
						/>
						<span className="mx-2 truncate">{product_name}</span>
						<span>x</span>
						<span className="text-right">{quantity}</span>
					</div>
				))}
			<div className="bg-gray-200 h-[1px] w-full my-2" />
			<div className="flex justify-between items-center text-xs">
				<span>{__('Cart total', 'power-course')}</span>
				<Price amount={cartTotal} />
			</div>
		</div>
	)
}

export default Cart
