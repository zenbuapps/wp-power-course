import {
	BaseRecord,
	useList,
	CrudFilter,
	CrudOperators,
	HttpError,
	GetListResponse,
} from '@refinedev/core'
import { UseQueryOptions, QueryKey } from '@tanstack/react-query'
import { useState, useEffect } from 'react'

export type TListSelectProps<T> = {
	loading: boolean
	initLoading: boolean
	onSearch: (_value: string) => void
	dataSource: T[]
	onListClick: (_item: T) => () => void
	selectedItems: T[]
	setSelectedItems: React.Dispatch<React.SetStateAction<T[]>>
	rowKey?: keyof T
	initKeys?: string[]
}

type TUseListSelectParams<T extends BaseRecord> = {
	resource: string
	rowKey?: string
	initKeys?: string[]
	filters?: CrudFilter[]
	searchField?: string
	searchOperator?: CrudOperators
	queryOptions?:
		| UseQueryOptions<
				GetListResponse<T>,
				HttpError,
				GetListResponse<T>,
				QueryKey
		  >
		| undefined
}

export const useListSelect = <T extends BaseRecord>({
	resource,
	rowKey = 'id',
	initKeys = [],
	filters,
	searchField = 's',
	searchOperator = 'eq',
	queryOptions,
}: TUseListSelectParams<T>) => {
	const [searchKeyWord, setSearchKeyWord] = useState<string>('')
	const [selectedItems, setSelectedItems] = useState<T[]>([])

	const onSearch = (value: string) => {
		setSearchKeyWord(value)
	}

	const searchItemsResult = useList<T>({
		dataProviderName: resource !== 'users' ? 'power-course' : undefined,
		resource,
		filters: [
			{
				field: searchField,
				operator: searchOperator,
				value: searchKeyWord,
			} as CrudFilter,
			...(filters || []),
		],
		queryOptions,
	})

	// 處理點擊商品，有可能是加入也可能是移除

	const onListClick = (item: T) => () => {
		const isInclude = selectedItems?.some(
			(theItem) => theItem?.[rowKey as keyof T] === item?.[rowKey as keyof T]
		)
		if (isInclude) {
			// 當前列表中已經有這個商品，所以要移除

			setSelectedItems(
				selectedItems.filter(
					(theItem) =>
						theItem?.[rowKey as keyof T] !== item?.[rowKey as keyof T]
				)
			)
		} else {
			// 當前列表中沒有這個商品，所以要加入

			setSelectedItems([...selectedItems, item])
		}
	}

	// 初始值
	const { data: initData, isFetching: initIsFetching } = useList<T>({
		resource,
		filters: [
			{
				field: 'include',
				operator: 'eq',
				value: initKeys,
			},
		],
		queryOptions: {
			enabled: !!initKeys.length,
		},
	})

	useEffect(() => {
		if (!initIsFetching) {
			const initItems = initData?.data || []
			setSelectedItems(initItems)
		}
	}, [initIsFetching])

	const listSelectProps: TListSelectProps<T> = {
		loading: searchItemsResult.isFetching,
		initLoading: initIsFetching,
		onSearch,
		dataSource: searchItemsResult.data?.data || [],
		onListClick,
		selectedItems,
		setSelectedItems,
		rowKey,
		initKeys,
	}

	return {
		listSelectProps,
	}
}
