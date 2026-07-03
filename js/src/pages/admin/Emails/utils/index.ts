import { IBlockData } from 'j7-easy-email-core'
import parseJson from 'parse-json'

/**
 * 將 email 內容（DB 取回的字串或編輯器產生的物件）還原為 IBlockData 物件
 *
 * 資料鏈規約（見 JSON_PARSE_ERROR.md）：JSON.stringify 一次、JSON.parse 一次。
 * 此函式是「唯一一次 parse」的入口，並容錯兩種歷史壞資料：
 * 1. 雙重（多重）編碼——早期編輯器尚未載入完就儲存，造成字串被再度 stringify；
 *    偵測到 parse 出字串時會繼續拆層（最多 5 層）
 * 2. 完全非法的 JSON——回傳 null，由呼叫端決定 fallback（絕不靜默改寫資料）
 *
 * @param raw DB 取回的字串或編輯器物件
 * @return 解析成功回傳 IBlockData 物件；空值或解析失敗回傳 null
 */
export function tryParseEmailContent(
	raw: unknown
): IBlockData<any, any> | null {
	let current: unknown = raw
	let depth = 0
	while (typeof current === 'string' && depth < 5) {
		if (current.trim() === '') {
			return null
		}
		try {
			current = parseJson(current)
		} catch (error) {
			console.error('parse JSON error: ', error)
			return null
		}
		depth++
	}
	return current && typeof current === 'object'
		? (current as IBlockData<any, any>)
		: null
}
