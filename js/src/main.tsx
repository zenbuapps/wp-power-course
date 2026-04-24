import { StyleProvider } from '@ant-design/cssinjs'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ConfigProvider } from 'antd'
import { EnvProvider } from 'antd-toolkit'
import { BunnyProvider } from 'antd-toolkit/refine'
import React from 'react'
import ReactDOM from 'react-dom/client'

import { APP1_SELECTOR, APP2_SELECTOR, env } from '@/utils'

import App1 from './App1'
import App2 from './App2'
import { TPlayerProps } from './App2/Player'

const queryClient = new QueryClient({
	defaultOptions: {
		queries: {
			refetchOnWindowFocus: false,
			retry: 0,
		},
	},
})

const { BUNNY_LIBRARY_ID, BUNNY_CDN_HOSTNAME, BUNNY_STREAM_API_KEY } = env

const run = () => {
	const app1Nodes = document.querySelectorAll(APP1_SELECTOR)

	const mapping = [
		{
			els: app1Nodes,
			App: App1,
		},
	]

	mapping.forEach(({ els, App }) => {
		if (!!els) {
			els.forEach((el) => {
				ReactDOM.createRoot(el).render(
					<QueryClientProvider client={queryClient}>
						<StyleProvider hashPriority="low">
							<EnvProvider env={env}>
								<BunnyProvider
									bunny_library_id={BUNNY_LIBRARY_ID}
									bunny_cdn_hostname={BUNNY_CDN_HOSTNAME}
									bunny_stream_api_key={BUNNY_STREAM_API_KEY}
								>
									<ConfigProvider
										theme={{
											token: {
												colorPrimary: '#1677ff',
												borderRadius: 6,
											},
											components: {
												Segmented: {
													itemSelectedBg: '#1677ff',
													itemSelectedColor: '#ffffff',
												},
											},
										}}
									>
										<App />
									</ConfigProvider>
								</BunnyProvider>
							</EnvProvider>
						</StyleProvider>
					</QueryClientProvider>
				)
			})
		}
	})

	// 一個畫面可能會有多個 vidstack 元素
	const vidstackNodes = document.querySelectorAll(APP2_SELECTOR)
	vidstackNodes.forEach((vidstackNode) => {
		const dataset: TPlayerProps = vidstackNode.dataset || {}

		ReactDOM.createRoot(vidstackNode).render(
			<React.StrictMode>
				<App2 {...dataset} />
			</React.StrictMode>
		)
	})
}

// 修正 race condition：<script type="module" async> 若在 DOMContentLoaded 之後
// 才 evaluate，單純 addEventListener('DOMContentLoaded', ...) 會註冊但永遠不觸發，
// 導致 React app 靜默不 mount。這裡先檢查 readyState，已過 'loading' 就直接執行。
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', run)
} else {
	run()
}
