import { Amount } from 'antd-toolkit'
import { round } from 'lodash-es'

/** Price 元件 props */
type TPriceProps = {
	/** 金額（未四捨五入） */
	amount: number
	/** 貨幣代碼，預設 TWD */
	currency?: string
	/** 是否顯示貨幣符號，預設 true */
	symbol?: boolean
	/** 小數位數，預設 0 */
	precision?: number
}

const CURRENCY = 'TWD'

/**
 * 價格顯示元件
 *
 * 以 antd-toolkit 的 Amount 呈現格式化後的金額，先依 precision 四捨五入。
 */
export const Price = ({
	amount,
	currency = CURRENCY,
	symbol = true,
	precision = 0,
}: TPriceProps) => {
	const roundedAmount = round(amount, precision)

	return <Amount amount={roundedAmount} currency={currency} symbol={symbol} />
}
