import { useCopyToClipboard } from '@uidotdev/usehooks'
import { Form, Tooltip, Tag, message } from 'antd'
import React from 'react'

import { TriggerAt } from './enum'
import useSendCondition from './hooks'

const Variables = ({ activeKey }: { activeKey: string }) => {
	const { data } = useSendCondition()
	const schema = data?.data || {}
	const { user_schema = {}, course_schema = {}, chapter_schema = {} } = schema
	const [, copyToClipboard] = useCopyToClipboard()

	const form = Form.useFormInstance()

	const watchTriggerAt = Form.useWatch([TriggerAt.FIELD_NAME], form)

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

	const avl_schema = {
		...user_schema,
		...course_schema,
		...chapter_schema,
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
