import { Form, Input, InputNumber, ColorPicker, Alert, Select } from 'antd'
import { useWoocommerce } from 'antd-toolkit/wp'
import { memo } from 'react'

import { __ } from '@wordpress/i18n'

import cantPlayVideo from '@/assets/images/cant_play.jpg'
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
									"The watermark displays the current user's email",
									'power-course'
								)}
							</li>
							<li>
								{__(
									'Enable dynamic watermark to deter unauthorized recording',
									'power-course'
								)}
							</li>
							<li>
								{__(
									'Dynamic watermark is only shown on classroom videos, not on sales page videos',
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
						'Prevent your hard-recorded videos from being pirated',
						'power-course'
					)}
					description={
						<ol className="pl-4">
							<li>
								{__(
									"The watermark displays the current user's email",
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
					help={__(
						'Use \\n for line breaks, not <br />',
						'power-course'
					)}
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
					{__(
						'Extend course sales page permalink settings',
						'power-course'
					)}
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
					<Input
						placeholder={__('e.g., courses', 'power-course')}
						allowClear
					/>
				</Item>
			</div>
			<div className="flex-1 h-auto md:h-[calc(100%-5.375rem)] md:overflow-y-auto">
				<Heading className="mt-8">
					{__(
						'If the watermark feature is not showing',
						'power-course'
					)}
				</Heading>
				<p>
					{__(
						'Please disable the DRM feature in Bunny for the watermark to display correctly.',
						'power-course'
					)}
				</p>
				<SimpleImage src={cantPlayVideo} ratio="aspect-[2.1]" />
			</div>
		</div>
	)
}

export default memo(General)
