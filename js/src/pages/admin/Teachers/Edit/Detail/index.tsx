import { __ } from '@wordpress/i18n'
import { Statistic, Tabs, TabsProps } from 'antd'
import { Heading } from 'antd-toolkit'
import React, { memo } from 'react'

import { useRecord } from '../hooks'

import Basic from './Basic'

/**
 * 講師 Edit 頁右側 Detail 區塊
 *
 * 結構：
 * - 左上：講師 Statistic（負責課程數 / 學員人數 / 註冊時間 / 上次登入）
 * - 左下：4-Tab 結構（基本資料 / 訂單紀錄 / 學習紀錄 / Meta）
 *
 * 各 Tab 先留 placeholder，由階段 3.3~3.6 逐步填實。
 */
const DetailComponent = () => {
	const record = useRecord()
	const {
		teacher_courses_count,
		teacher_students_count,
		user_registered,
		user_registered_human,
		date_last_active,
	} = record

	const items: TabsProps['items'] = [
		{
			key: 'Basic',
			label: __('Basic info', 'power-course'),
			children: <Basic />,
		},
		{
			key: 'Orders',
			label: __('Order records', 'power-course'),
			children: (
				<div className="text-gray-400 text-xs">Orders Tab 占位（階段 3.4）</div>
			),
		},
		{
			key: 'Learning',
			label: __('Learning records', 'power-course'),
			children: (
				<div className="text-gray-400 text-xs">
					Learning Tab 占位（階段 3.5）
				</div>
			),
		},
		{
			key: 'Meta',
			label: __('Meta', 'power-course'),
			children: (
				<div className="text-gray-400 text-xs">Meta Tab 占位（階段 3.6）</div>
			),
		},
	]

	return (
		<div className="mb-12">
			<Heading className="mb-8">
				{__('Instructor information', 'power-course')}
			</Heading>

			{record?.id && (
				<div className="grid grid-cols-2 lg:grid-cols-4 gap-8 mb-8">
					<Statistic
						title={__('Courses taught', 'power-course')}
						value={teacher_courses_count || 0}
					/>
					<Statistic
						title={__('Students taught', 'power-course')}
						value={teacher_students_count || 0}
					/>
					<Statistic
						title={
							user_registered_human
								? `${__('Registered at', 'power-course')} (${user_registered_human})`
								: __('Registered at', 'power-course')
						}
						value={user_registered || ''}
					/>
					<Statistic
						title={__('Last active', 'power-course')}
						value={date_last_active || '-'}
					/>
				</div>
			)}

			<Tabs items={items} />
		</div>
	)
}

export const Detail = memo(DetailComponent)
