import { useSelect } from '@refinedev/antd'
import { Form, Select, FormItemProps, SelectProps } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'
import React, { FC, memo } from 'react'
import { __, sprintf } from '@wordpress/i18n'

import { TFilterProps, TTerm } from '@/components/product/ProductTable/types'
import { keyLabelMapper } from '@/components/product/ProductTable/utils'

const { Item } = Form

type TTermSelector = {
	name: [keyof TFilterProps]
	taxonomy: string
	formItemProps?: Omit<FormItemProps, 'name'>
	selectProps?: SelectProps
}

/**
 * TermSelector Component for WooCommerce Product Filter
 * Fetches terms from WordPress API and renders a Select component.
 *
 * @param name          Field name in Form, passed to WooCommerce `wc_get_products` PHP function.
 * @param taxonomy      WordPress term taxonomy name, optional.
 * @param formItemProps Antd Form.Item props, excluding `name`.
 * @param selectProps   Antd Select props, optional.
 * @return ReactNode
 */

const TermSelector: FC<TTermSelector> = ({
	name: formName,
	taxonomy,
	formItemProps,
	selectProps,
}) => {
	const name = formName[0]
	const fieldLabel = keyLabelMapper(name)
	const { selectProps: selectPropsFetched } = useSelect<TTerm>({
		resource: 'terms',
		dataProviderName: 'power-course',
		optionLabel: 'name',
		optionValue: 'id',
		sorters: [
			{
				field: 'name',
				order: 'asc',
			},
		],
		pagination: {
			mode: 'off',
		},
		filters: [
			{
				field: 'taxonomy',
				operator: 'eq',
				value: taxonomy,
			},
		],
		errorNotification: () => {
			return {
				message: sprintf(
					// translators: %s: 欄位名稱
					__('Failed to fetch %s API', 'power-course'),
					fieldLabel
				),
				type: 'error',
			}
		},
	})

	return (
		<Item name={formName} label={fieldLabel} {...formItemProps}>
			<Select
				{...defaultSelectProps}
				{...selectPropsFetched}
				size="small"
				placeholder={__('Multiple selection', 'power-course')}
				{...selectProps}
			/>
		</Item>
	)
}

export default memo(TermSelector)
