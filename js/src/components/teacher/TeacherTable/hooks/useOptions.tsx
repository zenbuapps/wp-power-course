import { useCustom, useApiUrl } from '@refinedev/core'

type TRoleOption = {
	value: string
	label: string
}

type TUsersOptionsResponse = {
	code: string
	message: string
	data: {
		roles: TRoleOption[]
	}
}

export const useOptions = () => {
	const apiUrl = useApiUrl()

	const { data, isFetching } = useCustom<TUsersOptionsResponse>({
		url: `${apiUrl}/users/options`,
		method: 'get',
	})

	const roles: TRoleOption[] = data?.data?.data?.roles ?? []

	return {
		roles,
		isFetching,
	}
}
