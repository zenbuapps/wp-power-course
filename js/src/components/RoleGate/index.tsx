/**
 * RoleGate — UI 層的角色可見性閘門元件
 *
 * 用途：依當前使用者的角色，決定是否 render children。
 *
 * 信任邊界：
 *   本元件**不是**安全邊界。真正的權限執行由後端 REST permission_callback
 *   負責。即使攻擊者透過 devtools 強制 render children，後端仍會回 403。
 *   本元件僅作 UX 改善（隱藏使用者無法操作的功能）。
 *
 * 範例：
 *   ```tsx
 *   <RoleGate capability="admin">
 *     <SecretSettings />
 *   </RoleGate>
 *
 *   <RoleGate capability="admin" fallback={<NotAllowed />}>
 *     <SecretSettings />
 *   </RoleGate>
 *   ```
 */
import { memo, type ReactNode } from 'react'

import { IS_ADMIN } from '@/utils/env'

/** 目前支援的 capability 等級；未來新增其他 capability 時擴充本 union 即可 */
type Capability = 'admin'

type RoleGateProps = {
	/** 需要的 capability 等級，預設 'admin' */
	capability?: Capability
	/** 通過時 render 的內容 */
	children: ReactNode
	/** 未通過時 render 的內容，預設 null */
	fallback?: ReactNode
}

const RoleGateComponent = ({
	capability = 'admin',
	children,
	fallback = null,
}: RoleGateProps) => {
	if (capability === 'admin' && !IS_ADMIN) {
		return <>{fallback}</>
	}
	return <>{children}</>
}

export const RoleGate = memo(RoleGateComponent)

export default RoleGate
