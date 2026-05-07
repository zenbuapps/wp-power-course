import { __ } from '@wordpress/i18n'
import { Form, FormItemProps, Input } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import { FC, ChangeEvent, useState, useEffect } from 'react'

import { getFullPath } from '../utils'

const { Item } = Form

type TCodeProps = FormItemProps & {
	/** Issue #10：multi trial videos 時為 true，Code 模式無字幕，僅占位 */
	hideSubtitle?: boolean
	/**
	 * 父層 Form.List 的路徑前綴；非 Form.List 場景不傳。
	 * 詳見 VideoInput/index.tsx 的 prop 說明。
	 */
	listName?: NamePath
}

// 抽象組件，適用任何拿來 iFrame 的平台
const Code: FC<TCodeProps> = (codeProps) => {
	const { hideSubtitle: _hideSubtitle, listName, ...formItemProps } = codeProps
	const { name } = formItemProps
	const form = Form.useFormInstance()
	const [value, setValue] = useState('')

	if (!name) {
		throw new Error('name is required')
	}

	/** Form.List 場景下手動拼上 list 前綴；非 Form.List 場景 fullPath === name */
	const fullPath = getFullPath(name, listName)
	const watchField = Form.useWatch(fullPath, form)

	useEffect(() => {
		if (watchField) {
			setValue(watchField?.id)
		}
	}, [watchField])

	const handleChange = (e: ChangeEvent<HTMLTextAreaElement>) => {
		setValue(e.target.value)
		form.setFieldValue(fullPath, {
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
