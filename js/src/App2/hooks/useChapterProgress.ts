import { useCallback, useEffect, useRef, useState } from 'react'

/** 僅追蹤這些影片類型的播放進度 */
const TRACKED_VIDEO_TYPES = ['bunny', 'bunny-stream-api', 'youtube', 'vimeo']

/** Throttle 間隔（毫秒） */
const THROTTLE_MS = 30_000

interface UseChapterProgressOptions {
	chapterId?: string
	courseId?: string
	videoType?: string
	/**
	 * 章節是否已完成（finished_at 已寫入）。
	 * 已完成時不再追蹤進度 — 不 GET 上次位置（避免 seek 到末端觸發 ended 循環）、
	 * 不 POST 新進度（保留 DB 原值讓使用者後續回到「繼續觀看」時仍有參考點）。
	 */
	isFinished?: boolean
}

interface UseChapterProgressResult {
	/** 初始播放位置（秒），0 代表不 seek */
	initialPosition: number
	/** 是否正在載入初始位置（GET API 尚未回應） */
	isLoadingPosition: boolean
	/** Throttle 30 秒的 POST 回呼 */
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
 * 2. onTimeUpdate throttle 30s → POST 寫入進度
 * 3. onPause / onEnded → 立即 flush
 * 4. beforeunload / visibilitychange → sendBeacon flush
 */
export function useChapterProgress({
	chapterId,
	courseId: _courseId,
	videoType,
	isFinished = false,
}: UseChapterProgressOptions): UseChapterProgressResult {
	const [initialPosition, setInitialPosition] = useState<number>(0)
	const [isLoadingPosition, setIsLoadingPosition] = useState<boolean>(false)

	/**
	 * 是否應該追蹤此影片類型
	 *
	 * isFinished=true 時一律關閉追蹤，避免：
	 * (A) GET 拉到接近影片結尾的 last_position_seconds，
	 *     被 Player 初始 seek 帶到末端而立刻觸發 onEnded → 自動跳下一章。
	 * (B) 重看過程的 time_update / pause / ended 覆蓋掉 DB 值。
	 */
	const shouldTrack = Boolean(
		chapterId &&
		videoType &&
		TRACKED_VIDEO_TYPES.includes(videoType) &&
		!isFinished
	)

	/** 最後一次 POST 的時間戳（用於 throttle） */
	const lastPostTimeRef = useRef<number>(0)

	/** 當前播放位置（供 beforeunload 等非同步事件使用） */
	const currentPositionRef = useRef<number>(0)

	/** pending throttle timer */
	const throttleTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

	/** 取得 REST API nonce */
	const getNonce = useCallback((): string => {
		return (
			(window as Window & { wpApiSettings?: { nonce?: string } })?.wpApiSettings
				?.nonce ?? ''
		)
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
				await fetch(`${getApiBase()}/chapters/${chapterId}/progress`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': getNonce(),
					},
					body: JSON.stringify({ last_position_seconds: currentTime }),
				})
			} catch {
				// 靜默忽略背景請求失敗
			}
		},
		[shouldTrack, chapterId, getApiBase, getNonce]
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
		[shouldTrack, chapterId, getApiBase, getNonce]
	)

	// ========== 初始 GET ==========

	useEffect(() => {
		if (!shouldTrack || !chapterId) return

		const controller = new AbortController()
		setIsLoadingPosition(true)

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
			.finally(() => {
				setIsLoadingPosition(false)
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

	/** Throttle 30 秒的 timeUpdate 回呼 */
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
		[shouldTrack, postProgress]
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
		[shouldTrack, postProgress]
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
		[shouldTrack, postProgress]
	)

	return {
		initialPosition,
		isLoadingPosition,
		handleTimeUpdate,
		handlePause,
		handleEnded,
	}
}
