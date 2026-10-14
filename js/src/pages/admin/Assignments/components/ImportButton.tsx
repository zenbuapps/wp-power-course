/**
 * 匯入批改結果 CSV 按鈕（Issue #131 第二波，DEC-W2-18）
 *
 * 放在匯出按鈕旁。antd Upload 只負責開啟檔案對話框：beforeUpload 攔下檔案交給
 * `useImportGradingCsv` 上傳，回傳 `LIST_IGNORE` 不走 antd 自己的上傳、也不進檔案清單。
 * 匯入結果由外層以頁內 Alert（`ImportResult`）顯示。
 * 對應 `features/assignment/匯入批改結果CSV.feature`。
 */
import { UploadOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Button, Upload, type UploadProps } from 'antd'
import { memo } from 'react'

import { useImportGradingCsv } from '../hooks'
import { type TImportButtonProps } from '../types'

/** 檔案對話框只列出 CSV */
const CSV_ACCEPT = '.csv,text/csv'

/**
 * 匯入按鈕元件
 */
const ImportButtonComponent = ({ onStart, onImported }: TImportButtonProps) => {
	const { importCsv, isImporting } = useImportGradingCsv()

	/**
	 * 選好檔案後直接上傳
	 *
	 * @param file 使用者選擇的檔案
	 */
	const handleBeforeUpload: UploadProps['beforeUpload'] = (file) => {
		onStart()
		importCsv(file, onImported)

		return Upload.LIST_IGNORE
	}

	return (
		<Upload
			accept={CSV_ACCEPT}
			showUploadList={false}
			beforeUpload={handleBeforeUpload}
			disabled={isImporting}
		>
			<Button icon={<UploadOutlined />} loading={isImporting}>
				{__('Import grading CSV', 'power-course')}
			</Button>
		</Upload>
	)
}

export const ImportButton = memo(ImportButtonComponent)
