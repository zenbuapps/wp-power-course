import { CheckOutlined } from '@ant-design/icons'
import { BaseRecord } from '@refinedev/core'
import { List, Input } from 'antd'
import React, { useState } from 'react'

import defaultImage from '@/assets/images/defaultImage.jpg'
import { PopconfirmDelete } from '@/components/general'

import { TListSelectProps } from './useListSelect'

const { Search } = Input

export const ListSelect = <T extends BaseRecord>({
	listSelectProps,
	rowName,
	rowUrl,
}: {
	listSelectProps: TListSelectProps<T>
	rowName: keyof T
	rowUrl: keyof T
}) => {
	const {
		loading,
		initLoading,
		onSearch,
		dataSource,
		onListClick,
		selectedItems,
		setSelectedItems,
		rowKey = 'id' as keyof T,
		initKeys = [],
	} = listSelectProps
	const [showList, setShowList] = useState<boolean>(false)

	return (
		<>
			<div className="relative mb-2">
				<Search
					placeholder="請輸入關鍵字後按下 ENTER 搜尋，每次最多返回 20 筆資料"
					allowClear
					onSearch={onSearch}
					enterButton
					loading={loading}
					onClick={() => setShowList(!showList)}
				/>
				<div
					className={`absolute border border-solid border-gray-200 rounded-md shadow-lg top-[100%] w-full bg-white z-50 h-[30rem] overflow-y-auto ${showList ? 'tw-block' : 'tw-hidden'}`}
					onMouseLeave={() => setShowList(false)}
				>
					<List
						rowKey={rowKey}
						dataSource={dataSource}
						renderItem={(item) => {
							const { [rowKey]: key, [rowUrl]: url, [rowName]: name } = item
							const isInclude = selectedItems?.some(
								({ [rowKey]: theKey = '' }) =>
									theKey === item?.[rowKey as keyof T]
							)
							return (
								<div
									key={key as React.Key}
									className={`flex items-center justify-between gap-4 p-2 mb-0 cursor-pointer hover:bg-blue-100 ${isInclude ? 'bg-blue-100' : 'bg-white'}`}
									onClick={onListClick(item)}
								>
									<div className="rounded-full aspect-square w-8 overflow-hidden">
										<img
											alt=""
											src={url as string}
											className="w-full h-full rounded object-cover"
										/>
									</div>
									<div className="w-full">
										{name as string} #{key as string | number}
									</div>
									<div className="w-8 text-center">
										{isInclude && <CheckOutlined className="text-blue-500" />}
									</div>
								</div>
							)
						}}
					/>
				</div>
			</div>

			{!initLoading &&
				selectedItems?.map(
					({ [rowKey]: key, [rowUrl]: url, [rowName]: name }) => (
						<div
							key={key}
							className="flex items-center justify-between gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2"
						>
							<div className="rounded-full aspect-square w-8 overflow-hidden">
								<img
									alt=""
									src={url || defaultImage}
									className="w-full h-full rounded object-cover"
								/>
							</div>
							<div className="flex-1">
								{name}{' '}
								<span className="text-gray-400 text-xs ml-1">#{key}</span>
							</div>
							<div className="w-8 text-right">
								<PopconfirmDelete
									popconfirmProps={{
										onConfirm: () => {
											setSelectedItems(
												selectedItems?.filter(
													({ [rowKey]: theKey }) => theKey !== key
												)
											)
										},
									}}
								/>
							</div>
						</div>
					)
				)}

			{/* Loading */}
			{initLoading &&
				initKeys.map((key) => (
					<div
						key={key}
						className="flex items-center justify-start gap-4 border border-solid border-gray-200 p-2 rounded-md mb-2 animate-pulse"
					>
						<div className="bg-slate-300 h-9 w-16 rounded object-cover" />
						<div>
							<div className="bg-slate-300 h-3 w-20 mb-1" />
							<div className="bg-slate-300 size-32" />
						</div>
					</div>
				))}
		</>
	)
}

export * from './useListSelect'
