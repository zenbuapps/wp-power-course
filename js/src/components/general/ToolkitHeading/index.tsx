import { DividerProps } from 'antd'
import { Heading as UpstreamHeading } from 'antd-toolkit'
import React, { FC } from 'react'

/**
 * antd-toolkit `Heading` 的型別修補 re-export。
 *
 * 上游把 props 宣告為
 * `{ children; titleProps?; size?: 'md' | 'sm'; hideIcon?: boolean } & DividerProps`，
 * 但 antd 5.20 起 `DividerProps` 也新增了 `size?: SizeType`
 * （'small' | 'middle' | 'large'）。兩組字面量在交集型別下沒有共同成員，
 * `size` 因此收斂成 `undefined`，導致傳入任何值都報 TS2322
 * （Type 'string' is not assignable to type 'undefined'）。
 *
 * 這裡只重新標註型別（Omit 掉衝突的 `size`），元件實作與畫面完全沿用上游，
 * runtime 行為零變化。待 antd-toolkit 修正型別後，
 * 各 call site 即可直接改回 `import { Heading } from 'antd-toolkit'` 並刪除本檔。
 *
 * 與 `@/components/general/Heading` 的差異：後者是專案自家較早的實作，
 * 不支援 `hideIcon`，兩者樣式不完全相同，故不能互相取代。
 */
type TToolkitHeadingProps = {
	children: React.ReactNode
	titleProps?: React.ComponentProps<typeof UpstreamHeading>['titleProps']
	size?: 'md' | 'sm'
	hideIcon?: boolean
} & Omit<DividerProps, 'size'>

export const ToolkitHeading =
	UpstreamHeading as unknown as FC<TToolkitHeadingProps>
