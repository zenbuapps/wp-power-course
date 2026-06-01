import { useApiUrl, useCustom } from '@refinedev/core'
import { SelectProps } from 'antd'
import { USER_ROLES } from 'antd-toolkit/wp'

/**
 * 取得使用者角色選項的 Hook
 *
 * 透過 power-course provider 的 users/options 端點取得角色清單，
 * 並以 antd-toolkit/wp 的 USER_ROLES 做 label 在地化對照（查不到則退回後端 label）。
 * 供基本資料 Tab 的角色下拉選單使用。
 */
export const useOptions = () => {
	const apiUrl = useApiUrl('power-course')
	const { data, isLoading } = useCustom({
		url: `${apiUrl}/users/options`,
		method: 'get',
		dataProviderName: 'power-course',
	})
	const roles = (data?.data?.data?.roles as SelectProps['options']) || []
	const formattedRoles = roles?.map(({ value, label }) => ({
		value,
		label: USER_ROLES.find(({ value: v }) => v === value)?.label || label,
	}))
	return {
		roles: formattedRoles,
		isLoading,
	}
}
