import { useApiUrl, useCustom, useCustomMutation } from '@refinedev/core'
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
					message.success(`${srclang} 字幕上傳成功`)
					refetch()
				},
				onError: (error) => {
					const errorMessage = error?.message || '字幕上傳失敗'
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
					message.success(`${srclang} 字幕已刪除`)
					refetch()
				},
				onError: () => {
					message.error('字幕刪除失敗')
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
