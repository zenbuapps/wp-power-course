import { UploadOutlined } from '@ant-design/icons'
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
				message.error(`${file.name} 不是 csv 檔`)
			}
			return isCSV(file) || Upload.LIST_IGNORE
		},
		onChange(info) {
			if (info.file.status === 'done') {
				message.success(
					`${info.file.name} 檔案上傳成功，上傳完成後會寄信通知管理員`
				)
			} else if (info.file.status === 'error') {
				message.error(`${info.file.name} file upload failed.`)
			}
		},
		...uploadProps,
	}
	return (
		<Upload {...props}>
			<Button
				icon={<UploadOutlined />}
				{...{
					children: '上傳 csv 檔',
					...buttonProps,
				}}
			/>
		</Upload>
	)
}

function isCSV(file: any) {
	return file.type === 'text/csv'
}
