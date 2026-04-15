import { useSelect } from '@refinedev/antd'
import { BaseOption, GetListResponse } from '@refinedev/core'
import type { QueryObserverResult } from '@tanstack/react-query'
import { __ } from '@wordpress/i18n'
import {
	Form,
	Select,
	InputNumber,
	Space,
	TimePicker,
	Input,
	SelectProps,
} from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import dayjs, { Dayjs } from 'dayjs'
import React, { useEffect } from 'react'

import {
	TCourseBaseRecord,
	TChapterRecord,
} from '@/pages/admin/Courses/List/types'

import { TriggerAt, TriggerCondition, SendingType, SendingUnit } from './enum'

const { Item } = Form

const Condition = ({ email_ids }: { email_ids: string[] }) => {
	const form = Form.useFormInstance()

	const watchTriggerAt = Form.useWatch([TriggerAt.FIELD_NAME], form)
	const watchTriggerCondition = Form.useWatch(
		['condition', TriggerCondition.FIELD_NAME],
		form
	)
	const watchCourseIds = Form.useWatch(['condition', 'course_ids'], form)
	const watchSendingType = Form.useWatch(
		['condition', 'sending', SendingType.FIELD_NAME],
		form
	)
	const watchSendingUnit = Form.useWatch(
		['condition', 'sending', SendingUnit.FIELD_NAME],
		form
	)

	const { selectProps: courseSelectProps } = useSelect<TCourseBaseRecord>({
		resource: 'courses',
		dataProviderName: 'power-course',
		optionLabel: 'name',
		optionValue: 'id',
		onSearch: (value) => [
			{
				field: 's',
				operator: 'eq',
				value,
			},
		],
	})

	const { selectProps: chapterSelectProps, query: chapterQuery } =
		useSelect<TChapterRecord>({
			resource: 'chapters',
			optionLabel: 'name',
			optionValue: 'id',
			onSearch: (value) => [
				{
					field: 's',
					operator: 'eq',
					value,
				},
			],
			filters: watchCourseIds
				? [
						{
							field: 'posts_per_page',
							operator: 'eq',
							value: 100,
						},
						{
							field: 'post_parent__in',
							operator: 'eq',
							value: watchCourseIds,
						},
					]
				: [
						{
							field: 'posts_per_page',
							operator: 'eq',
							value: 100,
						},
					],
			queryOptions: {
				enabled: [TriggerAt.CHAPTER_FINISH, TriggerAt.CHAPTER_ENTER].includes(
					watchTriggerAt
				),
			},
		})

	// 將 option 分組
	const formattedChapterSelectProps = formatChapterSelectProps(
		chapterSelectProps,
		chapterQuery
	)

	useEffect(() => {
		if (watchTriggerAt === TriggerAt.COURSE_LAUNCH) {
			form.setFieldValue(
				['condition', TriggerCondition.FIELD_NAME],
				TriggerCondition.EACH
			)
		}
	}, [watchTriggerAt])

	return (
		<>
			<Space.Compact block>
				<Item
					label={__('Trigger timing', 'power-course')}
					name={[TriggerAt.FIELD_NAME]}
					initialValue={TriggerAt.COURSE_GRANTED}
					className="w-32"
				>
					<Select
						options={[
							{
								label: __('When course is granted', 'power-course'),
								value: TriggerAt.COURSE_GRANTED,
							},
							{
								label: __('When course is finished', 'power-course'),
								value: TriggerAt.COURSE_FINISH,
							},
							{
								label: __('When course is launched', 'power-course'),
								value: TriggerAt.COURSE_LAUNCH,
							},
							{
								label: __('When chapter is finished', 'power-course'),
								value: TriggerAt.CHAPTER_FINISH,
							},
							{
								label: __('When chapter is entered', 'power-course'),
								value: TriggerAt.CHAPTER_ENTER,
							},
						]}
					/>
				</Item>

				<Item
					className="flex-1"
					label={__('Select course', 'power-course')}
					name={['condition', 'course_ids']}
					tooltip={__(
						'Multiple selection supported, keyword search available',
						'power-course'
					)}
					help={__('Leave empty = select all courses', 'power-course')}
				>
					<Select
						{...defaultSelectProps}
						{...courseSelectProps}
						placeholder={__(
							'Multiple selection supported, keyword search available',
							'power-course'
						)}
					/>
				</Item>

				{[TriggerAt.CHAPTER_FINISH, TriggerAt.CHAPTER_ENTER].includes(
					watchTriggerAt
				) && (
					<Item
						label={__('Select chapter', 'power-course')}
						name={['condition', 'chapter_ids']}
						className="flex-1"
						tooltip={__(
							'Multiple selection supported, keyword search available',
							'power-course'
						)}
						help={__('Leave empty = select all chapters', 'power-course')}
					>
						<Select
							{...defaultSelectProps}
							{...formattedChapterSelectProps}
							placeholder={__(
								'Multiple selection supported, keyword search available',
								'power-course'
							)}
						/>
					</Item>
				)}

				<Item
					className="w-[10rem]"
					label={__('Trigger condition', 'power-course')}
					name={['condition', TriggerCondition.FIELD_NAME]}
					initialValue="each"
				>
					<Select
						options={[
							{
								label: __('When any one is met', 'power-course'),
								value: TriggerCondition.EACH,
							},
							{
								label: __('When all are met', 'power-course'),
								value: TriggerCondition.ALL,
								disabled: [TriggerAt.COURSE_LAUNCH].includes(watchTriggerAt),
							},
							{
								label: __('When reaching specified quantity', 'power-course'),
								value: TriggerCondition.QUANTITY_GREATER_THAN,
								disabled: [TriggerAt.COURSE_LAUNCH].includes(watchTriggerAt),
							},
						]}
					/>
				</Item>

				{watchTriggerCondition === TriggerCondition.QUANTITY_GREATER_THAN && (
					<Item
						className="w-20"
						label={__('Quantity', 'power-course')}
						name={['condition', 'qty']}
						initialValue={1}
					>
						<InputNumber min={1} className="w-20" />
					</Item>
				)}
			</Space.Compact>

			<Space.Compact block>
				<Item
					name={['condition', 'sending', SendingType.FIELD_NAME]}
					className="w-40"
					label={__('After trigger condition is met', 'power-course')}
					initialValue={SendingType.NOW}
				>
					<Select
						options={[
							{
								label: __('Send immediately', 'power-course'),
								value: SendingType.NOW,
							},
							{
								label: __('Send later', 'power-course'),
								value: SendingType.LATER,
							},
						]}
					/>
				</Item>

				{watchSendingType === SendingType.LATER && (
					<>
						<Item
							name={['condition', 'sending', 'value']}
							label=" "
							className="w-20"
							initialValue={1}
						>
							<InputNumber min={1} className="w-full" />
						</Item>

						<Item
							name={['condition', 'sending', SendingUnit.FIELD_NAME]}
							label=" "
							className="w-16"
							initialValue={SendingUnit.DAY}
						>
							<Select
								options={[
									{
										label: __('Day', 'power-course'),
										value: SendingUnit.DAY,
									},
									{
										label: __('Hour', 'power-course'),
										value: SendingUnit.HOUR,
									},
									{
										label: __('Minute', 'power-course'),
										value: SendingUnit.MINUTE,
									},
								]}
							/>
						</Item>

						{watchSendingUnit === SendingUnit.DAY && (
							<>
								<Item label=" " className="w-20">
									<Input
										defaultValue={__('later at', 'power-course')}
										className="pointer-events-none"
									/>
								</Item>

								<Item
									name={['condition', 'sending', 'range']}
									label=" "
									className="w-60"
									normalize={(values: [Dayjs, Dayjs] | undefined) =>
										values ? values?.map((v) => v?.format('HH:mm')) : []
									}
									getValueProps={(values: [Dayjs, Dayjs] | undefined) => {
										if (!Array.isArray(values)) {
											return {
												value: undefined,
											}
										}

										// format('HH:mm') to Dayjs
										return {
											value: values?.map((v) => dayjs(v, 'HH:mm')),
										}
									}}
								>
									<TimePicker.RangePicker
										defaultValue={[
											dayjs('08:00', 'HH:mm'),
											dayjs('12:00', 'HH:mm'),
										]}
										format="HH:mm"
										placeholder={[
											__('Start time', 'power-course'),
											__('End time', 'power-course'),
										]}
									/>
								</Item>
							</>
						)}
					</>
				)}
			</Space.Compact>
		</>
	)
}

export default Condition

// 將 option 分組
function formatChapterSelectProps(
	props: SelectProps<BaseOption, any>,
	query: QueryObserverResult<GetListResponse<TChapterRecord>>
) {
	const chapters = query.data?.data || []
	const newOptions = chapters.reduce(
		(acc, curr) => {
			const sub_chapters = curr?.chapters || []
			if (sub_chapters.length === 0) {
				return acc
			}

			const newOption = {
				label: <>{curr.name}</>,
				options: sub_chapters.map((sub_chapter) => ({
					label: sub_chapter.name,
					value: sub_chapter.id,
				})),
			}

			acc?.push(newOption)
			return acc
		},
		[] as SelectProps['options']
	)

	const newProps = {
		...props,
		options: newOptions,
	}

	return newProps
}
