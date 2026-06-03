import { __ } from '@wordpress/i18n'
import { Form, Input, Space } from 'antd'
import { useCountries } from 'antd-toolkit/wp'

import { TUserInfo } from '@/components/user/types'
import { INFO_LABEL_MAPPER } from '@/utils'

import AddressInput from './AddressInput'

const { Item } = Form

/** InfoTable props */
type TInfoTableProps = {
	/** 區塊類型（帳單 / 運送） */
	type: 'billing' | 'shipping'
	/** 是否處於編輯模式 */
	isEditing: boolean
} & Partial<TUserInfo>

/**
 * 帳單 / 運送資訊表格元件
 *
 * view 模式以唯讀文字呈現姓名、電話、地址（國家以 useCountries 反查名稱）、
 * Email、公司；edit 模式以巢狀 Form（name `[type, field]`）提供輸入欄位，
 * 儲存時由 Modal 主體攤平成 `billing_xxx` / `shipping_xxx`。
 * billing 區塊額外含 Email 與公司欄位。
 */
export const InfoTable = ({
	type = 'billing',
	isEditing = false,
	first_name = '',
	last_name = '',
	email = '',
	phone = '',
	company = '',
	state = '',
	postcode = '',
	city = '',
	address_1 = '',
	address_2 = '',
	country = '',
}: TInfoTableProps) => {
	const COUNTRIES = useCountries()
	return (
		<table className="table table-vertical table-sm text-xs [&_th]:!w-20 [&_td]:break-all">
			<tbody>
				<tr>
					<th>{__('Name', 'power-course')}</th>
					<td className="gap-x-1">
						{!isEditing && `${last_name}${first_name}`}
						{isEditing &&
							['last_name', 'first_name'].map((field) => (
								<Space.Compact
									key={field}
									block
									className={isEditing ? '' : 'tw-hidden'}
								>
									<div className="text-xs bg-gray-50 border-l border-y border-r-0 border-solid border-gray-300 w-20 rounded-l-[0.25rem] px-2 text-left">
										{INFO_LABEL_MAPPER?.[field]}
									</div>
									<Item
										name={[type, field]}
										noStyle
										label={field}
										hidden={!isEditing}
									>
										<Input size="small" className="text-right text-xs flex-1" />
									</Item>
								</Space.Compact>
							))}
					</td>
				</tr>
				<tr>
					<th>{__('Phone', 'power-course')}</th>
					<td>
						{!isEditing && phone}
						{isEditing && (
							<Item name={[type, 'phone']} noStyle hidden={!isEditing}>
								<Input size="small" className="text-right text-xs" />
							</Item>
						)}
					</td>
				</tr>
				<tr>
					<th>{__('Address', 'power-course')}</th>
					<td className="flex flex-col items-end gap-1 text-xs">
						{!isEditing &&
							`${COUNTRIES?.[country] || ''} ${postcode}${state}${city}${address_1}${address_2}`}
						{isEditing &&
							[
								'country',
								'postcode',
								'state',
								'city',
								'address_1',
								'address_2',
							].map((field) => (
								<Space.Compact
									key={field}
									block
									className={isEditing ? '' : 'tw-hidden'}
								>
									<div className="text-xs bg-gray-50 border-l border-y border-r-0 border-solid border-gray-300 w-20 rounded-l-[0.25rem] px-2 text-left">
										{INFO_LABEL_MAPPER?.[field]}
									</div>
									<AddressInput
										isEditing={isEditing}
										type={type}
										field={field}
									/>
								</Space.Compact>
							))}
					</td>
				</tr>
				{'billing' === type && (
					<tr>
						<th>{__('Email', 'power-course')}</th>
						<td>
							{!isEditing && email}
							{isEditing && (
								<Item name={[type, 'email']} noStyle hidden={!isEditing}>
									<Input size="small" className="text-right text-xs" />
								</Item>
							)}
						</td>
					</tr>
				)}

				{'billing' === type && (
					<tr>
						<th>{__('Company', 'power-course')}</th>
						<td>
							{!isEditing && company}
							{isEditing && (
								<Item name={[type, 'company']} noStyle hidden={!isEditing}>
									<Input size="small" className="text-right text-xs" />
								</Item>
							)}
						</td>
					</tr>
				)}
			</tbody>
		</table>
	)
}
