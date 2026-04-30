import { __ } from '@wordpress/i18n'
import { FormItemProps, Select, Form } from 'antd'
import { FC } from 'react'

import Bunny from './Bunny'
import Code from './Code'
import Vimeo from './Vimeo'
import Youtube from './Youtube'

const { Item } = Form

/**
 * VideoInput props
 *
 * `hideSubtitle`：Issue #10 多影片試看 (TrialVideosList) 場景傳 true，
 * 跳過 SubtitleManager 渲染 —— 多影片字幕屬 v2 範圍，目前 trial_video 字幕仍走單一 slot。
 */
export type TVideoInputProps = FormItemProps & {
	hideSubtitle?: boolean
}

export const VideoInput: FC<TVideoInputProps> = (videoInputProps) => {
	const { name, hideSubtitle = false, ...restFormItemProps } = videoInputProps
	const form = Form.useFormInstance()
	const watchVideoType = Form.useWatch([...name, 'type'], form)

	const handleChange = () => {
		form.setFieldValue([...name, 'id'], '')
		form.setFieldValue([...name, 'meta'], {})
	}

	const subProps = { name, ...restFormItemProps, hideSubtitle }

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
			{watchVideoType === 'youtube' && <Youtube {...subProps} />}
			{watchVideoType === 'vimeo' && <Vimeo {...subProps} />}
			{watchVideoType === 'bunny-stream-api' && <Bunny {...subProps} />}
			{watchVideoType === 'code' && <Code {...subProps} />}
		</>
	)
}
