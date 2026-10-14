/**
 * 匯入批改結果 CSV 的結果摘要（Issue #131 第二波，DEC-W2-18）
 *
 * 頁內可關閉的 Alert：共 N 筆・更新 X・略過 Y・失敗 Z；錯誤列只列前 20 筆，其餘以「另有 N 筆錯誤」帶過。
 * 不用 toast：結果要留在頁面上，讓管理員對照 CSV 修正。
 * 錯誤訊息由後端依站台語系產生，一律以 React 文字節點呈現。
 */
import { __, _n, sprintf } from '@wordpress/i18n'
import { Alert, type AlertProps } from 'antd'
import { memo } from 'react'

import {
	type TImportError,
	type TImportResultProps,
	type TImportSummary,
} from '../types'

/** 錯誤列最多顯示幾筆（同參考實作） */
const MAX_VISIBLE_ERRORS = 20

/**
 * 單列錯誤的顯示文字
 *
 * row 0 代表整份檔案無法處理（解析失敗、空檔、超過列數上限），沒有列號可標，只顯示訊息。
 *
 * @param error 單列錯誤
 */
const getErrorText = ({ row, message }: TImportError): string =>
	row > 0
		? sprintf(
				// translators: 1: CSV 列號（含表頭，第一筆資料為 2）, 2: 錯誤訊息
				__('Row %1$d: %2$s', 'power-course'),
				row,
				message
			)
		: message

/**
 * Alert 類型：有失敗列為 error；有略過列或檔案層錯誤為 warning；其餘為 success
 *
 * @param summary 匯入摘要
 */
const getAlertType = ({
	failed,
	skipped,
	errors,
}: TImportSummary): AlertProps['type'] => {
	if (failed > 0) {
		return 'error'
	}

	return skipped > 0 || errors.length > 0 ? 'warning' : 'success'
}

/**
 * 匯入結果元件
 */
const ImportResultComponent = ({ summary, onClose }: TImportResultProps) => {
	const { total, updated, skipped, failed, errors } = summary
	const hiddenCount = errors.length - MAX_VISIBLE_ERRORS

	return (
		<Alert
			className="pc-assignment-import-result mb-4"
			type={getAlertType(summary)}
			showIcon
			closable
			onClose={onClose}
			message={sprintf(
				// translators: 1: 資料列總數, 2: 更新列數, 3: 略過列數, 4: 失敗列數
				__(
					'Import result: %1$d total, %2$d updated, %3$d skipped, %4$d failed',
					'power-course'
				),
				total,
				updated,
				skipped,
				failed
			)}
			description={
				errors.length > 0 && (
					<ul className="m-0 max-h-40 list-none overflow-y-auto p-0">
						{errors.slice(0, MAX_VISIBLE_ERRORS).map((error, index) => (
							<li key={`${error.row}-${index}`}>{getErrorText(error)}</li>
						))}
						{hiddenCount > 0 && (
							<li className="text-gray-500">
								{sprintf(
									// translators: %d: 未顯示的錯誤筆數
									_n(
										'%d more error',
										'%d more errors',
										hiddenCount,
										'power-course'
									),
									hiddenCount
								)}
							</li>
						)}
					</ul>
				)
			}
		/>
	)
}

export const ImportResult = memo(ImportResultComponent)
