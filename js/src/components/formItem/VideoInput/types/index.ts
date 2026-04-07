export type TVideoType = 'youtube' | 'vimeo' | 'bunny-stream-api' | 'code'

export type TVideo = {
	type: TVideoType
	id: string
	meta: {
		[key: string]: any
	}
}

/** Video slot 類型，對應後端的影片欄位名稱 */
export type TVideoSlot = 'chapter_video' | 'feature_video' | 'trial_video'

/** 字幕軌道資料 */
export type TSubtitleTrack = {
	srclang: string
	label: string
	url: string
	attachment_id: number
}
