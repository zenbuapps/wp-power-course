import { useSelect } from '@refinedev/antd'
import { UseSelectProps, HttpError } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { SelectProps } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { useState } from 'react'

import { TProductRecord } from '@/components/product/ProductTable/types'

type TUseProductSelectParams = {
	selectProps?: SelectProps
	useSelectProps?: Partial<
		UseSelectProps<TProductRecord, HttpError, TProductRecord>
	>
}

export const useProductSelect = (params?: TUseProductSelectParams) => {
	const selectProps = params?.selectProps
	const useSelectProps = params?.useSelectProps
	const [productIds, setProductIds] = useState<string[]>([])

	const { selectProps: refineSelectProps, query } = useSelect<TProductRecord>({
		resource: 'products',
		dataProviderName: 'power-course',
		debounce: 500,
		pagination: {
			pageSize: 20,
			mode: 'server',
		},
		onSearch: (value) => [
			{
				field: 's',
				operator: 'contains',
				value,
			},
		],
		...useSelectProps,
	})

	const products = query.data?.data ?? []
	const options = products.map((product) => ({
		label: product.name,
		value: product.id,
	}))

	const mergedSelectProps: SelectProps = {
		...defaultSelectProps,
		placeholder: __('Search product keyword', 'power-course'),
		value: productIds,
		onChange: (value: string[]) => {
			setProductIds(value)
		},
		...selectProps,
		...refineSelectProps,
		options,
	}

	return {
		selectProps: mergedSelectProps,
		productIds,
		setProductIds,
	}
}
