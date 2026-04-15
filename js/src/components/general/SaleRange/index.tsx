import { __ } from '@wordpress/i18n'
import dayjs from 'dayjs'
import React, { FC } from 'react'

export const SaleRange: FC<{
	saleDateRange: [number, number] // 只可能是 10 位數或 0
	format?: string
}> = ({ saleDateRange, format = 'YYYY-MM-DD HH:mm' }) => {
	const [saleFrom, saleTo] = saleDateRange

	if (!saleFrom && !saleTo) return null

	const notSetText = __('Not set', 'power-course')
	const saleFromText = saleFrom
		? dayjs.unix(saleFrom).format(format)
		: notSetText
	const saleToText = saleTo ? dayjs.unix(saleTo).format(format) : notSetText

	return <>{`${saleFromText} - ${saleToText}`}</>
}
