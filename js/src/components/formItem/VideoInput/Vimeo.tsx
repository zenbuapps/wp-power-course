import { Form, FormItemProps } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import { FC } from 'react'

import { getVimeoVideoId } from '@/utils'

import { getFullPath } from '../utils'

import Iframe from './Iframe'
import SubtitleManager from './SubtitleManager'
import { TVideoSlot } from './types'

/** 有效的 Video Slot 值，用於 runtime 驗證 */
const VALID_VIDEO_SLOTS: TVideoSlot[] = [
	'chapter_video',
	'feature_video',
	'trial_video',
]

type TVimeoProps = FormItemProps & {
	/** Issue #10：多影片試看時為 true，跳過 SubtitleManager 渲染 */
	hideSubtitle?: boolean
	/**
	 * 父層 Form.List 的路徑前綴；非 Form.List 場景不傳。
	 * 詳見 VideoInput/index.tsx 的 prop 說明。
	 */
	listName?: NamePath
}

const Vimeo: FC<TVimeoProps> = (formItemProps) => {
	const { hideSubtitle = false, listName, ...restFormItemProps } = formItemProps
	const form = Form.useFormInstance()
	const name = formItemProps?.name
	if (!name) {
		throw new Error('name is required')
	}

	/** 從 NamePath 陣列最後一個元素取得 video slot，並做 runtime 驗證 */
	const nameArray = Array.isArray(name) ? name : [name]
	const rawSlot = nameArray[nameArray.length - 1]
	const videoSlot: TVideoSlot = VALID_VIDEO_SLOTS.includes(
		rawSlot as TVideoSlot
	)
		? (rawSlot as TVideoSlot)
		: 'chapter_video'

	/** recordId 是 root 的 postId，不需拼 listName 前綴 */
	const recordId = Form.useWatch(['id'], form)

	/** Form.List 場景下手動拼上 list 前綴 */
	const fullPath = getFullPath(name, listName)
	/** 監聽影片欄位值，判斷是否已填入影片 */
	const watchField = Form.useWatch(fullPath, form)
	const hasVideo = !!watchField?.id

	const getVideoUrl = (videoId: string | null) =>
		videoId ? `https://vimeo.com/${videoId}` : ''
	const getEmbedVideoUrl = (videoId: string | null) => {
		if (!videoId) return ''

		// videoId 以 / 拆開
		const [vId, hash] = videoId.split('/')

		return `https://player.vimeo.com/video/${vId}?h=${hash}&color=a6a8a8&title=0&byline=0&portrait=0`
	}

	return (
		<>
			<Iframe
				type="vimeo"
				formItemProps={restFormItemProps}
				getVideoId={getVimeoVideoId}
				getEmbedVideoUrl={getEmbedVideoUrl}
				getVideoUrl={getVideoUrl}
				exampleUrl="https://vimeo.com/900151069"
				listName={listName}
			/>
			{recordId && hasVideo && !hideSubtitle && (
				<SubtitleManager postId={recordId} videoSlot={videoSlot} />
			)}
		</>
	)
}

export default Vimeo
