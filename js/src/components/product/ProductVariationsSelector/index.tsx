import { CloseCircleFilled, CheckCircleFilled } from '@ant-design/icons'
import { Button } from 'antd'
import React, { FC } from 'react'

import {
	TCourseRecord,
	TProductVariation,
} from '@/pages/admin/Courses/List/types'

export type TProductVariationsSelectorParams = {
	record: TCourseRecord
	selectedAttributes: { name: string; value: string }[]
	setSelectedAttributes: React.Dispatch<
		React.SetStateAction<
			{
				name: string
				value: string
			}[]
		>
	>
	selectedVariation?: TProductVariation
}

export const ProductVariationsSelector: FC<
	TProductVariationsSelectorParams
> = ({
	record,
	selectedAttributes,
	setSelectedAttributes,
	selectedVariation,
}) => {
	const { type = 'simple', attributes = [] } = record
	if (
		!['variable', 'variable-subscription'].includes(type) ||
		!attributes?.length
	)
		return <></>

	const handleClick = (name: string, option: string) => () => {
		const selectedAttribute = selectedAttributes?.find(
			(item) => item?.name === name
		)
		if (selectedAttribute) {
			setSelectedAttributes([
				...selectedAttributes.filter((item) => item?.name !== name),
				{ name, value: option },
			])
		} else {
			setSelectedAttributes([...selectedAttributes, { name, value: option }])
		}
	}

	const hasSelectedAllAttributes =
		selectedAttributes?.length === attributes?.length

	return (
		<>
			{attributes?.map(
				({ name = 'unknown_attr', options = [], position = 0 }) => {
					const selectedAttribute = selectedAttributes?.find(
						(item) => item?.name === name
					)
					return (
						<div key={name} className="mb-4">
							<p className="mb-0">{name}</p>
							<div className="flex flex-wrap">
								{options?.map((option) => (
									<Button
										key={option}
										type={`${selectedAttribute?.value === option ? 'primary' : 'default'}`}
										onClick={handleClick(name, option)}
										size="small"
										className="mr-1 mb-1 min-h-[unset]"
									>
										<span className="text-xs">
											{decodeURIComponent(option)}
										</span>
									</Button>
								))}
							</div>
						</div>
					)
				}
			)}

			{!hasSelectedAllAttributes && (
				<p className="m-0 text-gray-400 text-xs">
					<CloseCircleFilled className="mr-2 text-red-500" />
					未選擇商品屬性
				</p>
			)}
			{hasSelectedAllAttributes && !!selectedVariation && (
				<p className="m-0 text-gray-400 text-xs">
					<CheckCircleFilled className="mr-2 text-green-500" />
					已選擇商品屬性
				</p>
			)}
		</>
	)
}

export * from './useProductVariationsSelector'
