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
