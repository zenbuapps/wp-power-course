import {
	CustomResponse,
	HttpError,
	UseLoadingOvertimeReturnType,
} from '@refinedev/core'
import { QueryObserverResult } from '@tanstack/react-query'
import { FormInstance } from 'antd'
import React, { createContext, useContext } from 'react'

import {
	EViewType,
	TFilterProps,
	TQuery,
	TViewTypeProps,
	TFormattedRevenue,
} from '@/pages/admin/Analytics/types'

export const RevenueContext = createContext<
	| {
			context?: 'detail'
			initialQuery?: Partial<TQuery>
			viewType: EViewType
			setViewType: React.Dispatch<React.SetStateAction<EViewType>>
			result: QueryObserverResult<
				CustomResponse<TFormattedRevenue>,
				HttpError
			> &
				UseLoadingOvertimeReturnType
			lastYearResult: QueryObserverResult<
				CustomResponse<TFormattedRevenue>,
				HttpError
			> &
				UseLoadingOvertimeReturnType
			isFetching: boolean
			isLoading: boolean
			form: FormInstance
			enabled: boolean
			setEnabled: React.Dispatch<React.SetStateAction<boolean>>
			filterProps: TFilterProps
			viewTypeProps: TViewTypeProps
	  }
	| undefined
>(undefined)

export const useRevenueContext = () => {
	const context = useContext(RevenueContext)

	if (!context) {
		throw new Error('useRevenueContext must be used within a RevenueContext')
	}

	return context
}
