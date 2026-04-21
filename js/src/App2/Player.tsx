import '@vidstack/react/player/styles/default/theme.css'
import '@vidstack/react/player/styles/default/layouts/video.css'
import {
	MediaPlayer,
	MediaProvider,
	Poster,
	Track,
	type MediaPlayerInstance,
} from '@vidstack/react'
import {
	defaultLayoutIcons,
	DefaultVideoLayout,
	DefaultAudioLayout,
} from '@vidstack/react/player/layouts/default'
import { stringToBool } from 'antd-toolkit/wp'
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { WaterMark } from '@/components/general'

import Ended from './Ended'
import { useChapterProgress } from './hooks/useChapterProgress'

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
	video_type?: string
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
	video_type,
}: TPlayerProps) => {
	const [isPlaying, setIsPlaying] = useState(false)
	const [isEnded, setIsEnded] = useState(false)

	/** 影片總長（秒），透過 onDurationChange 更新，使用 ref 避免不必要的 re-render */
	const durationRef = useRef<number>(0)

	/** 當前播放位置（秒），供 onPause / onEnded 使用 */
	const currentTimeRef = useRef<number>(0)

	/** 是否已在本次頁面載入中自動完成，防止重複觸發 API */
	const hasAutoFinishedRef = useRef<boolean>(false)

	/** 是否已執行初始 seek，防止重複 seek */
	const hasSeekedRef = useRef<boolean>(false)

	/** player 是否已進入 canPlay 狀態 */
	const canPlayRef = useRef<boolean>(false)

	/** VidStack player ref（必須透過 ref 操作，useMediaRemote 在 context 外無效） */
	const playerRef = useRef<MediaPlayerInstance>(null)

	/**
	 * 章節已完成（finished_at 已寫入）旗標。
	 * 用於：
	 * 1. 關閉 useChapterProgress 的 GET/POST（不再 seek 到末端、不再覆蓋進度）
	 * 2. 讓 <Ended> 不進入倒數自動跳下一章流程（使用者在重看模式）
	 */
	const isFinishedFlag = is_finished === 'true'

	/** 章節播放進度 hook */
	const { initialPosition, handleTimeUpdate, handlePause, handleEnded } =
		useChapterProgress({
			chapterId: chapter_id,
			courseId: course_id,
			videoType: video_type,
			isFinished: isFinishedFlag,
		})

	/**
	 * 嘗試 seek 到初始位置（統一入口，三個時機點呼叫）
	 * 1. onLoadedMetadata — 最早可 seek 的時機（HLS 特別有效）
	 * 2. onCanPlay — player 確認可播放
	 * 3. useEffect([initialPosition]) — GET 回應晚於 canPlay 時的兜底
	 */
	const trySeekToInitialPosition = useCallback(() => {
		if (initialPosition > 0 && !hasSeekedRef.current && playerRef.current) {
			hasSeekedRef.current = true
			playerRef.current.currentTime = initialPosition
		}
	}, [initialPosition])

	/**
	 * useEffect 兜底：當 GET 回應晚於 canPlay 時，透過 state 變化觸發 seek
	 */
	useEffect(() => {
		if (canPlayRef.current) {
			trySeekToInitialPosition()
		}
	}, [initialPosition, trySeekToInitialPosition])

	/**
	 * 重看本章（Issue #206 Q1=B，post-test 2026-04-20 調整為僅保留此出口）
	 *
	 * 順序（R5 緩解 VidStack ended 狀態下 seek 的不確定性）：
	 * 1. 先 setIsEnded(false) 隱藏遮罩避免畫面 flash
	 * 2. currentTime = 0 seek 回章節開頭
	 * 3. play() 並捕捉 autoplay policy 的 DOMException（靜默失敗，用戶可手動按播放）
	 *
	 * 注意：嚴格不重置 hasAutoFinishedRef（R6 — 避免已完成章節被二次 toggle-finish
	 * 而變成「未完成」）。
	 */
	const handleReplay = useCallback(() => {
		if (!playerRef.current) return
		setIsEnded(false)
		playerRef.current.currentTime = 0
		void playerRef.current.play().catch(() => {
			// 靜默：autoplay policy 阻擋時影片停在 0s，用戶手動點擊播放
		})
	}, [])

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
				ref={playerRef}
				src={src}
				viewType="video"
				streamType="on-demand"
				logLevel="warn"
				crossOrigin
				playsInline
				poster={thumbnail_url || undefined}
				posterLoad="eager"
				onLoadedMetadata={() => {
					// 最早的 seek 時機（HLS 特別有效，YouTube 可能較晚觸發）
					trySeekToInitialPosition()
				}}
				onCanPlay={() => {
					canPlayRef.current = true
					trySeekToInitialPosition()
				}}
				onPlaying={() => {
					setIsPlaying(true)
					showWatermark = true
				}}
				onPause={() => {
					setIsPlaying(false)
					handlePause(currentTimeRef.current)
				}}
				onDurationChange={(detail) => {
					if (detail > 0) {
						durationRef.current = detail
					}
				}}
				onTimeUpdate={(detail, nativeEvent) => {
					currentTimeRef.current = detail.currentTime
					handleTimeUpdate(detail.currentTime)

					const duration = durationRef.current
					if (duration <= 0) return
					const ratio = detail.currentTime / duration
					if (ratio >= FINISH_THRESHOLD) {
						dispatchAutoFinishEvent(nativeEvent.target as EventTarget)
					}
				}}
				onEnded={(_detail, nativeEvent) => {
					setIsEnded(true)
					handleEnded(currentTimeRef.current || durationRef.current)
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

				{isEnded && (
					<Ended
						next_post_url={next_post_url}
						onReplay={handleReplay}
						isFinished={isFinishedFlag}
					/>
				)}

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
