import { useEnv as useATEnv, TEnv } from 'antd-toolkit'
import { AxiosInstance } from 'axios'

type Env = TEnv & {
	APP1_SELECTOR: string
	ELEMENTOR_ENABLED: boolean
	AXIOS_INSTANCE: AxiosInstance
}

export const useEnv = () => {
	const values = useATEnv<Env>()
	return values
}
