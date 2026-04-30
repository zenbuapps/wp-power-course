import { __ } from '@wordpress/i18n'
import { Form, FormItemProps, Input } from 'antd'
import { FC, ChangeEvent, useState, useEffect } from 'react'

const { Item } = Form

type TCodeProps = FormItemProps & {
	/** Issue #10：multi trial videos 時為 true，Code 模式無字幕，僅占位 */
	hideSubtitle?: boolean
}

// 抽象組件，適用任何拿來 iFrame 的平台
const Code: FC<TCodeProps> = (codeProps) => {
	const { hideSubtitle: _hideSubtitle, ...formItemProps } = codeProps
	const { name } = formItemProps
	const form = Form.useFormInstance()
	const [value, setValue] = useState('')
	const watchField = Form.useWatch(name, form)

	useEffect(() => {
		if (watchField) {
			setValue(watchField?.id)
		}
	}, [watchField])

	if (!name) {
		throw new Error('name is required')
	}

	const handleChange = (e: ChangeEvent<HTMLTextAreaElement>) => {
		setValue(e.target.value)
		form.setFieldValue(name, {
			type: 'code',
			id: e.target.value,
			meta: {},
		})
	}

	return (
		<div className="relative">
			<Input.TextArea
				allowClear
				className="mb-1 rounded-lg"
				rows={12}
				onChange={handleChange}
				value={value}
				placeholder={__(
					'You can place any HTML, iframe or JavaScript embed code here, such as JWP video, prestoplayer/WordPress shortcode, etc.',
					'power-course'
				)}
			/>
			<Item {...formItemProps} hidden />
		</div>
	)
}

export default Code
