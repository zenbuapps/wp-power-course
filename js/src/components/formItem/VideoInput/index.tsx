import { __ } from '@wordpress/i18n'
import { FormItemProps, Select, Form } from 'antd'
import { FC } from 'react'

import Bunny from './Bunny'
import Code from './Code'
import Vimeo from './Vimeo'
import Youtube from './Youtube'

const { Item } = Form

export const VideoInput: FC<FormItemProps> = (formItemProps) => {
	const { name, ...restFormItemProps } = formItemProps
	const form = Form.useFormInstance()
	const watchVideoType = Form.useWatch([...name, 'type'], form)

	const handleChange = () => {
		form.setFieldValue([...name, 'id'], '')
		form.setFieldValue([...name, 'meta'], {})
	}

	return (
		<>
			<Item
				{...restFormItemProps}
				name={[
					...name,
					'type',
				]}
				className="mb-1"
				initialValue="none"
			>
				<Select
					className="w-full"
					size="small"
					onChange={handleChange}
					options={[
						{ value: 'none', label: __('No video', 'power-course') },
						{ value: 'youtube', label: __('Youtube embed', 'power-course') },
						{ value: 'vimeo', label: __('Vimeo embed', 'power-course') },
						{ value: 'bunny-stream-api', label: 'Bunny Stream API' },
						{ value: 'code', label: __('Custom code', 'power-course') },
					]}
				/>
			</Item>
			{watchVideoType === 'youtube' && <Youtube {...formItemProps} />}
			{watchVideoType === 'vimeo' && <Vimeo {...formItemProps} />}
			{watchVideoType === 'bunny-stream-api' && <Bunny {...formItemProps} />}
			{watchVideoType === 'code' && <Code {...formItemProps} />}
		</>
	)
}
