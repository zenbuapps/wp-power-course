import { UploadOutlined } from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Button, message, Upload, UploadProps, ButtonProps } from 'antd'
import { PiMicrosoftExcelLogoFill } from 'react-icons/pi'

type TFileUploadProps = {
	uploadProps: UploadProps
	buttonProps?: ButtonProps
}

/**
 * 一般檔案上傳，例如 csv ，後端收到 2 進制檔案後處理
 * 預設為處理csv
 * 至少要填 action 跟
 * @param {TFileUploadProps} { uploadProps }
 * @return {JSX.Element} Component
 */
export const FileUpload = ({ uploadProps, buttonProps }: TFileUploadProps) => {
	const props: UploadProps = {
		name: 'files',
		headers: {
			'X-WP-Nonce': window?.wpApiSettings?.nonce || '',
		},
		iconRender: (file) => {
			if (isCSV(file)) {
				return <PiMicrosoftExcelLogoFill className="relative top-0.5" />
			}
			return <UploadOutlined />
		},
		maxCount: 1,
		accept: '.csv',
		withCredentials: true,
		beforeUpload: (file) => {
			if (!isCSV(file)) {
				message.error(
					sprintf(
						// translators: %s: 檔案名稱
						__('%s is not a CSV file', 'power-course'),
						file.name
					)
				)
			}
			return isCSV(file) || Upload.LIST_IGNORE
		},
		onChange(info) {
			if (info.file.status === 'done') {
				message.success(
					sprintf(
						// translators: %s: 檔案名稱
						__(
							'%s uploaded successfully, the administrator will be notified by email when processing is complete',
							'power-course'
						),
						info.file.name
					)
				)
			} else if (info.file.status === 'error') {
				message.error(
					sprintf(
						// translators: %s: 檔案名稱
						__('%s file upload failed.', 'power-course'),
						info.file.name
					)
				)
			}
		},
		...uploadProps,
	}
	return (
		<Upload {...props}>
			<Button
				icon={<UploadOutlined />}
				{...{
					children: __('Upload CSV file', 'power-course'),
					...buttonProps,
				}}
			/>
		</Upload>
	)
}

function isCSV(file: any) {
	return file.type === 'text/csv'
}
