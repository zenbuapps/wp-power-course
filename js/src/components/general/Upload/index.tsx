import { InboxOutlined } from '@ant-design/icons'
import { Upload as AntdUpload, UploadProps } from 'antd'
import React from 'react'

const { Dragger } = AntdUpload

export const Upload: React.FC<{
	uploadProps: UploadProps
}> = ({ uploadProps }) => {
	return (
		<>
			<div className="aspect-video w-full">
				<Dragger {...uploadProps}>
					<p className="ant-upload-drag-icon">
						<InboxOutlined />
					</p>
					<p className="ant-upload-text">點擊或拖曳文件到這裡上傳</p>
					<p className="ant-upload-hint">僅支持 video/mp4 類型 文件</p>
				</Dragger>
			</div>
		</>
	)
}
