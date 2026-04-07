import { Form, InputNumber } from 'antd'
import { memo, useEffect } from 'react'

import { FiSwitch } from '@/components/formItem'
import { Heading } from '@/components/general'
const { Item } = Form

const Appearance = () => {
	const form = Form.useFormInstance()
	const watchFVT = Form.useWatch(['fix_video_and_tabs_mobile'], form)

	useEffect(() => {
		if ('no' === watchFVT) {
			form.setFieldValue('pc_header_offset', 0)
		}
	}, [watchFVT])

	return (
		<div className="flex flex-col md:flex-row gap-8">
			<div className="w-full max-w-[400px]">
				<Heading className="mt-8">課程銷售頁</Heading>
				<FiSwitch
					formItemProps={{
						name: ['fix_video_and_tabs_mobile'],
						label: '手機版時，影片以及 tabs 黏性(sticky)置頂',
						tooltip:
							'開啟後，手機版時下滑課程銷售頁，影片以及 tabs 會黏性(sticky)置頂，可能會蓋住 fixed 的 header',
						initialValue: 'no',
					}}
				/>

				<Item
					hidden={'no' === watchFVT}
					name={['pc_header_offset']}
					label="偏移距離"
					tooltip="舉例來說，如果你的 Header 高度為 64px，請輸入 64，手機版時，影片以及 tabs 會黏性(sticky)置頂並偏移 64px"
					initialValue={0}
				>
					<InputNumber addonAfter="px" />
				</Item>

				<Heading className="mt-8">My Account</Heading>
				<FiSwitch
					formItemProps={{
						name: ['hide_myaccount_courses'],
						label: '隱藏 My Account 我的課程選單',
						tooltip:
							'還沒準備好對外公布你的課程網站? 可以隱藏 My Account 我的課程選單',
						initialValue: 'no',
					}}
				/>

				<Heading className="mt-8">商店頁</Heading>
				<FiSwitch
					formItemProps={{
						name: ['hide_courses_in_main_query'],
						label: '商店頁隱藏課程商品',
						tooltip:
							'開啟後商店頁、彙整頁，不顯示課程商品，如果要顯示課程列表可以使用 Power Course 的短代碼',
					}}
				/>
				<FiSwitch
					formItemProps={{
						name: ['hide_courses_in_search_result'],
						label: '搜尋結果隱藏課程商品',
						tooltip: '開啟後搜尋不到課程商品',
					}}
				/>
			</div>
			<div className="flex-1 h-auto md:h-[calc(100%-5.375rem)] md:overflow-y-auto"></div>
		</div>
	)
}

export default memo(Appearance)
