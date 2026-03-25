import { useCustom, useApiUrl } from '@refinedev/core'
import { FormInstance } from 'antd'
import React, { useEffect } from 'react'

import { TSettings } from '../types'

type TSettingsResponse = {
	code: string
	data: TSettings
	message: string
}

const useSettings = ({ form }: { form: FormInstance }) => {
	const apiUrl = useApiUrl('power-course')
	const result = useCustom<TSettingsResponse>({
		url: `${apiUrl}/settings`,
		method: 'get',
	})

	const { isFetching } = result
	useEffect(() => {
		if (!isFetching) {
			const values = result.data?.data?.data
			form.setFieldsValue(values)
		}
	}, [isFetching])

	return result
}

export default useSettings
