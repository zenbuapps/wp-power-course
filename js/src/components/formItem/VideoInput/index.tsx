import { __ } from '@wordpress/i18n'
import { FormItemProps, Select, Form } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import { FC } from 'react'

import { getFullPath } from '../utils'

import Bunny from './Bunny'
import Code from './Code'
import { TVideoSlot } from './types'
import Vimeo from './Vimeo'
import Youtube from './Youtube'

const { Item } = Form

/**
 * VideoInput props
 *
 * - `videoSlot`：明確指定影片所屬的 slot（如 `trial_video_3`），
 *   父元件知道自己處於哪種場景時應傳此 prop（特別是 Form.List 多影片試看，
 *   因為 Bunny 從 name 末元素推斷會拿到陣列數字 index 而非 slot 字串）。
 *   未傳時 Bunny 會 fallback 到「從 name 末元素推斷」，向下相容單一影片場景
 *   （chapter_video / feature_video / 單部 trial_video）。
 * - `listName`：當 VideoInput 被放在 `Form.List` 內時，由父層傳入 list 路徑前綴。
 *   `Form.useWatch` 不會繼承 Form.List 的 name 前綴，必須手動拼上才能正確讀到值，
 *   否則 conditional render（Youtube / Vimeo / Bunny / Code 區塊）永遠不會亮。
 *   此 prop 會繼續往下透傳給 Bunny / Youtube / Vimeo / Code 子元件，
 *   因為它們內部也有同樣的 useWatch / setFieldValue 直接吃 form instance。
 *   非 Form.List 場景（feature_video、chapter_video）不需要傳此 prop。
 */
export type TVideoInputProps = FormItemProps & {
	videoSlot?: TVideoSlot
	listName?: NamePath
}

export const VideoInput: FC<TVideoInputProps> = (videoInputProps) => {
	const { name, videoSlot, listName, ...restFormItemProps } = videoInputProps
	const form = Form.useFormInstance()

	/**
	 * 計算 useWatch 的完整路徑：若在 Form.List 內，需手動拼上 list 前綴。
	 * 注意：`form.setFieldValue` 走的是 root form instance，因此寫入時也要拼上 listName。
	 */
	const fullPath = getFullPath(name as NamePath, listName)
	const watchVideoType = Form.useWatch([...fullPath, 'type'], form)

	const handleChange = () => {
		form.setFieldValue([...fullPath, 'id'], '')
		form.setFieldValue([...fullPath, 'meta'], {})
	}

	const subProps = { name, ...restFormItemProps, videoSlot, listName }

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
