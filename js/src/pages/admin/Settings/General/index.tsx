import { createInterpolateElement } from '@wordpress/element'
import { __ } from '@wordpress/i18n'
import { Form, Input, InputNumber, ColorPicker, Alert, Select } from 'antd'
import { useWoocommerce } from 'antd-toolkit/wp'
import { memo } from 'react'

import cantPlayVideo from '@/assets/images/cant_play.jpg'
import { FiSwitch } from '@/components/formItem'
import { Heading, SimpleImage } from '@/components/general'

const { Item } = Form

const getDefaultOrderStatusOptions = () => [
	{ label: __('Completed', 'power-course'), value: 'completed' },
	{ label: __('Processing', 'power-course'), value: 'completed' },
]

const General = () => {
	const defaultOrderStatusOptions = getDefaultOrderStatusOptions()
	const { order_statuses = defaultOrderStatusOptions } = useWoocommerce()
	const paid_statuses = order_statuses?.filter(
		(status) =>
			![
				'pending',
				'on-hold',
				'cancelled',
				'refunded',
				'failed',
				'checkout-draft',
			]?.includes(status?.value)
	)

	return (
		<div className="flex flex-col md:flex-row gap-8">
			<div className="w-full max-w-[400px]">
				<Heading className="mt-8">
					{__('Course access trigger', 'power-course')}
				</Heading>
				<Item
					name={['course_access_trigger']}
					label={__(
						'Trigger course access when the order reaches this status',
						'power-course'
					)}
				>
					<Select options={paid_statuses} />
				</Item>
				<Heading className="mt-8">
					{__('Comments and reviews', 'power-course')}
				</Heading>
				<FiSwitch
					formItemProps={{
						name: ['comment_moderation'],
						label: __('Require comment moderation', 'power-course'),
						tooltip: __(
							'When enabled, whether comments and reviews need approval follows WordPress Settings > Discussion (manual approval, previously approved author, link count and moderation keywords). When disabled, comments are published immediately, but comments matching the disallowed keys still go to spam or trash.',
							'power-course'
						),
						initialValue: 'yes',
					}}
				/>
				<Heading className="mt-8">
					{__('Classroom video watermark settings', 'power-course')}
				</Heading>
				<Alert
					className="mb-4"
					message={__(
						'Prevent your hard-recorded videos from being pirated',
						'power-course'
					)}
					description={
						<ol className="pl-4">
							<li>
								{__(
									'Shows the student\'s name, email, etc. on the video. Set the content in "Watermark text" below.',
									'power-course'
								)}
							</li>
							<li>
								{__(
									'Moves around regularly, so it is hard to crop out',
									'power-course'
								)}
							</li>
							<li>
								{__(
									'Classroom videos only, not sales page videos',
									'power-course'
								)}
							</li>
						</ol>
					}
					type="info"
					showIcon
				/>
				<Item
					name={['pc_watermark_qty']}
					label={__('Watermark count', 'power-course')}
					tooltip={__(
						'Enter 0 to disable watermark. Recommended count is 3-10, too many will impact viewing experience.',
						'power-course'
					)}
				>
					<InputNumber min={0} max={30} className="w-full" />
				</Item>
				<Item
					name={['pc_watermark_interval']}
					label={__('Watermark update interval', 'power-course')}
					tooltip={__(
						'Unit: seconds. Recommended value is 5-10, too large will impact viewing experience.',
						'power-course'
					)}
				>
					<InputNumber min={1} max={3000} className="w-full" />
				</Item>
				<Item
					name={['pc_watermark_text']}
					label={__('Watermark text', 'power-course')}
					tooltip={__(
						'Available variables: {display_name} {email} {ip} {username} {post_title}. Also supports <br /> for line breaks.',
						'power-course'
					)}
					help={__('Use <br /> for line breaks', 'power-course')}
				>
					<Input.TextArea
						allowClear
						placeholder="Student:{display_name} IP:{ip} <br /> Email:{email}"
						rows={3}
					/>
				</Item>
				<Item
					name={['pc_watermark_color']}
					label={__('Watermark color', 'power-course')}
					normalize={(value) => value.toRgbString()}
				>
					<ColorPicker
						defaultFormat="rgb"
						presets={[
							{
								label: __('Default', 'power-course'),
								colors: [
									'rgba(255, 255, 255, 0.5)',
									'rgba(200, 200, 200, 0.5)',
								],
							},
						]}
					/>
				</Item>

				<Heading className="mt-8">
					{__('Course material PDF watermark settings', 'power-course')}
				</Heading>
				<Alert
					className="mb-4"
					message={__(
						'Prevent your course materials from being leaked',
						'power-course'
					)}
					description={
						<ol className="pl-4">
							<li>
								{__(
									'Stamps the student\'s name, email, etc. on downloaded PDFs. Set the content in "Watermark text" below.',
									'power-course'
								)}
							</li>
							<li>
								{__(
									'Only PDFs uploaded inside the classroom will show the watermark',
									'power-course'
								)}
							</li>
						</ol>
					}
					type="info"
					showIcon
				/>
				<Item
					name={['pc_pdf_watermark_qty']}
					label={__('Watermark count', 'power-course')}
					tooltip={__(
						'Enter 0 to disable watermark. Recommended count is 3-10, too many will impact viewing experience.',
						'power-course'
					)}
				>
					<InputNumber min={0} max={30} className="w-full" />
				</Item>
				<Item
					name={['pc_pdf_watermark_text']}
					label={__('Watermark text', 'power-course')}
					tooltip={__(
						'Available variables: {display_name} {email} {ip} {username} {post_title}. Also supports \\n for line breaks.',
						'power-course'
					)}
					help={__('Use \\n for line breaks, not <br />', 'power-course')}
				>
					<Input.TextArea
						allowClear
						placeholder="Student:{display_name} IP:{ip} \n Email:{email}"
						rows={3}
					/>
				</Item>
				<Item
					name={['pc_pdf_watermark_color']}
					label={__('Watermark color', 'power-course')}
					normalize={(value) => value.toRgbString()}
				>
					<ColorPicker
						defaultFormat="rgb"
						presets={[
							{
								label: __('Default', 'power-course'),
								colors: [
									'rgba(255, 255, 255, 0.5)',
									'rgba(200, 200, 200, 0.5)',
								],
							},
						]}
					/>
				</Item>

				<Heading className="mt-8">
					{__('Extend course sales page permalink settings', 'power-course')}
				</Heading>
				<Item
					name={['course_permalink_structure']}
					label={__(
						'Extend the permalink structure of the course sales page',
						'power-course'
					)}
					tooltip={__(
						"For example, if you enter 'courses', users visiting courses/{slug} can also see the course sales page.",
						'power-course'
					)}
				>
					<Input placeholder={__('e.g., courses', 'power-course')} allowClear />
				</Item>
			</div>
			<div className="flex-1 h-auto md:h-[calc(100%-5.375rem)] md:overflow-y-auto">
				<Heading className="mt-8">
					{__("Can't see the video watermark?", 'power-course')}
				</Heading>
				<p>
					{__(
						'DRM on a Bunny video library blocks the watermark. Turn it off:',
						'power-course'
					)}
				</p>
				<ol className="pl-4 my-2">
					<li>
						{createInterpolateElement(
							__('Log in to <a>dash.bunny.net</a>', 'power-course'),
							{
								// 連結文字由 createInterpolateElement 從翻譯字串填入
								a: (
									// eslint-disable-next-line jsx-a11y/anchor-has-content
									<a
										href="https://dash.bunny.net"
										target="_blank"
										rel="noopener noreferrer"
									/>
								),
							}
						)}
					</li>
					<li>
						{__(
							'Click "Stream" > your video library > "Security" > "DRM"',
							'power-course'
						)}
					</li>
					<li>{__('Turn off "MediaCage Basic DRM"', 'power-course')}</li>
				</ol>
				<p className="text-gray-500 text-xs">
					{__(
						'※ Do this for every library with course videos. Menu names may differ; follow the Bunny dashboard.',
						'power-course'
					)}
				</p>
				<SimpleImage src={cantPlayVideo} ratio="aspect-[2.1]" />
			</div>
		</div>
	)
}

export default memo(General)
