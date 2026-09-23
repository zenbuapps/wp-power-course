import { useCopyToClipboard } from '@uidotdev/usehooks'
import { Form, Tooltip, Tag, message } from 'antd'

import { TriggerAt, TTriggerAt, isAssignmentTriggerAt } from './enum'
import useSendCondition from './hooks'
import type { TEmailSchema } from './types'

const Variables = ({ activeKey }: { activeKey: string }) => {
	const { data } = useSendCondition()
	const schema = data?.data
	const user_schema: TEmailSchema = schema?.user_schema || {}
	const course_schema: TEmailSchema = schema?.course_schema || {}
	const chapter_schema: TEmailSchema = schema?.chapter_schema || {}
	const assignment_schema: TEmailSchema = schema?.assignment_schema || {}
	const [, copyToClipboard] = useCopyToClipboard()

	const form = Form.useFormInstance()

	const watchTriggerAt = Form.useWatch<TTriggerAt>([TriggerAt.FIELD_NAME], form)

	const handleCopy = (key: string) => async () => {
		await copyToClipboard(`{${key}}`)
		message.success(`已複製 {${key}}`)
	}

	if ('specific' === activeKey) {
		return Object.keys(user_schema).map((key) => (
			<Tooltip key={key} title={user_schema?.[key]}>
				<Tag
					color="#eee"
					className="rounded-xl !text-gray-600 px-3 cursor-pointer mb-2"
					onClick={handleCopy(key)}
				>
					{`{${key}}`}
				</Tag>
			</Tooltip>
		))
	}

	// 作業類 trigger（DEC-11）才列出作業變數；
	// 其他 trigger 的信件沒有作業上下文，列出來只會讓站長貼到空字串。
	const avl_schema: TEmailSchema = {
		...user_schema,
		...course_schema,
		...chapter_schema,
		...(isAssignmentTriggerAt(watchTriggerAt) ? assignment_schema : {}),
	}

	return Object.keys(avl_schema).map((key) => (
		<Tooltip key={key} title={avl_schema?.[key]}>
			<Tag
				color="#eee"
				className="rounded-xl !text-gray-600 px-3 cursor-pointer mb-2"
				onClick={handleCopy(key)}
			>
				{`{${key}}`}
			</Tag>
		</Tooltip>
	))
}

export default Variables
