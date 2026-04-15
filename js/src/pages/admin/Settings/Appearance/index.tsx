import { Form, InputNumber } from 'antd'
import { memo, useEffect } from 'react'

import { __ } from '@wordpress/i18n'

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
				<Heading className="mt-8">
					{__('Course sales page', 'power-course')}
				</Heading>
				<FiSwitch
					formItemProps={{
						name: ['fix_video_and_tabs_mobile'],
						label: __(
							'Sticky video and tabs on mobile',
							'power-course'
						),
						tooltip: __(
							'When enabled, the video and tabs will stick to the top on mobile when scrolling down the course sales page. This may cover a fixed header.',
							'power-course'
						),
						initialValue: 'no',
					}}
				/>

				<Item
					hidden={'no' === watchFVT}
					name={['pc_header_offset']}
					label={__('Offset distance', 'power-course')}
					tooltip={__(
						'For example, if your header height is 64px, enter 64. On mobile, the video and tabs will stick to the top with an offset of 64px.',
						'power-course'
					)}
					initialValue={0}
				>
					<InputNumber addonAfter="px" />
				</Item>

				<Heading className="mt-8">{__('My Account', 'power-course')}</Heading>
				<FiSwitch
					formItemProps={{
						name: ['hide_myaccount_courses'],
						label: __(
							'Hide My Courses menu in My Account',
							'power-course'
						),
						tooltip: __(
							'Not ready to publish your course site? You can hide the My Courses menu in My Account.',
							'power-course'
						),
						initialValue: 'no',
					}}
				/>

				<Heading className="mt-8">{__('Shop page', 'power-course')}</Heading>
				<FiSwitch
					formItemProps={{
						name: ['hide_courses_in_main_query'],
						label: __(
							'Hide course products on shop page',
							'power-course'
						),
						tooltip: __(
							'When enabled, course products will not be displayed on the shop page or archive pages. Use the Power Course shortcode to display course lists.',
							'power-course'
						),
					}}
				/>
				<FiSwitch
					formItemProps={{
						name: ['hide_courses_in_search_result'],
						label: __(
							'Hide course products in search results',
							'power-course'
						),
						tooltip: __(
							'When enabled, course products will not be found in search results.',
							'power-course'
						),
					}}
				/>
			</div>
			<div className="flex-1 h-auto md:h-[calc(100%-5.375rem)] md:overflow-y-auto"></div>
		</div>
	)
}

export default memo(Appearance)
