import {
	useCustom,
	useApiUrl,
	CustomResponse,
	HttpError,
	UseLoadingOvertimeReturnType,
} from '@refinedev/core'
import { QueryObserverResult } from '@tanstack/react-query'
import { Form } from 'antd'
import { objToCrudFilters } from 'antd-toolkit/refine'
import dayjs from 'dayjs'
import { round, uniq } from 'lodash-es'
import React, { useState, useEffect } from 'react'

import {
	TRevenue,
	TFormattedRevenue,
	TFilterProps,
	TViewTypeProps,
	TQuery,
	defaultQuery,
} from '@/pages/admin/Analytics/types'

export type TUseRevenueParams = {
	initialQuery?: Partial<TQuery>
	context?: 'detail'
}

const useRevenue = ({ initialQuery, context }: TUseRevenueParams) => {
	const apiUrl = useApiUrl('power-course')
	const [form] = Form.useForm()

	const DEFAULT_QUERY = {
		...defaultQuery,
		...initialQuery,
	}

	const [enabled, setEnabled] = useState(false)
	const compare_last_year = Form.useWatch(['compare_last_year'], form)
	const date_range = Form.useWatch(['date_range'], form)
	const product_ids = Form.useWatch(['products'], form) || []
	const bundle_product_ids = Form.useWatch(['bundle_products'], form) || []
	const interval = Form.useWatch(['interval'], form)

	const query = {
		...DEFAULT_QUERY,
		compare_last_year,
		after: date_range?.[0],
		before: date_range?.[1],
		product_includes: uniq([...product_ids, ...bundle_product_ids]),
		interval,
	}

	const result = useCustom<TRevenue>({
		url: `${apiUrl}/reports/revenue/stats`,
		method: 'get',
		config: {
			filters: objToCrudFilters(query),
		},
		queryOptions: {
			enabled,
		},
	})

	const lastYearQuery = {
		...query,
		after: dayjs(query.after).subtract(1, 'year').format('YYYY-MM-DDTHH:mm:ss'),
		before: dayjs(query.before)
			.subtract(1, 'year')
			.format('YYYY-MM-DDTHH:mm:ss'),
	}

	const lastYearResult = useCustom<TRevenue>({
		url: `${apiUrl}/reports/revenue/stats`,
		method: 'get',
		config: {
			filters: objToCrudFilters(lastYearQuery),
		},
		queryOptions: {
			enabled: !!compare_last_year && enabled,
		},
	})

	// 格式化新的 result
	const formattedResult = getFormattedResult(result, false)
	const formattedLastYearResult = getFormattedResult(lastYearResult, true)

	// 取得 response header 上的 X-WP-TotalPages
	// @ts-ignore
	const totalPages = Number(result?.data?.headers?.['x-wp-totalpages']) || 1

	// @ts-ignore
	const total = Number(result?.data?.headers?.['x-wp-total']) || 1

	useEffect(() => {
		if (result.isSuccess || result.isError) {
			setEnabled(false)
		}
	}, [result])

	return {
		result: formattedResult,
		lastYearResult: formattedLastYearResult,
		isLoading: compare_last_year
			? result.isLoading || lastYearResult.isLoading
			: result.isLoading,
		isFetching: compare_last_year
			? result.isFetching || lastYearResult.isFetching
			: result.isFetching,
		form,
		enabled,
		setEnabled,
		filterProps: {
			isFetching: result.isFetching,
			isLoading: result.isLoading,
			totalPages,
			total,
		} as TFilterProps,
		viewTypeProps: {
			revenueData: formattedResult?.data?.data,
			lastYearRevenueData: formattedLastYearResult?.data?.data,
		} as TViewTypeProps,
	}
}

/**
 * 格式化 result
 * @param result
 * @param dataLabel
 * @param isLastYear
 * @return
 */
function getFormattedResult(
	result: QueryObserverResult<CustomResponse<TRevenue>, HttpError> &
		UseLoadingOvertimeReturnType,
	isLastYear: boolean
): QueryObserverResult<CustomResponse<TFormattedRevenue>, HttpError> &
	UseLoadingOvertimeReturnType {
	const intervals = result?.data?.data?.intervals || []
	const lastIntervals = intervals[intervals.length - 1]

	const formatIntervals = intervals.map(({ subtotals, ...restInterval }) => {
		const interval_compared = isLastYear
			? Number(restInterval?.interval?.slice(0, 4)) +
				1 +
				restInterval?.interval?.slice(4) // 把restInterval.interval 這個string前四個字取出後 +1
			: restInterval.interval

		// 把 subtotals 數據 round 最多小數點2位
		const roundedSubtotals = Object.fromEntries(
			Object.entries(subtotals).map(([key, value]) => [
				key,
				round(value as number, 2),
			])
		)

		// 建立新物件而不是修改原物件
		const newInterval = {
			...restInterval,
			...roundedSubtotals,
			dataLabel: `${lastIntervals?.interval?.slice(0, 4)}年`,
			interval_compared,
		}
		return newInterval
	})

	// 創建新的 result
	const formatResult = {
		...result,
		data: {
			...result?.data,
			data: {
				...result?.data?.data,
				intervals: formatIntervals,
			},
		},
	} as QueryObserverResult<CustomResponse<TFormattedRevenue>, HttpError> &
		UseLoadingOvertimeReturnType

	return formatResult
}

export default useRevenue
