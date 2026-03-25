import { PlusOutlined, EyeOutlined, CloseOutlined } from '@ant-design/icons'
import { Form, Input, Image } from 'antd'
import { defaultImage } from 'antd-toolkit'
import { MediaLibraryModal, useMediaLibraryModal } from 'antd-toolkit/wp'

const { Item } = Form

/**
 * 使用者頭像上傳元件
 * 使用 WordPress Media Library 選圖器選取頭像
 * 儲存的是圖片 URL（user_avatar_url），非 attachment ID
 */
export const UserAvatarUpload = () => {
	const form = Form.useFormInstance()
	const watchAvatarUrl: string | undefined = Form.useWatch(
		['user_avatar_url'],
		form
	)

	const { show, modalProps, ...mediaLibraryProps } = useMediaLibraryModal({
		initItems: [],
		onConfirm: (selectedItems) => {
			if (selectedItems.length > 0) {
				form.setFieldValue('user_avatar_url', selectedItems[0].url)
			}
		},
	})

	/** 移除頭像 */
	const handleRemove = () => {
		form.setFieldValue('user_avatar_url', '')
	}

	return (
		<div className="flex justify-center w-full mb-4">
			{watchAvatarUrl ? (
				<Image
					className="aspect-square rounded-full object-cover w-24 h-24"
					preview={{
						mask: (
							<div className="flex items-center justify-center gap-2">
								<EyeOutlined />
								<CloseOutlined onClick={handleRemove} />
							</div>
						),
						maskClassName: 'rounded-full',
					}}
					src={watchAvatarUrl || defaultImage}
					fallback={defaultImage}
				/>
			) : (
				<div
					className="group aspect-square rounded-full cursor-pointer bg-gray-100 hover:bg-blue-100 border-dashed border-2 border-gray-200 hover:border-blue-200 transition-all duration-300 flex flex-col justify-center items-center w-24 h-24"
					onClick={show}
				>
					<PlusOutlined className="text-gray-500 group-hover:text-blue-500 transition-all duration-300" />
					<p className="text-xs text-gray-400 m-0 mt-1">
						建議尺寸
						<br />
						400x400
					</p>
				</div>
			)}
			<Item name={['user_avatar_url']} hidden>
				<Input />
			</Item>
			<MediaLibraryModal
				modalProps={modalProps}
				mediaLibraryProps={{
					...mediaLibraryProps,
					limit: 1,
					uploadProps: {
						accept: 'image/*',
					},
				}}
			/>
		</div>
	)
}
