import { useCallback, useEffect, useRef, useState } from 'react'

/** 僅追蹤這些影片類型的播放進度 */
const TRACKED_VIDEO_TYPES = ['bunny', 'bunny-stream-api', 'youtube', 'vimeo']

/** Throttle 間隔（毫秒） */
const THROTTLE_MS = 10_000

interface UseChapterProgressOptions {
	chapterId?: string
	courseId?: string
	videoType?: string
}

interface UseChapterProgressResult {
	/** 初始播放位置（秒），0 代表不 seek */
	initialPosition: number
	/** Throttle 10 秒的 POST 回呼 */
	handleTimeUpdate: (currentTime: number) => void
	/** 暫停時立即 flush */
	handlePause: (currentTime: number) => void
	/** 影片結束時立即 flush */
	handleEnded: (currentTime: number) => void
}

/**
 * 章節播放進度 Hook
 *
 * 負責：
 * 1. 初始 GET → 取得上次播放位置
 * 2. onTimeUpdate throttle 10s → POST 寫入進度
 * 3. onPause / onEnded → 立即 flush
 * 4. beforeunload / visibilitychange → sendBeacon flush
 */
export function useChapterProgress({
	chapterId,
	courseId: _courseId,
	videoType,
}: UseChapterProgressOptions): UseChapterProgressResult {
	const [initialPosition, setInitialPosition] = useState<number>(0)

	/** 是否應該追蹤此影片類型 */
	const shouldTrack = Boolean(
		chapterId &&
			videoType &&
			TRACKED_VIDEO_TYPES.includes(videoType),
	)

	/** 最後一次 POST 的時間戳（用於 throttle） */
	const lastPostTimeRef = useRef<number>(0)

	/** 當前播放位置（供 beforeunload 等非同步事件使用） */
	const currentPositionRef = useRef<number>(0)

	/** pending throttle timer */
	const throttleTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

	/** 取得 REST API nonce */
	const getNonce = useCallback((): string => {
		return (window as Window & { wpApiSettings?: { nonce?: string } })
			?.wpApiSettings?.nonce ?? ''
	}, [])

	/** REST API base URL */
	const getApiBase = useCallback((): string => {
		return `${window.location.origin}/wp-json/power-course`
	}, [])

	/**
	 * POST 進度到後端（一般 fetch）
	 */
	const postProgress = useCallback(
		async (currentTime: number): Promise<void> => {
			if (!shouldTrack || !chapterId) return

			try {
				await fetch(
					`${getApiBase()}/chapters/${chapterId}/progress`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': getNonce(),
						},
						body: JSON.stringify({ last_position_seconds: currentTime }),
					},
				)
			} catch {
				// 靜默忽略背景請求失敗
			}
		},
		[shouldTrack, chapterId, getApiBase, getNonce],
	)

	/**
	 * sendBeacon POST（用於 beforeunload / visibilitychange）
	 * 使用 application/x-www-form-urlencoded + nonce 放 query string
	 */
	const beaconProgress = useCallback(
		(currentTime: number): void => {
			if (!shouldTrack || !chapterId) return

			const nonce = getNonce()
			const url = `${getApiBase()}/chapters/${chapterId}/progress?_wpnonce=${encodeURIComponent(nonce)}`
			const body = new URLSearchParams({
				last_position_seconds: String(currentTime),
			}).toString()
			const blob = new Blob([body], {
				type: 'application/x-www-form-urlencoded',
			})

			const sent = navigator.sendBeacon(url, blob)
			if (!sent) {
				// fallback: fetch keepalive
				fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body,
					keepalive: true,
				}).catch(() => {
					// 靜默忽略
				})
			}
		},
		[shouldTrack, chapterId, getApiBase, getNonce],
	)

	// ========== 初始 GET ==========

	useEffect(() => {
		if (!shouldTrack || !chapterId) return

		const controller = new AbortController()

		fetch(`${getApiBase()}/chapters/${chapterId}/progress`, {
			headers: {
				'X-WP-Nonce': getNonce(),
			},
			signal: controller.signal,
		})
			.then((res) => res.json())
			.then((json: unknown) => {
				const data = (json as { data?: { last_position_seconds?: number } })
					?.data
				const seconds = data?.last_position_seconds ?? 0
				if (seconds > 0) {
					setInitialPosition(seconds)
				}
			})
			.catch(() => {
				// 靜默忽略（abort 或網路錯誤）
			})

		return () => {
			controller.abort()
		}
	}, [shouldTrack, chapterId, getApiBase, getNonce])

	// ========== beforeunload / visibilitychange flush ==========

	useEffect(() => {
		if (!shouldTrack) return

		const handleBeforeUnload = () => {
			beaconProgress(currentPositionRef.current)
		}

		const handleVisibilityChange = () => {
			if (document.visibilityState === 'hidden') {
				beaconProgress(currentPositionRef.current)
			}
		}

		window.addEventListener('beforeunload', handleBeforeUnload)
		document.addEventListener('visibilitychange', handleVisibilityChange)

		return () => {
			window.removeEventListener('beforeunload', handleBeforeUnload)
			document.removeEventListener('visibilitychange', handleVisibilityChange)

			// 清除 pending throttle timer
			if (throttleTimerRef.current) {
				clearTimeout(throttleTimerRef.current)
				throttleTimerRef.current = null
			}
		}
	}, [shouldTrack, beaconProgress])

	// ========== 事件處理回呼 ==========

	/** Throttle 10 秒的 timeUpdate 回呼 */
	const handleTimeUpdate = useCallback(
		(currentTime: number): void => {
			currentPositionRef.current = currentTime

			if (!shouldTrack) return

			const now = Date.now()
			const elapsed = now - lastPostTimeRef.current

			if (elapsed >= THROTTLE_MS) {
				lastPostTimeRef.current = now
				postProgress(currentTime)
			} else if (!throttleTimerRef.current) {
				// 排程在 throttle 到期後再發
				const remaining = THROTTLE_MS - elapsed
				throttleTimerRef.current = setTimeout(() => {
					throttleTimerRef.current = null
					lastPostTimeRef.current = Date.now()
					postProgress(currentPositionRef.current)
				}, remaining)
			}
		},
		[shouldTrack, postProgress],
	)

	/** 暫停時立即 flush */
	const handlePause = useCallback(
		(currentTime: number): void => {
			currentPositionRef.current = currentTime
			if (!shouldTrack) return

			// 取消 pending throttle（避免重複送出）
			if (throttleTimerRef.current) {
				clearTimeout(throttleTimerRef.current)
				throttleTimerRef.current = null
			}
			lastPostTimeRef.current = Date.now()
			postProgress(currentTime)
		},
		[shouldTrack, postProgress],
	)

	/** 影片結束時立即 flush */
	const handleEnded = useCallback(
		(currentTime: number): void => {
			currentPositionRef.current = currentTime
			if (!shouldTrack) return

			if (throttleTimerRef.current) {
				clearTimeout(throttleTimerRef.current)
				throttleTimerRef.current = null
			}
			lastPostTimeRef.current = Date.now()
			postProgress(currentTime)
		},
		[shouldTrack, postProgress],
	)

	return {
		initialPosition,
		handleTimeUpdate,
		handlePause,
		handleEnded,
	}
}
