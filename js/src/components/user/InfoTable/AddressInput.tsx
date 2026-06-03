import { Form, Input, Select } from 'antd'
import { useCountryOptions } from 'antd-toolkit/wp'

const { Item } = Form

/** AddressInput props */
type TAddressInputProps = {
	/** 欄位所屬區塊（帳單 / 運送） */
	type: 'billing' | 'shipping'
	/** 是否處於編輯模式 */
	isEditing: boolean
	/** 地址子欄位名（country / postcode / state / city / address_1 / address_2） */
	field: string
}

/**
 * 地址單一子欄位輸入元件
 *
 * country 欄位以 Select 呈現 WooCommerce 國家選項；其餘欄位以純文字 Input。
 * Form name 採巢狀 `[type, field]` 結構，儲存時由主體攤平成 `billing_xxx` / `shipping_xxx`。
 */
const AddressInput = ({ type, isEditing, field }: TAddressInputProps) => {
	const countryOptions = useCountryOptions()

	if ('country' === field) {
		return (
			<Item name={[type, field]} noStyle label={field} hidden={!isEditing}>
				<Select
					options={countryOptions}
					size="small"
					className="text-right [&_.ant-select-selection-item]:!text-xs flex-1 h-[1.125rem]"
					allowClear
				/>
			</Item>
		)
	}

	return (
		<Item name={[type, field]} noStyle label={field} hidden={!isEditing}>
			<Input size="small" className="text-right text-xs flex-1" />
		</Item>
	)
}

export default AddressInput
