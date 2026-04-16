import { useApiUrl, useCustom, useCustomMutation } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { message } from 'antd'
import { useState } from 'react'

import { TSubtitleTrack, TVideoSlot } from '../types'

/** useSubtitles hook 參數 */
type TUseSubtitlesParams = {
	/** Post ID，用於呼叫字幕相關 API */
	postId: number
	/** Video slot 名稱 */
	videoSlot?: TVideoSlot
}

/** useSubtitles hook 回傳值 */
type TUseSubtitlesReturn = {
	subtitles: TSubtitleTrack[]
	isLoading: boolean
	isUploading: boolean
	deletingLang: string | null
	handleUpload: (
		file: File,
		srclang: string,
		callbacks?: { onSettled?: () => void }
	) => void
	handleDelete: (srclang: string) => void
}

/**
 * 字幕管理 Hook
 * 封裝字幕的 GET / POST / DELETE 操作，透過 Refine Data Hooks 與後端溝通
 */
export const useSubtitles = ({
	postId,
	videoSlot = 'chapter_video',
}: TUseSubtitlesParams): TUseSubtitlesReturn => {
	const apiUrl = useApiUrl('power-course')
	const subtitleBaseUrl = `${apiUrl}/posts/${postId}/subtitles/${videoSlot}`

	const [deletingLang, setDeletingLang] = useState<string | null>(null)

	/** 取得字幕列表 */
	const {
		data: queryData,
		isLoading,
		refetch,
	} = useCustom<TSubtitleTrack[]>({
		url: subtitleBaseUrl,
		method: 'get',
		queryOptions: {
			enabled: !!postId,
		},
	})

	const subtitles = queryData?.data ?? []

	/** 上傳字幕 */
	const { mutate: mutateUpload, isLoading: isUploading } = useCustomMutation()

	const handleUpload = (
		file: File,
		srclang: string,
		callbacks?: { onSettled?: () => void }
	) => {
		const formData = new FormData()
		formData.append('file', file)
		formData.append('srclang', srclang)

		mutateUpload(
			{
				url: subtitleBaseUrl,
				method: 'post',
				values: formData as unknown as Record<string, unknown>,
				config: {
					headers: { 'Content-Type': 'multipart/form-data' },
				},
				successNotification: false,
				errorNotification: false,
			},
			{
				onSuccess: () => {
					message.success(
						sprintf(
							// translators: %s: 字幕語言代碼，例如 zh-TW、en
							__('%s subtitle uploaded successfully', 'power-course'),
							srclang
						)
					)
					refetch()
				},
				onError: (error) => {
					const errorMessage =
						error?.message || __('Failed to upload subtitle', 'power-course')
					message.error(errorMessage)
				},
				onSettled: () => {
					callbacks?.onSettled?.()
				},
			}
		)
	}

	/** 刪除字幕 */
	const { mutate: mutateDelete } = useCustomMutation()

	const handleDelete = (srclang: string) => {
		setDeletingLang(srclang)
		mutateDelete(
			{
				url: `${subtitleBaseUrl}/${srclang}`,
				method: 'delete',
				values: {},
				successNotification: false,
				errorNotification: false,
			},
			{
				onSuccess: () => {
					message.success(
						sprintf(
							// translators: %s: 字幕語言代碼，例如 zh-TW、en
							__('%s subtitle deleted', 'power-course'),
							srclang
						)
					)
					refetch()
				},
				onError: () => {
					message.error(__('Failed to delete subtitle', 'power-course'))
				},
				onSettled: () => {
					setDeletingLang(null)
				},
			}
		)
	}

	return {
		subtitles,
		isLoading,
		isUploading,
		deletingLang,
		handleUpload,
		handleDelete,
	}
}
