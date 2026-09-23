import { useCustom, useApiUrl } from '@refinedev/core'

import type { TEmailOptions } from '../types'

const useSendCondition = () => {
	const apiUrl = useApiUrl('power-email')

	const query = useCustom<TEmailOptions>({
		url: `${apiUrl}/emails/options`,
		method: 'get',
	})

	return query
}

export default useSendCondition
