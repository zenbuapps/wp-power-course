import { useLayoutEffect, useRef, useState } from 'react'

type TZoomStyle = {
	zoom: number
	minWidth: number
}

/** 設計基準視窗寬：此寬度（含）以上完全不縮放 */
const DESIGN_VW = 1920

/** 縮放下限對應的視窗寬：再窄就不再縮小，改由橫向捲軸操作 */
const MIN_VW = 1300

/**
 * 郵件編輯器等比例縮放（issue #258）
 *
 * easy-email 的 StandardLayout 是三欄版面：左側元件欄 360px、右側屬性欄 350px
 * 皆為固定寬，中間畫布另有約 640px 的最小寬度，合計需要約 1690px 的版面寬。
 * 視窗窄於約 1610px 時中間畫布會被右側欄蓋掉，信件本體看不到全貌。
 *
 * 這裡不動 easy-email 內部，改讓編輯器「以 1920 視窗的版面尺度排版，再整體
 * 等比例縮小」：
 *
 * - 視窗 >= 1920：zoom = 1，與現況完全相同
 * - 視窗 1300 ~ 1920：zoom = 視窗寬 / 1920（1300 時為 0.6771）
 * - 視窗 < 1300：zoom 停在 0.6771，版面寬凍結在 1300 的水準，溢出部分用橫向捲軸
 *
 * 用 CSS zoom 而非 transform: scale 的理由（皆已於 Chrome 實測）：
 *
 * 1. zoom 會影響 layout box —— 縮放後父層高寬同步收斂；transform 不會，
 *    會殘留未縮放的高度空白與假的橫向捲軸（實測 scrollWidth 1551 vs 1050）
 * 2. zoom 不會成為 position: fixed 子孫的 containing block；transform 會，
 *    arco 的 Modal / Drawer / Trigger 會整組定位錯亂
 * 3. 縮放後 getBoundingClientRect 與滑鼠 clientX / clientY 仍在同一座標系，
 *    easy-email 判定拖放落點的 `mouseY - top <= 0.5 * height` 比值不變，
 *    拖拉行為不受影響
 *
 * 已知副作用：arco 的下拉 / 彈出層 portal 到 document.body，不在縮放範圍內，
 * 會以 100% 尺寸呈現。位置正確、功能正常，僅視覺比例與面板不一致。
 *
 * @return wrapRef 掛在「不縮放」的外層容器上（負責量測可用寬與橫向捲軸），
 *         style   掛在「要縮放」的內層容器上
 */
export const useEditorZoom = () => {
	// 用 callback ref 而非 useRef：編輯器包在 Suspense 內，lazy chunk 解析時序
	// 會讓 React 換掉這個 DOM 節點。若用 useRef + 空依賴的 effect，
	// ResizeObserver 會留在已 detach 的舊節點上（clientWidth 恆為 0），
	// 縮放就再也不會套用
	const [wrap, setWrap] = useState<HTMLDivElement | null>(null)

	// 側欄與卡片留白等固定佔用寬度。取歷程最大值：視窗窄於 xl 斷點時外層版面
	// 會被鎖成 1200px，此值會變小，直接採用會讓「視窗變窄反而版面變寬」
	const maxChromeRef = useRef(0)

	const [style, setStyle] = useState<TZoomStyle>({ zoom: 1, minWidth: 0 })

	useLayoutEffect(() => {
		if (!wrap) {
			return
		}

		const calc = () => {
			// wrap 本身不縮放，clientWidth 即是編輯器可用的實際寬度
			const containerWidth = wrap.clientWidth
			if (!containerWidth) {
				return
			}

			maxChromeRef.current = Math.max(
				maxChromeRef.current,
				window.innerWidth - containerWidth
			)

			const zoom = Math.min(Math.max(window.innerWidth, MIN_VW) / DESIGN_VW, 1)

			// 縮放後的視覺寬度不得低於視窗 1300 時的水準，不足的部分交給橫向捲軸
			const minVisualWidth = Math.max(
				containerWidth,
				MIN_VW - maxChromeRef.current
			)
			const minWidth = minVisualWidth / zoom

			setStyle((prev) =>
				prev.zoom === zoom && prev.minWidth === minWidth
					? prev
					: { zoom, minWidth }
			)
		}

		calc()

		// 側欄收合等不伴隨 window resize 的版面變動也要重算
		const observer = new ResizeObserver(calc)
		observer.observe(wrap)
		window.addEventListener('resize', calc)

		return () => {
			observer.disconnect()
			window.removeEventListener('resize', calc)
		}
	}, [wrap])

	return { wrapRef: setWrap, style }
}
