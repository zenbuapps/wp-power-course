import { useCustom, useApiUrl } from '@refinedev/core'

type TRoleOption = {
	value: string
	label: string
}

type TUsersOptionsResponse = {
	roles?: TRoleOption[]
}

/**
 * 取得 WP 角色清單，供 Filter 的 role__in 下拉選單使用
 *
 * 透過 Refine Data Hook 打 Powerhouse 的 `/users/options` endpoint
 * （default dataProvider = Powerhouse）。回應結構：{ roles: [{value, label}] }
 */
export const useOptions = () => {
	const apiUrl = useApiUrl()

	const { data, isFetching } = useCustom<TUsersOptionsResponse>({
		url: `${apiUrl}/users/options`,
		method: 'get',
	})

	const roles: TRoleOption[] = data?.data?.roles ?? []

	return {
		roles,
		isFetching,
	}
}
