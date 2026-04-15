import { simpleDecrypt } from 'antd-toolkit'

// @ts-ignore
const encryptedEnv = window?.power_course_data?.env

if (!encryptedEnv) {
	throw new Error('env is not found')
}

export const env = simpleDecrypt(encryptedEnv)
export const API_URL = env?.API_URL || '/wp-json'
export const APP1_SELECTOR = env?.APP1_SELECTOR || '#power_course'
export const APP2_SELECTOR = env?.APP2_SELECTOR || '.pc-vidstack'
export const DEFAULT_IMAGE = 'https://placehold.co/480x480?text=%3CIMG%20/%3E'
