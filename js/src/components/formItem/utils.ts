import type { NamePath } from 'antd/es/form/interface'

/**
 * 將 NamePath（string | number | (string | number)[]）統一轉成陣列形式。
 * antd 的 NamePath 可能是純字串、純數字，或路徑陣列，這裡統一規格化為陣列
 * 以便後續做路徑拼接。
 */
export const toNameArray = (path: NamePath): (string | number)[] =>
	Array.isArray(path) ? path : [path]

/**
 * 計算 form instance API（useWatch / setFieldValue / getFieldValue）所需的完整路徑。
 *
 * 在 `Form.List` 內，`Form.Item` 的 `name` 會自動繼承 list 前綴，
 * 但 `Form.useWatch`、`form.setFieldValue`、`form.getFieldValue` 等
 * 直接操作 form instance 的 API **不會繼承**，必須手動拼上 listName 前綴。
 *
 * @param name     元件當前的相對 name（在 Form.List 內就是 field index 對應的相對路徑）
 * @param listName 父層 Form.List 的路徑前綴；非 Form.List 場景不傳
 * @return 拼接完成的完整路徑陣列
 */
export const getFullPath = (
	name: NamePath,
	listName?: NamePath
): (string | number)[] => {
	const nameArr = toNameArray(name)
	return listName ? [...toNameArray(listName), ...nameArr] : [...nameArr]
}
