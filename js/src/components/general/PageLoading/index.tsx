import { LoadingOutlined } from '@ant-design/icons'

/**
 * 頁面載入中畫面
 * @return
 */
export const PageLoading = ({
	type = 'empty',
}: {
	type?: 'empty' | 'general'
}) => {
	if (type === 'empty') {
		/*
		 * 因為頁面載入好後還會有一次 Loading 動畫
		 * 用戶看到 2 個不同的 Loading 動畫，體驗會很割裂
		 * 那不如用白屏就好
		 */
		return <></>
	}

	return (
		<div className="flex flex-col justify-center items-center h-full w-full py-12">
			<LoadingOutlined className="text-xl text-primary mb-8" />
			<div className="text-base-content/75">LOADING...</div>
		</div>
	)
}
