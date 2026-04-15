import {
	ThemedLayoutContextProvider,
	type RefineThemedLayoutV2Props,
} from '@refinedev/antd'
import { Grid, Layout as AntdLayout } from 'antd'
import React from 'react'

import { ThemedHeaderV2 as DefaultHeader } from './header'
import { ThemedSiderV2 as DefaultSider } from './sider'

export const ThemedLayoutV2: React.FC<RefineThemedLayoutV2Props> = ({
	children,
	Header,
	Sider,
	Title,
	Footer,
	OffLayoutArea,
	initialSiderCollapsed,
}) => {
	const breakpoint = Grid.useBreakpoint()
	const SiderToRender = Sider ?? DefaultSider
	const HeaderToRender = Header ?? DefaultHeader
	const isSmall = typeof breakpoint.sm === 'undefined' ? true : breakpoint.sm
	const hasSider = !!SiderToRender({ Title })

	return (
		<ThemedLayoutContextProvider initialSiderCollapsed={initialSiderCollapsed}>
			<AntdLayout style={{ minHeight: '100vh' }} hasSider={hasSider}>
				<SiderToRender Title={Title} />
				<AntdLayout>
					<HeaderToRender />
					<AntdLayout.Content>
						<div
							style={{
								minHeight: 360,
								padding: isSmall ? 24 : 12,
							}}
						>
							{children}
						</div>
						{OffLayoutArea && <OffLayoutArea />}
					</AntdLayout.Content>
					{Footer && <Footer />}
				</AntdLayout>
			</AntdLayout>
		</ThemedLayoutContextProvider>
	)
}
