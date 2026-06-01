import { useApiUrl, useCustom } from '@refinedev/core'
import { useMemo } from 'react'

type TCountryState = {
	code: string
	name: string
}

type TCountry = {
	code: string
	name: string
	states: TCountryState[]
}

type TOption = {
	label: string
	value: string
}

/**
 * 取得 WooCommerce 國家 / 州省選項的 Hook
 *
 * 透過 wc-rest provider 的 data/countries 端點取得國家清單，
 * 產出 country 的 Select 選項，並提供依國家代碼查詢州省選項的函式。
 * 國家與州省皆以 WooCommerce 代碼（code）儲存。
 */
export const useCountryOptions = () => {
	const apiUrl = useApiUrl('wc-rest')

	const { data, isLoading } = useCustom<TCountry[]>({
		url: `${apiUrl}/data/countries`,
		method: 'get',
		queryOptions: {
			staleTime: 1000 * 60 * 60,
		},
	})

	const countries = useMemo<TCountry[]>(() => data?.data ?? [], [data?.data])

	const countryOptions = useMemo<TOption[]>(
		() =>
			countries.map((country) => ({
				label: country.name,
				value: country.code,
			})),
		[countries]
	)

	/**
	 * 依國家代碼取得州省選項；若該國家無州省則回傳空陣列
	 * @param countryCode WooCommerce 國家代碼
	 */
	const getStateOptions = (countryCode?: string): TOption[] => {
		if (!countryCode) return []
		const target = countries.find((country) => country.code === countryCode)
		if (!target?.states?.length) return []
		return target.states.map((state) => ({
			label: state.name,
			value: state.code,
		}))
	}

	return {
		countryOptions,
		getStateOptions,
		isLoading,
	}
}
