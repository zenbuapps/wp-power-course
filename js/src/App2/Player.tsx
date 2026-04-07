import '@vidstack/react/player/styles/default/theme.css'
import '@vidstack/react/player/styles/default/layouts/video.css'
import { MediaPlayer, MediaProvider, Poster, Track } from '@vidstack/react'
import {
	defaultLayoutIcons,
	DefaultVideoLayout,
	DefaultAudioLayout,
} from '@vidstack/react/player/layouts/default'
import { stringToBool } from 'antd-toolkit/wp'
import React, { useMemo, useState } from 'react'

import { WaterMark } from '@/components/general'

import Ended from './Ended'

let showWatermark = false

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
}: TPlayerProps) => {
	const [isPlaying, setIsPlaying] = useState(false)
	const [isEnded, setIsEnded] = useState(false)

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
				onEnded={() => setIsEnded(true)}
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
