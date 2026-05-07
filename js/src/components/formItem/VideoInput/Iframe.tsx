import { DeleteOutlined } from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Form, FormItemProps, Input } from 'antd'
import type { NamePath } from 'antd/es/form/interface'
import {
	FC,
	DetailedHTMLProps,
	IframeHTMLAttributes,
	useEffect,
	useState,
} from 'react'

import { getFullPath } from '../utils'

import { TVideoType } from './types'

const { Item } = Form

// 抽象組件，適用任何拿來 iFrame 的平台
const Iframe: FC<{
	type: TVideoType
	formItemProps: FormItemProps
	getVideoId: (_url: string | null) => string | null
	getEmbedVideoUrl: (_videoId: string | null) => string
	getVideoUrl: (_videoId: string | null, input?: string) => string
	exampleUrl: string
	iframeProps?: Partial<
		DetailedHTMLProps<
			IframeHTMLAttributes<HTMLIFrameElement>,
			HTMLIFrameElement
		>
	>
	/**
	 * 父層 Form.List 的路徑前綴；非 Form.List 場景不傳。
	 * useWatch / setFieldValue 直接走 form instance，不會繼承 list 前綴，需手動拼接。
	 */
	listName?: NamePath
}> = ({
	type,
	formItemProps,
	getVideoId,
	getEmbedVideoUrl,
	getVideoUrl,
	exampleUrl,
	iframeProps,
	listName,
}) => {
	const [vIdOrUrl, setVIdOrUrl] = useState('')
	const form = Form.useFormInstance()
	const { name } = formItemProps

	if (!name) {
		throw new Error('name is required')
	}

	/** Form.List 場景下手動拼上 list 前綴；非 Form.List 場景 fullPath === name */
	const fullPath = getFullPath(name, listName)
	const watchField = Form.useWatch(fullPath, form)
	const platFormName = type.toUpperCase()

	useEffect(() => {
		if (watchField?.id) {
			const url = getVideoUrl(watchField.id, vIdOrUrl)
			setVIdOrUrl(url)
		}
	}, [watchField?.id])

	const videoId = watchField?.id
	const validVideoId = watchField && videoId
	const invalidVideoId = watchField && videoId === null

	const embedVideoUrl = getEmbedVideoUrl(videoId)

	const handleDelete = () => {
		form.setFieldValue(fullPath, {
			type,
			id: '',
			meta: {},
		})
	}

	return (
		<div className="relative">
			<Input
				size="small"
				allowClear
				placeholder={sprintf(
					// translators: %s: 影片平台名稱，例如 YOUTUBE、VIMEO
					__('Please enter %s video URL', 'power-course'),
					platFormName
				)}
				value={vIdOrUrl}
				onChange={(e) => {
					const string = e.target.value
					setVIdOrUrl(string)
					const vId = string ? getVideoId(string) : ''
					form.setFieldValue(fullPath, {
						type,
						id: vId,
						meta: {},
					})
				}}
				className="mb-1"
			/>
			<Item {...formItemProps} hidden />

			{/* 如果章節已經有存影片，則顯示影片，有瀏覽器 preview，則以 瀏覽器 preview 優先 */}
			{validVideoId && (
				<>
					<div
						className="aspect-video w-full p-2"
						style={{
							border: '1px dashed #d9d9d9',
							backgroundColor: 'rgba(0, 0, 0, 0.02)',
							borderRadius: '8px',
						}}
					>
						<div className="w-full h-full rounded-xl overflow-hidden">
							<div
								className={`rounded-xl bg-gray-200 ${watchField ? 'tw-block' : 'tw-hidden'}`}
								style={{
									position: 'relative',
									paddingTop: '56.25%',
								}}
							>
								<iframe
									title={__('Video player', 'power-course')}
									className="border-0 absolute top-0 left-0 w-full h-full rounded-xl"
									src={embedVideoUrl}
									loading="lazy"
									frameBorder="0"
									allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
									referrerPolicy="strict-origin-when-cross-origin"
									allowFullScreen={true}
									{...iframeProps}
								></iframe>
								<div
									onClick={handleDelete}
									className="group absolute top-4 right-4 rounded-md size-12 bg-white shadow-lg flex justify-center items-center transition duration-300 hover:bg-red-500 cursor-pointer"
								>
									<DeleteOutlined className="text-red-500 group-hover:text-white" />
								</div>
							</div>
						</div>
					</div>
				</>
			)}

			{invalidVideoId && (
				<div>
					{sprintf(
						// translators: 1: 影片平台名稱, 2: 範例網址
						__(
							'Please enter a valid %1$s video URL, e.g.: %2$s',
							'power-course'
						),
						platFormName,
						exampleUrl
					)}
				</div>
			)}
		</div>
	)
}

export default Iframe
