import { IBlockData } from 'j7-easy-email-core'

import {
	TriggerAt,
	TriggerCondition,
	SendingType,
	SendingUnit,
} from '@/components/emails/SendCondition/enum'

export type TEmailListRecord = Omit<TEmailRecord, 'description'>

export type TEmailRecord = {
	id: string
	name: string
	subject: string
	status: string
	allow_repeat_send?: boolean | 'yes' | 'no'
	description: string
	short_description: string
	condition: {
		trigger_condition: Omit<TriggerCondition, 'FIELD_NAME'>
		sending: {
			type: Omit<SendingType, 'FIELD_NAME'>
			value: string
			unit: Omit<SendingUnit, 'FIELD_NAME'>
			range: [string, string]
		}
		trigger_at: Omit<TriggerAt, 'FIELD_NAME'>
	}
	date_created: string
	date_modified: string
}

export type TFormValues = {
	status: 'publish' | 'draft'
	name: string
	subject: string
	short_description: IBlockData | string
	description: string
	allow_repeat_send?: 'yes' | 'no'
}

/** 內容無法解析的郵件模板是否可自動修復（對應後端 JsonRepair::STATE_*） */
export const EMAIL_JSON_HEALTH_STATE = {
	REPAIRABLE: 'repairable',
	UNREPAIRABLE: 'unrepairable',
} as const

export type TEmailJsonHealthState =
	(typeof EMAIL_JSON_HEALTH_STATE)[keyof typeof EMAIL_JSON_HEALTH_STATE]

/** `GET power-email/emails/json-health` 回傳的單一損壞模板與修復預覽 */
export type TEmailJsonHealthItem = {
	id: string
	name: string
	status: string
	state: TEmailJsonHealthState
	/** 預覽當下內容的指紋，修復時原樣送回；內容已變動時後端回 409 */
	fingerprint: string
	/** 修復會變更的 HTML 標籤，一律當純文字呈現 */
	changes: { before: string; after: string }[]
}

// Action Scheduler 紀錄
export type TAsRecord = {
	id: string
	hook: string // hook
	status_name: string
	status: string
	args: any
	group: string
	log_entries: any
	recurrence: string
	claim_id: number
	schedule: string
}
