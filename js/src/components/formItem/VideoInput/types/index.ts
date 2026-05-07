export type TVideoType = 'youtube' | 'vimeo' | 'bunny-stream-api' | 'code'

export type TVideo = {
	type: TVideoType
	id: string
	meta: {
		[key: string]: any
	}
}

/**
 * Video slot 類型，對應後端的影片欄位名稱
 *
 * - `chapter_video` / `feature_video` / `trial_video`：單部影片場景，meta key 為 `pc_subtitles_{slot}`
 * - `trial_video_${number}`：多部試看影片場景（TrialVideosList），N = 0~5，
 *   後端對應 meta key 為 `pc_subtitles_trial_video_{N}`，由 SubtitleApi regex 解析
 */
export type TVideoSlot =
	| 'chapter_video'
	| 'feature_video'
	| 'trial_video'
	| `trial_video_${number}`

/** 字幕軌道資料 */
export type TSubtitleTrack = {
	srclang: string
	label: string
	url: string
	attachment_id: number
}
