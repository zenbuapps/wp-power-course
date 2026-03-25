import Hls from 'hls.js'
import $ from 'jquery'

export const HlsSupport = () => {
	const audios = document.querySelectorAll<HTMLMediaElement>(
		'.power-editor audio',
	)

	if (!audios.length) return

	audios.forEach((audio) => {
		const source = audio.getAttribute('src') || ''

		// 對於原生支持 HLS 的瀏覽器（如 Safari）
		if (!audio.canPlayType('application/vnd.apple.mpegurl')) {
			if (!Hls.isSupported()) {
				$(audio)
					.closest('.bn-block')
					.append(
						'<p class="text-red-500 text-sm">您的瀏覽器不支援 HLS 串流</p>',
					)
				return
			}

			const hls = new Hls()
			hls.loadSource(source)
			hls.attachMedia(audio)

			// hls.on(Hls.Events.MANIFEST_PARSED, function () {
			// 	console.log('HLS manifest loaded')
			// 	audio.play()
			// })

			// 錯誤處理
			hls.on(Hls.Events.ERROR, function (event, data) {
				if (data.fatal) {
					switch (data.type) {
						case Hls.ErrorTypes.NETWORK_ERROR:
							console.error('無法下載串流音檔，請檢查網路或者是否放到影片檔案')
							$(audio)
								.closest('.bn-block')
								.append(
									'<p class="text-red-500 text-sm">無法下載串流音檔，請檢查網路或者是否放到影片檔案</p>',
								)

							// 在 audio 後面插入 <p></p>
							// hls.startLoad()
							break
						case Hls.ErrorTypes.MEDIA_ERROR:
							console.error('Fatal media error encountered, trying to recover')
							hls.recoverMediaError()
							break
						default:
							console.error('Fatal error, cannot recover')
							hls.destroy()
							break
					}
				}
			})
		}
	})
}
