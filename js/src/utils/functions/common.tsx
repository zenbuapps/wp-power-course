import React from 'react'

export const windowOuterWidth = window?.outerWidth || 1200

export const isIphone = /iPhone/.test(navigator.userAgent)

export const handleClearZero = (e: React.MouseEvent<HTMLInputElement>) => {
	const target = e.target as HTMLInputElement
	if (target.value === '0') {
		target.value = ''
	}
}

export const getCopyableJson = (variable: any) => {
	const jsonStringStrippedEscapeC = JSON.stringify(
		JSON.stringify(variable || '{}')
	).replace(/\\/g, '')
	const jsonString = jsonStringStrippedEscapeC.slice(
		1,
		jsonStringStrippedEscapeC.length - 1
	)

	if (typeof variable === 'object') {
		const countKeys = Object.keys(variable).length

		return countKeys === 0 ? '' : jsonString
	}
	return !!variable ? jsonString : ''
}

export const getQueryString = (name: string) => {
	const urlParams = new URLSearchParams(window.location.search)
	const paramValue = urlParams.get(name)
	return paramValue
}

export const getCurrencyString = ({
	price,
	symbol = 'NT$',
}: {
	price: number | string | undefined
	symbol?: string
}) => {
	if (typeof price === 'undefined') return ''
	if (typeof price === 'string') return `${symbol} ${price}`
	return `${symbol} ${price.toString()}`
}

export const filterObjKeys = (
	obj: object,
	arr: (string | number | boolean | undefined | null)[] = [undefined]
) => {
	for (const key in obj) {
		if (arr.includes(obj[key as keyof typeof obj])) {
			delete obj[key as keyof typeof obj]
		} else if (typeof obj[key as keyof typeof obj] === 'object') {
			filterObjKeys(obj[key as keyof typeof obj]) // 递归处理嵌套对象
			if (Object.keys(obj[key as keyof typeof obj]).length === 0) {
				delete obj[key as keyof typeof obj]
			}
		}
	}

	return obj
}

export const isUsingBlockEditor =
	typeof window?.wp !== 'undefined' && typeof window?.wp?.blocks !== 'undefined'

export function removeTrailingSlash(str: string) {
	if (str.endsWith('/')) {
		// 如果字符串以斜杠结尾，使用 slice 方法去除最后一个字符

		return str.slice(0, -1)
	}

	// 否则，返回原字符串

	return str
}

export const getIsVariation = (type: string) => {
	return ['variation', 'subscription_variation'].includes(type)
}

export const getFileExtension = (filename: string) => {
	// 先將檔名轉為小寫，以便處理大小寫不同的情況
	const name = filename.toLowerCase()

	// 尋找最後一個點的位置
	const lastDotPosition = name.lastIndexOf('.')

	// 如果沒有找到點，或者點在開頭（隱藏檔案），則返回空字串
	if (lastDotPosition < 1) return ''

	// 返回從最後一個點之後到結尾的子字串
	return name.slice(lastDotPosition + 1)
}

export const getEstimateUploadTimeInSeconds = (fileSize: number) => {
	// 將文件大小轉換為 bits（1 byte = 8 bits）

	const fileSizeInBits = fileSize * 8

	// 上傳速度（30 Mbps = 30,000,000 bits/second）
	const uploadSpeed = 30 * 1000 * 1000 // bits per second

	// 計算預期上傳時間（秒）

	const estimatedTimeInSeconds = fileSizeInBits / uploadSpeed

	// 返回秒數，保留兩位小數

	return Number(estimatedTimeInSeconds.toFixed(2))
}

export const getVideoUrl = (file: File) => {
	return URL.createObjectURL(file)
}

/**
 * 從 Youtube 的 URL 中取得影片 ID
 *
 * @param {string} url
 * @return {string | null} 影片 ID
 */
export const getYoutubeVideoId = (url: string | null): string | null => {
	if (!url) return ''
	try {
		const urlObj = new URL(url)
		if (urlObj.hostname === 'youtu.be') {
			return urlObj.pathname.slice(1)
		}
		const searchParams = new URLSearchParams(urlObj.search)
		return searchParams.get('v')
	} catch (error) {
		console.error('Invalid YouTube URL:', error)
		return null
	}
}

/**
 * 從 vimeo 的 URL 中取得影片 ID
 *
 * @param {string} url // ex: https://vimeo.com/900151069 或 https://vimeo.com/900151069/abc123def
 * @return {string | null} 影片 ID (可能包含 HASH)
 */
export const getVimeoVideoId = (url: string | null): string | null => {
	if (!url) return ''
	try {
		const regex =
			/(?:https?:\/\/)?(?:www\.)?vimeo\.com\/(\d+(?:\/[a-zA-Z0-9]+)?)/
		const match = url.match(regex)
		return match ? match[1] : null
	} catch (error) {
		console.error('Invalid Vimeo URL:', error)
		return null
	}
}
