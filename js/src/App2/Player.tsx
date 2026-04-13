import '@vidstack/react/player/styles/default/theme.css'
import '@vidstack/react/player/styles/default/layouts/video.css'
import { MediaPlayer, MediaProvider, Poster, Track } from '@vidstack/react'
import {
	defaultLayoutIcons,
	DefaultVideoLayout,
	DefaultAudioLayout,
} from '@vidstack/react/player/layouts/default'
import { stringToBool } from 'antd-toolkit/wp'
import React, { useMemo, useRef, useState } from 'react'

import { WaterMark } from '@/components/general'

import Ended from './Ended'

let showWatermark = false

/** 影片自動完成的進度門檻值（95%） */
const FINISH_THRESHOLD = 0.95

/**
 * 字幕軌道資料型別
 */
type TSubtitleTrack = {
	srclang: string
	label: string
	url: string
	attachment_id: number
}

export type TPlayerProps = {
	src: string
	thumbnail_url: string
	watermark_text: string
	watermark_qty: string
	watermark_color: string
	watermark_interval: string
	next_post_url: string
	autoplay: 'yes' | 'no'
	subtitles?: string
	chapter_id?: string
	course_id?: string
	is_finished?: string
}

const Player = ({
	src,
	thumbnail_url,
	watermark_text,
	watermark_qty,
	watermark_color,
	watermark_interval,
	next_post_url,
	autoplay,
	subtitles,
	chapter_id,
	course_id,
	is_finished,
}: TPlayerProps) => {
	const [isPlaying, setIsPlaying] = useState(false)
	const [isEnded, setIsEnded] = useState(false)

	/** 影片總長（秒），透過 onDurationChange 更新，使用 ref 避免不必要的 re-render */
	const durationRef = useRef<number>(0)

	/** 是否已在本次頁面載入中自動完成，防止重複觸發 API */
	const hasAutoFinishedRef = useRef<boolean>(false)

	/**
	 * dispatch 自動完成章節的 Custom DOM Event
	 * 事件會 bubble 到 document 層，由 finishChapter.ts 監聽並呼叫 API
	 */
	const dispatchAutoFinishEvent = (target: EventTarget) => {
		if (is_finished === 'true') return
		if (hasAutoFinishedRef.current) return
		hasAutoFinishedRef.current = true

		target.dispatchEvent(
			new CustomEvent('pc:auto-finish-chapter', {
				detail: {
					chapterId: chapter_id ? Number(chapter_id) : 0,
					courseId: course_id ? Number(course_id) : 0,
				},
				bubbles: true,
			})
		)
	}

	// 解析字幕 JSON 字串為陣列
	const subtitleTracks: TSubtitleTrack[] = useMemo(() => {
		if (!subtitles) return []
		try {
			const parsed = JSON.parse(subtitles)
			if (Array.isArray(parsed)) {
				return parsed as TSubtitleTrack[]
			}
		} catch (_e) {
			// 字幕 JSON 解析失敗，忽略
		}
		return []
	}, [subtitles])

	return (
		<>
			<MediaPlayer
				src={src}
				viewType="video"
				streamType="on-demand"
				logLevel="warn"
				crossOrigin
				playsInline
				poster={thumbnail_url || undefined}
				posterLoad="eager"
				onPlaying={() => {
					setIsPlaying(true)
					showWatermark = true
				}}
				onPause={() => {
					setIsPlaying(false)
				}}
				onDurationChange={(detail) => {
					if (detail > 0) {
						durationRef.current = detail
					}
				}}
				onTimeUpdate={(detail, nativeEvent) => {
					const duration = durationRef.current
					if (duration <= 0) return
					const ratio = detail.currentTime / duration
					if (ratio >= FINISH_THRESHOLD) {
						dispatchAutoFinishEvent(nativeEvent.target as EventTarget)
					}
				}}
				onEnded={(_detail, nativeEvent) => {
					setIsEnded(true)
					dispatchAutoFinishEvent(nativeEvent.target as EventTarget)
				}}
				autoPlay={stringToBool(autoplay)}
			>
				<MediaProvider>
					<Poster className="vds-poster" />
					{subtitleTracks.map((track) => (
						<Track
							key={track.srclang}
							src={track.url}
							kind="subtitles"
							label={track.label}
							language={track.srclang}
							type="vtt"
						/>
					))}
				</MediaProvider>
				<DefaultAudioLayout
					smallLayoutWhen={true}
					icons={defaultLayoutIcons}
					colorScheme="dark"
				/>

				<DefaultVideoLayout
					smallLayoutWhen={true}
					icons={defaultLayoutIcons}
					colorScheme="dark"
				/>

				{isEnded && <Ended next_post_url={next_post_url} />}

				{!isEnded && (
					<div
						className={`absolute w-full h-full top-0 left-0 ${showWatermark ? 'tw-block' : 'tw-hidden'}`}
					>
						<WaterMark
							interval={Number(watermark_interval)}
							qty={Number(watermark_qty)}
							text={watermark_text}
							isPlaying={isPlaying}
							style={{
								color: watermark_color,
							}}
						/>
					</div>
				)}
			</MediaPlayer>
		</>
	)
}

export default Player
