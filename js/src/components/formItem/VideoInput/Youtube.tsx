import { Form, FormItemProps } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import { FC } from 'react'

import { getYoutubeVideoId } from '@/utils'

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

type TYoutubeProps = FormItemProps & {
	/** Issue #10：多影片試看時為 true，跳過 SubtitleManager 渲染 */
	hideSubtitle?: boolean
	/**
	 * 影片所屬的 video slot；由父元件明確指定（特別是 Form.List 多影片試看場景，
	 * 此時 name 末元素是陣列數字 index，無法推斷出 slot 字串）。
	 *
	 * 未傳時 fallback 至「從 name 末元素推斷」邏輯，保持向下相容
	 * （chapter_video / feature_video / 單部 trial_video 場景）。
	 */
	videoSlot?: TVideoSlot
	/**
	 * 父層 Form.List 的路徑前綴；非 Form.List 場景不傳。
	 * 詳見 VideoInput/index.tsx 的 prop 說明。
	 */
	listName?: NamePath
}

const Youtube: FC<TYoutubeProps> = (formItemProps) => {
	const {
		hideSubtitle = false,
		videoSlot: videoSlotProp,
		listName,
		...restFormItemProps
	} = formItemProps
	const form = Form.useFormInstance()
	const name = formItemProps?.name
	if (!name) {
		throw new Error('name is required')
	}

	/**
	 * Video slot 解析優先順序：
	 *  1. 父元件明確傳入的 `videoSlot` prop（多部試看場景必須走此路徑）
	 *  2. fallback：從 name 末元素推斷（向下相容單部影片場景）
	 */
	const nameArray = Array.isArray(name) ? name : [name]
	const rawSlot = nameArray[nameArray.length - 1]
	const inferredSlot: TVideoSlot = VALID_VIDEO_SLOTS.includes(
		rawSlot as TVideoSlot
	)
		? (rawSlot as TVideoSlot)
		: 'chapter_video'
	const videoSlot: TVideoSlot = videoSlotProp ?? inferredSlot

	/** recordId 是 root 的 postId，不需拼 listName 前綴 */
	const recordId = Form.useWatch(['id'], form)

	/** Form.List 場景下手動拼上 list 前綴 */
	const fullPath = getFullPath(name, listName)
	/** 監聽影片欄位值，判斷是否已填入影片 */
	const watchField = Form.useWatch(fullPath, form)
	const hasVideo = !!watchField?.id

	const getVideoUrl = (videoId: string | null, input?: string) => {
		if (input) {
			const urlObj = new URL(input)
			if (urlObj.hostname === 'youtu.be') {
				return `https://youtu.be/${videoId}`
			}
			return `https://www.youtube.com/watch?v=${videoId}`
		}

		if (videoId) {
			return `https://www.youtube.com/watch?v=${videoId}`
		}

		return ''
	}
	const getEmbedVideoUrl = (videoId: string | null) =>
		videoId ? `https://www.youtube.com/embed/${videoId}` : ''

	return (
		<>
			<Iframe
				type="youtube"
				formItemProps={restFormItemProps}
				getVideoId={getYoutubeVideoId}
				getEmbedVideoUrl={getEmbedVideoUrl}
				getVideoUrl={getVideoUrl}
				exampleUrl="https://www.youtube.com/watch?v=fqcPIPczRVA"
				listName={listName}
			/>
			{recordId && hasVideo && !hideSubtitle && (
				<SubtitleManager postId={recordId} videoSlot={videoSlot} />
			)}
		</>
	)
}

export default Youtube
