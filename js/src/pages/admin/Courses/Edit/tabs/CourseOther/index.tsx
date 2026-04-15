import { __ } from '@wordpress/i18n'
import {
	Form,
	Switch,
	Slider,
	InputNumber,
	Rate,
	Tooltip,
	FormProps,
	FormInstance,
} from 'antd'
import React, { memo } from 'react'

import { FiSwitch, DatePicker } from '@/components/formItem'
import { Heading } from '@/components/general'
import { useRecord } from '@/pages/admin/Courses/Edit/hooks'

const { Item } = Form

const CourseOtherComponent = ({ formProps }: { formProps: FormProps }) => {
	const form = formProps.form as FormInstance
	const course = useRecord()

	// 判斷是否為外部課程（hook 必須無條件呼叫）
	const watchIsExternal = Form.useWatch(['is_external'], form)
	const isExternal = course?.type === 'external' || watchIsExternal === true
	const watchShowTotalStudent: boolean =
		Form.useWatch(['show_total_student'], form) === 'yes'

	const watchCustomRating: number = Form.useWatch(['custom_rating'], form)
	const watchExtraReviewCount: number = Form.useWatch(
		['extra_review_count'],
		form
	)
	const watchShowReview: boolean =
		Form.useWatch(['show_review'], form) === 'yes'

	const watchShowReviewTab: boolean =
		Form.useWatch(['show_review_tab'], form) === 'yes'

	const watchShowTotalSales: boolean =
		Form.useWatch(['show_total_sales'], form) === 'yes'

	return (
		<Form {...formProps}>
			<Heading>{__('Course Introduction Section', 'power-course')}</Heading>
			<div className="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-3 gap-6">
				<FiSwitch
					formItemProps={{
						name: ['show_total_sales'],
						label: (
							<div className="flex gap-x-2">
								{__('Show', 'power-course')}
								<span className="bg-red-100 text-red-500 text-xs flex items-center px-2 py-1 rounded-md size-fit mb-1">
									{__('Sold OO units', 'power-course')}
								</span>
							</div>
						),
					}}
				/>
				<FiSwitch
					formItemProps={{
						name: ['show_rest_stock'],
						label: (
							<div className="flex gap-x-2">
								{__('Show', 'power-course')}
								<span className="bg-green-100 text-green-500 text-xs flex items-center px-2 py-1 rounded-md size-fit mb-1">
									{__('OO units remaining', 'power-course')}
								</span>
							</div>
						),
					}}
				/>
				<FiSwitch
					formItemProps={{
						name: ['is_popular'],
						label: (
							<div className="flex gap-x-2">
								{__('Show', 'power-course')}{' '}
								<span className="bg-red-100 [&_svg]:fill-red-500 text-red-500 text-xs flex items-center px-2 py-1 rounded-md size-fit mb-1">
									<svg
										className="size-4 mr-1"
										viewBox="0 0 24 24"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<g strokeWidth="0"></g>
										<g strokeLinecap="round" strokeLinejoin="round"></g>
										<g>
											{' '}
											<path d="M12.8324 21.8013C15.9583 21.1747 20 18.926 20 13.1112C20 7.8196 16.1267 4.29593 13.3415 2.67685C12.7235 2.31757 12 2.79006 12 3.50492V5.3334C12 6.77526 11.3938 9.40711 9.70932 10.5018C8.84932 11.0607 7.92052 10.2242 7.816 9.20388L7.73017 8.36604C7.6304 7.39203 6.63841 6.80075 5.85996 7.3946C4.46147 8.46144 3 10.3296 3 13.1112C3 20.2223 8.28889 22.0001 10.9333 22.0001C11.0871 22.0001 11.2488 21.9955 11.4171 21.9858C10.1113 21.8742 8 21.064 8 18.4442C8 16.3949 9.49507 15.0085 10.631 14.3346C10.9365 14.1533 11.2941 14.3887 11.2941 14.7439V15.3331C11.2941 15.784 11.4685 16.4889 11.8836 16.9714C12.3534 17.5174 13.0429 16.9454 13.0985 16.2273C13.1161 16.0008 13.3439 15.8564 13.5401 15.9711C14.1814 16.3459 15 17.1465 15 18.4442C15 20.4922 13.871 21.4343 12.8324 21.8013Z"></path>{' '}
										</g>
									</svg>
									{__('Popular Course', 'power-course')}
								</span>
							</div>
						),
					}}
				/>
				<FiSwitch
					formItemProps={{
						name: ['is_featured'],
						label: (
							<div className="flex gap-x-2">
								{__('Show', 'power-course')}
								<span className="bg-amber-100 [&_svg_path]:fill-amber-500 text-amber-500 text-xs flex items-center px-2 py-1 rounded-md size-fit mb-1">
									<svg
										className="size-4 mr-1"
										viewBox="0 0 24 24"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<g strokeWidth="0"></g>
										<g strokeLinecap="round" strokeLinejoin="round"></g>
										<g>
											<path
												opacity="0.5"
												d="M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z"
											></path>{' '}
											<path d="M10.4127 8.49812L10.5766 8.20419C11.2099 7.06807 11.5266 6.5 12 6.5C12.4734 6.5 12.7901 7.06806 13.4234 8.20419L13.5873 8.49813C13.7672 8.82097 13.8572 8.98239 13.9975 9.0889C14.1378 9.19541 14.3126 9.23495 14.6621 9.31402L14.9802 9.38601C16.2101 9.66428 16.825 9.80341 16.9713 10.2739C17.1176 10.7443 16.6984 11.2345 15.86 12.215L15.643 12.4686C15.4048 12.7472 15.2857 12.8865 15.2321 13.0589C15.1785 13.2312 15.1965 13.4171 15.2325 13.7888L15.2653 14.1272C15.3921 15.4353 15.4554 16.0894 15.0724 16.3801C14.6894 16.6709 14.1137 16.4058 12.9622 15.8756L12.6643 15.7384C12.337 15.5878 12.1734 15.5124 12 15.5124C11.8266 15.5124 11.663 15.5878 11.3357 15.7384L11.0378 15.8756C9.88633 16.4058 9.31059 16.6709 8.92757 16.3801C8.54456 16.0894 8.60794 15.4353 8.7347 14.1272L8.76749 13.7888C8.80351 13.4171 8.82152 13.2312 8.76793 13.0589C8.71434 12.8865 8.59521 12.7472 8.35696 12.4686L8.14005 12.215C7.30162 11.2345 6.88241 10.7443 7.02871 10.2739C7.17501 9.80341 7.78994 9.66427 9.01977 9.38601L9.33794 9.31402C9.68743 9.23495 9.86217 9.19541 10.0025 9.0889C10.1428 8.98239 10.2328 8.82097 10.4127 8.49812Z"></path>{' '}
										</g>
									</svg>
									{__('Featured Course', 'power-course')}
								</span>
							</div>
						),
					}}
				/>

				<FiSwitch
					formItemProps={{
						name: ['show_join'],
						label: (
							<div className="flex gap-x-2">
								{__('Show', 'power-course')}
								<span className="bg-blue-100 [&_svg]:stroke-blue-500 text-blue-500 text-[0.625rem] sm:text-xs flex items-center px-1 sm:px-2 py-0.5 sm:py-1 rounded sm:rounded-md size-fit text-nowrap">
									<svg
										className="size-3 sm:size-4 mr-0.5 sm:mr-1"
										viewBox="0 0 24 24"
										fill="none"
										xmlns="http://www.w3.org/2000/svg"
									>
										<g strokeWidth="0"></g>
										<g strokeLinecap="round" strokeLinejoin="round"></g>
										<g>
											{' '}
											<path
												d="M12 10.4V20M12 10.4C12 8.15979 12 7.03969 11.564 6.18404C11.1805 5.43139 10.5686 4.81947 9.81596 4.43597C8.96031 4 7.84021 4 5.6 4H4.6C4.03995 4 3.75992 4 3.54601 4.10899C3.35785 4.20487 3.20487 4.35785 3.10899 4.54601C3 4.75992 3 5.03995 3 5.6V16.4C3 16.9601 3 17.2401 3.10899 17.454C3.20487 17.6422 3.35785 17.7951 3.54601 17.891C3.75992 18 4.03995 18 4.6 18H7.54668C8.08687 18 8.35696 18 8.61814 18.0466C8.84995 18.0879 9.0761 18.1563 9.29191 18.2506C9.53504 18.3567 9.75977 18.5065 10.2092 18.8062L12 20M12 10.4C12 8.15979 12 7.03969 12.436 6.18404C12.8195 5.43139 13.4314 4.81947 14.184 4.43597C15.0397 4 16.1598 4 18.4 4H19.4C19.9601 4 20.2401 4 20.454 4.10899C20.6422 4.20487 20.7951 4.35785 20.891 4.54601C21 4.75992 21 5.03995 21 5.6V16.4C21 16.9601 21 17.2401 20.891 17.454C20.7951 17.6422 20.6422 17.7951 20.454 17.891C20.2401 18 19.9601 18 19.4 18H16.4533C15.9131 18 15.643 18 15.3819 18.0466C15.15 18.0879 14.9239 18.1563 14.7081 18.2506C14.465 18.3567 14.2402 18.5065 13.7908 18.8062L12 20"
												strokeWidth="2"
												strokeLinecap="round"
												strokeLinejoin="round"
											></path>{' '}
										</g>
									</svg>
									{__('Start Learning Now', 'power-course')}
								</span>
							</div>
						),
					}}
				/>
			</div>

			{/* <Heading size="sm">課程評價星星</Heading> */}
			<div className="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-3 gap-6">
				<FiSwitch
					formItemProps={{
						name: ['show_review'],
						label: __('Show course rating stars', 'power-course'),
					}}
				/>
				{watchShowReview && (
					<>
						<Item
							label={__('Custom course rating', 'power-course')}
							name={['custom_rating']}
							initialValue={2.5}
						>
							<Slider step={0.1} min={0} max={5} />
						</Item>
						<div>
							<p className="mb-2">{__('Preview', 'power-course')}</p>
							<Tooltip
								title={__('Preview', 'power-course')}
								className="w-fit"
							>
								<div className="flex items-center text-gray-800">
									<span className="mr-2 text-2xl font-semibold">
										{Number(watchCustomRating).toFixed(1)}
									</span>
									<Rate disabled value={watchCustomRating} allowHalf />
									<span className="ml-2">({watchExtraReviewCount || 0})</span>
								</div>
							</Tooltip>
						</div>
						<Item
							label={__('Padded review count', 'power-course')}
							name={['extra_review_count']}
							tooltip={__(
								'Frontend displayed review count = actual review count + padded review count',
								'power-course'
							)}
							initialValue={0}
						>
							<InputNumber className="w-full" min={0} />
						</Item>
					</>
				)}
			</div>

			<Heading>{__('Course Information', 'power-course')}</Heading>

			<div className="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-3 gap-6">
				{/* 外部課程隱藏站內統計相關設定 */}
				{!isExternal && (
					<>
						<FiSwitch
							formItemProps={{
								name: ['show_course_complete'],
								label: __(
									'Show "Course fully launched"',
									'power-course'
								),
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['show_course_schedule'],
								label: __('Show course start date', 'power-course'),
								initialValue: 'yes',
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['show_course_time'],
								label: __('Show course duration', 'power-course'),
								initialValue: 'yes',
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['show_course_chapters'],
								label: __('Show number of chapters', 'power-course'),
								initialValue: 'yes',
							}}
						/>
						<FiSwitch
							formItemProps={{
								name: ['show_course_limit'],
								label: __('Show watch time', 'power-course'),
								initialValue: 'yes',
							}}
						/>
						<FiSwitch
							formItemProps={{
								name: ['show_total_student'],
								label: __('Show course students', 'power-course'),
								initialValue: 'yes',
							}}
						/>
						<Item
							hidden={!watchShowTotalStudent}
							label={__('Padded student count', 'power-course')}
							name={['extra_student_count']}
							tooltip={__(
								'Frontend displayed student count = actual student count + padded student count',
								'power-course'
							)}
							initialValue={0}
						>
							<InputNumber
								addonBefore={__('Actual student count + ', 'power-course')}
								addonAfter={__('people', 'power-course')}
								className="w-full"
								min={0}
							/>
						</Item>
					</>
				)}
			</div>

			<Heading>{__('Course Details', 'power-course')}</Heading>
			<div className="grid sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-3 gap-6">
				<FiSwitch
					formItemProps={{
						name: ['show_description_tab'],
						label: __('Show introduction', 'power-course'),
					}}
				/>
				{/* 外部課程隱藏章節與留言設定 */}
				{!isExternal && (
					<>
						<FiSwitch
							formItemProps={{
								name: ['show_chapter_tab'],
								label: __('Show chapters', 'power-course'),
							}}
						/>
						<FiSwitch
							formItemProps={{
								name: ['enable_comment'],
								label: __('Show comments', 'power-course'),
							}}
						/>
					</>
				)}
				<FiSwitch
					formItemProps={{
						name: ['show_qa_tab'],
						label: __('Show Q&A', 'power-course'),
					}}
				/>

				<FiSwitch
					formItemProps={{
						name: ['show_review_tab'],
						label: __('Show reviews', 'power-course'),
					}}
				/>
				{watchShowReviewTab && (
					<>
						<FiSwitch
							formItemProps={{
								name: ['show_review_list'],
								label: __('Show user course reviews', 'power-course'),
							}}
						/>
						<Item
							label={__(
								'Allow purchased users to review course',
								'power-course'
							)}
							name={['reviews_allowed']}
							tooltip={__(
								'Allow purchased users to review the course. You can also design it to only collect user reviews without displaying them.',
								'power-course'
							)}
							help={__(
								'Users must meet both conditions "Purchased course" and "Not yet reviewed this course" to review the course',
								'power-course'
							)}
						>
							<Switch />
						</Item>
					</>
				)}
			</div>

			{/* 外部課程隱藏銷售方案整個區塊 */}
			{!isExternal && (
				<>
					<Heading>{__('Bundles', 'power-course')}</Heading>
					<div className="grid 2xl:grid-cols-3 gap-6">
						<FiSwitch
							formItemProps={{
								name: ['show_customer_amount'],
								label: __(
									'Show "OO students have purchased this bundle" text',
									'power-course'
								),
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['show_stock_quantity'],
								label: __(
									'Show "OO units remaining" text',
									'power-course'
								),
								tooltip: __(
									'Only takes effect when you have set the course stock quantity',
									'power-course'
								),
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['enable_bundles_sticky'],
								label: __(
									'Sticky bundles on desktop (> 810px)',
									'power-course'
								),
								tooltip: __(
									'When enabled, if your course introduction is too long, the bundles on the side will stick to the screen',
									'power-course'
								),
							}}
						/>

						<FiSwitch
							formItemProps={{
								name: ['enable_mobile_fixed_cta'],
								label: __(
									'Fix mobile CTA at bottom (< 810px)',
									'power-course'
								),
								tooltip: __(
									'Mobile CTA: if there is only one course, it will directly add the course and go to checkout; if there are multiple bundles, it will scroll to the bundles section for user selection',
									'power-course'
								),
							}}
						/>
					</div>
				</>
			)}

			<Heading>{__('Publish Time', 'power-course')}</Heading>

			<div className="grid 2xl:grid-cols-3 gap-6">
				<DatePicker
					formItemProps={{
						name: ['date_created'],
						label: __('Publish Time', 'power-course'),
						className: 'mb-0',
						tooltip: __(
							'You can control the course display order by setting the publish time together with shortcodes',
							'power-course'
						),
					}}
				/>
			</div>
		</Form>
	)
}

export const CourseOther = memo(CourseOtherComponent)
