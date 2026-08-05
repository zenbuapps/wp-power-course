import { useCustom, useApiUrl } from '@refinedev/core'

const useSendCondition = () => {
	const apiUrl = useApiUrl('power-email')

	const query = useCustom({
		url: `${apiUrl}/emails/options`,
		method: 'get',
	})

	return query
}

export default useSendCondition
