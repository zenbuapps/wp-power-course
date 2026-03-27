import dayjs from 'dayjs'
import React, { FC } from 'react'

export const SaleRange: FC<{
	saleDateRange: [number, number] // 只可能是 10 位數或 0
	format?: string
}> = ({ saleDateRange, format = 'YYYY-MM-DD HH:mm' }) => {
	const [saleFrom, saleTo] = saleDateRange

	if (!saleFrom && !saleTo) return null

	const saleFromText = saleFrom ? dayjs.unix(saleFrom).format(format) : '未設定'
	const saleToText = saleTo ? dayjs.unix(saleTo).format(format) : '未設定'

	return <>{`${saleFromText} - ${saleToText}`}</>
}
